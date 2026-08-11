<?php

namespace Tests\Unit;

use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\ResultadoReconciliacaoPlanoAcao;
use App\Enums\StatusPlanoAcao;
use App\Enums\TipoCronogramaImportacao;
use App\Models\CronogramaImportacao;
use App\Models\CronogramaImportacaoHealthCheck;
use App\Models\PlanoAcao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckResultado;
use App\Support\HealthCheck\PlanoAcao\PlanoAcaoReconciliador;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 4.1, Partes E/F/G/K — PlanoAcaoReconciliador testado isoladamente,
 * SEM integração com ImportarCronogramaJob (Parte I, deliberadamente
 * adiada). Constrói HealthCheckFinding/HealthCheckResultado à mão (mesmo
 * estilo de ScoreCalculatorTest) em vez de importar XML — mais rápido e
 * isola exatamente a lógica de reconciliação.
 */
class PlanoAcaoReconciliadorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private PlanoAcaoReconciliador $reconciliador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');

        $this->reconciliador = new PlanoAcaoReconciliador();
    }

    private function finding(
        string $regraId,
        array $uids,
        HealthCheckCategoria $categoria = HealthCheckCategoria::Estrutura,
        HealthCheckSeveridade $severidade = HealthCheckSeveridade::Critico,
    ): HealthCheckFinding {
        return new HealthCheckFinding(
            regraId: $regraId,
            categoria: $categoria,
            severidade: $severidade,
            titulo: "Finding de teste $regraId",
            descricao: 'descrição de teste',
            impacto: 'impacto de teste',
            recomendacao: 'recomendação de teste',
            atividades: array_map(fn ($uid) => ['uid' => $uid, 'codigo' => "c-$uid", 'nome' => "Atividade $uid"], $uids),
        );
    }

    /** Mesmo shape agrupado real do STRUCT-005 (CicloLogicoRule). */
    private function findingAgrupado(string $regraId, array $uids, int $grupoId = 1): HealthCheckFinding
    {
        return new HealthCheckFinding(
            regraId: $regraId,
            categoria: HealthCheckCategoria::Estrutura,
            severidade: HealthCheckSeveridade::Critico,
            titulo: "Finding agrupado de teste $regraId",
            descricao: 'descrição de teste',
            impacto: 'impacto de teste',
            recomendacao: 'recomendação de teste',
            atividades: [[
                'ciclo_id' => $grupoId,
                'atividades' => array_map(fn ($uid) => ['uid' => $uid, 'codigo' => "c-$uid", 'nome' => "Atividade $uid"], $uids),
                'relacoes' => [],
            ]],
        );
    }

    /** @return array{0: CronogramaImportacao, 1: CronogramaImportacaoHealthCheck} */
    private function persistirImportacaoComFindings(array $findings): array
    {
        $importacao = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);

        $resultado = new HealthCheckResultado($findings);

        $healthCheck = CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $importacao->id]
            + CronogramaImportacaoHealthCheck::camposParaPersistir($resultado->toArray())
            + [
                'score' => 82,
                'faixa_score' => 'bom',
                'cobertura' => 100,
                'score_por_dimensao' => [],
                'mapa_acoes' => [],
                'potencial_recuperavel' => 18,
                'versao_score' => '1.0',
            ]
        );

        return [$importacao, $healthCheck];
    }

    private function criarAcao(string $regraId, array $uids, CronogramaImportacao $origem): PlanoAcao
    {
        return PlanoAcao::criarDeFinding($this->finding($regraId, $uids), $origem, $this->obra->id);
    }

    /** @param PlanoAcao[] $acoes */
    private function colecao(array $acoes): Collection
    {
        return new Collection($acoes);
    }

    // =====================================================================
    // OS 4 RESULTADOS DE RECONCILIAÇÃO (estrutura PLANA)
    // =====================================================================

    public function test_sem_sobreposicao_marca_como_resolvido_e_nunca_apaga_a_acao(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([]); // nenhum finding

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertCount(1, $eventos);
        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Resolvido, $eventos->first()->resultado);
        $this->assertSame(0, $eventos->first()->quantidade_atual);
        $this->assertSame(['101', '102'], $eventos->first()->uids_anteriores);
        $this->assertSame([], $eventos->first()->uids_atuais);

        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Resolvida, $acao->status);
        $this->assertNotNull($acao->resolvida_em);
        $this->assertNotNull(PlanoAcao::find($acao->id), 'a ação nunca deve ser apagada automaticamente');
        // uids_referencia mantém a última referência conhecida, nunca é limpo.
        $this->assertSame(['101', '102'], $acao->uids_referencia);
    }

    public function test_mesmo_conjunto_marca_como_persistente(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Persistente, $eventos->first()->resultado);

        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status);
        $this->assertNull($acao->resolvida_em);
        $this->assertSame(['101', '102'], $acao->uids_referencia);
    }

    public function test_sobreposicao_com_crescimento_marca_como_agravado(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);

        // Nada do conjunto original saiu (101 e 102 continuam), mas 103 entrou.
        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102', '103'])]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);
        $evento = $eventos->first();

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Agravado, $evento->resultado);
        $this->assertSame(2, $evento->quantidade_anterior);
        $this->assertSame(3, $evento->quantidade_atual);
        $this->assertNull($evento->impacto_anterior, 'impacto não é confiável nesta fase — ver CLAUDE.md Fase 4.1');
        $this->assertNull($evento->impacto_atual);

        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status);
        $this->assertEqualsCanonicalizing(['101', '102', '103'], $acao->uids_referencia);
    }

    public function test_sobreposicao_parcial_marca_como_alterado_e_nunca_resolve_sozinho(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);

        // 102 saiu, 104 entrou, 101 permaneceu — sobreposição parcial.
        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '104'])]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Alterado, $eventos->first()->resultado);

        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status, 'Alterado nunca resolve automaticamente — exige revisão humana');
        $this->assertEqualsCanonicalizing(['101', '104'], $acao->uids_referencia);
    }

    // =====================================================================
    // FINDING NOVO NÃO ASSOCIA A AÇÃO EXISTENTE
    // =====================================================================

    public function test_finding_de_regra_diferente_com_mesmos_uids_nao_associa_a_acao_existente(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);

        // Mesmos uids, mas regra DIFERENTE — não deve ser tratado como o mesmo problema.
        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-004', ['101', '102'])]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Resolvido, $eventos->first()->resultado);
    }

    // =====================================================================
    // FINDING AGRUPADO (mesma bateria de cenários, estrutura real do STRUCT-005)
    // =====================================================================

    public function test_finding_agrupado_persistente(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->findingAgrupado('STRUCT-005', ['201', '202'])]);
        $acao = PlanoAcao::criarDeFinding($this->findingAgrupado('STRUCT-005', ['201', '202']), $primeira, $this->obra->id);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$this->findingAgrupado('STRUCT-005', ['201', '202'])]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Persistente, $eventos->first()->resultado);
    }

    public function test_finding_agrupado_agravado(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->findingAgrupado('STRUCT-005', ['201', '202'])]);
        $acao = PlanoAcao::criarDeFinding($this->findingAgrupado('STRUCT-005', ['201', '202']), $primeira, $this->obra->id);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$this->findingAgrupado('STRUCT-005', ['201', '202', '203'])]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Agravado, $eventos->first()->resultado);
    }

    public function test_finding_agrupado_alterado(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->findingAgrupado('STRUCT-005', ['201', '202'])]);
        $acao = PlanoAcao::criarDeFinding($this->findingAgrupado('STRUCT-005', ['201', '202']), $primeira, $this->obra->id);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$this->findingAgrupado('STRUCT-005', ['201', '205'])]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Alterado, $eventos->first()->resultado);
    }

    public function test_finding_agrupado_sem_sobreposicao_resolve(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->findingAgrupado('STRUCT-005', ['201', '202'])]);
        $acao = PlanoAcao::criarDeFinding($this->findingAgrupado('STRUCT-005', ['201', '202']), $primeira, $this->obra->id);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Resolvido, $eventos->first()->resultado);
    }

    // =====================================================================
    // MÚLTIPLOS GRUPOS NA MESMA REGRA — só o grupo com sobreposição conta
    // =====================================================================

    public function test_multiplos_grupos_da_mesma_regra_so_considera_o_grupo_com_sobreposicao(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->findingAgrupado('STRUCT-005', ['201', '202'], grupoId: 1)]);
        $acao = PlanoAcao::criarDeFinding($this->findingAgrupado('STRUCT-005', ['201', '202'], grupoId: 1), $primeira, $this->obra->id);

        // 2 ciclos na nova importação: um relacionado (cresceu), outro
        // completamente independente (não deve interferir na reconciliação).
        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([
            $this->findingAgrupado('STRUCT-005', ['201', '202', '203'], grupoId: 1),
            $this->findingAgrupado('STRUCT-005', ['301', '302'], grupoId: 2),
        ]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);
        $evento = $eventos->first();

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Agravado, $evento->resultado);
        $this->assertEqualsCanonicalizing(['201', '202', '203'], $evento->uids_atuais);
        $this->assertNotContains('301', $evento->uids_atuais);
        $this->assertNotContains('302', $evento->uids_atuais);
    }

    // =====================================================================
    // UIDS REPETIDOS / CONJUNTO VAZIO
    // =====================================================================

    public function test_uids_repetidos_dentro_do_finding_nao_inflam_a_quantidade(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);

        $findingComDuplicata = new HealthCheckFinding(
            regraId: 'STRUCT-005',
            categoria: HealthCheckCategoria::Estrutura,
            severidade: HealthCheckSeveridade::Critico,
            titulo: 'teste',
            descricao: 'teste',
            impacto: 'teste',
            recomendacao: 'teste',
            atividades: [
                ['uid' => '101', 'codigo' => 'c-101', 'nome' => 'A'],
                ['uid' => '101', 'codigo' => 'c-101', 'nome' => 'A'], // duplicado de propósito
                ['uid' => '102', 'codigo' => 'c-102', 'nome' => 'B'],
            ],
        );

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$findingComDuplicata]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertSame(2, $eventos->first()->quantidade_atual);
        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Persistente, $eventos->first()->resultado);
    }

    public function test_finding_com_atividades_vazio_e_tratado_como_sem_sobreposicao(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);

        $findingVazio = new HealthCheckFinding(
            regraId: 'STRUCT-005',
            categoria: HealthCheckCategoria::Estrutura,
            severidade: HealthCheckSeveridade::Critico,
            titulo: 'teste',
            descricao: 'teste',
            impacto: 'teste',
            recomendacao: 'teste',
            atividades: [], // estrutura malformada/defensiva — nunca deveria acontecer de verdade
        );

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$findingVazio]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Resolvido, $eventos->first()->resultado);
    }

    // =====================================================================
    // INTEGRIDADE
    // =====================================================================

    public function test_acao_ja_resolvida_nao_e_reconciliada_de_novo(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);
        $acao->update(['status' => StatusPlanoAcao::Resolvida, 'resolvida_em' => now()]);

        // Mesmo que o finding volte a aparecer (o que na prática não faria
        // sentido pra uma ação já resolvida), o reconciliador nunca deve
        // reabrir sozinho.
        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertCount(0, $eventos, 'ação não-Aberta nunca deve gerar evento de reconciliação');
        $this->assertSame(0, $acao->reconciliacoes()->count());

        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Resolvida, $acao->status);
    }

    public function test_acao_cancelada_nao_e_reaberta_automaticamente(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);
        $acao->update(['status' => StatusPlanoAcao::Cancelada]);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertCount(0, $eventos);

        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Cancelada, $acao->status);
    }

    public function test_snapshot_de_health_check_permanece_intacto_apos_reconciliacao(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102', '103'])]);
        $findingsAntes = $healthCheck2->fresh()->findings;
        $scoreAntes = $healthCheck2->fresh()->score;

        $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $healthCheck2Depois = $healthCheck2->fresh();
        $this->assertSame($findingsAntes, $healthCheck2Depois->findings, 'reconciliação nunca pode alterar os findings persistidos');
        $this->assertSame($scoreAntes, $healthCheck2Depois->score, 'reconciliação nunca pode alterar o Score persistido');
    }

    public function test_evento_de_reconciliacao_grava_dados_suficientes_para_auditoria(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102', '103'])]);

        $evento = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2)->first();

        $this->assertTrue($evento->planoAcao->is($acao));
        $this->assertTrue($evento->importacao->is($segunda));
        $this->assertSame(StatusPlanoAcao::Aberta, $evento->status_anterior);
        $this->assertSame(StatusPlanoAcao::Aberta, $evento->status_novo);
        $this->assertSame($this->tenant->id, $evento->tenant_id);
    }

    // =====================================================================
    // FILTRO POR TIPO DE IMPORTAÇÃO (Ciclo 3) — regra de Execução não pode
    // ser "resolvida" por uma reimportação de Baseline, já que o Health
    // Check da Baseline nunca avalia regras de Execução (Ciclo 2).
    // =====================================================================

    public function test_baseline_nao_reconcilia_acao_de_regra_execucao_e_ela_fica_intocada(): void
    {
        // PROG-001 é Execucao (classificação definitiva do Ciclo 1).
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('PROG-001', ['101', '102'])]);
        $acao = $this->criarAcao('PROG-001', ['101', '102'], $primeira);

        // Health Check de uma Baseline nunca avalia PROG-001 — finding ausente de propósito.
        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([]);

        $eventos = $this->reconciliador->reconciliar(
            $this->colecao([$acao]),
            $segunda,
            $healthCheck2,
            TipoCronogramaImportacao::Baseline,
        );

        $this->assertCount(0, $eventos, 'ação de Execução não pode gerar evento numa reconciliação de Baseline');
        $this->assertSame(0, $acao->reconciliacoes()->count());

        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status);
        $this->assertNull($acao->resolvida_em);
        $this->assertSame(['101', '102'], $acao->uids_referencia, 'uids_referencia também não pode ser tocado');
    }

    public function test_baseline_continua_reconciliando_normalmente_acao_de_regra_planejamento(): void
    {
        // STRUCT-005 é Planejamento — continua avaliada em qualquer tipo.
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('STRUCT-005', ['101', '102'])]);
        $acao = $this->criarAcao('STRUCT-005', ['101', '102'], $primeira);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([]); // finding sumiu de verdade

        $eventos = $this->reconciliador->reconciliar(
            $this->colecao([$acao]),
            $segunda,
            $healthCheck2,
            TipoCronogramaImportacao::Baseline,
        );

        $this->assertCount(1, $eventos);
        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Resolvido, $eventos->first()->resultado);

        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Resolvida, $acao->status);
        $this->assertNotNull($acao->resolvida_em);
    }

    public function test_avanco_continua_resolvendo_normalmente_acao_de_regra_execucao(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('PROG-001', ['101', '102'])]);
        $acao = $this->criarAcao('PROG-001', ['101', '102'], $primeira);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([]);

        $eventos = $this->reconciliador->reconciliar(
            $this->colecao([$acao]),
            $segunda,
            $healthCheck2,
            TipoCronogramaImportacao::Avanco,
        );

        $this->assertCount(1, $eventos);
        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Resolvido, $eventos->first()->resultado);

        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Resolvida, $acao->status);
    }

    public function test_ambos_continua_resolvendo_normalmente_acao_de_regra_execucao(): void
    {
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('PROG-001', ['101', '102'])]);
        $acao = $this->criarAcao('PROG-001', ['101', '102'], $primeira);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([]);

        $eventos = $this->reconciliador->reconciliar(
            $this->colecao([$acao]),
            $segunda,
            $healthCheck2,
            TipoCronogramaImportacao::Ambos,
        );

        $this->assertCount(1, $eventos);
        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Resolvido, $eventos->first()->resultado);
    }

    public function test_reconciliar_sem_tipo_preserva_comportamento_legado_mesmo_para_regra_execucao(): void
    {
        // Compatibilidade: chamada existente sem $tipo (ex.: PlanoAcaoEdicaoTest)
        // continua reconciliando TUDO, igual a antes do Ciclo 3.
        [$primeira, ] = $this->persistirImportacaoComFindings([$this->finding('PROG-001', ['101', '102'])]);
        $acao = $this->criarAcao('PROG-001', ['101', '102'], $primeira);

        [$segunda, $healthCheck2] = $this->persistirImportacaoComFindings([]);

        $eventos = $this->reconciliador->reconciliar($this->colecao([$acao]), $segunda, $healthCheck2);

        $this->assertCount(1, $eventos);
        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Resolvido, $eventos->first()->resultado);

        $acao->refresh();
        $this->assertSame(StatusPlanoAcao::Resolvida, $acao->status);
    }
}
