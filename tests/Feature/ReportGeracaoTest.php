<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
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

class ReportGeracaoTest extends TestCase
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

        // Fundações: baseline 80 (jan) + 20 (fev) = 100; realizado 60 (jan, único período <= data_status)
        $this->criarHh($this->filhoA->id, SerieAvanco::Previsto, '2026-01-01', 80);
        $this->criarHh($this->filhoA->id, SerieAvanco::Previsto, '2026-02-01', 20);
        $this->criarHh($this->filhoA->id, SerieAvanco::Realizado, '2026-01-01', 60);

        // Estrutura: baseline 200 (jan) + 100 (fev) = 300; realizado 100 (jan)
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
            'baseline_termino' => '2026-03-15',
            'data_termino' => '2026-03-20',
        ]);

        // Só Mensal de propósito: nenhum teste deste arquivo confere um
        // valor específico de percentual_previsto/percentual_real (só
        // igualdade antes/depois na fotografia, ou totais/contadores que
        // não dependem do corte semanal de hhAcumuladoAteData()) — e
        // test_serie_semanal_e_recortada_para_as_ultimas_quatro_semanas usa
        // datas de semana específicas que não podem ganhar entradas extras
        // (ver ReportDesvioCalculoTest para os testes que de fato exercitam
        // o corte semanal e por isso gravam as duas granularidades).
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => $serie->value,
            'periodo_inicio' => $periodoInicio,
            'horas' => $horas,
        ]);
    }

    private function gerador(): ReportGerador
    {
        return app(ReportGerador::class);
    }

    public function test_gera_rascunho_com_curva_e_datapoints_batendo_com_dados_ao_vivo(): void
    {
        $report = $this->gerador()->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $this->raiz->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $this->assertTrue($report->estaRascunho());
        $this->assertSame($this->usuario->id, $report->criado_por);
        $this->assertCount(1, $report->curvas);

        $curva = $report->curvas->first();
        $this->assertSame($this->raiz->id, $curva->pacote_trabalho_id);
        $this->assertEquals(400.0, (float) $curva->total_hh_previsto);

        $mensalPrevisto = $curva->datapoints
            ->where('granularidade', GranularidadePeriodo::Mensal)
            ->where('serie', SerieAvanco::Previsto)
            ->sortBy('periodo_inicio')
            ->values();

        $this->assertCount(2, $mensalPrevisto);
        $this->assertEquals(280.0, (float) $mensalPrevisto[0]['horas']);
        $this->assertEquals(120.0, (float) $mensalPrevisto[1]['horas']);
        $this->assertEquals(100.0, (float) $mensalPrevisto[1]['percentual_acumulado']);
    }

    public function test_percentual_acumulado_do_realizado_usa_o_total_previsto_como_denominador(): void
    {
        // Bug corrigido: %realizado NÃO pode ser dividido pelo total
        // realizado (senão sempre bateria ~100%, o que não faz sentido).
        // Raiz: total_hh_previsto = 400 (filhoA 100 + filhoB 300).
        // Realizado até jan: filhoA=60 + filhoB=100 = 160.
        // Se o bug existisse: 160/160*100 = 100%. Correto: 160/400*100 = 40%.
        $report = $this->gerador()->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $this->raiz->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $realizado = $report->curvas->first()->datapoints
            ->where('granularidade', GranularidadePeriodo::Mensal)
            ->where('serie', SerieAvanco::Realizado)
            ->sortBy('periodo_inicio')
            ->first();

        $this->assertEquals(160.0, (float) $realizado->horas);
        $this->assertEquals(40.0, (float) $realizado->percentual_acumulado);
    }

    public function test_contadores_de_atividade_sao_gravados_no_momento_da_geracao(): void
    {
        $isolado = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Isolado',
            'codigo' => '9',
        ]);

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $isolado->id,
            'status' => StatusAtividade::Concluido->value,
            'data_termino' => '2026-01-10',
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $isolado->id,
            'status' => StatusAtividade::EmExecucao->value,
            'data_termino' => '2026-01-05', // < data_status (2026-01-20) e não concluída = atrasada
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $isolado->id,
            'status' => StatusAtividade::Planejado->value,
            'data_termino' => '2026-03-01', // > data_status, não atrasada
        ]);

        $report = $this->gerador()->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $isolado->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $curva = $report->curvas->first();
        $this->assertSame(3, $curva->total_atividades);
        $this->assertSame(1, $curva->atividades_concluidas);
        $this->assertSame(1, $curva->atividades_atrasadas);
    }

    public function test_contadores_de_atividade_nao_mudam_apos_reimportacao(): void
    {
        $isolado = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Isolado',
            'codigo' => '9',
        ]);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $isolado->id,
            'status' => StatusAtividade::EmExecucao->value,
            'data_termino' => '2026-01-05',
        ]);

        $report = $this->gerador()->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $isolado->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $curva = $report->curvas->first();
        $this->assertSame(1, $curva->atividades_atrasadas);
        $this->assertSame(0, $curva->atividades_concluidas);

        // Simula a atividade sendo concluída depois — o report já gerado
        // não deve mudar (fotografia).
        $atividade->update(['status' => StatusAtividade::Concluido->value]);

        $curva->refresh();
        $this->assertSame(1, $curva->atividades_atrasadas);
        $this->assertSame(0, $curva->atividades_concluidas);
    }

    public function test_serie_semanal_e_recortada_para_as_ultimas_quatro_semanas(): void
    {
        // Seis semanas de dados semanais, todas <= data_status pra não interferir no corte.
        $semanas = [
            '2025-12-15', '2025-12-22', '2025-12-29',
            '2026-01-05', '2026-01-12', '2026-01-19',
        ];

        foreach ($semanas as $semana) {
            $atividade = Atividade::factory()->create([
                'tenant_id' => $this->tenant->id,
                'obra_id' => $this->obra->id,
                'pacote_trabalho_id' => $this->raiz->id,
            ]);

            AvancoPeriodo::create([
                'tenant_id' => $this->tenant->id,
                'cronograma_importacao_id' => $this->importacao->id,
                'atividade_id' => $atividade->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => SerieAvanco::Previsto->value,
                'periodo_inicio' => $semana,
                'horas' => 10,
            ]);
        }

        $report = $this->gerador()->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $this->raiz->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $semanalPrevisto = $report->curvas->first()->datapoints
            ->where('granularidade', GranularidadePeriodo::Semanal)
            ->where('serie', SerieAvanco::Previsto);

        $this->assertCount(4, $semanalPrevisto);
        $this->assertEqualsCanonicalizing(
            ['2025-12-29', '2026-01-05', '2026-01-12', '2026-01-19'],
            $semanalPrevisto->pluck('periodo_inicio')->map(fn ($d) => $d->toDateString())->all()
        );
    }

    public function test_termino_e_pacote_registram_snapshot_no_momento_da_geracao(): void
    {
        $report = $this->gerador()->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $this->raiz->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $curva = $report->curvas->first();
        $this->assertSame('2026-03-15', $curva->termino_linha_base->toDateString());
        $this->assertSame('2026-03-20', $curva->termino_tendencia->toDateString());
    }

    public function test_report_e_fotografia_nao_muda_apos_reimportacao(): void
    {
        $report = $this->gerador()->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $this->raiz->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $totalOriginal = (float) $report->curvas->first()->total_hh_previsto;
        $desvioPaiOriginal = (float) $report->curvas->first()->desvios->firstWhere('eh_nivel_pai', true)->percentual_desvio;

        // Simula uma reimportação que muda os HH ao vivo (ex: cronograma revisado).
        AvancoPeriodo::where('cronograma_importacao_id', $this->importacao->id)
            ->where('serie', SerieAvanco::Previsto->value)
            ->update(['horas' => 9999]);

        $report->refresh();
        $report->load('curvas.datapoints', 'curvas.desvios');

        $this->assertEquals($totalOriginal, (float) $report->curvas->first()->total_hh_previsto);
        $this->assertEquals(
            $desvioPaiOriginal,
            (float) $report->curvas->first()->desvios->firstWhere('eh_nivel_pai', true)->percentual_desvio
        );
    }

    public function test_curva_da_obra_inteira_usa_todos_os_pacotes_raiz(): void
    {
        $report = $this->gerador()->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => null, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        $curva = $report->curvas->first();
        $this->assertNull($curva->pacote_trabalho_id);
        $this->assertEquals(400.0, (float) $curva->total_hh_previsto);
        // nível pai (400 HH) + o único pacote raiz cadastrado (CIVIL, 400 HH)
        $this->assertCount(2, $curva->desvios);
    }

    public function test_pontos_de_atencao_sao_gravados_por_curva(): void
    {
        $report = $this->gerador()->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                [
                    'pacote_trabalho_id' => $this->raiz->id,
                    'ordem' => 0,
                    'pontos_atencao' => [
                        ['categoria' => 'SUPRIMENTOS', 'texto' => 'Atraso na entrega de aço.'],
                        ['categoria' => null, 'texto' => 'Chuva na semana.'],
                    ],
                ],
            ],
        ]);

        $pontos = $report->curvas->first()->pontosAtencao;
        $this->assertCount(2, $pontos);
        $this->assertSame('SUPRIMENTOS', $pontos[0]->categoria);
        $this->assertNull($pontos[1]->categoria);
    }

    public function test_emitir_muda_status_e_registra_autor_e_data(): void
    {
        $report = $this->gerador()->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [],
        ]);

        $emissor = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->gerador()->emitir($report, $emissor);

        $report->refresh();
        $this->assertTrue($report->estaEmitido());
        $this->assertSame($emissor->id, $report->emitido_por);
        $this->assertNotNull($report->emitido_em);
    }
}
