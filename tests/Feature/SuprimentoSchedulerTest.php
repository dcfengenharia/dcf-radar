<?php

namespace Tests\Feature;

use App\Enums\SerieAvanco;
use App\Models\Atividade;
use App\Models\FluxoSuprimento;
use App\Models\ItemSuprimento;
use App\Models\ItemSuprimentoEtapaData;
use App\Models\Tenant;
use App\Models\Work;
use App\Services\SuprimentoScheduler;
use App\Support\DiasUteisCalculator;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuprimentoSchedulerTest extends TestCase
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

    private function criarFluxo(array $etapas): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);

        foreach ($etapas as $indice => [$nome, $prazo]) {
            $fluxo->etapas()->create([
                'tenant_id' => $this->tenant->id,
                'ordem' => $indice + 1,
                'nome' => $nome,
                'prazo_dias_uteis' => $prazo,
            ]);
        }

        return $fluxo->fresh(['etapas']);
    }

    private function criarAtividade(string $inicioPlanejado): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => $inicioPlanejado,
        ]);
    }

    private function criarItem(FluxoSuprimento $fluxo, Atividade ...$atividades): ItemSuprimento
    {
        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item de Teste',
        ]);

        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach(collect($atividades)->pluck('id')));

        return $item->fresh(['atividades']);
    }

    public function test_congelar_previsto_encadeia_retroativo_pulando_feriado(): void
    {
        // Segunda-feira, sem feriados no meio.
        $fluxo = $this->criarFluxo([['Cotação', 5], ['Pedido', 3]]);
        $atividade = $this->criarAtividade('2026-07-20');
        $item = $this->criarItem($fluxo, $atividade);

        $this->scheduler->criarEtapasDoItem($item);
        $this->scheduler->congelarPrevisto($item);

        $calc = DiasUteisCalculator::paraObra($this->obra);
        $necessidade = Carbon::parse('2026-07-20');

        $etapaCotacao = $item->etapas()->where('nome', 'Cotação')->firstOrFail();
        $etapaPedido = $item->etapas()->where('nome', 'Pedido')->firstOrFail();

        $previstoPedido = $etapaPedido->datas()->where('serie', SerieAvanco::Previsto->value)->firstOrFail();
        $previstoCotacao = $etapaCotacao->datas()->where('serie', SerieAvanco::Previsto->value)->firstOrFail();

        $this->assertTrue($previstoPedido->data->isSameDay($necessidade));
        $this->assertTrue($previstoCotacao->data->isSameDay($calc->subtrair($necessidade, 5)));
    }

    public function test_congelar_previsto_e_imutavel_apos_mudanca_de_necessidade(): void
    {
        $fluxo = $this->criarFluxo([['Cotação', 5]]);
        $atividade = $this->criarAtividade('2026-07-20');
        $item = $this->criarItem($fluxo, $atividade);

        $this->scheduler->criarEtapasDoItem($item);
        $this->scheduler->congelarPrevisto($item);

        $etapa = $item->etapas()->firstOrFail();
        $previstoAntes = $etapa->datas()->where('serie', SerieAvanco::Previsto->value)->firstOrFail()->data;

        // Reimportação move a atividade pra outra data.
        $atividade->update(['inicio_planejado' => '2026-09-01']);
        $this->scheduler->congelarPrevisto($item->fresh(['atividades']));

        $previstoDepois = $etapa->fresh()->datas()->where('serie', SerieAvanco::Previsto->value)->firstOrFail()->data;

        $this->assertTrue($previstoAntes->isSameDay($previstoDepois));
    }

    public function test_recalcular_tendencia_encadeia_pra_frente_a_partir_de_realizado_no_meio(): void
    {
        $fluxo = $this->criarFluxo([['A', 5], ['B', 3], ['C', 2]]);
        $atividade = $this->criarAtividade('2026-08-31');
        $item = $this->criarItem($fluxo, $atividade);

        $this->scheduler->criarEtapasDoItem($item);
        $this->scheduler->congelarPrevisto($item);

        $etapaA = $item->etapas()->where('nome', 'A')->firstOrFail();
        $etapaB = $item->etapas()->where('nome', 'B')->firstOrFail();
        $etapaC = $item->etapas()->where('nome', 'C')->firstOrFail();

        $realizadoA = Carbon::parse('2026-07-06');
        ItemSuprimentoEtapaData::create([
            'tenant_id' => $this->tenant->id,
            'item_suprimento_etapa_id' => $etapaA->id,
            'serie' => SerieAvanco::Realizado->value,
            'data' => $realizadoA->toDateString(),
        ]);

        $this->scheduler->recalcularTendencia($item);

        $calc = DiasUteisCalculator::paraObra($this->obra);
        $tendenciaA = $etapaA->datas()->where('serie', SerieAvanco::Tendencia->value)->firstOrFail();
        $tendenciaB = $etapaB->datas()->where('serie', SerieAvanco::Tendencia->value)->firstOrFail();
        $tendenciaC = $etapaC->datas()->where('serie', SerieAvanco::Tendencia->value)->firstOrFail();

        $this->assertTrue($tendenciaA->data->isSameDay($realizadoA));
        $this->assertTrue($tendenciaB->data->isSameDay($calc->somar($realizadoA, 3)));
        $this->assertTrue($tendenciaC->data->isSameDay($calc->somar($calc->somar($realizadoA, 3), 2)));
    }

    public function test_recalcular_tendencia_cai_pro_retroativo_ao_vivo_quando_nada_realizado(): void
    {
        // Duas etapas: só a PENÚLTIMA tem seu prazo aplicado no encadeamento
        // retroativo — a última sempre é fixada exatamente na necessidade
        // (é o "prazo de gap até a etapa seguinte", que não existe pra
        // quem é a própria última etapa).
        $fluxo = $this->criarFluxo([['Cotação', 5], ['Pedido', 3]]);
        $atividade = $this->criarAtividade('2026-07-20');
        $item = $this->criarItem($fluxo, $atividade);

        $this->scheduler->criarEtapasDoItem($item);
        $this->scheduler->congelarPrevisto($item);

        // Reimportação antecipa a necessidade — Tendência deve refletir,
        // mesmo sem nenhum Realizado ainda.
        $atividade->update(['inicio_planejado' => '2026-07-13']);
        $this->scheduler->recalcularTendencia($item->fresh(['atividades']));

        $calc = DiasUteisCalculator::paraObra($this->obra);
        $etapaCotacao = $item->etapas()->where('nome', 'Cotação')->firstOrFail();
        $etapaPedido = $item->etapas()->where('nome', 'Pedido')->firstOrFail();

        $tendenciaCotacao = $etapaCotacao->datas()->where('serie', SerieAvanco::Tendencia->value)->firstOrFail();
        $tendenciaPedido = $etapaPedido->datas()->where('serie', SerieAvanco::Tendencia->value)->firstOrFail();
        $previstoCotacao = $etapaCotacao->datas()->where('serie', SerieAvanco::Previsto->value)->firstOrFail();

        $this->assertTrue($tendenciaPedido->data->isSameDay(Carbon::parse('2026-07-13')));
        $this->assertTrue($tendenciaCotacao->data->isSameDay($calc->subtrair(Carbon::parse('2026-07-13'), 5)));
        // Previsto continua ancorado na necessidade original (2026-07-20), não muda.
        $this->assertTrue($previstoCotacao->data->isSameDay($calc->subtrair(Carbon::parse('2026-07-20'), 5)));
    }

    public function test_necessidade_usa_a_atividade_mais_cedo_entre_varias_vinculadas(): void
    {
        $fluxo = $this->criarFluxo([['Cotação', 5], ['Pedido', 3]]);
        $atividadeCedo = $this->criarAtividade('2026-07-20');
        $atividadeTarde = $this->criarAtividade('2026-09-01');
        $item = $this->criarItem($fluxo, $atividadeTarde, $atividadeCedo);

        $this->assertTrue($item->necessidade()->isSameDay(Carbon::parse('2026-07-20')));

        $this->scheduler->criarEtapasDoItem($item);
        $this->scheduler->congelarPrevisto($item);

        $calc = DiasUteisCalculator::paraObra($this->obra);
        $etapaCotacao = $item->etapas()->where('nome', 'Cotação')->firstOrFail();
        $previsto = $etapaCotacao->datas()->where('serie', SerieAvanco::Previsto->value)->firstOrFail();

        $this->assertTrue($previsto->data->isSameDay($calc->subtrair(Carbon::parse('2026-07-20'), 5)));
    }

    public function test_necessidade_troca_de_atividade_quando_a_ordem_das_datas_se_inverte(): void
    {
        $fluxo = $this->criarFluxo([['Cotação', 5]]);
        $atividadeA = $this->criarAtividade('2026-08-01');
        $atividadeB = $this->criarAtividade('2026-09-01');
        $item = $this->criarItem($fluxo, $atividadeA, $atividadeB);

        $this->assertTrue($item->necessidade()->isSameDay(Carbon::parse('2026-08-01')));

        // Atividade A é adiada pra depois de B — a necessidade passa a ser B.
        $atividadeA->update(['inicio_planejado' => '2026-10-01']);

        $this->assertTrue($item->fresh(['atividades'])->necessidade()->isSameDay(Carbon::parse('2026-09-01')));
    }
}
