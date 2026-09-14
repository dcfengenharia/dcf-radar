<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusPedidoCompra;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Material;
use App\Models\PedidoCompra;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Etapa 2.CORREÇÃO — UI mínima pra atribuir a ponte explícita de
 * proveniência (`PedidoCompraItemAdjudicacao`) sem precisar chamar a
 * Action diretamente — o mesmo fluxo já testado em
 * `AdjudicacaoProvenienciaFechamentoTest`, agora ponta a ponta pela tela
 * real de Suprimentos.
 */
class SuprimentosProvenienciaAdjudicacaoUiTest extends TestCase
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

    private function cenarioRcEmitidaComAdjudicacao(): array
    {
        $unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
        $material = Material::create([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Válvula',
            'unidade_medida_id' => $unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'Documento']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $ito = ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Válvula 2"',
            'unidade_medida_id' => $unidade->id, 'quantidade' => 100, 'material_id' => $material->id,
        ]);

        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);

        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, 100);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 100);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        $rc = (new CriarRequisicaoCompra())->execute($pacote, $fluxo, null, $this->user);
        $rcItem = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rc = $rc->fresh();
        $rcItem = $rcItem->fresh();

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid(), 'cnpj' => '00.000.000/0001-00']);
        $adjudicacao = (new CriarAdjudicacaoRequisicaoCompra())->execute($rc, $fornecedor, 'Decisão de compra', null, null, $this->user);
        $itemAdj = (new AtualizarAdjudicacaoRequisicaoCompra())->adicionarItem($adjudicacao, $rcItem, null, 100);

        return [$rc, $rcItem, $fornecedor, $itemAdj, $pacote];
    }

    public function test_painel_de_origem_aparece_quando_adjudicacao_esta_ativa_e_pendente(): void
    {
        [$rc, $rcItem, $fornecedor, , $pacote] = $this->cenarioRcEmitidaComAdjudicacao();

        $component = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->call('abrirRcDetalhe', $rc->id)
            ->set('pedidoFornecedorIdNovo', $fornecedor->id)
            ->set('pedidoDataPrevistaEntregaNovo', '2027-03-01')
            ->call('criarPedidoRascunho');

        $pedido = PedidoCompra::where('requisicao_compra_id', $rc->id)->firstOrFail();

        $component->set('pedidoItemRcItemIdNovo', $rcItem->id)
            ->set('pedidoItemQuantidadeNovo', '100')
            ->call('adicionarItemPedido');

        $component->assertSee('Origem da Adjudicação');

        $this->assertSame(StatusPedidoCompra::Rascunho, $pedido->fresh()->status);
    }

    public function test_atribuir_origem_pela_ui_permite_emitir_o_pedido(): void
    {
        [$rc, $rcItem, $fornecedor, $itemAdj, $pacote] = $this->cenarioRcEmitidaComAdjudicacao();

        $component = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->call('abrirRcDetalhe', $rc->id)
            ->set('pedidoFornecedorIdNovo', $fornecedor->id)
            ->set('pedidoDataPrevistaEntregaNovo', '2027-03-01')
            ->call('criarPedidoRascunho');

        $pedido = PedidoCompra::where('requisicao_compra_id', $rc->id)->firstOrFail();

        $component->set('pedidoItemRcItemIdNovo', $rcItem->id)
            ->set('pedidoItemQuantidadeNovo', '100')
            ->call('adicionarItemPedido');

        $pedidoItem = $pedido->fresh()->itens()->firstOrFail();

        // Emitir sem atribuir a origem é bloqueado (mensagem amigável,
        // nunca 500) — mesma exceção coberta em Action isolada.
        $component->call('emitirPedido');
        $this->assertSame(StatusPedidoCompra::Rascunho, $pedido->fresh()->status);

        $component->set('pedidoProvenienciaAdjudicacaoItemIdNovo', $itemAdj->id)
            ->set('pedidoProvenienciaQuantidadeNovo', '100')
            ->call('atribuirProvenienciaAdjudicacao', 'item', $pedidoItem->id);

        $this->assertEmpty(array_filter(
            $component->instance()->pedidoItensExigemProveniencia(),
            fn ($linha) => $linha['saldo_a_atribuir'] > 0.0005
        ));

        $component->call('emitirPedido');

        $this->assertSame(StatusPedidoCompra::Emitido, $pedido->fresh()->status);
        $this->assertEquals(100.0, $itemAdj->fresh()->quantidadeConsumidaViaBridge());
    }
}
