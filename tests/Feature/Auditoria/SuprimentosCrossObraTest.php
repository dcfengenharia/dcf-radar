<?php

namespace Tests\Feature\Auditoria;

use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\Papel;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\PedidoCompraItem;
use App\Models\RecebimentoPedido;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A1, TEN-01/TEN-02 — prova adversarial: um usuário
 * com permissão SÓ na Obra A (a obra da página que está visualizando) não
 * pode manipular nem ler dado de um Pacote/Recebimento da Obra B do mesmo
 * tenant, mesmo forjando o ID no payload do Livewire.
 */
class SuprimentosCrossObraTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obraA;
    private Work $obraB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obraA = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // Permissão SÓ na Obra A — nunca vinculado à Obra B.
        $this->vincularObra($this->obraA, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    private function criarPacoteNaObraB(): ItemSuprimento
    {
        return ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obraB->id,
            'nome' => 'Pacote da Obra B',
        ]);
    }

    public function test_ten01_excluir_pacote_de_outra_obra_por_id_forjado_e_negado_e_registro_permanece_intacto(): void
    {
        $pacoteB = $this->criarPacoteNaObraB();

        // Componente é montado NA OBRA A (contexto legítimo do usuário) —
        // mas o método é chamado com o ID de um pacote da Obra B, como um
        // payload manipulado faria. O resolver agora escopado por obra_id
        // não encontra o registro e propaga ModelNotFoundException (mesmo
        // comportamento de qualquer resolver ...DaObraAtual() do arquivo,
        // que dentro do harness de Livewire::test() propaga como exceção
        // crua em vez de virar 404 HTTP — não é regressão, é a garantia
        // de segurança funcionando).
        try {
            Livewire::test('pages::radar.suprimentos', ['obra' => $this->obraA])
                ->call('excluirItem', $pacoteB->id);
            $this->fail('TEN-01: esperava ModelNotFoundException ao tentar excluir pacote de outra obra por ID forjado.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // esperado
        }

        $this->assertNotNull(
            ItemSuprimento::find($pacoteB->id),
            'TEN-01: pacote de outra obra do mesmo tenant não pode ser excluído por ID forjado.'
        );
    }

    public function test_ten01_excluir_pacote_da_propria_obra_continua_funcionando(): void
    {
        $pacoteA = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obraA->id,
            'nome' => 'Pacote da Obra A',
        ]);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obraA])
            ->call('excluirItem', $pacoteA->id);

        $this->assertNull(ItemSuprimento::find($pacoteA->id));
    }

    /**
     * Cadeia completa TakeOff -> RP -> Pacote -> RC -> Pedido -> Recebimento
     * na OBRA B, construída por um ator com autoridade lá (nunca
     * $this->user, que deliberadamente só tem acesso à Obra A).
     */
    private function pedidoItemEmitidoNaObraB(): PedidoCompraItem
    {
        $adminObraB = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraB, $adminObraB, Papel::GerentePlanejamento->value);

        $doc = DocumentoEngenharia::create(['obra_id' => $this->obraB->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $itemTakeOff = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item A', 'quantidade' => 100]);

        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obraB->id, null, $adminObraB->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $itemTakeOff->id, 100);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $adminObraB);

        $pacoteB = $this->criarPacoteNaObraB();
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacoteB, 100);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        $rc = (new CriarRequisicaoCompra())->execute($pacoteB, $fluxo, null, $adminObraB);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 50);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $adminObraB);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $this->obraB->id, 'nome' => 'Fornecedor B', 'cnpj' => '00.000.000/0001-00']);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $adminObraB);
        $item = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, 50);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $adminObraB);

        return $item->fresh();
    }

    public function test_ten02_painel_de_distribuicao_de_recebimento_de_outra_obra_nunca_popula_dados(): void
    {
        $itemPedidoB = $this->pedidoItemEmitidoNaObraB();

        $adminObraB = User::where('tenant_id', $this->tenant->id)->where('id', '!=', $this->user->id)->firstOrFail();
        $recebimentoB = RecebimentoPedido::create([
            'tenant_id' => $this->tenant->id,
            'pedido_compra_item_id' => $itemPedidoB->id,
            'quantidade_recebida' => 10,
            'recebido_em' => '2026-11-01',
            'registrado_por' => $adminObraB->id,
        ]);

        $componente = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obraA])
            ->call('abrirDistribuicaoRecebimento', $recebimentoB->id);

        // O computed nunca deve resolver/expor o recebimento de outra obra.
        $painel = $componente->instance()->distribuicaoRecebimentoAberto();
        $this->assertNull($painel, 'TEN-02: recebimento de outra obra do mesmo tenant nunca deveria ser exposto no painel.');
    }
}
