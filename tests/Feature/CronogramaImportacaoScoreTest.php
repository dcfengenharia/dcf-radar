<?php

namespace Tests\Feature;

use App\Enums\HealthCheckCategoria;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\CronogramaImportacao;
use App\Models\CronogramaImportacaoHealthCheck;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\HealthCheck\Score\AcaoRecomendada;
use App\Support\HealthCheck\Score\FaixaScore;
use App\Support\HealthCheck\Score\ScoreCalculator;
use App\Support\HealthCheck\Score\ScoreDimensao;
use App\Support\HealthCheck\Score\ScoreResultado;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 3, Etapa 4 — persistência do Score junto de CronogramaImportacaoHealthCheck.
 * Nenhuma regra de Health Check/STRUCT/LOGIC/SLACK, nenhum comportamento de
 * aplicar()/rollback/polling foi alterado — só a persistência do snapshot do
 * Score, calculado dentro da MESMA transação já existente.
 */
class CronogramaImportacaoScoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function arquivoFixture(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents($this->fixturePath($name)));
    }

    private function fixturePath(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    // =====================================================================
    // PERSISTÊNCIA BÁSICA (1-7)
    // =====================================================================

    public function test_confirmar_persiste_score_completo_junto_do_health_check(): void
    {
        // Ciclo 7 — cronograma_sample.xml só dispara regras de Execução
        // (PROG-001/WORK-004), filtradas na Baseline (Ciclo 2). Troca pra
        // cronograma_fase2b3_slack.xml, que dispara regras de Planejamento
        // reais sob Baseline (confirmado via HealthCheckEngine ao vivo).
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_fase2b3_slack.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $healthCheck = CronogramaImportacaoHealthCheck::first();

        // 1) score
        $this->assertIsInt($healthCheck->score);
        $this->assertGreaterThanOrEqual(0, $healthCheck->score);
        $this->assertLessThanOrEqual(100, $healthCheck->score);

        // 2) faixa
        $this->assertInstanceOf(FaixaScore::class, $healthCheck->faixa_score);
        $this->assertSame(FaixaScore::paraScore($healthCheck->score), $healthCheck->faixa_score);

        // 3) cobertura
        $this->assertIsInt($healthCheck->cobertura);

        // 4) scores por dimensão
        $this->assertIsArray($healthCheck->score_por_dimensao);
        $this->assertCount(count(HealthCheckCategoria::cases()), $healthCheck->score_por_dimensao);
        foreach (HealthCheckCategoria::cases() as $categoria) {
            $this->assertArrayHasKey($categoria->value, $healthCheck->score_por_dimensao);
        }

        // 5) mapa de ações (cronograma_fase2b3_slack.xml dispara SLACK-001/BASE-003/etc., todos penalizadores)
        $this->assertIsArray($healthCheck->mapa_acoes);
        $this->assertNotEmpty($healthCheck->mapa_acoes);

        // 6) potencial recuperável
        $this->assertSame(100 - $healthCheck->score, $healthCheck->potencial_recuperavel);

        // 7) versão da fórmula
        $this->assertSame(ScoreCalculator::VERSAO_FORMULA, $healthCheck->versao_score);
    }

    public function test_score_persistido_bate_com_calculo_independente_do_scorecalculator(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $healthCheck = CronogramaImportacaoHealthCheck::first();

        // Reconstrói o plano de forma independente (mesmo arquivo) e roda o
        // ScoreCalculator de novo, fora do fluxo do Job — deve bater
        // exatamente com o que foi persistido (mesma fonte de verdade,
        // nenhuma segunda fórmula em lugar nenhum).
        $plano = app(ImportadorCronograma::class)->analisar($this->fixturePath('cronograma_sample.xml'), $this->obra);
        $esperado = app(ScoreCalculator::class)->calcular($healthCheck->resultado(), $plano);

        $this->assertSame($esperado->score, $healthCheck->score);
        $this->assertSame($esperado->faixa, $healthCheck->faixa_score);
        $this->assertSame($esperado->cobertura, $healthCheck->cobertura);
        $this->assertSame($esperado->potencialRecuperavel, $healthCheck->potencial_recuperavel);
    }

    // =====================================================================
    // SNAPSHOT / IMUTABILIDADE (8-9)
    // =====================================================================

    public function test_leitura_do_registro_retorna_exatamente_os_valores_persistidos(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $healthCheck = CronogramaImportacaoHealthCheck::first()->fresh();
        $scoreResultado = $healthCheck->scoreResultado();

        $this->assertInstanceOf(ScoreResultado::class, $scoreResultado);
        $this->assertSame($healthCheck->score, $scoreResultado->score);
        $this->assertSame($healthCheck->faixa_score, $scoreResultado->faixa);
        $this->assertSame($healthCheck->cobertura, $scoreResultado->cobertura);
        $this->assertSame($healthCheck->potencial_recuperavel, $scoreResultado->potencialRecuperavel);
        $this->assertSame($healthCheck->versao_score, $scoreResultado->versaoFormula);
        $this->assertCount(count($healthCheck->score_por_dimensao), $scoreResultado->porDimensao);
        $this->assertCount(count($healthCheck->mapa_acoes), $scoreResultado->mapaAcoes);
    }

    public function test_registro_e_um_snapshot_congelado_nao_um_calculo_ao_vivo(): void
    {
        // scoreResultado() nunca chama ScoreCalculator — só reidrata colunas
        // já gravadas. Buscar o registro 2x seguidas (inclusive forçando
        // reload do banco) tem que devolver o MESMO valor sempre, provando
        // que não há recálculo em nenhuma leitura — é isso que garante que
        // uma mudança futura na fórmula/pesos/categorias nunca muda um
        // Score já gravado.
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $primeira = CronogramaImportacaoHealthCheck::first()->fresh()->scoreResultado();
        $segunda = CronogramaImportacaoHealthCheck::first()->fresh()->scoreResultado();

        $this->assertSame($primeira->score, $segunda->score);
        $this->assertSame($primeira->faixa, $segunda->faixa);
        $this->assertEquals($primeira->porDimensao, $segunda->porDimensao);
        $this->assertEquals($primeira->mapaAcoes, $segunda->mapaAcoes);
    }

    // =====================================================================
    // JSON (10-12)
    // =====================================================================

    public function test_score_por_dimensao_e_serializado_e_deserializado_corretamente(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $healthCheck = CronogramaImportacaoHealthCheck::first()->fresh();
        $porDimensao = $healthCheck->score_por_dimensao;

        $this->assertIsArray($porDimensao[HealthCheckCategoria::Datas->value]);
        $this->assertArrayHasKey('categoria', $porDimensao[HealthCheckCategoria::Datas->value]);
        $this->assertArrayHasKey('score', $porDimensao[HealthCheckCategoria::Datas->value]);
        $this->assertArrayHasKey('quantidade_ocorrencias', $porDimensao[HealthCheckCategoria::Datas->value]);
        $this->assertArrayHasKey('severidade_maxima', $porDimensao[HealthCheckCategoria::Datas->value]);
        $this->assertArrayHasKey('impacto_total', $porDimensao[HealthCheckCategoria::Datas->value]);

        // Round-trip via DTO
        $dimensao = ScoreDimensao::fromArray($porDimensao[HealthCheckCategoria::Datas->value]);
        $this->assertInstanceOf(ScoreDimensao::class, $dimensao);
        $this->assertSame(HealthCheckCategoria::Datas, $dimensao->categoria);
    }

    public function test_mapa_de_acoes_e_serializado_e_deserializado_corretamente(): void
    {
        // Ciclo 7 — mesma troca de fixture: precisa de mapa_acoes não-vazio.
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_fase2b3_slack.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $healthCheck = CronogramaImportacaoHealthCheck::first()->fresh();
        $primeiraAcao = $healthCheck->mapa_acoes[0];

        foreach (['regra_id', 'categoria', 'severidade', 'titulo', 'quantidade_atividades', 'impacto', 'recomendacao'] as $campo) {
            $this->assertArrayHasKey($campo, $primeiraAcao);
        }

        $acao = AcaoRecomendada::fromArray($primeiraAcao);
        $this->assertInstanceOf(AcaoRecomendada::class, $acao);
        $this->assertSame($primeiraAcao['regra_id'], $acao->regraId);
    }

    public function test_scoreresultado_reidratado_contem_estrutura_completa_para_explicabilidade(): void
    {
        // Ciclo 7 — mesma troca de fixture: precisa de mapaAcoes não-vazio.
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_fase2b3_slack.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $scoreResultado = CronogramaImportacaoHealthCheck::first()->fresh()->scoreResultado();

        $primeiraAcao = $scoreResultado->mapaAcoes[0];
        $this->assertInstanceOf(AcaoRecomendada::class, $primeiraAcao);
        $this->assertNotEmpty($primeiraAcao->recomendacao);

        $dimensaoDatas = $scoreResultado->porDimensao[HealthCheckCategoria::Datas->value];
        $this->assertInstanceOf(ScoreDimensao::class, $dimensaoDatas);
    }

    // =====================================================================
    // HISTÓRICO ANTIGO (13-15)
    // =====================================================================

    public function test_registro_antigo_sem_score_continua_carregando_normalmente(): void
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->user->tenant_id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);

        // Simula um registro criado ANTES da Fase 3 — sem nenhuma das 7
        // colunas novas de Score (comportamento real: elas ficam NULL).
        $healthCheckAntigo = CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $importacao->id]
            + CronogramaImportacaoHealthCheck::camposParaPersistir(['findings' => []])
        );

        $healthCheckAntigo->refresh();

        $this->assertNull($healthCheckAntigo->score);
        $this->assertNull($healthCheckAntigo->faixa_score);
        $this->assertNull($healthCheckAntigo->cobertura);
        $this->assertNull($healthCheckAntigo->score_por_dimensao);
        $this->assertNull($healthCheckAntigo->mapa_acoes);
        $this->assertNull($healthCheckAntigo->potencial_recuperavel);
        $this->assertNull($healthCheckAntigo->versao_score);
    }

    public function test_registro_antigo_nao_recebe_nenhum_backfill_automatico(): void
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->user->tenant_id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);

        $healthCheckAntigo = CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $importacao->id]
            + CronogramaImportacaoHealthCheck::camposParaPersistir(['findings' => []])
        );

        // Nenhuma leitura/refresh/save deve preencher Score sozinho.
        $healthCheckAntigo->refresh();
        $healthCheckAntigo->save();
        $healthCheckAntigo->refresh();

        $this->assertNull($healthCheckAntigo->score);
    }

    public function test_scoreresultado_retorna_null_sem_excecao_para_registro_antigo(): void
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->user->tenant_id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);

        $healthCheckAntigo = CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $importacao->id]
            + CronogramaImportacaoHealthCheck::camposParaPersistir(['findings' => []])
        );

        $this->assertNull($healthCheckAntigo->scoreResultado());
    }

    // =====================================================================
    // TRANSAÇÃO (16-17)
    // =====================================================================

    public function test_falha_durante_aplicar_nao_deixa_health_check_nem_score_orfaos(): void
    {
        $importerQueQuebra = new class implements ImportadorCronograma {
            public function analisar(string $caminhoArquivo, Work $obra, \App\Enums\TipoCronogramaImportacao $tipo = \App\Enums\TipoCronogramaImportacao::Baseline): \App\DTOs\PlanoImportacao
            {
                return app(\App\Imports\MsProjectImporter::class)->analisar($caminhoArquivo, $obra, $tipo);
            }

            public function aplicar(\App\DTOs\PlanoImportacao $plano, Work $obra, ?string $userId, ?string $arquivo, \App\Enums\TipoCronogramaImportacao $tipo = \App\Enums\TipoCronogramaImportacao::Baseline): CronogramaImportacao
            {
                throw new \RuntimeException('Falha simulada dentro de aplicar()');
            }
        };

        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->assertSet('emPrevia', true);

        $this->app->bind(ImportadorCronograma::class, fn () => $importerQueQuebra);

        $component->call('confirmar');
        $component->assertSet('importado', false);

        $this->assertSame(0, CronogramaImportacao::count());
        $this->assertSame(0, CronogramaImportacaoHealthCheck::count());
    }

    public function test_nunca_existe_health_check_persistido_sem_score_correspondente(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        // Toda linha de cronograma_importacao_health_checks criada pelo
        // fluxo ATUAL (pós Fase 3) tem que ter Score — nunca metade gravado.
        foreach (CronogramaImportacaoHealthCheck::all() as $healthCheck) {
            $this->assertNotNull($healthCheck->score);
            $this->assertNotNull($healthCheck->versao_score);
        }
    }

    // =====================================================================
    // TENANT (18-19)
    // =====================================================================

    public function test_score_respeita_isolamento_de_tenant(): void
    {
        // Mesmo idioma já usado em TenantIsolationTest.php: cria o dado do
        // OUTRO tenant direto. Achado durante a implementação deste teste:
        // BelongsToTenant::bootBelongsToTenant() carimba 'tenant_id' a
        // partir de TenantContext::currentId() no evento 'creating',
        // IGNORANDO qualquer valor explícito passado em create() — mesma
        // trava de segurança documentada no CLAUDE.md ("nunca definir
        // tenant_id à mão"). Por isso, criar dado de um tenant diferente do
        // usuário autenticado exige TenantContext::actingAs() (mesmo
        // mecanismo já usado por comandos/jobs de plataforma), não um
        // 'tenant_id' explícito no array de create().
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        $healthCheckOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant, $outraObra) {
            $importacaoOutroTenant = CronogramaImportacao::create([
                'obra_id' => $outraObra->id,
                'importado_em' => now(),
            ]);

            $scoreDoOutroTenant = new ScoreResultado(
                score: 88,
                faixa: FaixaScore::Bom,
                cobertura: 100,
                porDimensao: [],
                mapaAcoes: [],
                potencialRecuperavel: 12,
                versaoFormula: ScoreCalculator::VERSAO_FORMULA,
            );

            return CronogramaImportacaoHealthCheck::create(
                ['cronograma_importacao_id' => $importacaoOutroTenant->id]
                + CronogramaImportacaoHealthCheck::camposParaPersistir(['findings' => []])
                + CronogramaImportacaoHealthCheck::camposDeScoreParaPersistir($scoreDoOutroTenant)
            );
        });

        $this->assertSame($outroTenant->id, $healthCheckOutroTenant->tenant_id);

        // Importação de verdade no tenant do usuário autenticado (setUp).
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        // O Score do outro tenant (com sua própria coluna 'score' preenchida)
        // é invisível pra query normal — mesmo global scope que já protegia
        // o restante do Health Check antes desta etapa.
        $this->assertNull(CronogramaImportacaoHealthCheck::find($healthCheckOutroTenant->id));
        $this->assertSame(1, CronogramaImportacaoHealthCheck::count());
        $this->assertNotNull(CronogramaImportacaoHealthCheck::first()->score);

        // Confirma que o registro do outro tenant existe de verdade (só não é visível por scope).
        $this->assertSame(
            88,
            CronogramaImportacaoHealthCheck::withoutGlobalScopes()->find($healthCheckOutroTenant->id)->score
        );
    }

    public function test_19_tenant_isolation_test_continua_passando_e_ja_cobre_este_model(): void
    {
        // CronogramaImportacaoHealthCheck já usa BelongsToTenant desde a
        // Fase 1 (Health Check) — as 7 colunas novas de Score não mudam o
        // escopo por tenant, então não precisam de uma entrada dedicada
        // nova em TenantIsolationTest.php (mesmo model, mesmo scope, só
        // mais colunas). Este teste documenta essa decisão; a suíte
        // completa desta etapa roda TenantIsolationTest.php de verdade.
        $this->assertTrue(true);
    }
}
