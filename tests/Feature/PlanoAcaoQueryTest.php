<?php

namespace Tests\Feature;

use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\ResultadoReconciliacaoPlanoAcao;
use App\Enums\StatusPlanoAcao;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 4.3, Etapa A — camada de model/query do Plano de Ação, ANTES de
 * qualquer Livewire/Blade. Prova que `ultimaReconciliacao()` (ofMany),
 * `severidadeDaRegra()`, `atividadesRelacionadas()` e a estratégia de
 * ordenação/filtro funcionam corretamente e sem N+1 — a Etapa B só precisa
 * encaixar isso numa página, a lógica em si já está validada aqui.
 */
class PlanoAcaoQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private CronogramaImportacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');

        $this->importacao = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);
    }

    private function criarAcao(array $overrides = []): PlanoAcao
    {
        return PlanoAcao::create(array_merge([
            'obra_id' => $this->obra->id,
            'cronograma_importacao_origem_id' => $this->importacao->id,
            'regra_id' => 'PROG-001',
            'titulo' => 'teste',
            'recomendacao' => 'teste',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => ['2'],
        ], $overrides));
    }

    private function criarReconciliacao(PlanoAcao $acao, ResultadoReconciliacaoPlanoAcao $resultado, \DateTimeInterface $createdAt): PlanoAcaoReconciliacao
    {
        $reconciliacao = PlanoAcaoReconciliacao::create([
            'plano_acao_id' => $acao->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'resultado' => $resultado,
            'status_anterior' => StatusPlanoAcao::Aberta,
            'status_novo' => StatusPlanoAcao::Aberta,
            'uids_anteriores' => ['2'],
            'uids_atuais' => ['2'],
            'quantidade_anterior' => 1,
            'quantidade_atual' => 1,
            'impacto_anterior' => null,
            'impacto_atual' => null,
        ]);
        $reconciliacao->forceFill(['created_at' => $createdAt])->save();

        return $reconciliacao->fresh();
    }

    // =====================================================================
    // ultimaReconciliacao() — ofMany
    // =====================================================================

    public function test_ultima_reconciliacao_resolve_a_mais_recente_por_created_at(): void
    {
        $acao = $this->criarAcao();
        $this->criarReconciliacao($acao, ResultadoReconciliacaoPlanoAcao::Persistente, now()->subDays(3));
        $maisRecente = $this->criarReconciliacao($acao, ResultadoReconciliacaoPlanoAcao::Agravado, now()->subDay());

        $this->assertTrue($acao->ultimaReconciliacao->is($maisRecente));
        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Agravado, $acao->ultimaReconciliacao->resultado);
    }

    public function test_ultima_reconciliacao_null_quando_nunca_reconciliada(): void
    {
        $acao = $this->criarAcao();

        $this->assertNull($acao->ultimaReconciliacao);
    }

    public function test_eager_load_de_ultima_reconciliacao_evita_n_mais_1(): void
    {
        foreach (range(1, 5) as $i) {
            $acao = $this->criarAcao(['uids_referencia' => ["u{$i}"]]);
            $this->criarReconciliacao($acao, ResultadoReconciliacaoPlanoAcao::Persistente, now()->subDays($i));
            $this->criarReconciliacao($acao, ResultadoReconciliacaoPlanoAcao::Agravado, now()->subHours($i));
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $acoes = PlanoAcao::where('obra_id', $this->obra->id)
            ->with(['ultimaReconciliacao.importacao', 'responsavel', 'autor', 'importacaoOrigem'])
            ->get();

        foreach ($acoes as $acao) {
            $acao->ultimaReconciliacao?->resultado;
            $acao->ultimaReconciliacao?->importacao?->id;
        }

        $this->assertCount(5, $acoes);
        // Uma query pra planos_acao + uma pra ultimaReconciliacao (ofMany) +
        // uma pra importacao das reconciliações + uma pra responsavel +
        // uma pra autor + uma pra importacaoOrigem = 6, nunca escalando com N.
        $this->assertLessThanOrEqual(6, $queryCount, "esperava um número fixo de queries, não uma por ação (N+1). Obtido: {$queryCount}");
    }

    // =====================================================================
    // severidadeDaRegra()
    // =====================================================================

    public function test_severidade_da_regra_deriva_do_health_check_de_origem(): void
    {
        $finding = new HealthCheckFinding(
            regraId: 'PROG-001',
            categoria: HealthCheckCategoria::Avanco,
            severidade: HealthCheckSeveridade::Alto,
            titulo: 'teste',
            descricao: 'teste',
            impacto: 'teste',
            recomendacao: 'teste',
            atividades: [['uid' => '2']],
        );

        CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $this->importacao->id]
            + CronogramaImportacaoHealthCheck::camposParaPersistir((new HealthCheckResultado([$finding]))->toArray())
        );

        $acao = $this->criarAcao();

        $this->assertSame(HealthCheckSeveridade::Alto, $acao->severidadeDaRegra());
    }

    public function test_severidade_da_regra_null_quando_origem_sem_health_check(): void
    {
        $acao = $this->criarAcao();

        $this->assertNull($acao->severidadeDaRegra());
    }

    public function test_severidade_da_regra_null_quando_regra_nao_aparece_nos_findings(): void
    {
        $finding = new HealthCheckFinding(
            regraId: 'WORK-004',
            categoria: HealthCheckCategoria::Hh,
            severidade: HealthCheckSeveridade::Medio,
            titulo: 'teste',
            descricao: 'teste',
            impacto: 'teste',
            recomendacao: 'teste',
            atividades: [['uid' => '2']],
        );

        CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $this->importacao->id]
            + CronogramaImportacaoHealthCheck::camposParaPersistir((new HealthCheckResultado([$finding]))->toArray())
        );

        $acao = $this->criarAcao(['regra_id' => 'PROG-001']); // regra diferente da que está no Health Check

        $this->assertNull($acao->severidadeDaRegra());
    }

    // =====================================================================
    // atividadesRelacionadas()
    // =====================================================================

    public function test_atividades_relacionadas_resolve_uid_para_codigo_e_nome(): void
    {
        Atividade::factory()->create(['obra_id' => $this->obra->id, 'external_uid' => '2', 'codigo_cronograma' => '1.1', 'nome' => 'Atividade Um']);
        Atividade::factory()->create(['obra_id' => $this->obra->id, 'external_uid' => '3', 'codigo_cronograma' => '1.2', 'nome' => 'Atividade Dois']);
        Atividade::factory()->create(['obra_id' => $this->obra->id, 'external_uid' => '999', 'codigo_cronograma' => '9.9', 'nome' => 'Não relacionada']);

        $acao = $this->criarAcao(['uids_referencia' => ['2', '3']]);

        $resolvidas = $acao->atividadesRelacionadas();

        $this->assertCount(2, $resolvidas);
        $this->assertEqualsCanonicalizing(['1.1', '1.2'], $resolvidas->pluck('codigo_cronograma')->all());
        $this->assertEqualsCanonicalizing(['Atividade Um', 'Atividade Dois'], $resolvidas->pluck('nome')->all());
    }

    public function test_atividades_relacionadas_vazio_quando_uid_nao_existe_mais(): void
    {
        $acao = $this->criarAcao(['uids_referencia' => ['uid-inexistente']]);

        $this->assertCount(0, $acao->atividadesRelacionadas());
    }

    // =====================================================================
    // FILTROS (query direta, mesma forma que a Etapa B vai usar)
    // =====================================================================

    public function test_filtro_por_status(): void
    {
        $this->criarAcao(['status' => StatusPlanoAcao::Aberta]);
        $this->criarAcao(['status' => StatusPlanoAcao::Resolvida]);

        $abertas = PlanoAcao::where('obra_id', $this->obra->id)->where('status', StatusPlanoAcao::Aberta->value)->get();

        $this->assertCount(1, $abertas);
    }

    public function test_filtro_por_responsavel(): void
    {
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $responsavel, 'engenheiro');

        $this->criarAcao(['responsavel_id' => $responsavel->id]);
        $this->criarAcao(['responsavel_id' => null]);

        $doResponsavel = PlanoAcao::where('obra_id', $this->obra->id)->where('responsavel_id', $responsavel->id)->get();

        $this->assertCount(1, $doResponsavel);
    }

    public function test_filtro_por_regra(): void
    {
        $this->criarAcao(['regra_id' => 'PROG-001']);
        $this->criarAcao(['regra_id' => 'WORK-004']);

        $daRegra = PlanoAcao::where('obra_id', $this->obra->id)->where('regra_id', 'PROG-001')->get();

        $this->assertCount(1, $daRegra);
    }

    public function test_filtro_por_intervalo_de_prazo(): void
    {
        $this->criarAcao(['prazo' => '2026-08-01']);
        $this->criarAcao(['prazo' => '2026-09-15']);
        $this->criarAcao(['prazo' => null]);

        $noIntervalo = PlanoAcao::where('obra_id', $this->obra->id)
            ->whereBetween('prazo', ['2026-07-01', '2026-08-31'])
            ->get();

        $this->assertCount(1, $noIntervalo);
    }

    public function test_filtro_por_situacao_da_ultima_reconciliacao_via_wherehas(): void
    {
        $agravada = $this->criarAcao();
        $this->criarReconciliacao($agravada, ResultadoReconciliacaoPlanoAcao::Agravado, now());

        $persistente = $this->criarAcao();
        $this->criarReconciliacao($persistente, ResultadoReconciliacaoPlanoAcao::Persistente, now());

        $semReconciliacao = $this->criarAcao();

        $agravadas = PlanoAcao::where('obra_id', $this->obra->id)
            ->whereHas('ultimaReconciliacao', fn ($q) => $q->where('resultado', ResultadoReconciliacaoPlanoAcao::Agravado->value))
            ->get();

        $this->assertCount(1, $agravadas);
        $this->assertTrue($agravadas->first()->is($agravada));
    }

    // =====================================================================
    // ORDENAÇÃO
    // =====================================================================

    private function ordenada()
    {
        return PlanoAcao::where('obra_id', $this->obra->id)
            ->orderByRaw("CASE WHEN status = 'aberta' THEN 0 ELSE 1 END ASC")
            ->orderByRaw('CASE WHEN prazo IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('prazo', 'asc')
            ->orderByRaw("COALESCE((
                SELECT CASE resultado
                    WHEN 'alterado' THEN 0
                    WHEN 'agravado' THEN 1
                    WHEN 'persistente' THEN 2
                    WHEN 'resolvido' THEN 3
                    ELSE 4
                END
                FROM plano_acao_reconciliacoes r
                WHERE r.plano_acao_id = planos_acao.id
                ORDER BY r.created_at DESC
                LIMIT 1
            ), 4) ASC")
            ->get();
    }

    public function test_ordenacao_aberta_antes_de_resolvida_e_cancelada(): void
    {
        $resolvida = $this->criarAcao(['status' => StatusPlanoAcao::Resolvida, 'titulo' => 'resolvida']);
        $cancelada = $this->criarAcao(['status' => StatusPlanoAcao::Cancelada, 'titulo' => 'cancelada']);
        $aberta = $this->criarAcao(['status' => StatusPlanoAcao::Aberta, 'titulo' => 'aberta']);

        $ordenadas = $this->ordenada();

        $this->assertTrue($ordenadas->first()->is($aberta));
    }

    public function test_ordenacao_prazo_vencido_antes_de_prazo_proximo_e_nulo_por_ultimo(): void
    {
        $semPrazo = $this->criarAcao(['prazo' => null, 'titulo' => 'sem prazo']);
        $proximo = $this->criarAcao(['prazo' => now()->addDays(10)->toDateString(), 'titulo' => 'proximo']);
        $vencido = $this->criarAcao(['prazo' => now()->subDays(5)->toDateString(), 'titulo' => 'vencido']);

        $ordenadas = $this->ordenada();

        $this->assertSame([$vencido->id, $proximo->id, $semPrazo->id], $ordenadas->pluck('id')->all());
    }

    public function test_ordenacao_desempata_por_situacao_alterado_agravado_persistente_sem_reconciliacao(): void
    {
        $semReconciliacao = $this->criarAcao(['titulo' => 'sem']);
        $persistente = $this->criarAcao(['titulo' => 'persistente']);
        $this->criarReconciliacao($persistente, ResultadoReconciliacaoPlanoAcao::Persistente, now());
        $agravado = $this->criarAcao(['titulo' => 'agravado']);
        $this->criarReconciliacao($agravado, ResultadoReconciliacaoPlanoAcao::Agravado, now());
        $alterado = $this->criarAcao(['titulo' => 'alterado']);
        $this->criarReconciliacao($alterado, ResultadoReconciliacaoPlanoAcao::Alterado, now());

        // Todas Abertas, todas sem prazo — a única diferença é a situação da última reconciliação.
        $ordenadas = $this->ordenada();

        $this->assertSame(
            [$alterado->id, $agravado->id, $persistente->id, $semReconciliacao->id],
            $ordenadas->pluck('id')->all()
        );
    }

    public function test_ordenacao_usa_a_reconciliacao_mais_recente_nao_a_primeira(): void
    {
        $acao = $this->criarAcao(['titulo' => 'evolui']);
        $this->criarReconciliacao($acao, ResultadoReconciliacaoPlanoAcao::Alterado, now()->subDays(5));
        $this->criarReconciliacao($acao, ResultadoReconciliacaoPlanoAcao::Persistente, now()->subDay());

        $outraAcao = $this->criarAcao(['titulo' => 'agravada']);
        $this->criarReconciliacao($outraAcao, ResultadoReconciliacaoPlanoAcao::Agravado, now());

        $ordenadas = $this->ordenada();

        // "evolui" tem situação ATUAL Persistente (a mais recente), não Alterado (a mais antiga) —
        // então "agravada" (Agravado) deve vir ANTES de "evolui" (Persistente).
        $this->assertSame([$outraAcao->id, $acao->id], $ordenadas->pluck('id')->all());
    }

    // =====================================================================
    // ISOLAMENTO
    // =====================================================================

    public function test_query_isolada_por_obra(): void
    {
        $daObra = $this->criarAcao();

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraImportacao = CronogramaImportacao::create(['obra_id' => $outraObra->id, 'importado_em' => now()]);
        PlanoAcao::create([
            'obra_id' => $outraObra->id,
            'cronograma_importacao_origem_id' => $outraImportacao->id,
            'regra_id' => 'PROG-001',
            'titulo' => 'outra obra',
            'recomendacao' => 'teste',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => ['2'],
        ]);

        $resultado = PlanoAcao::where('obra_id', $this->obra->id)->get();

        $this->assertCount(1, $resultado);
        $this->assertTrue($resultado->first()->is($daObra));
    }
}
