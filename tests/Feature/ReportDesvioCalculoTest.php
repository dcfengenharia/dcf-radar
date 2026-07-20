<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\ReportGerador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testa as fórmulas do quadro de análise de desvios (App\Services\
 * ReportGerador::gerarQuadroDesvios/calcularLinhaDesvio), verificadas
 * contra a planilha real do usuário: peso = HH do escopo da linha / HH
 * do escopo do pai; %previsto e %real usam o MESMO denominador (HH de
 * linha de base do escopo PRÓPRIO da linha, não do pai); %desvio =
 * %real - %previsto; %impacto = %desvio * peso.
 */
class ReportDesvioCalculoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private CronogramaImportacao $importacao;
    private User $usuario;
    private PacoteTrabalho $raiz;
    private PacoteTrabalho $filhoA;
    private PacoteTrabalho $filhoB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->usuario);

        $this->importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'data_status' => '2026-01-20',
            'importado_em' => now(),
        ]);

        $this->raiz = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'CIVIL',
            'codigo' => '1',
        ]);
        $this->filhoA = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $this->raiz->id,
            'nome' => 'Fundações',
            'codigo' => '1.1',
        ]);
        $this->filhoB = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $this->raiz->id,
            'nome' => 'Estrutura',
            'codigo' => '1.2',
        ]);

        // Fundações: baseline 80 (jan, <= data_status) + 20 (fev) = 100; realizado 60 (jan)
        // -> %previsto=80%, %real=60%, %desvio=-20%
        $this->criarHh($this->filhoA->id, SerieAvanco::Previsto, '2026-01-01', 80);
        $this->criarHh($this->filhoA->id, SerieAvanco::Previsto, '2026-02-01', 20);
        $this->criarHh($this->filhoA->id, SerieAvanco::Realizado, '2026-01-01', 60);

        // Estrutura: baseline 200 (jan) + 100 (fev) = 300; realizado 100 (jan)
        // -> %previsto=66.67%, %real=33.33%, %desvio=-33.33%
        $this->criarHh($this->filhoB->id, SerieAvanco::Previsto, '2026-01-01', 200);
        $this->criarHh($this->filhoB->id, SerieAvanco::Previsto, '2026-02-01', 100);
        $this->criarHh($this->filhoB->id, SerieAvanco::Realizado, '2026-01-01', 100);
    }

    private function criarHh(string $pacoteId, SerieAvanco $serie, string $periodoInicio, float $horas): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacoteId,
        ]);

        // Grava nas DUAS granularidades — igual o importador real sempre
        // faz — porque ReportGerador::hhAcumuladoAteData() (corte "até a
        // data de status" do quadro de desvios) lê Semanal, enquanto
        // totalHhBaselineDoEscopo() (denominador/peso) lê Mensal.
        foreach ([GranularidadePeriodo::Mensal, GranularidadePeriodo::Semanal] as $granularidade) {
            AvancoPeriodo::create([
                'tenant_id' => $this->tenant->id,
                'cronograma_importacao_id' => $this->importacao->id,
                'atividade_id' => $atividade->id,
                'granularidade' => $granularidade->value,
                'serie' => $serie->value,
                'periodo_inicio' => $periodoInicio,
                'horas' => $horas,
            ]);
        }
    }

    public function test_peso_previsto_real_desvio_e_impacto_por_linha(): void
    {
        $report = app(ReportGerador::class)->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $this->raiz->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $desvios = $report->curvas->first()->desvios->keyBy('pacote_trabalho_id');

        $pai = $desvios->get($this->raiz->id);
        $this->assertEqualsWithDelta(1.0, (float) $pai->peso, 0.0001);
        $this->assertEqualsWithDelta(70.0, (float) $pai->percentual_previsto, 0.01);
        $this->assertEqualsWithDelta(40.0, (float) $pai->percentual_real, 0.01);
        $this->assertEqualsWithDelta(-30.0, (float) $pai->percentual_desvio, 0.01);
        $this->assertEqualsWithDelta(-30.0, (float) $pai->percentual_impacto, 0.01);

        $a = $desvios->get($this->filhoA->id);
        $this->assertEqualsWithDelta(0.25, (float) $a->peso, 0.0001);
        $this->assertEqualsWithDelta(80.0, (float) $a->percentual_previsto, 0.01);
        $this->assertEqualsWithDelta(60.0, (float) $a->percentual_real, 0.01);
        $this->assertEqualsWithDelta(-20.0, (float) $a->percentual_desvio, 0.01);
        $this->assertEqualsWithDelta(-5.0, (float) $a->percentual_impacto, 0.01);

        $b = $desvios->get($this->filhoB->id);
        $this->assertEqualsWithDelta(0.75, (float) $b->peso, 0.0001);
        $this->assertEqualsWithDelta(66.67, (float) $b->percentual_previsto, 0.01);
        $this->assertEqualsWithDelta(33.33, (float) $b->percentual_real, 0.01);
        // Nota: %previsto e %real já vêm arredondados a 2 casas antes da
        // subtração (mesmo comportamento de exibição da planilha original),
        // então o desvio bate com a diferença dos valores JÁ arredondados
        // (66.67 - 33.33 = 33.34), não com a diferença exata (33.333...).
        $this->assertEqualsWithDelta(-33.34, (float) $b->percentual_desvio, 0.01);
        $this->assertEqualsWithDelta(-25.0, (float) $b->percentual_impacto, 0.02);
    }

    public function test_soma_ponderada_dos_filhos_bate_com_nivel_pai(): void
    {
        $report = app(ReportGerador::class)->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $this->raiz->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $desvios = $report->curvas->first()->desvios;
        $pai = $desvios->firstWhere('eh_nivel_pai', true);
        $somaImpactoFilhos = $desvios->where('eh_nivel_pai', false)->sum(fn ($d) => (float) $d->percentual_impacto);

        $this->assertEqualsWithDelta((float) $pai->percentual_impacto, $somaImpactoFilhos, 0.05);
    }

    public function test_apenas_nivel_pai_e_filhos_imediatos_aparecem_no_quadro(): void
    {
        $neto = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $this->filhoA->id,
            'nome' => 'Sapata 01',
            'codigo' => '1.1.1',
        ]);
        $this->criarHh($neto->id, SerieAvanco::Previsto, '2026-01-01', 10);

        $report = app(ReportGerador::class)->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $this->raiz->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $idsNoQuadro = $report->curvas->first()->desvios->pluck('pacote_trabalho_id')->all();

        $this->assertContains($this->raiz->id, $idsNoQuadro);
        $this->assertContains($this->filhoA->id, $idsNoQuadro);
        $this->assertContains($this->filhoB->id, $idsNoQuadro);
        $this->assertNotContains($neto->id, $idsNoQuadro);
    }

    public function test_filhos_do_quadro_de_desvios_sao_ordenados_naturalmente_por_codigo(): void
    {
        // Bug corrigido: ordenação SQL de string colocava "5.10" antes de
        // "5.3". Precisa da comparação natural segmento a segmento.
        $pai = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Tanques',
            'codigo' => '5',
        ]);
        $filho3 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $pai->id,
            'nome' => 'Tanque 3',
            'codigo' => '5.3',
        ]);
        $filho10 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $pai->id,
            'nome' => 'Tanque 10',
            'codigo' => '5.10',
        ]);
        $this->criarHh($filho3->id, SerieAvanco::Previsto, '2026-01-01', 10);
        $this->criarHh($filho10->id, SerieAvanco::Previsto, '2026-01-01', 10);

        $report = app(ReportGerador::class)->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $pai->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $ordemCodigos = $report->curvas->first()->desvios
            ->where('eh_nivel_pai', false)
            ->pluck('titulo_exibicao')
            ->values()
            ->all();

        $this->assertSame(['5.3 - Tanque 3', '5.10 - Tanque 10'], $ordemCodigos);
    }
}
