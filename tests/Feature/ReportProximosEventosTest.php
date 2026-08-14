<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Models\Atividade;
use App\Models\AtividadeSnapshot;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 5, Etapa F do roadmap original ("O que vem pela frente?") —
 * proximosEventosRelevantes() lê App\Models\AtividadeSnapshot ancorado em
 * Report->cronograma_importacao_id (fotografia histórica), nunca
 * Restricao/ReportDesvio/ProgramacaoSemanal. Lookahead Executivo, nunca
 * um segundo Plano Semanal.
 */
class ReportProximosEventosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    private Carbon $periodoReferencia;
    private Carbon $inicioJanela;
    private Carbon $fimJanela;
    private Carbon $dentroDaJanela;
    private Carbon $foraDaJanela;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->periodoReferencia = Carbon::today()->startOfWeek();
        $this->inicioJanela = $this->periodoReferencia->copy()->addWeek();
        $this->fimJanela = $this->inicioJanela->copy()->endOfWeek();
        $this->dentroDaJanela = $this->inicioJanela->copy()->addDays(2);
        $this->foraDaJanela = $this->fimJanela->copy()->addDays(30);
    }

    private function usuarioComPapel(Papel $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, $papel->value);

        return $user;
    }

    private function criarImportacao(): CronogramaImportacao
    {
        return CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);
    }

    private function criarReport(CronogramaImportacao $importacao): Report
    {
        return Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $importacao->id,
            'periodo_referencia' => $this->periodoReferencia,
            'data_status' => '2026-06-15',
            'status' => StatusReport::Rascunho->value,
        ]);
    }

    private function criarPacote(): PacoteTrabalho
    {
        return PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
    }

    private function criarCurva(Report $report, ?PacoteTrabalho $pacote = null): ReportCurva
    {
        return ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacote?->id,
        ]);
    }

    private function criarAtividade(array $overrides = []): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'is_marco' => false,
            'caminho_critico' => false,
            'fora_do_cronograma' => false,
        ], $overrides));
    }

    private function criarSnapshot(Atividade $atividade, CronogramaImportacao $importacao, array $overrides = []): AtividadeSnapshot
    {
        return AtividadeSnapshot::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'inicio_planejado' => null,
            'data_termino' => null,
            'baseline_inicio' => null,
            'baseline_termino' => null,
        ], $overrides));
    }

    // =========================================================================
    // 1. Atividade com início dentro da janela entra
    // =========================================================================

    public function test_atividade_com_inicio_dentro_da_janela_entra(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $atividade = $this->criarAtividade(['nome' => 'Fundação Bloco A']);
        $this->criarSnapshot($atividade, $importacao, [
            'inicio_planejado' => $this->dentroDaJanela->toDateString(),
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        $this->assertCount(1, $eventos);
        $this->assertSame('inicio', $eventos[0]['tipo']);
        $this->assertStringContainsString('Fundação Bloco A', $eventos[0]['titulo']);
    }

    // =========================================================================
    // 2. Atividade com término dentro da janela entra
    // =========================================================================

    public function test_atividade_com_termino_dentro_da_janela_entra(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $atividade = $this->criarAtividade(['nome' => 'Instalações Elétricas']);
        $this->criarSnapshot($atividade, $importacao, [
            'data_termino' => $this->dentroDaJanela->toDateString(),
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        $this->assertCount(1, $eventos);
        $this->assertSame('termino', $eventos[0]['tipo']);
    }

    // =========================================================================
    // 3. Marco dentro da janela entra
    // =========================================================================

    public function test_marco_dentro_da_janela_entra(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $atividade = $this->criarAtividade(['nome' => 'Entrega Fundação', 'is_marco' => true]);
        $this->criarSnapshot($atividade, $importacao, [
            'inicio_planejado' => $this->dentroDaJanela->toDateString(),
            'data_termino' => $this->dentroDaJanela->toDateString(),
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        // Marco gera só 1 evento (nunca também início/término separados).
        $this->assertCount(1, $eventos);
        $this->assertSame('marco', $eventos[0]['tipo']);
    }

    // =========================================================================
    // 4. Evento fora da janela não entra
    // =========================================================================

    public function test_evento_fora_da_janela_nao_entra(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $atividade = $this->criarAtividade();
        $this->criarSnapshot($atividade, $importacao, [
            'inicio_planejado' => $this->foraDaJanela->toDateString(),
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        $this->assertSame([], $eventos);
    }

    // =========================================================================
    // 5. Início exatamente no limite inicial entra
    // =========================================================================

    public function test_inicio_exatamente_no_limite_inicial_entra(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $atividade = $this->criarAtividade();
        $this->criarSnapshot($atividade, $importacao, [
            'inicio_planejado' => $this->inicioJanela->toDateString(),
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        $this->assertCount(1, $eventos);
    }

    // =========================================================================
    // 6. Evento exatamente no limite final entra
    // =========================================================================

    public function test_evento_exatamente_no_limite_final_entra(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $atividade = $this->criarAtividade();
        $this->criarSnapshot($atividade, $importacao, [
            'data_termino' => $this->fimJanela->toDateString(),
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        $this->assertCount(1, $eventos);
    }

    // =========================================================================
    // 7. Ordenação por data mais próxima
    // =========================================================================

    public function test_ordenacao_por_data_mais_proxima(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);

        $atividadeTarde = $this->criarAtividade(['nome' => 'Evento mais tarde']);
        $this->criarSnapshot($atividadeTarde, $importacao, [
            'inicio_planejado' => $this->fimJanela->toDateString(),
        ]);

        $atividadeCedo = $this->criarAtividade(['nome' => 'Evento mais cedo']);
        $this->criarSnapshot($atividadeCedo, $importacao, [
            'inicio_planejado' => $this->inicioJanela->toDateString(),
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        $this->assertSame([
            'Evento mais cedo',
            'Evento mais tarde',
        ], array_map(fn ($e) => explode(' - ', $e['titulo'])[1] ?? $e['titulo'], $eventos));
    }

    // =========================================================================
    // 8. Limite máximo de 5 eventos
    // =========================================================================

    public function test_limite_maximo_de_5_eventos(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);

        for ($i = 1; $i <= 7; $i++) {
            $atividade = $this->criarAtividade(['nome' => "Atividade {$i}"]);
            $this->criarSnapshot($atividade, $importacao, [
                'inicio_planejado' => $this->inicioJanela->copy()->addHours($i)->toDateString(),
            ]);
        }

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        $this->assertCount(5, $eventos);
    }

    // =========================================================================
    // 9. Múltiplas curvas/pacotes não afetam o resultado
    // =========================================================================

    public function test_multiplas_curvas_pacotes_nao_afetam_resultado(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);

        $pacoteA = $this->criarPacote();
        $this->criarCurva($report, $pacoteA);
        $pacoteB = $this->criarPacote();
        $this->criarCurva($report, $pacoteB);

        $atividade = $this->criarAtividade(['nome' => 'Atividade única']);
        $this->criarSnapshot($atividade, $importacao, [
            'inicio_planejado' => $this->dentroDaJanela->toDateString(),
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        $this->assertCount(1, $eventos);
    }

    // =========================================================================
    // 10. Report histórico usa o AtividadeSnapshot da importação correta
    // =========================================================================

    public function test_report_usa_snapshot_da_importacao_correta(): void
    {
        $importacao1 = $this->criarImportacao();
        $importacao2 = $this->criarImportacao();
        $report = $this->criarReport($importacao1);

        $atividade = $this->criarAtividade(['nome' => 'Atividade compartilhada']);

        $this->criarSnapshot($atividade, $importacao1, [
            'inicio_planejado' => $this->dentroDaJanela->toDateString(),
        ]);
        // Snapshot de OUTRA importação, mesma atividade, também dentro da
        // janela — nunca deve ser lido por este Report.
        $this->criarSnapshot($atividade, $importacao2, [
            'inicio_planejado' => $this->inicioJanela->toDateString(),
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        // Só 1 evento (o da importacao1) — nunca 2, mesmo a atividade tendo
        // snapshot em ambas as importações dentro da janela.
        $this->assertCount(1, $eventos);
        $this->assertSame($this->dentroDaJanela->toDateString(), $eventos[0]['data']);
    }

    // =========================================================================
    // 11. Alteração posterior da atividade não altera as datas históricas
    // =========================================================================

    public function test_alteracao_posterior_da_atividade_nao_altera_datas_historicas(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $atividade = $this->criarAtividade(['nome' => 'Atividade reimportada']);
        $this->criarSnapshot($atividade, $importacao, [
            'inicio_planejado' => $this->dentroDaJanela->toDateString(),
        ]);

        // Simula uma reimportação posterior que muda a data AO VIVO da
        // atividade pra bem fora da janela — o snapshot já gravado nunca
        // deve refletir essa mudança.
        $atividade->forceFill(['inicio_planejado' => $this->foraDaJanela])->saveQuietly();

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        $this->assertCount(1, $eventos);
        $this->assertSame($this->dentroDaJanela->toDateString(), $eventos[0]['data']);
    }

    // =========================================================================
    // 12. Ausência de snapshot não quebra o Report
    // =========================================================================

    public function test_ausencia_de_snapshot_nao_quebra_report(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $this->criarAtividade(); // sem nenhum snapshot

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $this->assertSame([], $component->instance()->proximosEventosRelevantes);
    }

    // =========================================================================
    // 13. Ausência de eventos não renderiza o bloco
    // =========================================================================

    public function test_ausencia_de_eventos_nao_renderiza_bloco(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $this->assertSame([], $component->instance()->proximosEventosRelevantes);
        $component->assertDontSee('Próximos Eventos Relevantes');
    }

    // =========================================================================
    // 14. Isolamento de tenant
    // =========================================================================

    public function test_isolamento_de_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraImportacao = CronogramaImportacao::create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
            'importado_em' => now(),
        ]);
        $outraAtividade = Atividade::factory()->create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
            'nome' => 'De outro tenant',
            'is_marco' => false,
            'caminho_critico' => false,
            'fora_do_cronograma' => false,
        ]);
        AtividadeSnapshot::create([
            'tenant_id' => $outroTenant->id,
            'cronograma_importacao_id' => $outraImportacao->id,
            'atividade_id' => $outraAtividade->id,
            'inicio_planejado' => $this->dentroDaJanela->toDateString(),
        ]);

        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $atividade = $this->criarAtividade(['nome' => 'Do meu tenant']);
        $this->criarSnapshot($atividade, $importacao, [
            'inicio_planejado' => $this->dentroDaJanela->toDateString(),
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $eventos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->proximosEventosRelevantes;

        $this->assertCount(1, $eventos);
        $this->assertStringContainsString('Do meu tenant', $eventos[0]['titulo']);
    }

    // =========================================================================
    // 15. Regressão dos principais computeds existentes
    // =========================================================================

    public function test_principais_desvios_continua_funcionando(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $pacote = $this->criarPacote();
        $curva = $this->criarCurva($report, $pacote);
        $curva->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $pacote->id,
            'eh_nivel_pai' => false,
            'titulo_exibicao' => 'Pacote regressão',
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 30.0,
            'percentual_desvio' => -20.0,
            'percentual_impacto' => -20.0,
            'ordem' => 0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $principaisDesvios = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->principaisDesvios;

        $this->assertCount(1, $principaisDesvios);
    }

    public function test_top_riscos_continua_funcionando(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $topRiscos = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->topRiscos;

        $this->assertIsArray($topRiscos);
    }

    public function test_decisoes_prioritarias_continua_funcionando(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $decisoes = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->decisoesPrioritarias;

        $this->assertIsArray($decisoes);
    }

    public function test_impacto_restricoes_continua_funcionando(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $impacto = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->impactoRestricoes;

        $this->assertIsArray($impacto);
    }

    public function test_resumo_executivo_continua_funcionando(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao);
        $pacote = $this->criarPacote();
        $curva = $this->criarCurva($report, $pacote);
        $curva->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $pacote->id,
            'eh_nivel_pai' => true,
            'titulo_exibicao' => 'Pacote raiz',
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 50.0,
            'percentual_desvio' => 0.0,
            'percentual_impacto' => 0.0,
            'ordem' => 0,
        ]);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $resumo = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->resumoExecutivo;

        $this->assertTrue($resumo['tem_dado']);
    }
}
