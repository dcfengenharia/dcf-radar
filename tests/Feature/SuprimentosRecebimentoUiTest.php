<?php

namespace Tests\Feature;

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
use App\Models\AlocacaoRequisicaoPacote;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.6 — UI de Recebimento Físico dentro de
 * ⚡suprimentos.blade.php (dentro do detalhe do item de um Pedido Emitido).
 */
class SuprimentosRecebimentoUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        // Ciclo 19, Etapa 19.6.CORREÇÃO — recebido_em > hoje agora é
        // bloqueado; as datas literais desta suíte (out-nov/2026)
        // precisam de um "hoje" fixo sempre posterior a elas.
        Carbon::setTestNow(Carbon::parse('2026-12-15'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedidoItemEmitido(float $quantidade, ?Work $obra = null): PedidoCompraItem
    {
        $obraAlvo = $obra ?? $this->obra;
        $pacote = ItemSuprimento::create(['obra_id' => $obraAlvo->id, 'nome' => 'Pacote X' . uniqid()]);

        $doc = DocumentoEngenharia::create(['obra_id' => $obraAlvo->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $itemTo = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item A', 'quantidade' => 1000]);

        $rp = (new CriarRequisicaoPlanejamento())->execute($obraAlvo->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $itemTo->id, max($quantidade, 100));
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, max($quantidade, 100));

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Recebimento UI ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($pacote, $fluxo, null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = Fornecedor::create(['obra_id' => $obraAlvo->id, 'nome' => 'Fornecedor X', 'cnpj' => '00.000.000/0001-00']);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $item = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return $item->fresh();
    }

    public function test_registrar_recebimento_via_ui_reflete_parcial(): void
    {
        $item = $this->pedidoItemEmitido(100);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $item->pedidoCompra->requisicaoCompra->item_suprimento_id)
            ->call('abrirRcDetalhe', $item->pedidoCompra->requisicao_compra_id)
            ->call('abrirPedidoDetalhe', $item->pedido_compra_id)
            ->call('abrirRecebimentoItem', $item->id)
            ->set('recebimentoQuantidadeNova', '40')
            ->set('recebimentoDataNova', '2026-10-15')
            ->call('registrarRecebimento', $item->id)
            ->assertSee('Parcialmente recebido');

        $this->assertSame(1, RecebimentoPedido::where('pedido_compra_item_id', $item->id)->count());
        $this->assertEqualsWithDelta(40.0, $item->fresh()->quantidadeRecebida(), 0.001);
    }

    public function test_registrar_recebimento_via_ui_ate_completar(): void
    {
        $item = $this->pedidoItemEmitido(50);

        $component = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $item->pedidoCompra->requisicaoCompra->item_suprimento_id)
            ->call('abrirRcDetalhe', $item->pedidoCompra->requisicao_compra_id)
            ->call('abrirPedidoDetalhe', $item->pedido_compra_id)
            ->call('abrirRecebimentoItem', $item->id)
            ->set('recebimentoQuantidadeNova', '50')
            ->set('recebimentoDataNova', '2026-10-15')
            ->call('registrarRecebimento', $item->id);

        $component->assertSee('Entrega completa')->assertSee('Recebido');

        $this->assertEqualsWithDelta(0.0, $item->fresh()->saldoAReceber(), 0.001);
    }

    public function test_historico_mostra_todos_os_eventos_nao_so_o_estado_final(): void
    {
        $item = $this->pedidoItemEmitido(100);

        $component = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $item->pedidoCompra->requisicaoCompra->item_suprimento_id)
            ->call('abrirRcDetalhe', $item->pedidoCompra->requisicao_compra_id)
            ->call('abrirPedidoDetalhe', $item->pedido_compra_id)
            ->call('abrirRecebimentoItem', $item->id)
            ->set('recebimentoQuantidadeNova', '20')
            ->set('recebimentoDataNova', '2026-10-15')
            ->call('registrarRecebimento', $item->id)
            ->call('abrirRecebimentoItem', $item->id)
            ->set('recebimentoQuantidadeNova', '30')
            ->set('recebimentoDataNova', '2026-10-18')
            ->call('registrarRecebimento', $item->id)
            ->call('abrirRecebimentoItem', $item->id);

        $component->assertSee('15/10/2026')->assertSee('18/10/2026');
    }

    public function test_over_recebimento_via_ui_mostra_toast_sem_500(): void
    {
        $item = $this->pedidoItemEmitido(50);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $item->pedidoCompra->requisicaoCompra->item_suprimento_id)
            ->call('abrirRcDetalhe', $item->pedidoCompra->requisicao_compra_id)
            ->call('abrirPedidoDetalhe', $item->pedido_compra_id)
            ->call('abrirRecebimentoItem', $item->id)
            ->set('recebimentoQuantidadeNova', '999')
            ->set('recebimentoDataNova', '2026-10-15')
            ->call('registrarRecebimento', $item->id)
            ->assertOk()
            ->assertDispatched('show-toast');

        $this->assertSame(0, RecebimentoPedido::where('pedido_compra_item_id', $item->id)->count());
    }

    // ---- O: usuário sem permissão ----

    public function test_usuario_sem_permissao_editar_nao_ve_botao_registrar(): void
    {
        $item = $this->pedidoItemEmitido(50);

        $clienteLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $clienteLeitura, Papel::ClienteLeitura->value);
        $this->actingAs($clienteLeitura);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $item->pedidoCompra->requisicaoCompra->item_suprimento_id)
            ->call('abrirRcDetalhe', $item->pedidoCompra->requisicao_compra_id)
            ->call('abrirPedidoDetalhe', $item->pedido_compra_id)
            ->assertDontSee('bx-truck', false);
    }

    public function test_usuario_sem_permissao_editar_chamada_direta_bloqueada(): void
    {
        $item = $this->pedidoItemEmitido(50);

        $clienteLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $clienteLeitura, Papel::ClienteLeitura->value);
        $this->actingAs($clienteLeitura);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->set('recebimentoQuantidadeNova', '10')
            ->set('recebimentoDataNova', '2026-10-15')
            ->call('registrarRecebimento', $item->id)
            ->assertForbidden();

        $this->assertSame(0, RecebimentoPedido::where('pedido_compra_item_id', $item->id)->count());
    }

    // ---- P7 — cross-obra: usuário com acesso às duas obras não recebe material de obra errada ----

    public function test_cross_obra_com_acesso_as_duas_obras_bloqueado_pelo_resolver(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);

        $itemObraB = $this->pedidoItemEmitido(50, $outraObra);

        // Ciclo 19, achado de teste já documentado (19.1 e diante):
        // ModelNotFoundException lançada dentro de uma chamada de método
        // Livewire nem sempre vira resposta HTTP 404 observável via
        // assertStatus() — o teste real é a exceção em si, garantia de
        // que o item de outra obra é literalmente inalcançável.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        // Componente montado pra OBRA A (this->obra) — tenta registrar
        // recebimento de um item que pertence à OBRA B.
        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->set('recebimentoQuantidadeNova', '10')
            ->set('recebimentoDataNova', '2026-10-15')
            ->call('registrarRecebimento', $itemObraB->id);

        $this->assertSame(0, RecebimentoPedido::where('pedido_compra_item_id', $itemObraB->id)->count());
    }
}
