<?php

namespace Tests\Feature;

use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\ResultadoReconciliacaoPlanoAcao;
use App\Enums\StatusPlanoAcao;
use App\Enums\TipoCronogramaImportacao;
use App\Jobs\ImportarCronogramaJob;
use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\CronogramaImportacaoHealthCheck;
use App\Models\PlanoAcao;
use App\Models\PlanoAcaoReconciliacao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckResultado;
use App\Support\HealthCheck\PlanoAcao\PlanoAcaoReconciliador;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 4.2 — integração REAL do PlanoAcaoReconciliador com
 * ImportarCronogramaJob (Parte 1 do pedido). Dispara o Job de verdade via
 * `dispatchSync()` (mesmo handle(), mesma transação, mesmos parâmetros que
 * o Job já usa em produção) — nunca chama o reconciliador isoladamente
 * aqui (isso já é feito exaustivamente em PlanoAcaoReconciliadorTest, Fase
 * 4.1). Os findings usam UIDs REAIS do fixture (cronograma_sample.xml tem
 * atividades UID 2 e 3), mas o healthCheckSerializado é montado à mão —
 * o Job nunca valida que o Health Check "bate" com o que o parser
 * encontrou de verdade (mesma arquitetura já usada por TODOS os testes de
 * Score/Health Check desde a Fase 1: o Job só reidrata o que recebe).
 */
class PlanoAcaoReconciliacaoIntegracaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
    }

    private function copiarFixtureParaArquivoTemp(): string
    {
        $path = 'cronograma-temp/' . Str::random(12) . '.xml';
        Storage::disk('local')->put($path, file_get_contents(__DIR__ . '/../Fixtures/cronograma_sample.xml'));

        return storage_path('app/' . $path);
    }

    private function finding(string $regraId, array $uids): HealthCheckFinding
    {
        return new HealthCheckFinding(
            regraId: $regraId,
            categoria: HealthCheckCategoria::Avanco,
            severidade: HealthCheckSeveridade::Alto,
            titulo: "Finding {$regraId}",
            descricao: 'descrição de teste',
            impacto: 'impacto de teste',
            recomendacao: 'recomendação de teste',
            atividades: array_map(fn ($uid) => ['uid' => $uid], $uids),
        );
    }

    private function dispararImportacao(array $findings, TipoCronogramaImportacao $tipo = TipoCronogramaImportacao::Baseline): void
    {
        ImportarCronogramaJob::dispatchSync(
            $this->obra,
            $this->copiarFixtureParaArquivoTemp(),
            $this->user->id,
            $tipo,
            null,
            (new HealthCheckResultado($findings))->toArray(),
        );
    }

    // =====================================================================
    // RECONCILIAÇÃO EXECUTA (Baseline e Avanço — Fase 4.2, decisão do usuário:
    // ambos os tipos, diferente da Evolução do Score)
    // =====================================================================

    public function test_reconciliacao_executa_em_importacao_baseline_quando_ha_acoes_abertas(): void
    {
        // Ciclo 3: numa Baseline, só ações de regra Planejamento continuam
        // sendo reconciliadas — por isso STRUCT-005 aqui, não PROG-001
        // (Execução, ver test_baseline_via_job_real_nao_reconcilia_acao_de_regra_execucao abaixo).
        $primeira = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'importado_em' => now()->subDay()]);
        $acao = PlanoAcao::criarDeFinding($this->finding('STRUCT-005', ['2']), $primeira, $this->obra->id);

        $this->dispararImportacao([$this->finding('STRUCT-005', ['2'])], TipoCronogramaImportacao::Baseline);

        $this->assertSame(1, PlanoAcaoReconciliacao::count());
        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status);
    }

    public function test_baseline_via_job_real_nao_reconcilia_acao_de_regra_execucao(): void
    {
        // Ciclo 3 — via Job real (não isolado): PROG-001 é Execução, o
        // Health Check de uma Baseline nunca a avalia (Ciclo 2), então a
        // ação correspondente precisa ficar intocada mesmo passando pelo
        // pipeline completo de ImportarCronogramaJob.
        $primeira = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'importado_em' => now()->subDay()]);
        $acao = PlanoAcao::criarDeFinding($this->finding('PROG-001', ['2']), $primeira, $this->obra->id);

        $this->dispararImportacao([], TipoCronogramaImportacao::Baseline);

        $this->assertSame(0, PlanoAcaoReconciliacao::count());
        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status);
        $this->assertNull($acao->resolvida_em);
    }

    public function test_reconciliacao_executa_em_importacao_avanco_tambem(): void
    {
        $primeira = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'importado_em' => now()->subDay()]);
        $acao = PlanoAcao::criarDeFinding($this->finding('PROG-001', ['2']), $primeira, $this->obra->id);

        $this->dispararImportacao([$this->finding('PROG-001', ['2'])], TipoCronogramaImportacao::Avanco);

        $this->assertSame(1, PlanoAcaoReconciliacao::count());
        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status);
    }

    public function test_sem_acoes_abertas_reconciliador_nao_e_chamado(): void
    {
        // Nenhuma ação criada — o Job não deve nem tentar reconciliar.
        $this->dispararImportacao([$this->finding('PROG-001', ['2'])]);

        $this->assertSame(0, PlanoAcaoReconciliacao::count());
    }

    // =====================================================================
    // OS 4 RESULTADOS, VIA JOB REAL
    // =====================================================================

    public function test_resultado_persistente_via_job_real(): void
    {
        $primeira = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'importado_em' => now()->subDay()]);
        $acao = PlanoAcao::criarDeFinding($this->finding('PROG-001', ['2']), $primeira, $this->obra->id);

        // PROG-001 é Execução — só avaliada/reconciliada em Avanço/Ambos (Ciclo 3).
        $this->dispararImportacao([$this->finding('PROG-001', ['2'])], TipoCronogramaImportacao::Avanco);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Persistente, PlanoAcaoReconciliacao::first()->resultado);
        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status);
    }

    public function test_resultado_agravado_via_job_real(): void
    {
        $primeira = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'importado_em' => now()->subDay()]);
        // Ação nasceu cobrindo só a atividade '2' (UID real do fixture).
        $acao = PlanoAcao::criarDeFinding($this->finding('PROG-001', ['2']), $primeira, $this->obra->id);

        // Nova importação: o mesmo problema, mas agora também afeta '3' —
        // nada saiu, só cresceu. PROG-001 é Execução (Ciclo 3).
        $this->dispararImportacao([$this->finding('PROG-001', ['2', '3'])], TipoCronogramaImportacao::Avanco);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Agravado, PlanoAcaoReconciliacao::first()->resultado);
        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status);
        $this->assertEqualsCanonicalizing(['2', '3'], $acao->uids_referencia);
    }

    public function test_resultado_alterado_via_job_real_nunca_resolve_sozinho(): void
    {
        $primeira = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'importado_em' => now()->subDay()]);
        // '9' é um uid artificial que nunca existirá na nova importação —
        // simula parte do problema original tendo "saído".
        $acao = PlanoAcao::create([
            'obra_id' => $this->obra->id,
            'cronograma_importacao_origem_id' => $primeira->id,
            'regra_id' => 'PROG-001',
            'titulo' => 'teste',
            'recomendacao' => 'teste',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => ['2', '9'],
        ]);

        // Nova importação: '2' continua, '9' sumiu, '3' é novo — sobreposição
        // parcial. PROG-001 é Execução (Ciclo 3).
        $this->dispararImportacao([$this->finding('PROG-001', ['2', '3'])], TipoCronogramaImportacao::Avanco);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Alterado, PlanoAcaoReconciliacao::first()->resultado);
        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status, 'Alterado nunca resolve sozinho — exige revisão humana');
    }

    public function test_resultado_resolvido_via_job_real_regra_nunca_mais_aparece(): void
    {
        $primeira = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'importado_em' => now()->subDay()]);
        $acao = PlanoAcao::criarDeFinding($this->finding('ZZZ-999', ['2']), $primeira, $this->obra->id);

        // Nova importação não tem nenhum finding ZZZ-999.
        $this->dispararImportacao([$this->finding('PROG-001', ['2'])]);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Resolvido, PlanoAcaoReconciliacao::first()->resultado);
        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Resolvida, $acao->status);
        $this->assertNotNull(PlanoAcao::find($acao->id), 'nunca apaga a ação automaticamente');
    }

    // =====================================================================
    // ISOLAMENTO
    // =====================================================================

    public function test_outra_obra_do_mesmo_tenant_nao_e_afetada(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraImportacao = CronogramaImportacao::create(['obra_id' => $outraObra->id, 'importado_em' => now()->subDay()]);
        $acaoOutraObra = PlanoAcao::criarDeFinding($this->finding('PROG-001', ['2']), $outraImportacao, $outraObra->id);

        $this->dispararImportacao([$this->finding('PROG-001', ['2'])]);

        $this->assertSame(0, PlanoAcaoReconciliacao::count());
        $acaoOutraObra->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acaoOutraObra->status);
    }

    public function test_outro_tenant_nao_e_afetado(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        $acaoOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outraObra) {
            $importacao = CronogramaImportacao::create(['obra_id' => $outraObra->id, 'importado_em' => now()->subDay()]);

            return PlanoAcao::criarDeFinding($this->finding('PROG-001', ['2']), $importacao, $outraObra->id);
        });

        $this->dispararImportacao([$this->finding('PROG-001', ['2'])]);

        $this->assertSame(0, PlanoAcaoReconciliacao::count());
        $this->assertSame(StatusPlanoAcao::Aberta, TenantContext::actingAs($outroTenant, fn () => $acaoOutroTenant->fresh())->status);
    }

    // =====================================================================
    // COMPATIBILIDADE
    // =====================================================================

    public function test_importacao_sem_health_check_nao_quebra_nem_reconcilia(): void
    {
        $primeira = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'importado_em' => now()->subDay()]);
        $acao = PlanoAcao::criarDeFinding($this->finding('PROG-001', ['2']), $primeira, $this->obra->id);

        ImportarCronogramaJob::dispatchSync(
            $this->obra,
            $this->copiarFixtureParaArquivoTemp(),
            $this->user->id,
            TipoCronogramaImportacao::Baseline,
            null,
            null, // healthCheckSerializado = null (mesmo caminho já existente pra importações sem Health Check)
        );

        $this->assertSame(0, CronogramaImportacaoHealthCheck::count());
        $this->assertSame(0, PlanoAcaoReconciliacao::count());
        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status);
    }

    // =====================================================================
    // ROLLBACK
    // =====================================================================

    public function test_falha_na_reconciliacao_desfaz_a_importacao_inteira(): void
    {
        $primeira = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'importado_em' => now()->subDay()]);
        PlanoAcao::criarDeFinding($this->finding('PROG-001', ['2']), $primeira, $this->obra->id);

        $antesImportacoes = CronogramaImportacao::count();
        $antesAtividades = Atividade::count();

        $this->mock(PlanoAcaoReconciliador::class, function ($mock) {
            $mock->shouldReceive('reconciliar')->andThrow(new \RuntimeException('Falha simulada na reconciliação'));
        });

        try {
            $this->dispararImportacao([$this->finding('PROG-001', ['2'])]);
            $this->fail('Esperava que a exceção da reconciliação propagasse.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Falha simulada na reconciliação', $e->getMessage());
        }

        $this->assertSame($antesImportacoes, CronogramaImportacao::count(), 'importação não pode ter sido persistida — rollback completo');
        $this->assertSame($antesAtividades, Atividade::count(), 'nenhuma atividade pode ter sido criada — rollback completo');
        $this->assertSame(0, CronogramaImportacaoHealthCheck::count(), 'Health Check não pode ter sido persistido — rollback completo');
    }

    // =====================================================================
    // IMUTABILIDADE
    // =====================================================================

    public function test_health_check_e_score_da_primeira_importacao_permanecem_intactos(): void
    {
        // Primeira importação — grava seu próprio Health Check/Score reais.
        $this->dispararImportacao([$this->finding('WORK-004', ['3'])]);
        $healthCheckAntigo = CronogramaImportacaoHealthCheck::first();
        $findingsAntes = $healthCheckAntigo->findings;
        $scoreAntes = $healthCheckAntigo->score;

        // Uma ação criada a partir da importação antiga.
        PlanoAcao::criarDeFinding($this->finding('WORK-004', ['3']), $healthCheckAntigo->cronogramaImportacao, $this->obra->id);

        // Segunda importação — reconcilia a ação, mas nunca deve tocar o snapshot da PRIMEIRA.
        $this->dispararImportacao([$this->finding('WORK-004', ['3'])]);

        $healthCheckAntigoDepois = $healthCheckAntigo->fresh();
        $this->assertSame($findingsAntes, $healthCheckAntigoDepois->findings);
        $this->assertSame($scoreAntes, $healthCheckAntigoDepois->score);
    }
}
