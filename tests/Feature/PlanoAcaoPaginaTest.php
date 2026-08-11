<?php

namespace Tests\Feature;

use App\Enums\ResultadoReconciliacaoPlanoAcao;
use App\Enums\StatusPlanoAcao;
use App\Models\CronogramaImportacao;
use App\Models\PlanoAcao;
use App\Models\PlanoAcaoReconciliacao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 4.3, Etapa B — página Livewire `radar.plano-acao` (só listagem,
 * filtros, paginação, ordenação — sem painel/edição/histórico interativo,
 * que ficam para as etapas seguintes).
 */
class PlanoAcaoPaginaTest extends TestCase
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
            'titulo' => 'Ação de teste',
            'recomendacao' => 'teste',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => ['2'],
        ], $overrides));
    }

    // =========================================================================
    // RENDERIZAÇÃO BÁSICA / SEGURANÇA
    // =========================================================================

    public function test_pagina_renderiza_para_quem_tem_permissao(): void
    {
        $this->criarAcao();

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee('Ação de teste');
    }

    public function test_pagina_bloqueia_quem_nao_tem_permissao_na_obra(): void
    {
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($semAcesso);

        // mount() sem acesso é tratado como navegação de página cheia —
        // App\Exceptions\Handler::render() redireciona com flash.popup em
        // vez do 403 cru (mesmo padrão documentado em ImportacaoDetalheTest).
        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_pagina_bloqueia_perfil_sem_permissao_de_ver_no_escopo(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroUsuario = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($outroUsuario);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_acao_de_outra_obra_nao_aparece_na_listagem(): void
    {
        $this->criarAcao(['titulo' => 'Da obra certa']);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraImportacao = CronogramaImportacao::create(['obra_id' => $outraObra->id, 'importado_em' => now()]);
        PlanoAcao::create([
            'obra_id' => $outraObra->id,
            'cronograma_importacao_origem_id' => $outraImportacao->id,
            'regra_id' => 'PROG-001',
            'titulo' => 'De outra obra',
            'recomendacao' => 'teste',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => ['2'],
        ]);

        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra]);

        $component->assertSee('Da obra certa')->assertDontSee('De outra obra');
    }

    // =========================================================================
    // FILTROS
    // =========================================================================

    public function test_filtro_por_status(): void
    {
        $this->criarAcao(['titulo' => 'aberta 1', 'status' => StatusPlanoAcao::Aberta]);
        $this->criarAcao(['titulo' => 'resolvida 1', 'status' => StatusPlanoAcao::Resolvida]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('filtroStatus', StatusPlanoAcao::Resolvida->value)
            ->assertSee('resolvida 1')
            ->assertDontSee('aberta 1');
    }

    public function test_filtro_por_responsavel(): void
    {
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $responsavel, 'engenheiro');

        $this->criarAcao(['titulo' => 'com responsavel', 'responsavel_id' => $responsavel->id]);
        $this->criarAcao(['titulo' => 'sem responsavel']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('filtroResponsavelId', $responsavel->id)
            ->assertSee('com responsavel')
            ->assertDontSee('sem responsavel');
    }

    public function test_filtro_por_regra(): void
    {
        $this->criarAcao(['titulo' => 'regra prog', 'regra_id' => 'PROG-001']);
        $this->criarAcao(['titulo' => 'regra work', 'regra_id' => 'WORK-004']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('filtroRegraId', 'WORK-004')
            ->assertSee('regra work')
            ->assertDontSee('regra prog');
    }

    public function test_filtro_por_intervalo_de_prazo(): void
    {
        $this->criarAcao(['titulo' => 'no intervalo', 'prazo' => '2026-08-15']);
        $this->criarAcao(['titulo' => 'fora do intervalo', 'prazo' => '2026-10-01']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('filtroPrazoDe', '2026-08-01')
            ->set('filtroPrazoAte', '2026-08-31')
            ->assertSee('no intervalo')
            ->assertDontSee('fora do intervalo');
    }

    public function test_filtro_por_situacao_da_ultima_analise(): void
    {
        $agravada = $this->criarAcao(['titulo' => 'agravada']);
        PlanoAcaoReconciliacao::create([
            'plano_acao_id' => $agravada->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'resultado' => ResultadoReconciliacaoPlanoAcao::Agravado,
            'status_anterior' => StatusPlanoAcao::Aberta,
            'status_novo' => StatusPlanoAcao::Aberta,
            'uids_anteriores' => ['2'],
            'uids_atuais' => ['2', '3'],
            'quantidade_anterior' => 1,
            'quantidade_atual' => 2,
        ]);
        $this->criarAcao(['titulo' => 'sem reconciliacao']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('filtroSituacao', ResultadoReconciliacaoPlanoAcao::Agravado->value)
            ->assertSee('agravada')
            ->assertDontSee('sem reconciliacao');
    }

    public function test_filtro_sem_reconciliacao(): void
    {
        $agravada = $this->criarAcao(['titulo' => 'agravada']);
        PlanoAcaoReconciliacao::create([
            'plano_acao_id' => $agravada->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'resultado' => ResultadoReconciliacaoPlanoAcao::Agravado,
            'status_anterior' => StatusPlanoAcao::Aberta,
            'status_novo' => StatusPlanoAcao::Aberta,
            'uids_anteriores' => ['2'],
            'uids_atuais' => ['2', '3'],
            'quantidade_anterior' => 1,
            'quantidade_atual' => 2,
        ]);
        $this->criarAcao(['titulo' => 'sem reconciliacao']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('filtroSituacao', 'sem_reconciliacao')
            ->assertSee('sem reconciliacao')
            ->assertDontSee('agravada');
    }

    public function test_filtro_de_regra_e_preenchido_via_query_string(): void
    {
        // Fase 4.3, Etapa E — permite o link do Mapa de Ações
        // (⚡importacao-detalhe.blade.php) chegar aqui já filtrado pela regra.
        $this->criarAcao(['titulo' => 'da regra buscada', 'regra_id' => 'STRUCT-005']);
        $this->criarAcao(['titulo' => 'de outra regra', 'regra_id' => 'PROG-001']);

        Livewire::withQueryParams(['regra' => 'STRUCT-005'])
            ->test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSet('filtroRegraId', 'STRUCT-005')
            ->assertSee('da regra buscada')
            ->assertDontSee('de outra regra');
    }

    public function test_filtros_combinados(): void
    {
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $responsavel, 'engenheiro');

        $this->criarAcao([
            'titulo' => 'bate os dois filtros',
            'regra_id' => 'PROG-001',
            'responsavel_id' => $responsavel->id,
        ]);
        $this->criarAcao([
            'titulo' => 'so bate um filtro',
            'regra_id' => 'PROG-001',
            'responsavel_id' => null,
        ]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('filtroRegraId', 'PROG-001')
            ->set('filtroResponsavelId', $responsavel->id)
            ->assertSee('bate os dois filtros')
            ->assertDontSee('so bate um filtro');
    }

    public function test_limpar_filtros(): void
    {
        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('filtroStatus', StatusPlanoAcao::Resolvida->value)
            ->set('filtroRegraId', 'PROG-001')
            ->assertSet('filtroStatus', StatusPlanoAcao::Resolvida->value);

        $component->call('limparFiltros')
            ->assertSet('filtroStatus', '')
            ->assertSet('filtroRegraId', null);
    }

    // =========================================================================
    // PAGINAÇÃO
    // =========================================================================

    public function test_paginacao_respeita_perpage_configurado(): void
    {
        foreach (range(1, 12) as $i) {
            $this->criarAcao(['titulo' => "acao {$i}", 'uids_referencia' => ["u{$i}"]]);
        }

        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('perPage', 5);

        $this->assertCount(5, $component->instance()->acoes->items());
        $this->assertSame(12, $component->instance()->acoes->total());
    }

    public function test_paginacao_aceita_as_4_opcoes_5_10_20_50(): void
    {
        foreach (range(1, 25) as $i) {
            $this->criarAcao(['titulo' => "acao {$i}", 'uids_referencia' => ["u{$i}"]]);
        }

        foreach ([5, 10, 20, 50] as $opcao) {
            $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
                ->set('perPage', $opcao);

            $this->assertCount(min($opcao, 25), $component->instance()->acoes->items());
        }
    }

    public function test_mudar_filtro_reseta_pagina(): void
    {
        foreach (range(1, 12) as $i) {
            $this->criarAcao(['titulo' => "acao {$i}", 'uids_referencia' => ["u{$i}"]]);
        }

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('perPage', 5)
            ->call('gotoPage', 2)
            ->set('filtroStatus', StatusPlanoAcao::Aberta->value)
            ->assertSet('paginators.page', 1);
    }

    // =========================================================================
    // ORDENAÇÃO (ponta a ponta via a página, mesma regra já validada na Etapa A)
    // =========================================================================

    public function test_ordenacao_aberta_antes_de_resolvida_na_pagina(): void
    {
        $this->criarAcao(['titulo' => 'zzz resolvida', 'status' => StatusPlanoAcao::Resolvida]);
        $this->criarAcao(['titulo' => 'aaa aberta', 'status' => StatusPlanoAcao::Aberta]);

        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra]);

        $titulos = collect($component->instance()->acoes->items())->pluck('titulo')->all();

        $this->assertSame(['aaa aberta', 'zzz resolvida'], $titulos);
    }

    // =========================================================================
    // SEVERIDADE DERIVADA (exibida, nunca persistida — Seção 11)
    // =========================================================================

    public function test_severidade_derivada_e_exibida_quando_disponivel(): void
    {
        $finding = new \App\Support\HealthCheck\HealthCheckFinding(
            regraId: 'PROG-001',
            categoria: \App\Enums\HealthCheckCategoria::Avanco,
            severidade: \App\Enums\HealthCheckSeveridade::Alto,
            titulo: 'teste',
            descricao: 'teste',
            impacto: 'teste',
            recomendacao: 'teste',
            atividades: [['uid' => '2']],
        );
        \App\Models\CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $this->importacao->id]
            + \App\Models\CronogramaImportacaoHealthCheck::camposParaPersistir(
                (new \App\Support\HealthCheck\HealthCheckResultado([$finding]))->toArray()
            )
        );

        $this->criarAcao(['titulo' => 'com severidade']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertSee('Alto');
    }

    public function test_ausencia_de_severidade_nao_quebra_a_pagina(): void
    {
        $this->criarAcao(['titulo' => 'sem health check de origem']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee('sem health check de origem');
    }

    // =========================================================================
    // N+1
    // =========================================================================

    private function criarAcoesComReconciliacao(int $quantidade, User $responsavel): void
    {
        foreach (range(1, $quantidade) as $i) {
            $acao = $this->criarAcao(["uids_referencia" => ["u{$i}-" . uniqid()], 'responsavel_id' => $responsavel->id]);
            PlanoAcaoReconciliacao::create([
                'plano_acao_id' => $acao->id,
                'cronograma_importacao_id' => $this->importacao->id,
                'resultado' => ResultadoReconciliacaoPlanoAcao::Persistente,
                'status_anterior' => StatusPlanoAcao::Aberta,
                'status_novo' => StatusPlanoAcao::Aberta,
                'uids_anteriores' => ['x'],
                'uids_atuais' => ['x'],
                'quantidade_anterior' => 1,
                'quantidade_atual' => 1,
            ]);
        }
    }

    private function contarQueriesDaListagem(int $perPage = 50): int
    {
        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('perPage', $perPage);

        return $queryCount;
    }

    public function test_listagem_nao_gera_n_mais_1(): void
    {
        // perPage FIXO em 2 nas duas medições — a página sempre renderiza só 2
        // linhas, então o número de queries deve ficar praticamente constante
        // mesmo que o total de ações na obra cresça de 2 para 8. (Achado da
        // Etapa C: quando perPage cresce JUNTO com o total — como acontecia
        // aqui antes, com perPage=50 mostrando tudo — o teste conflava "mais
        // linhas na página" com "mais linhas no banco", o que deixou de ser
        // válido depois que o painel expansível da Etapa C passou a incluir,
        // por linha renderizada, 1 query bounded a `atividadesRelacionadas()`
        // — bounded por quantas linhas aparecem NA PÁGINA, não pelo total da
        // obra. Corrigido fixando perPage e crescendo só o total no banco.)
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $responsavel, 'engenheiro');

        $this->criarAcoesComReconciliacao(2, $responsavel);
        $queriesCom2NoTotal = $this->contarQueriesDaListagem(perPage: 2);

        $this->criarAcoesComReconciliacao(6, $responsavel); // total agora: 8

        $queriesCom8NoTotal = $this->contarQueriesDaListagem(perPage: 2);

        $this->assertLessThanOrEqual(
            $queriesCom2NoTotal + 5,
            $queriesCom8NoTotal,
            "esperava contagem de queries praticamente constante (perPage fixo em 2). Com 2 no total: {$queriesCom2NoTotal}, com 8 no total: {$queriesCom8NoTotal}"
        );
    }
}
