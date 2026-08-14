<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Models\Atividade;
use App\Models\CausaNaoCumprimento;
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
 * Fase 5, Etapa B — Causas do Desvio: associa, por linha do quadro de
 * desvios já existente (ReportDesvio), as atividades do mesmo escopo com
 * CausaNaoCumprimento registrada. Nunca recalcula ReportGerador/ReportDesvio,
 * nunca afirma causalidade — só associação factual, ainda sem snapshot
 * (leitura ao vivo, já corretamente escopada no tempo).
 */
class ReportCausasDesvioTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function usuarioComPapel(Papel $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, $papel->value);

        return $user;
    }

    private function criarImportacao(?Tenant $tenant = null, ?Work $obra = null): CronogramaImportacao
    {
        return CronogramaImportacao::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'importado_em' => now(),
        ]);
    }

    private function criarReport(CronogramaImportacao $importacao, string $dataReferencia): Report
    {
        return Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $importacao->id,
            'data_status' => $dataReferencia,
            'status' => StatusReport::Rascunho->value,
        ]);
    }

    private function criarPacote(?PacoteTrabalho $parent = null, ?Tenant $tenant = null, ?Work $obra = null): PacoteTrabalho
    {
        return PacoteTrabalho::factory()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'parent_id' => $parent?->id,
        ]);
    }

    private function criarAtividade(PacoteTrabalho $pacote, ?Tenant $tenant = null, ?Work $obra = null): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'pacote_trabalho_id' => $pacote->id,
        ]);
    }

    private function criarCausa(Atividade $atividade, string $descricao, ?string $registradaEm = null): CausaNaoCumprimento
    {
        $causa = CausaNaoCumprimento::factory()->create([
            'tenant_id' => $atividade->tenant_id,
            'atividade_id' => $atividade->id,
            'descricao' => $descricao,
        ]);

        if ($registradaEm !== null) {
            $causa->forceFill(['created_at' => $registradaEm])->save();
        }

        return $causa;
    }

    private function criarCurva(Report $report, ?PacoteTrabalho $pacote = null): ReportCurva
    {
        return ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacote?->id,
        ]);
    }

    private function criarDesvio(ReportCurva $curva, ?PacoteTrabalho $pacote, bool $ehNivelPai, string $titulo): \App\Models\ReportDesvio
    {
        return $curva->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $pacote?->id,
            'eh_nivel_pai' => $ehNivelPai,
            'titulo_exibicao' => $titulo,
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 40.0,
            'percentual_desvio' => -10.0,
            'percentual_impacto' => -10.0,
            'ordem' => $ehNivelPai ? 0 : 1,
        ]);
    }

    // =========================================================================
    // Escopo da linha "nível pai" via ReportCurva
    // =========================================================================

    public function test_linha_nivel_pai_de_curva_obra_inteira_usa_escopo_de_todos_os_pacotes(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15');

        $pacoteA = $this->criarPacote();
        $pacoteB = $this->criarPacote();
        $atividadeA = $this->criarAtividade($pacoteA);
        $atividadeB = $this->criarAtividade($pacoteB);
        $this->criarCausa($atividadeA, 'Falta de material', '2026-06-10');

        // Curva de obra inteira: pacote_trabalho_id = null.
        $curva = $this->criarCurva($report, null);
        // pacote_trabalho_id do desvio pai é o "virtual" (pode ser qualquer
        // pacote existente) — o escopo correto deve vir de $curva, não daqui.
        $desvioPai = $this->criarDesvio($curva, $pacoteA, true, 'Obra Inteira');

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $causas = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->causasDoDesvio;

        $linha = $causas[$desvioPai->id];
        $this->assertSame(2, $linha['total_atividades']);
        $this->assertSame(1, $linha['atividades_com_causa']);
        $this->assertSame(1, $linha['atividades_sem_causa']);
    }

    public function test_linha_nivel_pai_de_curva_de_pacote_especifico_usa_escopo_so_daquele_pacote(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15');

        $pacoteRaiz = $this->criarPacote();
        $pacoteForaDoEscopo = $this->criarPacote();
        $atividadeDentro = $this->criarAtividade($pacoteRaiz);
        $atividadeFora = $this->criarAtividade($pacoteForaDoEscopo);
        $this->criarCausa($atividadeDentro, 'Atraso de projeto executivo', '2026-06-10');
        $this->criarCausa($atividadeFora, 'Causa de outro pacote, fora do escopo desta curva', '2026-06-10');

        // Curva escopada a UM pacote específico (não obra inteira).
        $curva = $this->criarCurva($report, $pacoteRaiz);
        $desvioPai = $this->criarDesvio($curva, $pacoteRaiz, true, $pacoteRaiz->nome);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $causas = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->causasDoDesvio;

        $linha = $causas[$desvioPai->id];
        // Só a atividade do pacoteRaiz deve entrar — pacoteForaDoEscopo é
        // irmão, não descendente, então nunca deveria aparecer aqui.
        $this->assertSame(1, $linha['total_atividades']);
        $this->assertSame(1, $linha['atividades_com_causa']);
        $this->assertCount(1, $linha['causas']);
        $this->assertSame('Atraso de projeto executivo', $linha['causas'][0]['descricao']);
    }

    // =========================================================================
    // Escopo de linha filha via ReportDesvio + descendantIds()
    // =========================================================================

    public function test_linha_filha_usa_descendantids_e_alcanca_atividade_em_neto(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15');

        $pacoteRaiz = $this->criarPacote();
        $pacoteFilho = $this->criarPacote($pacoteRaiz);
        $pacoteNeto = $this->criarPacote($pacoteFilho);
        // Atividade vive no NETO, não diretamente no filho — testa que
        // descendantIds() alcança mais de um nível de profundidade.
        $atividadeNeto = $this->criarAtividade($pacoteNeto);
        $this->criarCausa($atividadeNeto, 'Chuva impediu a concretagem', '2026-06-10');

        $curva = $this->criarCurva($report, $pacoteRaiz);
        $this->criarDesvio($curva, $pacoteRaiz, true, $pacoteRaiz->nome);
        $desvioFilho = $this->criarDesvio($curva, $pacoteFilho, false, $pacoteFilho->nome);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $causas = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->causasDoDesvio;

        $linha = $causas[$desvioFilho->id];
        $this->assertSame(1, $linha['total_atividades']);
        $this->assertSame(1, $linha['atividades_com_causa']);
        $this->assertSame('Chuva impediu a concretagem', $linha['causas'][0]['descricao']);
    }

    public function test_nao_ha_dupla_contagem_entre_linha_pai_e_linha_filha(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15');

        $pacoteRaiz = $this->criarPacote();
        $pacoteFilho = $this->criarPacote($pacoteRaiz);
        $atividade = $this->criarAtividade($pacoteFilho);
        $this->criarCausa($atividade, 'Causa única', '2026-06-10');

        $curva = $this->criarCurva($report, $pacoteRaiz);
        $desvioPai = $this->criarDesvio($curva, $pacoteRaiz, true, $pacoteRaiz->nome);
        $desvioFilho = $this->criarDesvio($curva, $pacoteFilho, false, $pacoteFilho->nome);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $causas = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->causasDoDesvio;

        // A atividade pertence ao escopo das DUAS linhas (pai engloba
        // filho) — cada linha deve contar 1, nunca somadas entre si.
        $this->assertSame(1, $causas[$desvioPai->id]['atividades_com_causa']);
        $this->assertSame(1, $causas[$desvioFilho->id]['atividades_com_causa']);
    }

    // =========================================================================
    // Múltiplas causas na mesma atividade
    // =========================================================================

    public function test_atividade_com_multiplas_causas_conta_uma_vez_mas_preserva_todos_os_registros(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15');

        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarCausa($atividade, 'Primeira causa declarada', '2026-05-01');
        $this->criarCausa($atividade, 'Segunda causa declarada', '2026-05-15');
        $this->criarCausa($atividade, 'Terceira causa declarada', '2026-06-01');

        $curva = $this->criarCurva($report, $pacote);
        $desvio = $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $linha = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->causasDoDesvio[$desvio->id];

        $this->assertSame(1, $linha['total_atividades']);
        // Nunca infla: 1 atividade com causa, mesmo com 3 registros.
        $this->assertSame(1, $linha['atividades_com_causa']);
        $this->assertSame(0, $linha['atividades_sem_causa']);
        // Mas os 3 registros históricos continuam todos visíveis.
        $this->assertCount(3, $linha['causas']);
    }

    // =========================================================================
    // Atividade sem causa registrada
    // =========================================================================

    public function test_atividade_sem_causa_aparece_como_sem_causa_registrada_e_nunca_inventa_causa(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15');

        $pacote = $this->criarPacote();
        $this->criarAtividade($pacote); // sem nenhuma causa

        $curva = $this->criarCurva($report, $pacote);
        $desvio = $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report]);

        $linha = $component->instance()->causasDoDesvio[$desvio->id];
        $this->assertSame(1, $linha['total_atividades']);
        $this->assertSame(0, $linha['atividades_com_causa']);
        $this->assertSame(1, $linha['atividades_sem_causa']);
        $this->assertEmpty($linha['causas']);

        $component
            ->assertSee('1 sem causa registrada', false)
            ->assertSee('Nenhuma causa declarada associada a este pacote até o momento.');
    }

    // =========================================================================
    // Filtro temporal
    // =========================================================================

    public function test_causa_registrada_apos_a_data_de_referencia_do_report_nao_e_considerada(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15');

        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        // Registrada DEPOIS da data de referência do report — não deve
        // aparecer nem contar, como se ainda não existisse na fotografia.
        $this->criarCausa($atividade, 'Causa registrada depois da fotografia', '2026-07-01');

        $curva = $this->criarCurva($report, $pacote);
        $desvio = $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $linha = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->causasDoDesvio[$desvio->id];

        $this->assertSame(0, $linha['atividades_com_causa']);
        $this->assertSame(1, $linha['atividades_sem_causa']);
        $this->assertEmpty($linha['causas']);
    }

    public function test_causa_registrada_ate_a_data_de_referencia_e_considerada(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15');

        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        // Registrada exatamente NO dia da data de referência — deve contar.
        $this->criarCausa($atividade, 'Causa registrada no próprio dia da fotografia', '2026-06-15 23:00:00');

        $curva = $this->criarCurva($report, $pacote);
        $desvio = $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $linha = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->causasDoDesvio[$desvio->id];

        $this->assertSame(1, $linha['atividades_com_causa']);
    }

    // =========================================================================
    // Isolamento entre tenants
    // =========================================================================

    public function test_causa_de_outro_tenant_nao_vaza_para_o_report(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15');

        $pacote = $this->criarPacote();
        $this->criarAtividade($pacote); // sem causa própria neste tenant

        // Outro tenant, outra obra, com a MESMA "forma" de dado.
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroPacote = $this->criarPacote(null, $outroTenant, $outraObra);
        $outraAtividade = $this->criarAtividade($outroPacote, $outroTenant, $outraObra);
        $this->criarCausa($outraAtividade, 'Causa de outro tenant, nunca deve aparecer aqui', '2026-06-10');

        $curva = $this->criarCurva($report, $pacote);
        $desvio = $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $linha = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->instance()
            ->causasDoDesvio[$desvio->id];

        $this->assertSame(1, $linha['total_atividades']);
        $this->assertSame(0, $linha['atividades_com_causa']);
        $this->assertEmpty($linha['causas']);
    }

    // =========================================================================
    // Linguagem — nunca causal
    // =========================================================================

    public function test_tela_nunca_usa_linguagem_causal(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15');

        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $this->criarCausa($atividade, 'Falta de mão de obra', '2026-06-10');

        $curva = $this->criarCurva($report, $pacote);
        $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertSee('Causas Associadas ao Desvio')
            ->assertSee('evidência encontrada')
            ->assertSee('causa declarada')
            ->assertDontSee('causado por')
            ->assertDontSee('devido a')
            ->assertDontSee('consequência de')
            ->assertDontSee('causa raiz');
    }

    // =========================================================================
    // Regressão da Etapa A (mesmo componente)
    // =========================================================================

    public function test_confiabilidade_do_cronograma_da_etapa_a_continua_funcionando(): void
    {
        $importacao = $this->criarImportacao();
        $report = $this->criarReport($importacao, '2026-06-15');
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $confiabilidade = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->confiabilidadeCronograma;

        // Importação sem Health Check nesta suíte — estado esperado
        // continua "indisponivel", como já testado na Etapa A.
        $this->assertSame('indisponivel', $confiabilidade['estado']);
    }
}
