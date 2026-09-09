<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Melhoria targeted — diagnóstico e tratamento da liberação no Plano
 * Semanal (popup "Liberação para Programação" + filtro por Liberação).
 *
 * Regra autoritativa auditada: Atividade::scopeProntas()/estaPronta() —
 * a ÚNICA definição de liberado/bloqueado do projeto inteiro. Estes
 * testes nunca reimplementam essa regra; só verificam que o Plano
 * Semanal a expõe/consome corretamente, sem duplicá-la.
 */
class PlanoSemanalLiberacaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private string $semanaInicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Engenheiro->value);
        $this->actingAs($this->user);

        $this->semanaInicio = Carbon::now()->startOfWeek()->toDateString();
    }

    private function componente()
    {
        return Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra]);
    }

    private function atividadeNaSemana(array $overrides = []): Atividade
    {
        $inicioSemana = Carbon::parse($this->semanaInicio);

        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana->copy()->addDay(),
            'data_termino' => $inicioSemana->copy()->addDays(3),
        ], $overrides));
    }

    // =========================================================================
    // A — todos os itens de prontidão atendidos e sem bloqueio → LIBERADA
    // =========================================================================
    public function test_a_atividade_sem_bloqueio_e_prontidao_completa_e_liberada(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Liberada']);

        $item = ItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Projeto Executivo',
            'ordem' => 1,
        ]);
        AtividadeItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $at->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
        ]);

        $this->assertTrue($at->estaPronta());

        $this->componente()
            ->assertSeeInOrder(['Atividade Liberada'])
            ->call('verAtividadeDetalhe', $at->id)
            ->assertSee('Liberada')
            ->assertSee('sem restrição bloqueante em aberto, checklist de prontidão completo');
    }

    // =========================================================================
    // B — item de prontidão pendente → BLOQUEADA, popup mostra exatamente o
    // item pendente
    // =========================================================================
    public function test_b_item_de_prontidao_pendente_bloqueia_e_popup_mostra_o_item(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Prontidao Pendente']);

        ItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'nome' => 'PBR', 'ordem' => 1,
        ]);
        ItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'nome' => 'JSA', 'ordem' => 2,
        ]);
        // Nenhum registro em atividade_itens_prontidao — os dois ficam pendentes.

        $this->assertFalse($at->estaPronta());

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertSee('Bloqueada')
            ->assertSee('Checklist de prontidão incompleto (0/2)')
            ->assertSee('PBR')
            ->assertSee('JSA');
    }

    // =========================================================================
    // C — restrição bloqueante aberta → BLOQUEADA, popup mostra a restrição
    // =========================================================================
    public function test_c_restricao_bloqueante_aberta_bloqueia_e_popup_mostra_a_restricao(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Com Restricao Bloqueante']);
        Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
            'descricao' => 'Liberação de área pendente',
        ]);

        $this->assertFalse($at->estaPronta());

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertSee('Bloqueada')
            ->assertSee('1 restrição(ões) bloqueante(s) em aberto')
            ->assertSee('Liberação de área pendente')
            ->assertSee('Bloqueante');
    }

    // =========================================================================
    // D — restrição NÃO bloqueante aberta não bloqueia por si só
    // =========================================================================
    public function test_d_restricao_nao_bloqueante_aberta_nao_bloqueia_sozinha(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Restricao Nao Bloqueante']);
        Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => false,
            'descricao' => 'Observação de campo, sem bloqueio',
        ]);

        $this->assertTrue($at->estaPronta());

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertSee('Liberada')
            ->assertSee('Outras restrições em aberto (não bloqueantes)')
            ->assertSee('Observação de campo, sem bloqueio');
    }

    // =========================================================================
    // E — resolver pendência válida pelo popup recalcula o estado
    // =========================================================================
    public function test_e_marcar_item_de_prontidao_pelo_popup_recalcula_liberacao(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Vira Liberada']);
        $item = ItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'nome' => 'Procedimento', 'ordem' => 1,
        ]);

        $this->assertFalse($at->fresh()->estaPronta());

        $componente = $this->componente()->call('verAtividadeDetalhe', $at->id);
        $componente->assertSee('Bloqueada');

        $componente->call('marcarItemNaDetalhe', $at->id, $item->id, true);

        $this->assertTrue($at->fresh()->estaPronta());
        $componente->assertSee('Liberada');

        $this->assertDatabaseHas('atividade_itens_prontidao', [
            'atividade_id' => $at->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
            'concluido_por' => $this->user->id,
        ]);
    }

    public function test_e2_dar_baixa_em_restricao_bloqueante_pelo_popup_recalcula_liberacao(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Restricao Resolvida']);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);

        $this->assertFalse($at->fresh()->estaPronta());

        $componente = $this->componente()->call('verAtividadeDetalhe', $at->id);
        $componente
            ->call('abrirModalBaixa', $restricao->id)
            ->set('dataBaixaNova', now()->toDateString())
            ->call('darBaixaRestricao');

        $this->assertEquals(StatusRestricao::Resolvida, $restricao->fresh()->status);
        $this->assertTrue($at->fresh()->estaPronta());
        $componente->assertSee('Liberada');
    }

    // =========================================================================
    // F/G/H — filtro Liberadas/Bloqueadas usa a MESMA fonte do KPI
    // =========================================================================
    public function test_fgh_filtro_liberadas_bloqueadas_usa_mesma_definicao_do_kpi(): void
    {
        $liberada1 = $this->atividadeNaSemana(['nome' => 'Liberada Um']);
        $liberada2 = $this->atividadeNaSemana(['nome' => 'Liberada Dois']);
        $bloqueada1 = $this->atividadeNaSemana(['nome' => 'Bloqueada Um']);
        $bloqueada2 = $this->atividadeNaSemana(['nome' => 'Bloqueada Dois']);
        $bloqueada3 = $this->atividadeNaSemana(['nome' => 'Bloqueada Tres']);

        foreach ([$bloqueada1, $bloqueada2, $bloqueada3] as $b) {
            Restricao::factory()->create([
                'tenant_id' => $this->obra->tenant_id,
                'atividade_id' => $b->id,
                'status' => StatusRestricao::Aberta->value,
                'bloqueante' => true,
            ]);
        }

        $componente = $this->componente();

        // H — filtro e KPI usam a mesma definição (idsProntas): 2 liberadas / 3 bloqueadas.
        $totalSemRestricao = $componente->instance()->totalSemRestricao;
        $totalComRestricao = $componente->instance()->totalComRestricao;
        $this->assertSame(2, $totalSemRestricao);
        $this->assertSame(3, $totalComRestricao);

        // F — filtro "Liberadas" deixa só as liberadas.
        $componente->set('liberacaoFiltro', 'liberadas')
            ->assertSee('Liberada Um')
            ->assertSee('Liberada Dois')
            ->assertDontSee('Bloqueada Um')
            ->assertDontSee('Bloqueada Dois')
            ->assertDontSee('Bloqueada Tres');

        // G — filtro "Bloqueadas" deixa só as bloqueadas.
        $componente->set('liberacaoFiltro', 'bloqueadas')
            ->assertDontSee('Liberada Um')
            ->assertDontSee('Liberada Dois')
            ->assertSee('Bloqueada Um')
            ->assertSee('Bloqueada Dois')
            ->assertSee('Bloqueada Tres');

        // "Todas" volta a mostrar tudo.
        $componente->set('liberacaoFiltro', 'todas')
            ->assertSee('Liberada Um')
            ->assertSee('Bloqueada Um');
    }

    public function test_h2_filtro_liberacao_nunca_altera_ppc_nem_kpis_de_periodo(): void
    {
        $comprometida = $this->atividadeNaSemana([
            'nome' => 'Comprometida PPC',
            'status' => StatusAtividade::Comprometido->value,
        ]);
        $this->atividadeNaSemana(['nome' => 'Bloqueada PPC']);
        Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $comprometida->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);

        $componente = $this->componente();
        $ppcAntes = $componente->instance()->ppc;
        $totalAntes = $componente->instance()->totalAtividadesPeriodo;

        $componente->set('liberacaoFiltro', 'liberadas');
        $this->assertSame($ppcAntes, $componente->instance()->ppc);
        $this->assertSame($totalAntes, $componente->instance()->totalAtividadesPeriodo);

        $componente->set('liberacaoFiltro', 'bloqueadas');
        $this->assertSame($ppcAntes, $componente->instance()->ppc);
        $this->assertSame($totalAntes, $componente->instance()->totalAtividadesPeriodo);
    }

    // =========================================================================
    // I — atividade CONCLUÍDA não vira candidata indevida a Programar
    // =========================================================================
    public function test_i_atividade_concluida_nao_e_liberada_nem_bloqueada_no_popup(): void
    {
        $at = $this->atividadeNaSemana([
            'nome' => 'Atividade Ja Concluida',
            'status' => StatusAtividade::Concluido->value,
            'concluido_em' => now(),
        ]);
        // Mesmo com item de prontidão pendente, concluída nunca deve
        // aparecer como "Bloqueada" (reconciliação de conclusão importada,
        // Ciclo 24) nem como candidata a Programar.
        ItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
            'nome' => 'Item Pendente', 'ordem' => 1,
        ]);

        $componente = $this->componente();
        $this->assertNotContains($at->id, $componente->instance()->idsSelecionaveis);

        $componente->call('verAtividadeDetalhe', $at->id)
            ->assertSee('Concluída')
            ->assertDontSee('Liberação não se aplica') // texto real é "liberação não se aplica" minúsculo — checagem abaixo
            ->assertSee('liberação não se aplica');
    }

    // =========================================================================
    // J — isolamento por tenant/obra
    // =========================================================================
    public function test_j_atividade_de_outra_obra_nunca_resolve_no_popup(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $atOutraObra = Atividade::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $outraObra->id,
            'nome' => 'Atividade De Outra Obra',
        ]);

        $componente = $this->componente()->call('verAtividadeDetalhe', $atOutraObra->id);
        $this->assertNull($componente->instance()->atividadeDetalhePlano);
        $componente->assertDontSee('Atividade De Outra Obra');
    }

    public function test_j2_atividade_de_outro_tenant_nunca_resolve_no_popup(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $atOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outraObraOutroTenant) {
            return Atividade::factory()->create([
                'tenant_id' => $outraObraOutroTenant->tenant_id,
                'obra_id' => $outraObraOutroTenant->id,
                'nome' => 'Atividade De Outro Tenant',
            ]);
        });

        $componente = $this->componente()->call('verAtividadeDetalhe', $atOutroTenant->id);
        $this->assertNull($componente->instance()->atividadeDetalhePlano);
    }

    // =========================================================================
    // K — Policies das ações reutilizadas
    // =========================================================================
    public function test_k_usuario_sem_permissao_nao_pode_criar_restricao(): void
    {
        $at = $this->atividadeNaSemana();
        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertDontSee('Nova Restrição');

        $this->componente()
            ->call('abrirModalRestricao', $at->id)
            ->assertForbidden();
    }

    public function test_k2_usuario_sem_permissao_nao_pode_dar_baixa(): void
    {
        $at = $this->atividadeNaSemana();
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);

        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertDontSee('Dar baixa');

        $this->componente()
            ->call('abrirModalBaixa', $restricao->id)
            ->assertForbidden();
    }

    public function test_k3_criar_restricao_pelo_popup_com_permissao_funciona(): void
    {
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalRestricao', $at->id)
            ->set('descricaoNova', 'Nova restrição criada pelo Plano Semanal')
            ->call('salvarRestricao');

        $this->assertDatabaseHas('restricoes', [
            'atividade_id' => $at->id,
            'descricao' => 'Nova restrição criada pelo Plano Semanal',
        ]);
    }

    // =========================================================================
    // L — popup não gera N+1 relevante
    // =========================================================================
    public function test_l_popup_nao_gera_n_mais_1_com_varias_restricoes_e_itens(): void
    {
        $atPoucos = $this->atividadeNaSemana(['nome' => 'Atividade Poucos']);
        Restricao::factory()->count(2)->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atPoucos->id,
            'status' => StatusRestricao::Aberta->value,
        ]);
        for ($i = 1; $i <= 2; $i++) {
            ItemProntidao::create([
                'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
                'nome' => "Item Poucos {$i}", 'ordem' => $i,
            ]);
        }

        DB::enableQueryLog();
        $this->componente()->call('verAtividadeDetalhe', $atPoucos->id)->assertOk();
        $queriesPoucos = count(DB::getQueryLog());
        DB::flushQueryLog();

        $atMuitos = $this->atividadeNaSemana(['nome' => 'Atividade Muitos']);
        Restricao::factory()->count(15)->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atMuitos->id,
            'status' => StatusRestricao::Aberta->value,
        ]);
        for ($i = 1; $i <= 15; $i++) {
            $item = ItemProntidao::create([
                'tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id,
                'nome' => "Item Muitos {$i}", 'ordem' => $i + 10,
            ]);
            AtividadeItemProntidao::create([
                'tenant_id' => $this->obra->tenant_id,
                'atividade_id' => $atMuitos->id,
                'item_prontidao_id' => $item->id,
                'concluido' => true,
            ]);
        }

        // Flush aqui é essencial: sem isso, as ~15+15+15 queries de
        // criação de fixture acima (Restricao/ItemProntidao/
        // AtividadeItemProntidao) entrariam na contagem do popup, comparando
        // "custo de abrir o popup" com "custo de abrir o popup + montar o
        // cenário" — nunca uma medição justa.
        DB::flushQueryLog();
        $this->componente()->call('verAtividadeDetalhe', $atMuitos->id)->assertOk();
        $queriesMuitos = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // 15x mais restrições/itens não pode custar 15x mais queries — o
        // popup usa eager-load em lote (with()), nunca 1 query por linha.
        $this->assertLessThanOrEqual($queriesPoucos + 5, $queriesMuitos,
            "Esperado custo praticamente constante; poucos={$queriesPoucos} muitos={$queriesMuitos}");
    }

    // =========================================================================
    // Performance de carregamento normal — abrir a tela não deve eager-load
    // relações pesadas de restrições/prontidão/GED pra TODAS as atividades
    // (item 10 do pedido).
    // =========================================================================
    public function test_carregamento_normal_nao_faz_eager_load_pesado_por_atividade(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $at = $this->atividadeNaSemana(['nome' => "Atividade Lista {$i}"]);
            Restricao::factory()->create([
                'tenant_id' => $this->obra->tenant_id,
                'atividade_id' => $at->id,
                'status' => StatusRestricao::Aberta->value,
                'bloqueante' => true,
            ]);
        }

        DB::enableQueryLog();
        $this->componente()->assertOk();
        $queries20 = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        for ($i = 21; $i <= 60; $i++) {
            $at = $this->atividadeNaSemana(['nome' => "Atividade Lista {$i}"]);
            Restricao::factory()->create([
                'tenant_id' => $this->obra->tenant_id,
                'atividade_id' => $at->id,
                'status' => StatusRestricao::Aberta->value,
                'bloqueante' => true,
            ]);
        }

        DB::enableQueryLog();
        $this->componente()->assertOk();
        $queries60 = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // 3x mais atividades não pode triplicar as queries — scopeProntas()
        // já é uma única query agregada (idsProntas), não N+1.
        $this->assertLessThanOrEqual($queries20 + 10, $queries60,
            "Esperado custo praticamente constante; 20={$queries20} 60={$queries60}");
    }
}
