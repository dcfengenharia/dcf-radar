<?php

namespace Tests\Feature;

use App\Enums\SerieAvanco;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\FluxoSuprimento;
use App\Models\ItemSuprimento;
use App\Models\ItemSuprimentoEtapaData;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\SuprimentoScheduler;
use App\Support\DiasUteisCalculator;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reimportação de cronograma pode mudar inicio_planejado de uma atividade
 * com item de suprimento vinculado — o hook em MsProjectImporter (logo
 * após ConclusaoAutomaticaAtividades::aplicar()) precisa recalcular
 * Tendência/status/restrição automaticamente, sem tocar o Previsto
 * congelado (mesma filosofia de RegistrarComprometimentoSemanal).
 */
class MsProjectImporterSuprimentosHookTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private ImportadorCronograma $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->importer = app(ImportadorCronograma::class);
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    public function test_reimportacao_recalcula_tendencia_sem_mexer_no_previsto_congelado(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'sample.xml');

        $atividade = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->firstOrFail();
        $this->assertTrue($atividade->inicio_planejado->isSameDay(Carbon::parse('2024-01-01')));

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 5]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 2, 'nome' => 'Pedido', 'prazo_dias_uteis' => 3]);

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item de Teste',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));
        $item = $item->fresh(['atividades']);

        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($item);
        $scheduler->congelarPrevisto($item);

        $etapaCotacao = $item->etapas()->where('nome', 'Cotação')->firstOrFail();
        $etapaPedido = $item->etapas()->where('nome', 'Pedido')->firstOrFail();

        $calc = DiasUteisCalculator::paraObra($this->obra);
        $necessidadeOriginal = Carbon::parse('2024-01-01');

        $previstoPedidoAntes = $etapaPedido->datas()->where('serie', SerieAvanco::Previsto->value)->firstOrFail()->data;
        $previstoCotacaoAntes = $etapaCotacao->datas()->where('serie', SerieAvanco::Previsto->value)->firstOrFail()->data;
        $this->assertTrue($previstoPedidoAntes->isSameDay($necessidadeOriginal));
        $this->assertTrue($previstoCotacaoAntes->isSameDay($calc->subtrair($necessidadeOriginal, 5)));

        // Reimportação: a mesma atividade (external_uid=2) agora com
        // inicio_planejado bem mais tarde.
        $planoV2 = $this->importer->analisar($this->fixture('cronograma_suprimentos_reimport.xml'), $this->obra);
        $this->importer->aplicar($planoV2, $this->obra, $this->user->id, 'reimport.xml');

        $atividade = $atividade->fresh();
        $this->assertTrue($atividade->inicio_planejado->isSameDay(Carbon::parse('2024-03-01')));

        $necessidadeNova = Carbon::parse('2024-03-01');

        // Tendência recalculada ao vivo pra refletir a nova necessidade.
        $tendenciaPedido = $etapaPedido->fresh()->datas()->where('serie', SerieAvanco::Tendencia->value)->first();
        $tendenciaCotacao = $etapaCotacao->fresh()->datas()->where('serie', SerieAvanco::Tendencia->value)->first();
        $this->assertNotNull($tendenciaPedido);
        $this->assertTrue($tendenciaPedido->data->isSameDay($necessidadeNova));
        $this->assertTrue($tendenciaCotacao->data->isSameDay($calc->subtrair($necessidadeNova, 5)));

        // Previsto continua ancorado na necessidade ORIGINAL — imutável.
        $previstoPedidoDepois = $etapaPedido->fresh()->datas()->where('serie', SerieAvanco::Previsto->value)->firstOrFail()->data;
        $previstoCotacaoDepois = $etapaCotacao->fresh()->datas()->where('serie', SerieAvanco::Previsto->value)->firstOrFail()->data;
        $this->assertTrue($previstoPedidoDepois->isSameDay($necessidadeOriginal));
        $this->assertTrue($previstoCotacaoDepois->isSameDay($calc->subtrair($necessidadeOriginal, 5)));
    }

    public function test_reimportacao_que_nao_toca_a_atividade_vinculada_nao_falha(): void
    {
        // Garante que o hook não quebra quando NENHUM item de suprimento
        // está vinculado às atividades tocadas pela importação (caso comum).
        $plano = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $importacao = $this->importer->aplicar($plano, $this->obra, $this->user->id, 'sample.xml');

        $this->assertNotNull($importacao);
        $this->assertSame(0, ItemSuprimento::where('obra_id', $this->obra->id)->count());
    }
}
