<?php

namespace Tests\Feature;

use App\Enums\SerieAvanco;
use App\Models\Atividade;
use App\Models\FluxoSuprimento;
use App\Models\ItemSuprimento;
use App\Models\ItemSuprimentoEtapaData;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\Work;
use App\Services\SuprimentoScheduler;
use App\Support\SincronizarRestricaoSuprimento;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ponta a ponta: confirma que um item de suprimento em risco bloqueia a
 * prontidão da atividade através do MESMO motor de restrições já usado
 * pelo resto do app (App\Models\Atividade::estaPronta()/scopeProntas()) —
 * não existe um segundo mecanismo de bloqueio paralelo específico de
 * Suprimentos.
 */
class ItemSuprimentoReadinessIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private SuprimentoScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->scheduler = new SuprimentoScheduler();
    }

    public function test_item_em_risco_bloqueia_prontidao_e_resolver_libera(): void
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 5]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 2, 'nome' => 'Pedido', 'prazo_dias_uteis' => 3]);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => '2026-09-01',
        ]);

        $this->assertTrue($atividade->estaPronta());
        $this->assertTrue(Atividade::prontas()->whereKey($atividade->id)->exists());

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item de Teste',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));
        $item = $item->fresh(['atividades']);

        $this->scheduler->criarEtapasDoItem($item);
        $this->scheduler->congelarPrevisto($item);

        // Cotação foi realizada bem atrasada — o item vira Atrasado.
        $etapaCotacao = $item->etapas()->where('nome', 'Cotação')->firstOrFail();
        ItemSuprimentoEtapaData::create([
            'tenant_id' => $this->tenant->id,
            'item_suprimento_etapa_id' => $etapaCotacao->id,
            'serie' => SerieAvanco::Realizado->value,
            'data' => Carbon::parse('2026-09-10')->toDateString(),
        ]);

        TenantContext::actingAs($this->tenant, fn () => SincronizarRestricaoSuprimento::sincronizarItem($item, null));

        $atividade = $atividade->fresh();
        $this->assertFalse($atividade->estaPronta());
        $this->assertFalse(Atividade::prontas()->whereKey($atividade->id)->exists());

        $restricao = Restricao::where('atividade_id', $atividade->id)
            ->where('origem_suprimento_item_id', $item->id)
            ->firstOrFail();
        $this->assertTrue($restricao->bloqueante);

        // Pedido também é realizado, ainda dentro do prazo — item normaliza.
        $etapaPedido = $item->etapas()->where('nome', 'Pedido')->firstOrFail();
        ItemSuprimentoEtapaData::create([
            'tenant_id' => $this->tenant->id,
            'item_suprimento_etapa_id' => $etapaPedido->id,
            'serie' => SerieAvanco::Realizado->value,
            'data' => Carbon::parse('2026-08-20')->toDateString(),
        ]);

        TenantContext::actingAs($this->tenant, fn () => SincronizarRestricaoSuprimento::sincronizarItem($item->fresh(), null));

        $atividade = $atividade->fresh();
        $this->assertTrue($atividade->estaPronta());
        $this->assertTrue(Atividade::prontas()->whereKey($atividade->id)->exists());
    }
}
