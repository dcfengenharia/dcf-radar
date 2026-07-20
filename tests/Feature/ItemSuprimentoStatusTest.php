<?php

namespace Tests\Feature;

use App\Enums\SerieAvanco;
use App\Enums\StatusItemSuprimento;
use App\Models\Atividade;
use App\Models\FluxoSuprimento;
use App\Models\ItemSuprimento;
use App\Models\ItemSuprimentoEtapa;
use App\Models\ItemSuprimentoEtapaData;
use App\Models\Tenant;
use App\Models\Work;
use App\Services\SuprimentoScheduler;
use App\Support\DiasUteisCalculator;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testa a classificação de status (App\Services\SuprimentoScheduler::
 * statusDoItem()) manipulando diretamente as datas de cada etapa —
 * decoupled do encadeamento de datas em si (já coberto em
 * SuprimentoSchedulerTest), foca só na LÓGICA DE DECISÃO do farol.
 */
class ItemSuprimentoStatusTest extends TestCase
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
    private function criarItemComEtapas(array $etapasDef, string $necessidade): ItemSuprimento
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
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));

        $this->scheduler->criarEtapasDoItem($item);

        return $item->fresh(['atividades']);
    }

    private function setarData(ItemSuprimento $item, string $nomeEtapa, SerieAvanco $serie, string $data): void
    {
        $etapa = $item->etapas()->where('nome', $nomeEtapa)->firstOrFail();

        ItemSuprimentoEtapaData::updateOrCreate(
            ['item_suprimento_etapa_id' => $etapa->id, 'serie' => $serie->value],
            ['tenant_id' => $this->tenant->id, 'data' => $data]
        );
    }

    public function test_status_concluido_quando_ultima_etapa_realizada(): void
    {
        $item = $this->criarItemComEtapas([['A', 5], ['B', 3]], '2026-09-01');
        $this->setarData($item, 'B', SerieAvanco::Realizado, '2026-08-20');

        $status = $this->scheduler->statusDoItem($item);

        $this->assertSame(StatusItemSuprimento::Concluido, $status);
        $this->assertSame(StatusItemSuprimento::Concluido, $item->fresh()->status);
    }

    public function test_status_atrasado_quando_tendencia_final_passa_da_necessidade(): void
    {
        $item = $this->criarItemComEtapas([['A', 5], ['B', 3]], '2026-09-01');
        // Algo já foi realizado (senão o encadeamento retroativo nunca
        // ultrapassaria a necessidade, por construção).
        $this->setarData($item, 'A', SerieAvanco::Realizado, '2026-08-20');
        $this->setarData($item, 'B', SerieAvanco::Tendencia, '2026-09-10');

        $status = $this->scheduler->statusDoItem($item);

        $this->assertSame(StatusItemSuprimento::Atrasado, $status);
    }

    public function test_status_em_risco_quando_etapa_pendente_ja_venceu_sem_realizado(): void
    {
        // Necessidade confortavelmente no futuro, mas a etapa em si já
        // deveria ter sido feita há tempos (hoje é 2026-07-13 no ambiente
        // de testes) — nada foi realizado ainda.
        $item = $this->criarItemComEtapas([['Cotação', 5]], '2026-09-01');
        $this->setarData($item, 'Cotação', SerieAvanco::Tendencia, '2026-06-01');

        $status = $this->scheduler->statusDoItem($item);

        $this->assertSame(StatusItemSuprimento::EmRisco, $status);
    }

    public function test_status_em_risco_quando_folga_final_e_pequena(): void
    {
        $necessidade = Carbon::parse('2026-09-01');
        $item = $this->criarItemComEtapas([['A', 5], ['B', 2]], $necessidade->toDateString());

        $calc = DiasUteisCalculator::paraObra($this->obra);
        $tendenciaFinal = $calc->subtrair($necessidade, 2); // dentro da janela de risco de 5 dias úteis

        $this->setarData($item, 'A', SerieAvanco::Realizado, $calc->subtrair($necessidade, 4)->toDateString());
        $this->setarData($item, 'B', SerieAvanco::Tendencia, $tendenciaFinal->toDateString());

        $status = $this->scheduler->statusDoItem($item);

        $this->assertSame(StatusItemSuprimento::EmRisco, $status);
    }

    public function test_status_em_andamento_quando_alguma_etapa_realizada_sem_risco(): void
    {
        $necessidade = Carbon::parse('2026-09-01');
        $item = $this->criarItemComEtapas([['A', 5], ['B', 2]], $necessidade->toDateString());

        $calc = DiasUteisCalculator::paraObra($this->obra);

        $this->setarData($item, 'A', SerieAvanco::Realizado, '2026-07-15');
        $this->setarData($item, 'B', SerieAvanco::Tendencia, $calc->subtrair($necessidade, 20)->toDateString());

        $status = $this->scheduler->statusDoItem($item);

        $this->assertSame(StatusItemSuprimento::EmAndamento, $status);
    }

    public function test_status_no_inicio_quando_nada_realizado_e_sem_risco(): void
    {
        $necessidade = Carbon::parse('2026-12-01');
        $item = $this->criarItemComEtapas([['Cotação', 5]], $necessidade->toDateString());

        // Deixa a Tendência bem confortável (equivalente ao encadeamento
        // retroativo padrão, longe de hoje e longe da necessidade).
        $calc = DiasUteisCalculator::paraObra($this->obra);
        $this->setarData($item, 'Cotação', SerieAvanco::Tendencia, $calc->subtrair($necessidade, 5)->toDateString());

        $status = $this->scheduler->statusDoItem($item);

        $this->assertSame(StatusItemSuprimento::NoInicio, $status);
    }

    public function test_status_sem_atividade_vinculada_e_no_inicio(): void
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Sem Vinculo']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 5]);

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Sem Atividade',
        ]);

        $status = $this->scheduler->statusDoItem($item->fresh(['atividades']));

        $this->assertSame(StatusItemSuprimento::NoInicio, $status);
    }
}
