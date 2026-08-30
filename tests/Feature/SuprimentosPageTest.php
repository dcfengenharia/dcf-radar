<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\SerieAvanco;
use App\Enums\StatusItemSuprimento;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemSuprimentoEtapaData;
use App\Models\PacoteEngenharia;
use App\Models\PerfilPermissao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\SuprimentoScheduler;
use App\Support\SincronizarRestricaoSuprimento;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SuprimentosPageTest extends TestCase
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
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    private function componente()
    {
        return Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra]);
    }

    private function criarFluxo(string $nome = 'Fluxo Teste'): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => $nome]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 5]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 2, 'nome' => 'Pedido', 'prazo_dias_uteis' => 3]);

        return $fluxo;
    }

    private function criarAtividade(string $inicio = '2026-09-01'): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => $inicio,
        ]);
    }

    public function test_pagina_nao_mostra_atividade_sem_nenhum_item_vinculado(): void
    {
        $atividade = $this->criarAtividade();

        // A tela só expõe o que já foi cadastrado aqui — atividade sem
        // item de suprimento vinculado não aparece na árvore.
        $this->componente()->assertDontSee($atividade->nome)->assertSee('Nenhum item de suprimento cadastrado');
    }

    public function test_pagina_mostra_item_assim_que_criado_sem_hierarquia_de_cronograma(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Recém-Criado',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));

        // A lista é plana — não mostra o nome da atividade/EAP por padrão,
        // só um badge com a contagem de atividades vinculadas.
        $this->componente()
            ->assertSee('Item Recém-Criado')
            ->assertDontSee($atividade->nome);
    }

    public function test_badge_de_atividades_mostra_contagem_e_popup_lista_atividades_vinculadas(): void
    {
        $fluxo = $this->criarFluxo();
        $atividadeA = $this->criarAtividade('2026-09-01');
        $atividadeB = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Fundação de Bombas',
            'inicio_planejado' => '2026-09-10',
            'data_termino' => '2026-09-20',
        ]);

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Com Duas Atividades',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach([$atividadeA->id, $atividadeB->id]));

        $this->componente()
            ->assertSee('Item Com Duas Atividades')
            ->assertDontSee($atividadeB->nome)
            ->call('abrirAtividadesVinculadas', $item->id)
            ->assertSee($atividadeA->nome)
            ->assertSee($atividadeB->nome)
            ->assertSee('20/09/2026');
    }

    public function test_criar_item_gera_etapas_e_previsto_congelado_para_a_atividade_vinculada(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();

        $this->componente()
            ->call('abrirModalCriar', $atividade->id)
            ->set('nomeNovo', 'Cimento CP-V')
            ->set('fluxoIdNovo', $fluxo->id)
            ->call('salvarItem')
            ->assertHasNoErrors();

        $item = ItemSuprimento::where('nome', 'Cimento CP-V')->firstOrFail();

        $this->assertTrue($item->atividades->contains('id', $atividade->id));
        $this->assertSame(2, $item->etapas()->count());
        $this->assertSame(2, ItemSuprimentoEtapaData::whereIn('item_suprimento_etapa_id', $item->etapas()->pluck('id'))
            ->where('serie', SerieAvanco::Previsto->value)->count());
    }

    public function test_criar_item_sem_atividade_selecionada_falha_validacao(): void
    {
        $fluxo = $this->criarFluxo();

        $this->componente()
            ->call('abrirModalCriar')
            ->set('nomeNovo', 'Item sem atividade')
            ->set('fluxoIdNovo', $fluxo->id)
            ->call('salvarItem')
            ->assertHasErrors(['atividadesIdsNovo']);

        $this->assertSame(0, ItemSuprimento::where('nome', 'Item sem atividade')->count());
    }

    public function test_editar_item_removendo_atividade_resolve_a_restricao_daquele_par(): void
    {
        $fluxo = $this->criarFluxo();
        $atividadeA = $this->criarAtividade('2026-09-01');
        $atividadeB = $this->criarAtividade('2026-09-15');

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Compartilhado',
        ]);
        TenantContext::actingAs($this->tenant, function () use ($item, $atividadeA, $atividadeB) {
            $item->atividades()->attach([$atividadeA->id, $atividadeB->id]);
        });
        $item = $item->fresh(['atividades']);

        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($item);
        $scheduler->congelarPrevisto($item);

        // Força o item pra Atrasado, gerando restrição bloqueante nas duas atividades.
        $primeiraEtapa = $item->etapas()->orderBy('ordem')->firstOrFail();
        ItemSuprimentoEtapaData::create([
            'tenant_id' => $this->tenant->id,
            'item_suprimento_etapa_id' => $primeiraEtapa->id,
            'serie' => SerieAvanco::Realizado->value,
            'data' => '2026-09-10',
        ]);
        TenantContext::actingAs($this->tenant, fn () => SincronizarRestricaoSuprimento::sincronizarItem($item, null));

        $this->assertSame(2, Restricao::where('origem_suprimento_item_id', $item->id)
            ->where('status', StatusRestricao::Aberta->value)->count());

        $this->componente()
            ->call('abrirModalEditar', $item->id)
            ->set('atividadesIdsNovo', [$atividadeB->id])
            ->call('salvarItem')
            ->assertHasNoErrors();

        $restricaoA = Restricao::where('atividade_id', $atividadeA->id)->where('origem_suprimento_item_id', $item->id)->firstOrFail();
        $restricaoB = Restricao::where('atividade_id', $atividadeB->id)->where('origem_suprimento_item_id', $item->id)->firstOrFail();

        $this->assertSame(StatusRestricao::Resolvida, $restricaoA->status);
        $this->assertSame(StatusRestricao::Aberta, $restricaoB->status);
        $this->assertFalse($item->fresh()->atividades->contains('id', $atividadeA->id));
    }

    public function test_salvar_realizado_no_detalhe_recalcula_tendencia_e_status(): void
    {
        $fluxo = $this->criarFluxo();
        // Necessidade bem distante de "hoje" (relativa a now(), nunca uma
        // string de data absoluta) — o status EmAndamento/EmRisco em
        // SuprimentoScheduler::calcularStatus() compara a tendência
        // recalculada contra Carbon::today(), então uma data fixa no
        // passado (ex.: '2026-09-01') fica cada vez mais perto de "hoje"
        // a cada dia que passa e eventualmente cruza o limiar de risco,
        // quebrando o teste sem nenhuma mudança de comportamento real.
        $atividade = $this->criarAtividade(now()->addDays(90)->toDateString());

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Realizado',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));
        $item = $item->fresh(['atividades']);

        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($item);
        $scheduler->congelarPrevisto($item);

        $etapaCotacao = $item->etapas()->where('nome', 'Cotação')->firstOrFail();

        $this->componente()
            ->call('abrirDetalhe', $item->id)
            ->set("realizadoForm.{$etapaCotacao->id}", now()->toDateString())
            ->call('salvarRealizado')
            ->assertHasNoErrors();

        $this->assertNotNull($etapaCotacao->datas()->where('serie', SerieAvanco::Realizado->value)->first());
        $this->assertSame(StatusItemSuprimento::EmAndamento, $item->fresh()->status);
    }

    public function test_filtro_por_status_mostra_so_itens_correspondentes(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();

        $itemNoInicio = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item No Início',
            'status' => StatusItemSuprimento::NoInicio->value,
        ]);
        TenantContext::actingAs($this->tenant, fn () => $itemNoInicio->atividades()->attach($atividade->id));

        $itemConcluido = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Concluído',
            'status' => StatusItemSuprimento::Concluido->value,
        ]);
        TenantContext::actingAs($this->tenant, fn () => $itemConcluido->atividades()->attach($atividade->id));

        $this->componente()
            ->set('statusFiltro', StatusItemSuprimento::Concluido->value)
            ->assertSee('Item Concluído')
            ->assertDontSee('Item No Início');
    }

    public function test_busca_por_nome_do_item(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Chapa Metálica Especial',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));

        $outroItem = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Cimento Portland',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $outroItem->atividades()->attach($atividade->id));

        $this->componente()
            ->set('search', 'Chapa')
            ->assertSee('Chapa Metálica Especial')
            ->assertDontSee('Cimento Portland');
    }

    public function test_excluir_item_resolve_restricoes_abertas_e_remove_o_item(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade('2026-09-01');

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Pra Excluir',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));
        $item = $item->fresh(['atividades']);

        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($item);
        $scheduler->congelarPrevisto($item);

        $primeiraEtapa = $item->etapas()->orderBy('ordem')->firstOrFail();
        ItemSuprimentoEtapaData::create([
            'tenant_id' => $this->tenant->id,
            'item_suprimento_etapa_id' => $primeiraEtapa->id,
            'serie' => SerieAvanco::Realizado->value,
            'data' => '2026-09-10',
        ]);
        TenantContext::actingAs($this->tenant, fn () => SincronizarRestricaoSuprimento::sincronizarItem($item, null));

        $this->assertSame(1, Restricao::where('origem_suprimento_item_id', $item->id)
            ->where('status', StatusRestricao::Aberta->value)->count());

        $this->componente()->call('excluirItem', $item->id)->assertHasNoErrors();

        $this->assertSoftDeleted('itens_suprimento', ['id' => $item->id]);
        $this->assertSame(0, Restricao::where('origem_suprimento_item_id', $item->id)
            ->where('status', StatusRestricao::Aberta->value)->count());
    }

    public function test_vincula_documentos_de_engenharia_ao_criar_item(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();
        $pacote = PacoteEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Pacote Estrutural']);
        $documento = DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'pacote_engenharia_id' => $pacote->id, 'descricao' => 'Projeto Executivo']);

        $this->componente()
            ->call('abrirModalCriar', $atividade->id)
            ->set('nomeNovo', 'Item Com Documento')
            ->set('fluxoIdNovo', $fluxo->id)
            ->set('documentosIdsNovo', [$documento->id])
            ->call('salvarItem')
            ->assertHasNoErrors();

        $item = ItemSuprimento::where('nome', 'Item Com Documento')->firstOrFail();
        $this->assertTrue($item->documentosEngenharia->contains('id', $documento->id));
    }

    public function test_perfil_sem_permissao_nao_consegue_criar_editar_excluir(): void
    {
        $atividade = $this->criarAtividade();
        $fluxo = $this->criarFluxo();

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Qualquer',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));

        $outroUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfil = $this->vincularObra($this->obra, $outroUser, Papel::ClienteLeitura->value);
        PerfilPermissao::where('perfil_id', $perfil->id)->where('funcionalidade', 'suprimentos.mapa')->delete();
        $this->actingAs($outroUser);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalCriar')
            ->assertForbidden();

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalEditar', $item->id)
            ->assertForbidden();

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('excluirItem', $item->id)
            ->assertForbidden();
    }

    public function test_lista_mostra_apenas_as_colunas_pedidas_sem_fornecedor(): void
    {
        // Pedido do usuário: nome+fluxo, atividades, farol, necessidade,
        // tendência (colorida), desvio, ações — SEM coluna de Fornecedor
        // (essa informação fica só no detalhe do item). O nome do
        // fornecedor ainda aparece no offcanvas de filtros (não é bug —
        // filtrar por fornecedor continua existindo), então a checagem
        // é sobre a coluna da tabela, não sobre o nome do fornecedor.
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();
        $fornecedor = Fornecedor::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Fornecedor XPTO']);

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'fornecedor_id' => $fornecedor->id,
            'nome' => 'Item Sem Fornecedor Na Lista',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));

        $this->componente()
            ->assertSee('Item Sem Fornecedor Na Lista')
            ->assertSee($fluxo->nome)
            ->assertDontSee('>Fornecedor<', false);
    }

    public function test_lista_mostra_badge_de_status_simplificada(): void
    {
        $fluxo = $this->criarFluxo();

        $atividadeAtrasada = $this->criarAtividade();
        $itemAtrasado = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Atrasado Badge',
            'status' => StatusItemSuprimento::Atrasado->value,
        ]);
        TenantContext::actingAs($this->tenant, fn () => $itemAtrasado->atividades()->attach($atividadeAtrasada->id));

        $atividadeOk = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => '2026-10-01',
        ]);
        $itemEmDia = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Em Dia Badge',
            'status' => StatusItemSuprimento::EmAndamento->value,
        ]);
        TenantContext::actingAs($this->tenant, fn () => $itemEmDia->atividades()->attach($atividadeOk->id));

        $atividadeConcluida = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => '2026-10-01',
        ]);
        $itemConcluido = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Concluido Badge',
            'status' => StatusItemSuprimento::Concluido->value,
        ]);
        TenantContext::actingAs($this->tenant, fn () => $itemConcluido->atividades()->attach($atividadeConcluida->id));

        $html = $this->componente()->html();

        $this->assertStringContainsString('Atrasado', $html);
        $this->assertStringContainsString('Em dia', $html);
        $this->assertStringContainsString('Concluído', $html);
    }

    public function test_lista_mostra_barra_de_progresso_das_etapas_do_item(): void
    {
        $fluxo = $this->criarFluxo(); // 2 etapas: Cotação, Pedido
        $atividade = $this->criarAtividade();

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Com Barra De Progresso',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));
        $item = $item->fresh(['atividades']);
        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($item);
        $scheduler->congelarPrevisto($item);

        $etapaCotacao = $item->etapas()->where('nome', 'Cotação')->firstOrFail();
        ItemSuprimentoEtapaData::updateOrCreate(
            ['item_suprimento_etapa_id' => $etapaCotacao->id, 'serie' => SerieAvanco::Realizado->value],
            ['tenant_id' => $this->tenant->id, 'data' => '2026-06-01']
        );

        $html = $this->componente()->html();

        $this->assertStringContainsString('progress-bar', $html);
        $this->assertStringContainsString('width: 50%', $html);
    }

    public function test_celula_de_etapa_atrasada_recebe_cor_de_fundo_vermelha(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade('2026-09-01');

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Atrasado Visual',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));
        $item = $item->fresh(['atividades']);
        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($item);
        $scheduler->congelarPrevisto($item);

        // Tendência da Cotação já passou (hoje é 2026-07-13 no ambiente de
        // testes) e não tem Realizado — deve pintar a célula de vermelho.
        $etapaCotacao = $item->etapas()->where('nome', 'Cotação')->firstOrFail();
        \App\Models\ItemSuprimentoEtapaData::updateOrCreate(
            ['item_suprimento_etapa_id' => $etapaCotacao->id, 'serie' => \App\Enums\SerieAvanco::Tendencia->value],
            ['tenant_id' => $this->tenant->id, 'data' => '2026-06-01']
        );

        $html = $this->componente()->set('fluxoIdFiltro', $fluxo->id)->html();

        $this->assertStringContainsString('bg-danger', $html);
    }

    public function test_celula_de_etapa_em_risco_nao_faz_lazy_load_da_obra(): void
    {
        // Regressão: estadoEtapa() chamava DiasUteisCalculator::paraObra($item->obra)
        // sem a relação 'obra' eager-loaded em itensFiltrados(), o que quebrava
        // com LazyLoadingViolationException assim que uma etapa caía no ramo
        // "em risco" (Tendência dentro da janela de 5 dias úteis) — o teste de
        // "atrasado" não pegava isso porque retorna antes de chegar nessa linha.
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade('2026-09-01');

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Em Risco Visual',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));
        $item = $item->fresh(['atividades']);
        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($item);
        $scheduler->congelarPrevisto($item);

        $etapaCotacao = $item->etapas()->where('nome', 'Cotação')->firstOrFail();
        ItemSuprimentoEtapaData::updateOrCreate(
            ['item_suprimento_etapa_id' => $etapaCotacao->id, 'serie' => SerieAvanco::Tendencia->value],
            ['tenant_id' => $this->tenant->id, 'data' => now()->addWeekday()->format('Y-m-d')]
        );

        $html = $this->componente()->set('fluxoIdFiltro', $fluxo->id)->html();

        $this->assertStringContainsString('bg-warning-subtle', $html);
    }

    public function test_farol_emoji_reflete_status_do_item(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Concluído Teste',
            'status' => StatusItemSuprimento::Concluido->value,
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));

        $this->componente()->assertSee('✅')->assertSee('Item Concluído Teste');
    }

    public function test_farol_no_inicio_usa_emoji_de_ampulheta_nao_esfera(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item No Início Teste',
            'status' => StatusItemSuprimento::NoInicio->value,
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));

        $html = $this->componente()->html();

        $this->assertStringContainsString('⏳', $html);
        $this->assertStringNotContainsString('⚪', $html);
    }

    public function test_icone_de_comentario_so_aparece_na_lista_quando_ha_comentarios(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Sem Comentario Ainda',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));

        $this->componente()->assertDontSee('bx-comment-detail', false);

        TenantContext::actingAs(
            $this->tenant,
            fn () => $item->comentarios()->create(['tenant_id' => $this->tenant->id, 'autor_id' => $this->user->id, 'comentario' => 'Aguardando aprovação do cliente.'])
        );

        $this->componente()->assertSee('bx-comment-detail', false);
    }

    public function test_adicionar_comentario_no_detalhe_do_item(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Para Comentar',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));

        $this->componente()
            ->call('abrirDetalhe', $item->id)
            ->set('comentarioNovo', 'Fornecedor confirmou prazo de entrega.')
            ->call('adicionarComentario')
            ->assertHasNoErrors()
            ->assertSee('Fornecedor confirmou prazo de entrega.');

        $this->assertSame(1, $item->comentarios()->count());
    }

    public function test_stepper_de_historico_mostra_progresso_proporcional_as_etapas(): void
    {
        $fluxo = $this->criarFluxo(); // 2 etapas: Cotação, Pedido
        $atividade = $this->criarAtividade();

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Com Progresso',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));
        $item = $item->fresh(['atividades']);
        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($item);
        $scheduler->congelarPrevisto($item);

        $etapaCotacao = $item->etapas()->where('nome', 'Cotação')->firstOrFail();
        ItemSuprimentoEtapaData::updateOrCreate(
            ['item_suprimento_etapa_id' => $etapaCotacao->id, 'serie' => SerieAvanco::Realizado->value],
            ['tenant_id' => $this->tenant->id, 'data' => '2026-06-01']
        );

        // 1 de 2 etapas concluídas = 50%, mostrado na barra de progresso do topo.
        $this->componente()
            ->call('abrirDetalhe', $item->id)
            ->assertSee('50%');
    }

    public function test_timeline_de_etapas_mostra_autor_da_baixa_na_etapa_concluida(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Com Autor Na Timeline',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));
        $item = $item->fresh(['atividades']);
        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($item);
        $scheduler->congelarPrevisto($item);

        $etapaCotacao = $item->etapas()->where('nome', 'Cotação')->firstOrFail();
        ItemSuprimentoEtapaData::updateOrCreate(
            ['item_suprimento_etapa_id' => $etapaCotacao->id, 'serie' => SerieAvanco::Realizado->value],
            ['tenant_id' => $this->tenant->id, 'data' => '2026-06-01', 'atualizado_por' => $this->user->id]
        );

        $this->componente()
            ->call('abrirDetalhe', $item->id)
            ->assertSee(trim("{$this->user->first_name} {$this->user->last_name}"));
    }

    public function test_fornecedor_selecionavel_no_modal_de_criacao(): void
    {
        $fluxo = $this->criarFluxo();
        $atividade = $this->criarAtividade();
        $fornecedor = Fornecedor::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Fornecedor XPTO']);

        $this->componente()
            ->call('abrirModalCriar', $atividade->id)
            ->set('nomeNovo', 'Item Com Fornecedor')
            ->set('fluxoIdNovo', $fluxo->id)
            ->set('fornecedorIdNovo', $fornecedor->id)
            ->call('salvarItem')
            ->assertHasNoErrors();

        $item = ItemSuprimento::where('nome', 'Item Com Fornecedor')->firstOrFail();
        $this->assertSame($fornecedor->id, $item->fornecedor_id);
    }

    /**
     * Ciclo 19, Etapa 19.3 — coluna "Demanda" (badge de RPs alocadas) e a
     * seção "Demanda do Planejamento" do modal de detalhe, ambas leitura
     * somente, nunca quebram a renderização da página legada.
     */
    public function test_pagina_mostra_demanda_do_planejamento_quando_pacote_tem_alocacao(): void
    {
        $documento = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'ISO-001', 'descricao' => 'Isometrico']);
        $revisao = $documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = \App\Models\ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $itemTakeOff = \App\Models\ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'Tubo de Aço', 'quantidade' => 100]);

        $rp = (new \App\Actions\Suprimentos\CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new \App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $itemTakeOff->id, 60);
        (new \App\Actions\Suprimentos\EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        $pacote = ItemSuprimento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Pacote Tubulação']);
        (new \App\Actions\Suprimentos\AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 40);

        $c = $this->componente();
        $c->assertSee('1 RP');

        $c->call('abrirDetalhe', $pacote->id)
            ->assertSee('Demanda do Planejamento')
            ->assertSee('Tubo de Aço')
            ->assertDontSee('Sem demanda formal do Planejamento alocada');
    }

    public function test_pagina_mostra_sem_demanda_quando_pacote_nao_tem_alocacao(): void
    {
        $pacote = ItemSuprimento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Pacote Sem Demanda']);

        $this->componente()
            ->call('abrirDetalhe', $pacote->id)
            ->assertSee('Sem demanda formal do Planejamento alocada');
    }
}
