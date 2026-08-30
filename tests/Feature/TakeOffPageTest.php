<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\DocumentoEngenharia;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.1.CORREÇÃO — UI de Take Off com a camada de Lista
 * (LM/LI) entre Revisão e Itens. A lógica de importação em si continua
 * coberta exaustivamente em TakeOffImporterTest.php, sem duplicar aqui.
 */
class TakeOffPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private DocumentoEngenharia $documento;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $this->documento = DocumentoEngenharia::create([
            'obra_id' => $this->obra->id,
            'codigo' => 'ISO-001',
            'descricao' => 'Isometrico principal',
        ]);
        $this->documento->revisoes()->create([
            'revisao' => 'R1',
            'data_emissao' => now(),
            'descricao' => 'Emissão inicial',
        ]);
    }

    private function componente()
    {
        return Livewire::test('pages::engenharia.take-off');
    }

    public function test_usuario_sem_permissao_na_obra_nao_consegue_selecionar(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->componente()
            ->set('obraId', $outraObra->id)
            ->assertStatus(403);
    }

    public function test_selecionar_documento_resolve_revisao_vigente_automaticamente(): void
    {
        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->assertSet('documentoId', $this->documento->id)
            ->assertSet('revisaoId', $this->documento->revisaoVigente()->id)
            ->assertSet('listaId', null);
    }

    public function test_criar_lista_com_sucesso(): void
    {
        $revisao = $this->documento->revisaoVigente();

        $c = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('abrirNovaLista')
            ->set('listaFormTipo', 'material')
            ->set('listaFormCodigo', 'LM-001')
            ->set('listaFormTitulo', 'Tubulação')
            ->call('salvarLista')
            ->assertSet('listaFormAberto', false);

        $this->assertDatabaseHas('listas_engenharia', [
            'documento_engenharia_revisao_id' => $revisao->id,
            'codigo' => 'LM-001',
            'titulo' => 'Tubulação',
        ]);

        // Lista recém-criada já fica selecionada.
        $lista = ListaEngenharia::where('codigo', 'LM-001')->first();
        $c->assertSet('listaId', $lista->id);
    }

    /** Teste I (nível UI) — código de lista duplicado bloqueado com mensagem amigável, nunca exceção crua. */
    public function test_criar_lista_com_codigo_duplicado_e_bloqueado(): void
    {
        $revisao = $this->documento->revisaoVigente();
        ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('abrirNovaLista')
            ->set('listaFormTipo', 'material')
            ->set('listaFormCodigo', 'LM-001')
            ->call('salvarLista')
            ->assertHasErrors(['listaFormCodigo']);

        $this->assertSame(1, ListaEngenharia::where('codigo', 'LM-001')->count());
    }

    /** Teste H — cadastro manual de item exige lista selecionada. */
    public function test_h_abrir_novo_item_sem_lista_selecionada_e_bloqueado(): void
    {
        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('abrirNovoItem')
            ->assertStatus(400);
    }

    public function test_criar_item_manual_com_sucesso(): void
    {
        $revisao = $this->documento->revisaoVigente();
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('selecionarLista', $lista->id)
            ->call('abrirNovoItem')
            ->set('formCodigo', 'MAT-100')
            ->set('formDescricao', 'Tubo de aço')
            ->set('formQuantidade', '25.5')
            ->call('salvarItem')
            ->assertSet('itemFormAberto', false);

        $this->assertDatabaseHas('itens_take_off', [
            'lista_engenharia_id' => $lista->id,
            'codigo' => 'MAT-100',
            'descricao' => 'Tubo de aço',
            'origem' => 'manual',
        ]);
    }

    public function test_criar_item_sem_descricao_ou_quantidade_falha_validacao(): void
    {
        $revisao = $this->documento->revisaoVigente();
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('selecionarLista', $lista->id)
            ->call('abrirNovoItem')
            ->set('formDescricao', '')
            ->set('formQuantidade', '')
            ->call('salvarItem')
            ->assertHasErrors(['formDescricao', 'formQuantidade']);

        $this->assertSame(0, ItemTakeOff::count());
    }

    /** Seção 14 — quantidade zero/negativa rejeitada no cadastro manual. */
    public function test_criar_item_com_quantidade_zero_ou_negativa_falha_validacao(): void
    {
        $revisao = $this->documento->revisaoVigente();
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('selecionarLista', $lista->id)
            ->call('abrirNovoItem')
            ->set('formDescricao', 'Item inválido')
            ->set('formQuantidade', '0')
            ->call('salvarItem')
            ->assertHasErrors(['formQuantidade']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('selecionarLista', $lista->id)
            ->call('abrirNovoItem')
            ->set('formDescricao', 'Item inválido')
            ->set('formQuantidade', '-3')
            ->call('salvarItem')
            ->assertHasErrors(['formQuantidade']);

        $this->assertSame(0, ItemTakeOff::count());
    }

    public function test_codigo_de_item_duplicado_na_mesma_lista_e_bloqueado(): void
    {
        $revisao = $this->documento->revisaoVigente();
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'MAT-100', 'descricao' => 'Já existe', 'quantidade' => 1]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('selecionarLista', $lista->id)
            ->call('abrirNovoItem')
            ->set('formCodigo', 'MAT-100')
            ->set('formDescricao', 'Duplicado')
            ->set('formQuantidade', '5')
            ->call('salvarItem')
            ->assertHasErrors(['formCodigo']);

        $this->assertSame(1, ItemTakeOff::where('codigo', 'MAT-100')->count());
    }

    /** Mesmo código em LISTAS diferentes não colide (identidade é por lista). */
    public function test_mesmo_codigo_de_item_em_listas_diferentes_nao_colide(): void
    {
        $revisao = $this->documento->revisaoVigente();
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $lm2 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-002']);
        ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'MAT-100', 'descricao' => 'Em LM-001', 'quantidade' => 1]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('selecionarLista', $lm2->id)
            ->call('abrirNovoItem')
            ->set('formCodigo', 'MAT-100')
            ->set('formDescricao', 'Em LM-002')
            ->set('formQuantidade', '5')
            ->call('salvarItem')
            ->assertHasNoErrors();

        $this->assertSame(2, ItemTakeOff::where('codigo', 'MAT-100')->count());
    }

    public function test_editar_item_existente(): void
    {
        $revisao = $this->documento->revisaoVigente();
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'MAT-100', 'descricao' => 'Original', 'quantidade' => 1]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('selecionarLista', $lista->id)
            ->call('abrirEdicaoItem', $item->id)
            ->assertSet('formDescricao', 'Original')
            ->set('formDescricao', 'Corrigido')
            ->call('salvarItem');

        $this->assertSame('Corrigido', $item->fresh()->descricao);
    }

    public function test_excluir_item(): void
    {
        $revisao = $this->documento->revisaoVigente();
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'descricao' => 'A excluir', 'quantidade' => 1]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('selecionarLista', $lista->id)
            ->call('excluirItem', $item->id);

        $this->assertSoftDeleted('itens_take_off', ['id' => $item->id]);
    }

    /** Teste J (nível UI) — lista de outra obra não é selecionável. */
    public function test_j_nao_e_possivel_selecionar_lista_de_outra_obra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value);

        $outroDocumento = DocumentoEngenharia::create(['obra_id' => $outraObra->id, 'codigo' => 'X', 'descricao' => 'X']);
        $outraRevisao = $outroDocumento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão']);
        $listaDeOutraObra = ListaEngenharia::create(['documento_engenharia_revisao_id' => $outraRevisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $c = $this->componente();
        $c->set('obraId', $this->obra->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $c->call('selecionarLista', $listaDeOutraObra->id);
    }

    public function test_consolidado_mostra_apenas_itens_de_revisoes_vigentes_com_curva_abc_por_unidade(): void
    {
        $revisao = $this->documento->revisaoVigente();
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'A', 'unidade_medida_id' => null, 'quantidade' => 80]);
        ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'B', 'descricao' => 'B', 'unidade_medida_id' => null, 'quantidade' => 20]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->set('abaAtiva', 'consolidado')
            ->assertSee('A')
            ->assertSee('B')
            ->assertSee('80,000')
            ->assertSee('20,000')
            ->assertSee('LM-001');
    }

    public function test_consolidado_filtra_por_lista(): void
    {
        $revisao = $this->documento->revisaoVigente();
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $lm2 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-002']);
        ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'MAT-X', 'descricao' => 'Material X', 'quantidade' => 10]);
        ItemTakeOff::create(['lista_engenharia_id' => $lm2->id, 'codigo' => 'MAT-Y', 'descricao' => 'Material Y', 'quantidade' => 5]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->set('abaAtiva', 'consolidado')
            ->set('consolidadoListaFiltro', $lm1->id)
            ->assertSee('Material X')
            ->assertDontSee('Material Y');
    }

    // ===================== 19.1.HARDENING — congelamento histórico (UI) =====================

    /**
     * Documento PRÓPRIO e isolado pra estes testes — `$this->documento`
     * (do setUp da classe) já nasce com uma revisão "R1" datada de
     * `now()`; reaproveitá-lo faria qualquer revisão retroativa nova
     * nunca conseguir ser vigente (perderia sempre pra essa R1 já
     * existente), inviabilizando o cenário "revisão vigente -> nasce
     * uma mais nova -> a antiga congela" que estes testes precisam.
     */
    /**
     * Retorna doc+r1 com R1 AINDA vigente (nenhuma R2 criada ainda) —
     * quem chamar precisa criar a Lista/Item ENQUANTO r1 é vigente,
     * só DEPOIS criar a R2 pra congelar r1 (mesma ordem exigida pelo
     * Observer: escrever primeiro, superar depois).
     */
    private function criarDocumentoComRevisaoVigente(): array
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'HARD-' . uniqid(), 'descricao' => 'Doc hardening']);
        $r1 = $doc->revisoes()->create(['revisao' => 'RH1', 'data_emissao' => now()->subDay(), 'descricao' => 'E1']);

        return [$doc, $r1];
    }

    private function congelarComNovaRevisao(DocumentoEngenharia $doc): void
    {
        $doc->revisoes()->create(['revisao' => 'RH2', 'data_emissao' => now(), 'descricao' => 'E2']);
    }

    public function test_abrir_novo_item_em_lista_historica_mostra_toast_amigavel_sem_abrir_modal(): void
    {
        [$doc, $r1] = $this->criarDocumentoComRevisaoVigente();
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $this->congelarComNovaRevisao($doc);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $doc->id)
            ->call('selecionarLista', $lista->id)
            ->call('abrirNovoItem')
            ->assertSet('itemFormAberto', false)
            ->assertDispatched('show-toast');
    }

    public function test_salvar_lista_em_revisao_historica_mostra_toast_amigavel(): void
    {
        [$doc, $r1] = $this->criarDocumentoComRevisaoVigente();
        $this->congelarComNovaRevisao($doc);

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->set('documentoId', $doc->id);
        $c->set('revisaoId', $r1->id);
        $c->set('listaFormTipo', 'material');
        $c->set('listaFormCodigo', 'LM-NOVA');
        $c->call('salvarLista')->assertDispatched('show-toast');

        $this->assertSame(0, ListaEngenharia::where('codigo', 'LM-NOVA')->count());
    }

    public function test_excluir_item_de_lista_historica_via_ui_mostra_toast_e_preserva_item(): void
    {
        [$doc, $r1] = $this->criarDocumentoComRevisaoVigente();
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 1]);
        $this->congelarComNovaRevisao($doc);

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->set('documentoId', $doc->id);
        $c->set('listaId', $lista->id);
        $c->call('excluirItem', $item->id)->assertDispatched('show-toast');

        $this->assertNotNull(ItemTakeOff::find($item->id));
    }

    /**
     * Ciclo 19, Etapa 19.2.CORREÇÃO — excluir pela UI um item vinculado a
     * uma Requisição do Planejamento nunca vira 500: mostra toast amigável
     * e preserva o item (mesmo padrão de UX já usado pro caso de lista
     * histórica, acima).
     */
    public function test_excluir_item_vinculado_a_rp_via_ui_mostra_toast_e_preserva_item(): void
    {
        $revisao = $this->documento->revisaoVigente();
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 100]);

        $rp = (new \App\Actions\Suprimentos\CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        (new \App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, 10);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $this->documento->id)
            ->call('selecionarLista', $lista->id)
            ->call('excluirItem', $item->id)
            ->assertDispatched('show-toast');

        $this->assertNull($item->fresh()->deleted_at);
        $this->assertDatabaseHas('itens_take_off', ['id' => $item->id]);
    }

    public function test_lista_historica_esconde_botoes_de_mutacao_e_mostra_badge(): void
    {
        [$doc, $r1] = $this->criarDocumentoComRevisaoVigente();
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $this->congelarComNovaRevisao($doc);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('selecionarDocumento', $doc->id)
            ->call('selecionarLista', $lista->id)
            ->assertSee('Histórica')
            ->assertDontSee('Novo item');
    }
}
