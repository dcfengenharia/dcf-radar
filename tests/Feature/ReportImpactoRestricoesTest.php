<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportDesvio;
use App\Models\ReportDesvioRestricao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\ImpactoRestricoesGerador;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 5, Etapa C2 — Impacto de Restrições: snapshot 1:1 por ReportDesvio,
 * gerado por App\Services\ImpactoRestricoesGerador (Opção B — chamado
 * FORA de ReportGerador, nos 2 call sites de gerarRascunho()). Vínculo
 * exclusivo Restricao -> Atividade -> PacoteTrabalho -> ReportDesvio,
 * MESMO algoritmo de escopo já aprovado em causasDoDesvio() (Etapa B).
 * Nunca lê Restricao ao vivo na tela — sempre o snapshot já persistido.
 */
class ReportImpactoRestricoesTest extends TestCase
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
            'data_status' => '2026-06-15',
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

    private function criarCurva(Report $report, ?PacoteTrabalho $pacote = null): ReportCurva
    {
        return ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacote?->id,
        ]);
    }

    private function criarDesvio(ReportCurva $curva, ?PacoteTrabalho $pacote, bool $ehNivelPai, string $titulo): ReportDesvio
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

    private function criarRestricao(Atividade $atividade, array $overrides = []): Restricao
    {
        return Restricao::factory()->create(array_merge([
            'tenant_id' => $atividade->tenant_id,
            'atividade_id' => $atividade->id,
        ], $overrides));
    }

    /**
     * Roda o gerador dentro de TenantContext::actingAs() — mesma condição
     * de contexto que os 2 call sites reais garantem (usuário autenticado
     * na tela do assistente, ou o próprio actingAs() do comando
     * automático), necessária pra App\Models\Concerns\BelongsToTenant
     * carimbar tenant_id em ReportDesvioRestricao::create().
     */
    private function gerarImpacto(Report $report): void
    {
        TenantContext::actingAs($this->tenant, function () use ($report) {
            app(ImpactoRestricoesGerador::class)->gerar($report->fresh(['curvas.desvios', 'curvas.pacoteTrabalho']));
        });
    }

    // =========================================================================
    // 1. Cada ReportDesvio recebe exatamente um snapshot
    // =========================================================================

    public function test_cada_report_desvio_recebe_exatamente_um_snapshot(): void
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $this->criarAtividade($pacote);

        $curva = $this->criarCurva($report, $pacote);
        $desvioPai = $this->criarDesvio($curva, $pacote, true, $pacote->nome);
        $desvioFilho = $this->criarDesvio($curva, $pacote, false, 'Filho');

        $this->gerarImpacto($report);

        $this->assertSame(2, ReportDesvioRestricao::count());
        $this->assertNotNull(ReportDesvioRestricao::where('report_desvio_id', $desvioPai->id)->first());
        $this->assertNotNull(ReportDesvioRestricao::where('report_desvio_id', $desvioFilho->id)->first());
    }

    // =========================================================================
    // 2/3/4. Escopo — nível pai (curva inteira/obra inteira), linha filha,
    // e descendentes/netos alcançados (mesmo algoritmo de causasDoDesvio())
    // =========================================================================

    public function test_escopo_correto_do_nivel_pai_usa_escopo_de_todos_os_pacotes_quando_obra_inteira(): void
    {
        $report = $this->criarReport($this->criarImportacao());

        $pacoteA = $this->criarPacote();
        $pacoteB = $this->criarPacote();
        $atividadeA = $this->criarAtividade($pacoteA);
        $atividadeB = $this->criarAtividade($pacoteB);
        $this->criarRestricao($atividadeA, ['descricao' => 'Restrição no pacote A']);
        $this->criarRestricao($atividadeB, ['descricao' => 'Restrição no pacote B']);

        // Curva de obra inteira: pacote_trabalho_id = null.
        $curva = $this->criarCurva($report, null);
        $desvioPai = $this->criarDesvio($curva, $pacoteA, true, 'Obra Inteira');

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvioPai->id)->firstOrFail();
        $this->assertSame(2, $snapshot->total_abertas);
    }

    public function test_escopo_correto_da_linha_filha_usa_pacote_proprio_e_ignora_irmao(): void
    {
        $report = $this->criarReport($this->criarImportacao());

        $pacoteRaiz = $this->criarPacote();
        $pacoteForaDoEscopo = $this->criarPacote();
        $atividadeDentro = $this->criarAtividade($pacoteRaiz);
        $atividadeFora = $this->criarAtividade($pacoteForaDoEscopo);
        $this->criarRestricao($atividadeDentro, ['descricao' => 'Dentro do escopo']);
        $this->criarRestricao($atividadeFora, ['descricao' => 'Fora do escopo, nunca deve aparecer']);

        $curva = $this->criarCurva($report, $pacoteRaiz);
        $desvioPai = $this->criarDesvio($curva, $pacoteRaiz, true, $pacoteRaiz->nome);

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvioPai->id)->firstOrFail();
        $this->assertSame(1, $snapshot->total_abertas);
        $this->assertSame('Dentro do escopo', $snapshot->detalhes[0]['descricao']);
    }

    public function test_descendentes_em_neto_sao_alcancados(): void
    {
        $report = $this->criarReport($this->criarImportacao());

        $pacoteRaiz = $this->criarPacote();
        $pacoteFilho = $this->criarPacote($pacoteRaiz);
        $pacoteNeto = $this->criarPacote($pacoteFilho);
        // Atividade vive no NETO, não diretamente no filho.
        $atividadeNeto = $this->criarAtividade($pacoteNeto);
        $this->criarRestricao($atividadeNeto, ['descricao' => 'Restrição no neto']);

        $curva = $this->criarCurva($report, $pacoteRaiz);
        $this->criarDesvio($curva, $pacoteRaiz, true, $pacoteRaiz->nome);
        $desvioFilho = $this->criarDesvio($curva, $pacoteFilho, false, $pacoteFilho->nome);

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvioFilho->id)->firstOrFail();
        $this->assertSame(1, $snapshot->total_abertas);
        $this->assertSame('Restrição no neto', $snapshot->detalhes[0]['descricao']);
    }

    // =========================================================================
    // 5/6/7. Restrição aberta / resolvida / reaberta
    // =========================================================================

    public function test_restricao_aberta_aparece_no_snapshot(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();
        $this->criarRestricao($atividade, ['status' => StatusRestricao::Aberta->value]);

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvio->id)->firstOrFail();
        $this->assertSame(1, $snapshot->total_abertas);
        $this->assertSame('Aberta', $snapshot->detalhes[0]['status']);
    }

    public function test_restricao_resolvida_antes_da_geracao_nao_aparece(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();
        $this->criarRestricao($atividade, [
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => now(),
        ]);

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvio->id)->firstOrFail();
        $this->assertSame(0, $snapshot->total_abertas);
        $this->assertEmpty($snapshot->detalhes);
    }

    public function test_restricao_reaberta_antes_da_geracao_aparece_com_status_atual_aberta(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();
        $restricao = $this->criarRestricao($atividade, ['status' => StatusRestricao::Aberta->value]);

        // Simula o ciclo real: resolve...
        $restricao->update(['status' => StatusRestricao::Resolvida->value, 'resolvida_em' => now()]);
        // ...e reabre (mesma operação de reabrirRestricao(), apaga resolvida_em).
        $restricao->update(['status' => StatusRestricao::Aberta->value, 'resolvida_em' => null]);

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvio->id)->firstOrFail();
        $this->assertSame(1, $snapshot->total_abertas);
        $this->assertSame('Aberta', $snapshot->detalhes[0]['status']);
    }

    // =========================================================================
    // 8/9/10. Contagem, ordenação e múltiplas restrições no mesmo pacote
    // =========================================================================

    public function test_contagem_de_abertas_vencidas_e_criticas(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();

        $this->criarRestricao($atividade, [
            'descricao' => 'Vencida',
            'probabilidade' => 1,
            'impacto' => 1, // 1 < 50 — explícito pra nunca herdar o P×I aleatório da factory e cruzar o limiar de crítica
            'prazo_limite' => now()->subDays(5)->toDateString(),
        ]);
        $this->criarRestricao($atividade, [
            'descricao' => 'Crítica',
            'probabilidade' => 8,
            'impacto' => 8, // 64 >= 50
            'prazo_limite' => now()->addDays(10)->toDateString(),
        ]);
        $this->criarRestricao($atividade, [
            'descricao' => 'Normal',
            'probabilidade' => 2,
            'impacto' => 2,
            'prazo_limite' => now()->addDays(10)->toDateString(),
        ]);

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvio->id)->firstOrFail();
        $this->assertSame(3, $snapshot->total_abertas);
        $this->assertSame(1, $snapshot->total_vencidas);
        $this->assertSame(1, $snapshot->total_criticas);
    }

    public function test_ordenacao_vencida_depois_risco_depois_prazo(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();

        $this->criarRestricao($atividade, [
            'descricao' => 'Crítica sem vencer',
            'probabilidade' => 9,
            'impacto' => 9,
            'prazo_limite' => now()->addDays(20)->toDateString(),
        ]);
        $this->criarRestricao($atividade, [
            'descricao' => 'Vencida',
            'probabilidade' => 1,
            'impacto' => 1,
            'prazo_limite' => now()->subDays(3)->toDateString(),
        ]);
        $this->criarRestricao($atividade, [
            'descricao' => 'Normal prazo mais próximo',
            'probabilidade' => 1,
            'impacto' => 1,
            'prazo_limite' => now()->addDays(2)->toDateString(),
        ]);

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvio->id)->firstOrFail();
        $descricoes = array_column($snapshot->detalhes, 'descricao');

        $this->assertSame([
            'Vencida',
            'Crítica sem vencer',
            'Normal prazo mais próximo',
        ], $descricoes);
    }

    public function test_multiplas_restricoes_no_mesmo_pacote_sao_todas_contadas(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();

        for ($i = 0; $i < 5; $i++) {
            $this->criarRestricao($atividade, ['descricao' => "Restrição {$i}"]);
        }

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvio->id)->firstOrFail();
        $this->assertSame(5, $snapshot->total_abertas);
        $this->assertCount(5, $snapshot->detalhes);
    }

    // =========================================================================
    // 11/12. Responsável interno e responsavel_externo como fallback
    // =========================================================================

    public function test_responsavel_interno_aparece_no_snapshot(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();
        $responsavel = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Maria',
            'last_name' => 'Silva',
        ]);
        $this->criarRestricao($atividade, ['responsavel_id' => $responsavel->id]);

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvio->id)->firstOrFail();
        $this->assertSame('Maria Silva', $snapshot->detalhes[0]['responsavel_nome']);
    }

    public function test_responsavel_externo_e_usado_como_fallback_sem_responsavel_interno(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();
        $this->criarRestricao($atividade, [
            'responsavel_id' => null,
            'responsavel_externo' => 'Fornecedor ACME',
        ]);

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvio->id)->firstOrFail();
        $this->assertSame('Fornecedor ACME', $snapshot->detalhes[0]['responsavel_nome']);
    }

    // =========================================================================
    // 13. P×I nulos nunca classificam como baixo risco
    // =========================================================================

    public function test_probabilidade_ou_impacto_nulos_nao_sao_classificados_como_baixo_risco(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();
        $this->criarRestricao($atividade, ['probabilidade' => null, 'impacto' => 5]);

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvio->id)->firstOrFail();
        $this->assertSame('nao_classificado', $snapshot->detalhes[0]['classificacao_risco']);
        $this->assertSame(0, $snapshot->total_criticas);
    }

    // =========================================================================
    // 14. Isolamento de tenant
    // =========================================================================

    public function test_restricao_de_outro_tenant_nunca_aparece(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();
        $this->criarRestricao($atividade, ['descricao' => 'Restrição própria']);

        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroPacote = $this->criarPacote(null, $outroTenant, $outraObra);
        $outraAtividade = $this->criarAtividade($outroPacote, $outroTenant, $outraObra);
        $this->criarRestricao($outraAtividade, ['descricao' => 'Restrição de outro tenant, nunca deve aparecer']);

        $this->gerarImpacto($report);

        $snapshot = ReportDesvioRestricao::where('report_desvio_id', $desvio->id)->firstOrFail();
        $this->assertSame(1, $snapshot->total_abertas);
        $this->assertSame('Restrição própria', $snapshot->detalhes[0]['descricao']);
    }

    // =========================================================================
    // 15. Report antigo sem snapshot — nunca inventa dado
    // =========================================================================

    public function test_report_antigo_sem_snapshot_nao_gera_dados_artificialmente(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();
        $this->criarRestricao($atividade); // existe restrição real...

        // ...mas o gerador NUNCA foi chamado pra este report (simula um
        // report criado antes desta etapa existir).
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report]);

        $this->assertNull($component->instance()->impactoRestricoes[$desvio->id]);
        $component->assertDontSee('Impacto de Restrições');
    }

    // =========================================================================
    // 16. Falha na geração não quebra o Report
    // =========================================================================

    public function test_falha_na_geracao_nao_quebra_o_report_ja_criado(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();
        $this->criarRestricao($atividade);

        $this->gerarImpacto($report);

        // Uma segunda chamada colide com a constraint unique(report_desvio_id)
        // — simula uma falha real de geração (mesmo comportamento que os 2
        // call sites protegem com try/catch).
        try {
            $this->gerarImpacto($report);
            $this->fail('Esperava uma exceção de violação de unique constraint.');
        } catch (\Throwable $e) {
            // Falha esperada — o importante é o que vem a seguir.
        }

        // O Report e seus desvios continuam intactos e consultáveis.
        $this->assertNotNull(Report::find($report->id));
        $this->assertNotNull(ReportDesvio::find($desvio->id));
        $this->assertSame(1, ReportDesvioRestricao::where('report_desvio_id', $desvio->id)->count());
    }

    // =========================================================================
    // 17/18. Regressão — Causas (Etapa B) e HH Exposta (C1/Consolidação)
    // =========================================================================

    public function test_causas_da_etapa_b_continuam_funcionando(): void
    {
        [$report] = $this->cenarioBasico();
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $causas = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->causasDoDesvio;

        $this->assertIsArray($causas);
    }

    public function test_hh_exposta_c1_continua_funcionando(): void
    {
        [$report] = $this->cenarioBasico();
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $hhExposta = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk()
            ->instance()
            ->hhExpostaPorAtraso;

        $this->assertIsArray($hhExposta);
    }

    // =========================================================================
    // 19. Nunca linguagem causal
    // =========================================================================

    public function test_interface_nunca_usa_linguagem_causal(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();
        $this->criarRestricao($atividade, ['descricao' => 'Falta de liberação de projeto']);

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertSee('Impacto de Restrições')
            ->assertSee('Falta de liberação de projeto')
            ->assertDontSee('causado por')
            ->assertDontSee('devido a')
            ->assertDontSee('restrição responsável pelo atraso')
            ->assertDontSee('consequência de')
            ->assertDontSee('causa raiz');
    }

    // =========================================================================
    // 20. Ausência de restrições não gera bloco vazio
    // =========================================================================

    public function test_ausencia_de_restricoes_nao_gera_bloco_vazio(): void
    {
        [$report, $desvio, $atividade] = $this->cenarioBasico();
        // Sem nenhuma restrição criada — snapshot gerado com total_abertas=0.

        $this->gerarImpacto($report);

        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertDontSee('Impacto de Restrições');
    }

    // =========================================================================
    // Helper de cenário básico compartilhado
    // =========================================================================

    /** @return array{0: Report, 1: ReportDesvio, 2: Atividade} */
    private function cenarioBasico(): array
    {
        $report = $this->criarReport($this->criarImportacao());
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade($pacote);
        $curva = $this->criarCurva($report, $pacote);
        $desvio = $this->criarDesvio($curva, $pacote, true, $pacote->nome);

        return [$report, $desvio, $atividade];
    }
}
