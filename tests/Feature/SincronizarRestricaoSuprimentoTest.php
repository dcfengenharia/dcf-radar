<?php

namespace Tests\Feature;

use App\Enums\PilarLean;
use App\Enums\SerieAvanco;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\FluxoSuprimento;
use App\Models\ItemSuprimento;
use App\Models\ItemSuprimentoEtapaData;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\SuprimentoScheduler;
use App\Support\SincronizarRestricaoSuprimento;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SincronizarRestricaoSuprimentoTest extends TestCase
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

    /** @param array<int, array{string, int}> $etapasDef */
    private function criarItemEmRisco(array $etapasDef, string $necessidade, Atividade ...$atividadesExtras): array
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);
        foreach ($etapasDef as $indice => [$nome, $prazo]) {
            $fluxo->etapas()->create([
                'tenant_id' => $this->tenant->id,
                'ordem' => $indice + 1,
                'nome' => $nome,
                'prazo_dias_uteis' => $prazo,
            ]);
        }

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => $necessidade,
        ]);

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item de Teste',
        ]);

        $todasAtividades = array_merge([$atividade], $atividadesExtras);
        TenantContext::actingAs($this->tenant, function () use ($item, $todasAtividades) {
            $item->atividades()->attach(collect($todasAtividades)->pluck('id'));
        });

        $item = $item->fresh(['atividades']);
        $this->scheduler->criarEtapasDoItem($item);
        $this->scheduler->congelarPrevisto($item);

        // Deixa a Tendência da última etapa deliberadamente ATRASADA em
        // relação à necessidade, pra forçar o status pra Atrasado (algo
        // precisa estar Realizado pra folga/atraso serem avaliados —
        // ver App\Services\SuprimentoScheduler::calcularStatus()).
        $primeiraEtapa = $item->etapas()->orderBy('ordem')->firstOrFail();
        ItemSuprimentoEtapaData::create([
            'tenant_id' => $this->tenant->id,
            'item_suprimento_etapa_id' => $primeiraEtapa->id,
            'serie' => SerieAvanco::Realizado->value,
            'data' => Carbon::parse($necessidade)->subDays(60)->toDateString(),
        ]);

        return [$item, $atividade];
    }

    // Restricao::create() dentro de SincronizarRestricaoSuprimento depende
    // de TenantContext::currentId() (via BelongsToTenant) pra carimbar
    // tenant_id, exatamente como no app real (requisição autenticada) — o
    // teste precisa simular esse contexto ambiente.
    private function sincronizar(ItemSuprimento $item, ?string $userId): void
    {
        TenantContext::actingAs($this->tenant, fn () => SincronizarRestricaoSuprimento::sincronizarItem($item, $userId));
    }

    private function desvincular(ItemSuprimento $item, Atividade $atividade, ?string $userId): void
    {
        TenantContext::actingAs($this->tenant, fn () => SincronizarRestricaoSuprimento::desvincularAtividade($item, $atividade, $userId));
    }

    private function resolverTudo(ItemSuprimento $item, ?string $userId): void
    {
        TenantContext::actingAs($this->tenant, fn () => SincronizarRestricaoSuprimento::resolverTudo($item, $userId));
    }

    public function test_abre_restricao_bloqueante_quando_item_entra_em_risco(): void
    {
        [$item, $atividade] = $this->criarItemEmRisco([['Cotação', 5], ['Pedido', 3]], '2026-09-01');

        $this->sincronizar($item, null);

        $restricao = Restricao::where('atividade_id', $atividade->id)
            ->where('origem_suprimento_item_id', $item->id)
            ->first();

        $this->assertNotNull($restricao);
        $this->assertTrue($restricao->bloqueante);
        $this->assertSame(StatusRestricao::Aberta, $restricao->status);
        $this->assertFalse($atividade->fresh()->estaPronta());
    }

    public function test_cria_categoria_materiais_automaticamente_quando_tenant_nao_tem_nenhuma(): void
    {
        $this->assertSame(0, CategoriaRestricao::where('tenant_id', $this->tenant->id)->count());

        [$item] = $this->criarItemEmRisco([['Cotação', 5], ['Pedido', 3]], '2026-09-01');
        $this->sincronizar($item, null);

        $categoria = CategoriaRestricao::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($categoria);
        $this->assertSame(PilarLean::Materiais, $categoria->pilar_lean);
    }

    public function test_nao_duplica_restricao_em_sincronizacoes_repetidas(): void
    {
        [$item] = $this->criarItemEmRisco([['Cotação', 5], ['Pedido', 3]], '2026-09-01');

        $this->sincronizar($item, null);
        $this->sincronizar($item->fresh(), null);
        $this->sincronizar($item->fresh(), null);

        $this->assertSame(1, Restricao::where('origem_suprimento_item_id', $item->id)->count());
    }

    public function test_resolve_automaticamente_quando_item_normaliza(): void
    {
        [$item, $atividade] = $this->criarItemEmRisco([['Cotação', 5], ['Pedido', 3]], '2026-09-01');
        $this->sincronizar($item, null);

        $restricao = Restricao::where('origem_suprimento_item_id', $item->id)->firstOrFail();
        $this->assertSame(StatusRestricao::Aberta, $restricao->status);

        // Marca a última etapa como Realizada dentro do prazo — o item
        // deixa de estar em risco/atrasado.
        $ultimaEtapa = $item->etapas()->orderBy('ordem', 'desc')->firstOrFail();
        ItemSuprimentoEtapaData::updateOrCreate(
            ['item_suprimento_etapa_id' => $ultimaEtapa->id, 'serie' => SerieAvanco::Realizado->value],
            ['tenant_id' => $this->tenant->id, 'data' => Carbon::parse('2026-08-20')->toDateString()]
        );

        $this->sincronizar($item->fresh(), null);

        $restricao = $restricao->fresh();
        $this->assertSame(StatusRestricao::Resolvida, $restricao->status);
        $this->assertNotNull($restricao->resolvida_em);
        $this->assertTrue($atividade->fresh()->estaPronta());
    }

    public function test_reabre_restricao_ja_resolvida_se_item_piora_de_novo(): void
    {
        [$item] = $this->criarItemEmRisco([['Cotação', 5], ['Pedido', 3]], '2026-09-01');
        $this->sincronizar($item, null);
        $restricao = Restricao::where('origem_suprimento_item_id', $item->id)->firstOrFail();

        $ultimaEtapa = $item->etapas()->orderBy('ordem', 'desc')->firstOrFail();
        ItemSuprimentoEtapaData::updateOrCreate(
            ['item_suprimento_etapa_id' => $ultimaEtapa->id, 'serie' => SerieAvanco::Realizado->value],
            ['tenant_id' => $this->tenant->id, 'data' => Carbon::parse('2026-08-20')->toDateString()]
        );
        $this->sincronizar($item->fresh(), null);
        $this->assertSame(StatusRestricao::Resolvida, $restricao->fresh()->status);

        // Realizado é removido/corrigido pra uma data muito atrasada —
        // o item volta a ficar Atrasado.
        ItemSuprimentoEtapaData::where('item_suprimento_etapa_id', $ultimaEtapa->id)
            ->where('serie', SerieAvanco::Realizado->value)
            ->update(['data' => Carbon::parse('2026-09-20')->toDateString()]);

        $this->sincronizar($item->fresh(), null);

        $this->assertSame(1, Restricao::where('origem_suprimento_item_id', $item->id)->count());
        $this->assertSame(StatusRestricao::Aberta, $restricao->fresh()->status);
    }

    public function test_registra_restricao_acao_so_quando_ha_usuario(): void
    {
        [$item] = $this->criarItemEmRisco([['Cotação', 5], ['Pedido', 3]], '2026-09-01');
        $this->sincronizar($item, null);
        $restricao = Restricao::where('origem_suprimento_item_id', $item->id)->firstOrFail();

        $ultimaEtapa = $item->etapas()->orderBy('ordem', 'desc')->firstOrFail();
        ItemSuprimentoEtapaData::updateOrCreate(
            ['item_suprimento_etapa_id' => $ultimaEtapa->id, 'serie' => SerieAvanco::Realizado->value],
            ['tenant_id' => $this->tenant->id, 'data' => Carbon::parse('2026-08-20')->toDateString()]
        );

        // Sem usuário (execução de sistema): não loga ação.
        $this->sincronizar($item->fresh(), null);
        $this->assertSame(0, $restricao->acoes()->count());

        // Reabre e resolve de novo, agora com usuário: loga ação.
        ItemSuprimentoEtapaData::where('item_suprimento_etapa_id', $ultimaEtapa->id)
            ->where('serie', SerieAvanco::Realizado->value)
            ->update(['data' => Carbon::parse('2026-09-20')->toDateString()]);
        $this->sincronizar($item->fresh(), null);

        $usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        ItemSuprimentoEtapaData::updateOrCreate(
            ['item_suprimento_etapa_id' => $ultimaEtapa->id, 'serie' => SerieAvanco::Realizado->value],
            ['tenant_id' => $this->tenant->id, 'data' => Carbon::parse('2026-08-20')->toDateString()]
        );
        $this->sincronizar($item->fresh(), $usuario->id);

        $this->assertSame(1, $restricao->acoes()->count());
    }

    public function test_item_vinculado_a_duas_atividades_cria_uma_restricao_em_cada(): void
    {
        $outraAtividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => '2026-09-15',
        ]);

        [$item, $atividadePrincipal] = $this->criarItemEmRisco([['Cotação', 5], ['Pedido', 3]], '2026-09-01', $outraAtividade);

        $this->sincronizar($item, null);

        $this->assertSame(2, Restricao::where('origem_suprimento_item_id', $item->id)->count());
        $this->assertFalse($atividadePrincipal->fresh()->estaPronta());
        $this->assertFalse($outraAtividade->fresh()->estaPronta());
    }

    public function test_desvincular_atividade_resolve_so_a_restricao_daquele_par(): void
    {
        $outraAtividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => '2026-09-15',
        ]);

        [$item, $atividadePrincipal] = $this->criarItemEmRisco([['Cotação', 5], ['Pedido', 3]], '2026-09-01', $outraAtividade);
        $this->sincronizar($item, null);

        $this->desvincular($item, $atividadePrincipal, null);

        $restricaoPrincipal = Restricao::where('atividade_id', $atividadePrincipal->id)
            ->where('origem_suprimento_item_id', $item->id)->firstOrFail();
        $restricaoOutra = Restricao::where('atividade_id', $outraAtividade->id)
            ->where('origem_suprimento_item_id', $item->id)->firstOrFail();

        $this->assertSame(StatusRestricao::Resolvida, $restricaoPrincipal->status);
        $this->assertSame(StatusRestricao::Aberta, $restricaoOutra->status);
        $this->assertTrue($atividadePrincipal->fresh()->estaPronta());
        $this->assertFalse($outraAtividade->fresh()->estaPronta());
    }

    public function test_resolver_tudo_resolve_todas_as_restricoes_abertas_do_item(): void
    {
        $outraAtividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => '2026-09-15',
        ]);

        [$item] = $this->criarItemEmRisco([['Cotação', 5], ['Pedido', 3]], '2026-09-01', $outraAtividade);
        $this->sincronizar($item, null);

        $this->assertSame(2, Restricao::where('origem_suprimento_item_id', $item->id)
            ->where('status', StatusRestricao::Aberta->value)->count());

        $this->resolverTudo($item, null);

        $this->assertSame(0, Restricao::where('origem_suprimento_item_id', $item->id)
            ->where('status', StatusRestricao::Aberta->value)->count());
        $this->assertSame(2, Restricao::where('origem_suprimento_item_id', $item->id)
            ->where('status', StatusRestricao::Resolvida->value)->count());
    }
}
