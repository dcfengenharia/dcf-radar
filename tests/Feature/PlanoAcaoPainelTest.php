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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 4.3, Etapa C — painel expansível inline do Plano de Ação. Cobre
 * conteúdo do painel (A-G do pedido), isolamento e integridade (nada é
 * alterado ao abrir/fechar). Alpine (`x-show`) só esconde visualmente via
 * CSS/JS em runtime — o HTML retornado por Livewire::test() sempre contém
 * o conteúdo do painel, então os testes de conteúdo funcionam via
 * assertSee normal; "fechada inicialmente" é verificado checando o valor
 * inicial de `x-data`.
 */
class PlanoAcaoPainelTest extends TestCase
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
            'arquivo' => 'cronograma-teste.xml',
            'importado_em' => now(),
        ]);
    }

    private function criarAcao(array $overrides = []): PlanoAcao
    {
        return PlanoAcao::create(array_merge([
            'obra_id' => $this->obra->id,
            'cronograma_importacao_origem_id' => $this->importacao->id,
            'regra_id' => 'PROG-001',
            'titulo' => 'Título congelado da ação',
            'recomendacao' => 'Recomendação congelada da ação',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => ['2', '3'],
        ], $overrides));
    }

    private function criarHealthCheckComSeveridade(HealthCheckSeveridade $severidade): void
    {
        $finding = new HealthCheckFinding(
            regraId: 'PROG-001',
            categoria: HealthCheckCategoria::Avanco,
            severidade: $severidade,
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
    }

    private function criarReconciliacao(PlanoAcao $acao, array $overrides = []): PlanoAcaoReconciliacao
    {
        return PlanoAcaoReconciliacao::create(array_merge([
            'plano_acao_id' => $acao->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'resultado' => ResultadoReconciliacaoPlanoAcao::Persistente,
            'status_anterior' => StatusPlanoAcao::Aberta,
            'status_novo' => StatusPlanoAcao::Aberta,
            'uids_anteriores' => ['2', '3'],
            'uids_atuais' => ['2', '3'],
            'quantidade_anterior' => 2,
            'quantidade_atual' => 2,
        ], $overrides));
    }

    // =========================================================================
    // VISUALIZAÇÃO
    // =========================================================================

    public function test_painel_fechado_inicialmente(): void
    {
        $this->criarAcao();

        // Ciclo 11 (Etapa B): x-data ganhou `selecionadas: []` (estado de
        // seleção pra "Transformar em Restrição") — `aberto: false`
        // continua sendo o valor inicial real, só o literal completo mudou.
        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSeeHtml('x-data="{ aberto: false, selecionadas: [] }"');
    }

    public function test_painel_mostra_titulo(): void
    {
        $this->criarAcao(['titulo' => 'Título específico para o teste']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee('Título específico para o teste');
    }

    public function test_painel_mostra_recomendacao(): void
    {
        $this->criarAcao(['recomendacao' => 'Recomendação específica para o teste']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee('Recomendação específica para o teste');
    }

    public function test_painel_mostra_regra(): void
    {
        $this->criarAcao(['regra_id' => 'STRUCT-005']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee('STRUCT-005');
    }

    public function test_painel_mostra_severidade(): void
    {
        $this->criarHealthCheckComSeveridade(HealthCheckSeveridade::Alto);
        $this->criarAcao();

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee('Alto');
    }

    public function test_painel_mostra_status_da_acao(): void
    {
        $this->criarAcao(['status' => StatusPlanoAcao::Resolvida]);

        $html = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])->html();

        // "Resolvida" aparece tanto na listagem quanto no painel — conta as ocorrências.
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Resolvida'));
    }

    public function test_painel_mostra_situacao_da_ultima_analise(): void
    {
        $acao = $this->criarAcao();
        $this->criarReconciliacao($acao, ['resultado' => ResultadoReconciliacaoPlanoAcao::Agravado]);

        $html = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])->html();

        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Agravado'));
    }

    public function test_painel_mostra_importacao_de_origem(): void
    {
        $this->criarAcao();

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee('cronograma-teste.xml')
            ->assertSee($this->importacao->importado_em->format('d/m/Y H:i'));
    }

    public function test_painel_mostra_ultima_importacao_analisada(): void
    {
        $acao = $this->criarAcao();
        $novaImportacao = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'importado_em' => now()->addDay(),
        ]);
        $this->criarReconciliacao($acao, ['cronograma_importacao_id' => $novaImportacao->id]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee($novaImportacao->importado_em->format('d/m/Y H:i'));
    }

    public function test_painel_mostra_atividades_relacionadas(): void
    {
        Atividade::factory()->create([
            'obra_id' => $this->obra->id,
            'external_uid' => '2',
            'codigo_cronograma' => '1.1',
            'nome' => 'Atividade Relacionada Um',
        ]);
        $this->criarAcao(['uids_referencia' => ['2']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee('Atividade Relacionada Um');
    }

    public function test_painel_mostra_uid(): void
    {
        Atividade::factory()->create([
            'obra_id' => $this->obra->id,
            'external_uid' => 'uid-especifico-123',
            'codigo_cronograma' => '1.1',
            'nome' => 'Atividade X',
        ]);
        $this->criarAcao(['uids_referencia' => ['uid-especifico-123']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee('uid-especifico-123');
    }

    public function test_painel_mostra_codigo_eap(): void
    {
        Atividade::factory()->create([
            'obra_id' => $this->obra->id,
            'external_uid' => '2',
            'codigo_cronograma' => '3.4.5',
            'nome' => 'Atividade Y',
        ]);
        $this->criarAcao(['uids_referencia' => ['2']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee('3.4.5');
    }

    public function test_painel_mostra_nome_da_atividade(): void
    {
        Atividade::factory()->create([
            'obra_id' => $this->obra->id,
            'external_uid' => '2',
            'codigo_cronograma' => '1.1',
            'nome' => 'Nome Exclusivo Da Atividade',
        ]);
        $this->criarAcao(['uids_referencia' => ['2']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee('Nome Exclusivo Da Atividade');
    }

    public function test_uid_sem_atividade_correspondente_nao_quebra(): void
    {
        // Nenhuma Atividade criada com external_uid = 'uid-orfao'.
        $this->criarAcao(['uids_referencia' => ['uid-orfao']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee('uid-orfao')
            ->assertSee('não encontrada no cadastro atual');
    }

    public function test_historico_aparece_em_ordem_decrescente(): void
    {
        $acao = $this->criarAcao();
        $antigo = $this->criarReconciliacao($acao, ['resultado' => ResultadoReconciliacaoPlanoAcao::Persistente]);
        $antigo->forceFill(['created_at' => now()->subDays(5)])->save();

        $recente = $this->criarReconciliacao($acao, ['resultado' => ResultadoReconciliacaoPlanoAcao::Agravado]);
        $recente->forceFill(['created_at' => now()->subDay()])->save();

        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra]);
        $acaoCarregada = $component->instance()->acoes->first();

        $this->assertTrue($acaoCarregada->reconciliacoes->first()->is($recente));
        $this->assertTrue($acaoCarregada->reconciliacoes->last()->is($antigo));
    }

    public function test_historico_mostra_resultado(): void
    {
        $acao = $this->criarAcao();
        $this->criarReconciliacao($acao, ['resultado' => ResultadoReconciliacaoPlanoAcao::Alterado]);

        $html = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])->html();

        $this->assertStringContainsString('Alterado', $html);
    }

    public function test_historico_mostra_status_anterior_e_novo(): void
    {
        $acao = $this->criarAcao();
        $this->criarReconciliacao($acao, [
            'status_anterior' => StatusPlanoAcao::Aberta,
            'status_novo' => StatusPlanoAcao::Aberta,
        ]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSeeHtml('Aberta → Aberta');
    }

    public function test_historico_mostra_quantidades(): void
    {
        $acao = $this->criarAcao();
        $this->criarReconciliacao($acao, ['quantidade_anterior' => 3, 'quantidade_atual' => 7]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSeeHtml('3 → 7');
    }

    public function test_historico_mostra_uids_anteriores_e_atuais(): void
    {
        $acao = $this->criarAcao();
        $this->criarReconciliacao($acao, [
            'uids_anteriores' => ['uid-antigo-1'],
            'uids_atuais' => ['uid-atual-1', 'uid-atual-2'],
        ]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee('uid-antigo-1')
            ->assertSee('uid-atual-1, uid-atual-2');
    }

    public function test_links_das_importacoes_apontam_para_rota_correta(): void
    {
        $acao = $this->criarAcao();
        $novaImportacao = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'importado_em' => now()->addDay()]);
        $this->criarReconciliacao($acao, ['cronograma_importacao_id' => $novaImportacao->id]);

        $html = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])->html();

        $this->assertStringContainsString(route('radar.importacoes.show', $this->importacao), $html);
        $this->assertStringContainsString(route('radar.importacoes.show', $novaImportacao), $html);
    }

    // =========================================================================
    // ISOLAMENTO
    // =========================================================================

    public function test_acao_de_outra_obra_nao_aparece_no_painel(): void
    {
        $this->criarAcao(['titulo' => 'Da obra certa']);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraImportacao = CronogramaImportacao::create(['obra_id' => $outraObra->id, 'importado_em' => now()]);
        PlanoAcao::create([
            'obra_id' => $outraObra->id,
            'cronograma_importacao_origem_id' => $outraImportacao->id,
            'regra_id' => 'PROG-001',
            'titulo' => 'De outra obra, nunca deveria aparecer',
            'recomendacao' => 'teste',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => ['2'],
        ]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertDontSee('De outra obra, nunca deveria aparecer');
    }

    public function test_acao_de_outro_tenant_nao_aparece_no_painel(): void
    {
        $this->criarAcao(['titulo' => 'Do tenant certo']);

        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraImportacao = CronogramaImportacao::create(['obra_id' => $outraObra->id, 'importado_em' => now()]);
        PlanoAcao::create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
            'cronograma_importacao_origem_id' => $outraImportacao->id,
            'regra_id' => 'PROG-001',
            'titulo' => 'De outro tenant, nunca deveria aparecer',
            'recomendacao' => 'teste',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => ['2'],
        ]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertDontSee('De outro tenant, nunca deveria aparecer');
    }

    public function test_usuario_sem_permissao_nao_acessa_a_pagina_nem_o_painel(): void
    {
        $this->criarAcao(['titulo' => 'Não deveria vazar']);

        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($semAcesso);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    // =========================================================================
    // INTEGRIDADE
    // =========================================================================

    public function test_renderizar_painel_nao_altera_banco(): void
    {
        $acao = $this->criarAcao();
        $this->criarReconciliacao($acao);

        $antesUidsReferencia = $acao->fresh()->uids_referencia;
        $antesStatus = $acao->fresh()->status;
        $antesUpdatedAt = $acao->fresh()->updated_at;

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra]);

        $depois = $acao->fresh();
        $this->assertSame($antesUidsReferencia, $depois->uids_referencia);
        $this->assertSame($antesStatus, $depois->status);
        $this->assertEquals($antesUpdatedAt, $depois->updated_at);
    }

    public function test_historico_permanece_exatamente_igual_apos_renderizar_painel(): void
    {
        $acao = $this->criarAcao();
        $evento = $this->criarReconciliacao($acao, ['resultado' => ResultadoReconciliacaoPlanoAcao::Agravado]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra]);

        $this->assertSame(1, PlanoAcaoReconciliacao::where('plano_acao_id', $acao->id)->count());
        $eventoDepois = PlanoAcaoReconciliacao::find($evento->id);
        $this->assertSame(ResultadoReconciliacaoPlanoAcao::Agravado, $eventoDepois->resultado);
        $this->assertEquals($evento->created_at, $eventoDepois->created_at);
    }

    public function test_uids_referencia_permanece_exatamente_igual(): void
    {
        $acao = $this->criarAcao(['uids_referencia' => ['2', '3', '4']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra]);

        $this->assertSame(['2', '3', '4'], $acao->fresh()->uids_referencia);
    }

    public function test_health_check_permanece_exatamente_igual(): void
    {
        $this->criarHealthCheckComSeveridade(HealthCheckSeveridade::Critico);
        $this->criarAcao();

        $antes = $this->importacao->healthCheck->findings;

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra]);

        $depois = $this->importacao->fresh()->healthCheck->findings;
        $this->assertSame($antes, $depois);
    }

    public function test_score_permanece_exatamente_igual(): void
    {
        $this->criarHealthCheckComSeveridade(HealthCheckSeveridade::Alto);
        $this->criarAcao();

        // Score da Etapa 4/persistência: como não gravamos score nesta fixture,
        // confirma que continua null (nunca inventado por causa do painel).
        $antesScore = $this->importacao->healthCheck->score;

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra]);

        $depoisScore = $this->importacao->fresh()->healthCheck->score;
        $this->assertSame($antesScore, $depoisScore);
    }

    // =========================================================================
    // N+1 — bounded pelo tamanho da página, não pelo total de ações da obra
    // =========================================================================

    private function criarAcaoComHistoricoEAtividades(int $indice): PlanoAcao
    {
        $uid = "uid-{$indice}-" . uniqid();
        Atividade::factory()->create([
            'obra_id' => $this->obra->id,
            'external_uid' => $uid,
            'codigo_cronograma' => "1.{$indice}",
            'nome' => "Atividade {$indice}",
        ]);
        $acao = $this->criarAcao(['uids_referencia' => [$uid]]);
        $this->criarReconciliacao($acao, ['uids_anteriores' => [$uid], 'uids_atuais' => [$uid]]);

        return $acao;
    }

    /**
     * Escopo de função dedicado (não o escopo do método de teste) — cada
     * chamada usa sua PRÓPRIA variável `$queryCount` local. `DB::listen()`
     * empilha listeners globalmente sem removê-los entre chamadas; se as
     * duas medições reaproveitassem a MESMA variável do método de teste, o
     * listener da 1ª medição continuaria ativo (e somando na mesma
     * variável) durante a 2ª, dobrando a contagem e gerando um falso
     * positivo de N+1 — foi exatamente isso que aconteceu na primeira
     * versão deste teste (34 vs 72, quase o dobro). Escopos de função
     * distintos isolam cada `$queryCount`, e o listener "morto" da medição
     * anterior passa a incrementar uma variável que ninguém mais lê.
     */
    private function contarQueriesDaListagem(int $perPage): int
    {
        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])->set('perPage', $perPage);

        return $queryCount;
    }

    public function test_query_count_nao_escala_com_total_de_acoes_da_obra_apenas_com_perpage(): void
    {
        foreach (range(1, 5) as $i) {
            $this->criarAcaoComHistoricoEAtividades($i);
        }

        $queriesCom5Total = $this->contarQueriesDaListagem(5);

        foreach (range(6, 20) as $i) {
            $this->criarAcaoComHistoricoEAtividades($i);
        }

        $queriesCom20Total = $this->contarQueriesDaListagem(5);

        // perPage fixo em 5 nos dois casos — o total de ações na obra saltou de
        // 5 para 20, mas a página sempre renderiza só 5 linhas. A diferença de
        // query count entre os dois cenários deve ser pequena (variação de
        // paginação/contagem), nunca proporcional ao crescimento de 15 ações.
        $this->assertLessThan(
            $queriesCom5Total + 5,
            $queriesCom20Total,
            "esperava contagem de queries praticamente constante (perPage fixo). Com 5 no total: {$queriesCom5Total}, com 20 no total: {$queriesCom20Total}"
        );
    }
}
