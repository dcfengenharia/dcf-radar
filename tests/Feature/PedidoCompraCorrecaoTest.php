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
use App\Enums\StatusPedidoCompra;
use App\Exceptions\FornecedorPedidoInvalidoException;
use App\Exceptions\PedidoCompraEmissaoInvalidaException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\InconsistenciaAvanco;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\PedidoCompra;
use App\Models\PlanoAcao;
use App\Models\RequisicaoCompraItem;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.5.CORREÇÃO — hardening da emissão do Pedido/OC:
 * fornecedor sempre revalidado fresh (nunca via relação já carregada,
 * nunca withTrashed) e data_prevista_entrega obrigatória na emissão.
 * Cobertura A-O do pedido de correção.
 */
class PedidoCompraCorrecaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private CriarRequisicaoPlanejamento $criarRp;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizarRp;
    private EmitirRequisicaoPlanejamento $emitirRp;
    private AlocarRequisicaoAoPacote $alocar;
    private CriarRequisicaoCompra $criarRc;
    private AtualizarRascunhoRequisicaoCompra $atualizarRc;
    private EmitirRequisicaoCompra $emitirRc;
    private CriarPedidoCompra $criarPedido;
    private AtualizarRascunhoPedidoCompra $atualizarPedido;
    private EmitirPedidoCompra $emitirPedido;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->criarRp = new CriarRequisicaoPlanejamento();
        $this->atualizarRp = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitirRp = new EmitirRequisicaoPlanejamento();
        $this->alocar = new AlocarRequisicaoAoPacote();
        $this->criarRc = new CriarRequisicaoCompra();
        $this->atualizarRc = new AtualizarRascunhoRequisicaoCompra();
        $this->emitirRc = new EmitirRequisicaoCompra();
        $this->criarPedido = new CriarPedidoCompra();
        $this->atualizarPedido = new AtualizarRascunhoPedidoCompra();
        $this->emitirPedido = new EmitirPedidoCompra();
    }

    private function criarFluxo(array $etapas): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'F' . uniqid()]);
        foreach ($etapas as $i => [$nome, $prazo]) {
            $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => $i + 1, 'nome' => $nome, 'prazo_dias_uteis' => $prazo]);
        }
        return $fluxo->fresh(['etapas']);
    }

    private function rcItemEmitido(float $quantidade, ?ItemSuprimento $pacote = null, ?Work $obra = null): RequisicaoCompraItem
    {
        $obraAlvo = $obra ?? $this->obra;
        $pacoteAlvo = $pacote ?? ItemSuprimento::create(['obra_id' => $obraAlvo->id, 'nome' => 'P' . uniqid()]);
        $doc = DocumentoEngenharia::create(['obra_id' => $obraAlvo->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item', 'quantidade' => max($quantidade, 100)]);
        $rp = $this->criarRp->execute($obraAlvo->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidade);
        $this->emitirRp->execute($rp->fresh(), $this->user);
        $alocacao = $this->alocar->alocar($rpItem->fresh(), $pacoteAlvo, $quantidade);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacoteAlvo, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);
        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        return $emitida->itens->first();
    }

    // ---- A/B/C: fornecedor ativo emite / soft-delete bloqueia / zero snapshot parcial ----

    public function test_a_fornecedor_ativo_emite_normalmente(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor Ativo']);
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-10-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);

        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->assertSame(StatusPedidoCompra::Emitido, $emitido->status);
        $this->assertSame('Fornecedor Ativo', $emitido->fornecedor_nome_snapshot);
    }

    public function test_b_c_fornecedor_soft_deletado_antes_da_emissao_bloqueia_sem_efeito_parcial(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor Sumindo']);
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-10-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);

        $fornecedor->delete();

        try {
            $this->emitirPedido->execute($pedido->fresh(), $this->user);
            $this->fail('Esperava FornecedorPedidoInvalidoException.');
        } catch (FornecedorPedidoInvalidoException $e) {
            // esperado
        }

        $final = PedidoCompra::with('itens')->findOrFail($pedido->id);
        $this->assertSame(StatusPedidoCompra::Rascunho, $final->status);
        $this->assertNull($final->numero);
        $this->assertNull($final->emitido_em);
        $this->assertNull($final->emitido_por);
        $this->assertNull($final->fornecedor_nome_snapshot);
        $this->assertNull($final->fornecedor_cnpj_snapshot);
        $this->assertNull($final->itens->first()->descricao_snapshot);
    }

    // ---- D/E: trocar fornecedor e emitir ----

    public function test_d_e_trocar_fornecedor_depois_da_falha_permite_emitir_com_snapshot_correto(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedorA = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor A']);
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedorA, '2026-10-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $fornecedorA->delete();

        try {
            $this->emitirPedido->execute($pedido->fresh(), $this->user);
        } catch (FornecedorPedidoInvalidoException $e) {
            // esperado, segue o teste
        }

        // Como fornecedor_id é imutável só depois de Emitido, e o Pedido
        // continua Rascunho, o usuário troca o fornecedor num NOVO
        // rascunho (o domínio não expõe "trocar fornecedor de um
        // rascunho existente" nesta etapa — comportamento real: cria
        // outro Pedido rascunho com o fornecedor correto).
        $fornecedorB = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor B']);
        $pedido2 = $this->criarPedido->execute($rcItem->requisicaoCompra()->first(), $fornecedorB, '2026-10-20', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido2, $rcItem->fresh(), 30);

        $emitido = $this->emitirPedido->execute($pedido2->fresh(), $this->user);

        $this->assertSame('Fornecedor B', $emitido->fornecedor_nome_snapshot);
        $this->assertNotSame('Fornecedor A', $emitido->fornecedor_nome_snapshot);
    }

    // ---- F: soft-delete depois da emissão preserva histórico (reafirmação) ----

    public function test_f_soft_delete_depois_da_emissao_preserva_historico(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor Histórico']);
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-10-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $fornecedor->delete();

        $historico = PedidoCompra::findOrFail($emitido->id);
        $this->assertSame('Fornecedor Histórico', $historico->fornecedor_nome_snapshot);
        $this->assertNotNull($historico->numero);
        $this->assertSame(StatusPedidoCompra::Emitido, $historico->status);
    }

    // ---- G/H: cross-obra/cross-tenant do fornecedor na emissão ----

    public function test_g_fornecedor_de_outra_obra_bloqueado_na_emissao(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedorMesmaObra = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'F']);
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedorMesmaObra, '2026-10-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);

        // Simula um fornecedor_id de outra obra "vazando" pro Pedido
        // (bypass hipotético da UI) — a Action precisa bloquear mesmo assim.
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $fornecedorOutraObra = Fornecedor::create(['obra_id' => $outraObra->id, 'nome' => 'F Outra Obra']);
        $pedido->forceFill(['fornecedor_id' => $fornecedorOutraObra->id])->save();

        $this->expectException(FornecedorPedidoInvalidoException::class);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);
    }

    public function test_h_fornecedor_de_outro_tenant_nunca_resolve(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'F']);
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-10-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);

        $outroTenant = Tenant::factory()->create();
        $fornecedorOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            return Fornecedor::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obra->id, 'nome' => 'F Outro Tenant']);
        });

        DB::table('pedidos_compra')->where('id', $pedido->id)->update(['fornecedor_id' => $fornecedorOutroTenant->id]);

        $this->expectException(FornecedorPedidoInvalidoException::class);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);
    }

    // ---- I/J/K/L: data prevista de entrega ----

    public function test_i_data_prevista_pode_estar_vazia_em_rascunho(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'F']);

        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, null, null, null, null, $this->user);

        $this->assertNull($pedido->data_prevista_entrega);
        $this->assertSame(StatusPedidoCompra::Rascunho, $pedido->status);
    }

    public function test_j_data_nula_bloqueia_emissao_sem_efeito_parcial(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'F']);
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, null, null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);

        try {
            $this->emitirPedido->execute($pedido->fresh(), $this->user);
            $this->fail('Esperava PedidoCompraEmissaoInvalidaException.');
        } catch (PedidoCompraEmissaoInvalidaException $e) {
            $this->assertStringContainsString('data prevista', $e->getMessage());
        }

        $final = PedidoCompra::with('itens')->findOrFail($pedido->id);
        $this->assertSame(StatusPedidoCompra::Rascunho, $final->status);
        $this->assertNull($final->numero);
        $this->assertNull($final->itens->first()->descricao_snapshot);
    }

    public function test_k_data_preenchida_permite_emissao(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'F']);
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-11-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);

        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->assertSame(StatusPedidoCompra::Emitido, $emitido->status);
        $this->assertSame('2026-11-01', $emitido->data_prevista_entrega->toDateString());
    }

    public function test_l_pedido_emitido_sempre_tem_data(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'F']);
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-11-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $todosEmitidos = PedidoCompra::where('status', StatusPedidoCompra::Emitido->value)->get();
        foreach ($todosEmitidos as $p) {
            $this->assertNotNull($p->data_prevista_entrega);
        }
    }

    // ---- M/N: regra MAX por RC, draft nunca participa (reafirmação com a nova invariante) ----

    public function test_m_n_rc_com_multiplos_pedidos_usa_max_draft_nunca_participa(): void
    {
        $rcItem = $this->rcItemEmitido(100);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'F']);
        $rc = $rcItem->requisicaoCompra;

        $p1 = $this->criarPedido->execute($rc, $fornecedor, '2026-10-12', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($p1, $rcItem, 30);
        $this->emitirPedido->execute($p1->fresh(), $this->user);

        $p2 = $this->criarPedido->execute($rc, $fornecedor, '2026-10-18', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($p2, $rcItem->fresh(), 30);
        $this->emitirPedido->execute($p2->fresh(), $this->user);

        // draft com data mais tarde — nunca deve dominar (nem pode ser
        // emitido sem consumir saldo, mas o ponto aqui é confirmar que
        // mesmo EXISTINDO como draft com data preenchida, não participa).
        $p3 = $this->criarPedido->execute($rc, $fornecedor, '2026-12-25', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($p3, $rcItem->fresh(), 10);

        $resultado = $rc->fresh(['pedidos', 'etapas'])->dataProjetadaAtendimento();
        $this->assertSame('2026-10-18', $resultado->toDateString());
    }

    // ---- O: UI com fornecedor stale mostra erro amigável, sem 500 ----

    public function test_o_ui_fornecedor_stale_mostra_toast_sem_500(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'F']);
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-10-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);

        $fornecedor->delete();

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirRcDetalhe', $rcItem->requisicao_compra_id)
            ->set('pedidoDetalheId', $pedido->id)
            ->call('emitirPedido')
            ->assertOk();

        $this->assertSame(StatusPedidoCompra::Rascunho, $pedido->fresh()->status);
    }

    // ---- zero efeito colateral após a correção ----

    public function test_zero_restricao_prontidao_inconsistencia_apos_correcao(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'F']);
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-10-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->assertSame(0, Restricao::count());
        $this->assertSame(0, PlanoAcao::count());
        $this->assertSame(0, InconsistenciaAvanco::count());
    }
}
