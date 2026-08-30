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
use App\Actions\Suprimentos\RegistrarConclusaoEtapaRequisicaoCompra;
use App\Enums\Papel;
use App\Enums\StatusPedidoCompra;
use App\Exceptions\PedidoCompraEmissaoInvalidaException;
use App\Exceptions\PedidoCompraImutavelException;
use App\Exceptions\SaldoRequisicaoCompraInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\InconsistenciaAvanco;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\PedidoCompra;
use App\Models\PlanoAcao;
use App\Models\RequisicaoCompra;
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
 * Ciclo 19, Etapa 19.5 — Pedido/Ordem de Compra. Cobertura A-AK do
 * pedido (condensada em cenários representativos).
 */
class PedidoCompraTest extends TestCase
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
    private RegistrarConclusaoEtapaRequisicaoCompra $concluirEtapa;
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
        $this->concluirEtapa = new RegistrarConclusaoEtapaRequisicaoCompra();
        $this->criarPedido = new CriarPedidoCompra();
        $this->atualizarPedido = new AtualizarRascunhoPedidoCompra();
        $this->emitirPedido = new EmitirPedidoCompra();
    }

    private function criarPacote(string $nome = 'Pacote X', ?Work $obra = null): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => $nome, 'codigo' => $nome . uniqid()]);
    }

    private function criarFornecedor(string $nome = 'Fornecedor X', ?Work $obra = null): Fornecedor
    {
        return Fornecedor::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => $nome, 'cnpj' => '00.000.000/0001-00']);
    }

    private function criarFluxo(array $etapas): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Pedido Teste']);
        foreach ($etapas as $indice => [$nome, $prazo]) {
            $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => $indice + 1, 'nome' => $nome, 'prazo_dias_uteis' => $prazo]);
        }
        return $fluxo->fresh(['etapas']);
    }

    private function alocacaoPronta(float $quantidadeAlocada, ?ItemSuprimento $pacote = null, ?Work $obra = null, float $quantidadePrevista = 1000): AlocacaoRequisicaoPacote
    {
        $obraAlvo = $obra ?? $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obraAlvo->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item A', 'quantidade' => $quantidadePrevista]);
        $rp = $this->criarRp->execute($obraAlvo->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidadeAlocada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $this->alocar->alocar($rpItem->fresh(), $pacote ?? $this->criarPacote(obra: $obraAlvo), $quantidadeAlocada);
    }

    /** RC Emitida pronta com 1 item, quantidade dada. */
    private function rcItemEmitido(float $quantidade, ?ItemSuprimento $pacote = null, ?Work $obra = null): RequisicaoCompraItem
    {
        $pacoteAlvo = $pacote ?? $this->criarPacote(obra: $obra);
        $alocacao = $this->alocacaoPronta(max($quantidade, 100), $pacoteAlvo, $obra);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacoteAlvo, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);
        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);

        return $emitida->itens->first();
    }

    // ---- A/B/C: cardinalidade ----

    public function test_a_criar_pedido_rascunho(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor();

        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);

        $this->assertSame(StatusPedidoCompra::Rascunho, $pedido->status);
        $this->assertNull($pedido->numero);
    }

    public function test_b_rc_pode_gerar_n_pedidos(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor();

        $p1 = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $p2 = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);

        $this->assertSame(2, PedidoCompra::where('requisicao_compra_id', $rcItem->requisicao_compra_id)->count());
        $this->assertNotSame($p1->id, $p2->id);
    }

    public function test_c_pedido_so_pode_usar_item_da_propria_rc(): void
    {
        $rcItemA = $this->rcItemEmitido(60);
        $rcItemB = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor();
        $pedidoDeA = $this->criarPedido->execute($rcItemA->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);

        $this->expectException(\InvalidArgumentException::class);
        $this->atualizarPedido->adicionarItem($pedidoDeA, $rcItemB, 10);
    }

    public function test_c2_criar_pedido_sobre_rc_rascunho_e_bloqueado(): void
    {
        $pacote = $this->criarPacote();
        $rcRascunho = $this->criarRc->execute($pacote, null, null, $this->user);
        $fornecedor = $this->criarFornecedor();

        $this->expectException(PedidoCompraEmissaoInvalidaException::class);
        $this->criarPedido->execute($rcRascunho, $fornecedor, '2026-12-01', null, null, null, $this->user);
    }

    // ---- D/E/F: itens ----

    public function test_d_adicionar_item_ao_pedido(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);

        $item = $this->atualizarPedido->adicionarItem($pedido, $rcItem, 40);

        $this->assertSame('40.000', (string) $item->quantidade_pedida);
    }

    public function test_e_pedido_parcial(): void
    {
        $rcItem = $this->rcItemEmitido(100);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);

        $item = $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);

        $this->assertSame('30.000', (string) $item->quantidade_pedida);
        // Ainda Rascunho: saldo OFICIAL continua cheio (mesma filosofia
        // de 19.4.CORREÇÃO — draft nunca consome saldo oficial).
        $this->assertSame(100.0, $rcItem->fresh()->saldoOficialParaPedido());

        $this->emitirPedido->execute($pedido->fresh(), $this->user);
        $this->assertSame(70.0, $rcItem->fresh()->saldoOficialParaPedido());
    }

    // ---- G/H: saldo, over-pedido ----

    public function test_g_saldo_dentro_de_um_unico_pedido(): void
    {
        $rcItem = $this->rcItemEmitido(100);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);

        $this->expectException(SaldoRequisicaoCompraInsuficienteException::class);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 101);
    }

    public function test_h_over_pedido_bloqueado_entre_dois_pedidos_emitidos(): void
    {
        $rcItem = $this->rcItemEmitido(100);
        $fornecedor = $this->criarFornecedor();

        $pedidoA = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedidoA, $rcItem, 60);
        $this->emitirPedido->execute($pedidoA->fresh(), $this->user);

        $pedidoB = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedidoB, $rcItem->fresh(), 40);
        $this->emitirPedido->execute($pedidoB->fresh(), $this->user);

        $pedidoC = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->expectException(SaldoRequisicaoCompraInsuficienteException::class);
        $this->atualizarPedido->adicionarItem($pedidoC, $rcItem->fresh(), 1);
    }

    // ---- I/J: drafts sobrepostos, emissão stale ----

    public function test_i_drafts_sobrepostos_coexistem(): void
    {
        $rcItem = $this->rcItemEmitido(100);
        $fornecedor = $this->criarFornecedor();

        $pedidoA = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedidoA, $rcItem, 80);

        $pedidoB = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $itemB = $this->atualizarPedido->adicionarItem($pedidoB, $rcItem->fresh(), 80);

        $this->assertNotNull($itemB->id);
        $this->assertSame(100.0, $rcItem->fresh()->saldoOficialParaPedido());
    }

    public function test_j_emissao_stale_revalida_saldo(): void
    {
        $rcItem = $this->rcItemEmitido(100);
        $fornecedor = $this->criarFornecedor();

        $pedidoA = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedidoA, $rcItem, 70);
        $pedidoB = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedidoB, $rcItem->fresh(), 70);

        $this->emitirPedido->execute($pedidoA->fresh(), $this->user);

        $this->expectException(SaldoRequisicaoCompraInsuficienteException::class);
        $this->emitirPedido->execute($pedidoB->fresh(), $this->user);
    }

    // ---- K: concorrência (prova estrutural sequencial, mesmo padrão já aceito) ----

    public function test_k_lock_disputado_na_mesma_linha_rcitem(): void
    {
        $rcItem = $this->rcItemEmitido(100);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);

        $sqls = [];
        DB::listen(function ($q) use (&$sqls) {
            if (str_contains(strtolower($q->sql), 'for update') && str_contains($q->sql, 'requisicao_compra_itens')) {
                $sqls[] = $q->sql;
            }
        });

        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);

        $this->assertGreaterThanOrEqual(1, count($sqls));
    }

    // ---- L/M/N: fornecedor ----

    public function test_l_fornecedor_e_associado_ao_pedido(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor('Aço Forte Ltda');
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);

        $this->assertSame($fornecedor->id, $pedido->fornecedor_id);
    }

    public function test_m_fornecedor_de_outra_obra_rejeitado(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $fornecedorOutraObra = $this->criarFornecedor('X', $outraObra);

        $this->expectException(\InvalidArgumentException::class);
        $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedorOutraObra, '2026-12-01', null, null, null, $this->user);
    }

    public function test_n_fornecedor_soft_deletado_apos_emissao_pedido_historico_continua_integro(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor('Fornecedor Sumido');
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $fornecedor->delete();

        $historico = PedidoCompra::findOrFail($emitido->id);
        $this->assertSame('Fornecedor Sumido', $historico->fornecedor_nome_snapshot);
        $this->assertNotNull($historico->numero);
    }

    // ---- O/P: numeração ----

    public function test_o_numeracao_sequencial_por_obra_rascunho_nao_consome(): void
    {
        $rcItem1 = $this->rcItemEmitido(100);
        $fornecedor = $this->criarFornecedor();

        $rascunho = $this->criarPedido->execute($rcItem1->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->assertNull($rascunho->numero);

        $p1 = $this->criarPedido->execute($rcItem1->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($p1, $rcItem1, 30);
        $emitido1 = $this->emitirPedido->execute($p1->fresh(), $this->user);
        $this->assertSame(1, $emitido1->numero);

        $rcItem2 = $this->rcItemEmitido(100);
        $p2 = $this->criarPedido->execute($rcItem2->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($p2, $rcItem2, 30);
        $emitido2 = $this->emitirPedido->execute($p2->fresh(), $this->user);
        $this->assertSame(2, $emitido2->numero);

        $this->assertNull($rascunho->fresh()->numero);
    }

    public function test_p_duas_obras_reiniciam_numeracao_em_1(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);

        $rcItemA = $this->rcItemEmitido(100);
        $fornecedorA = $this->criarFornecedor('A');
        $pA = $this->criarPedido->execute($rcItemA->requisicaoCompra, $fornecedorA, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pA, $rcItemA, 30);
        $emitidoA = $this->emitirPedido->execute($pA->fresh(), $this->user);

        $rcItemB = $this->rcItemEmitido(100, obra: $outraObra);
        $fornecedorB = $this->criarFornecedor('B', $outraObra);
        $pB = $this->criarPedido->execute($rcItemB->requisicaoCompra, $fornecedorB, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pB, $rcItemB, 30);
        $emitidoB = $this->emitirPedido->execute($pB->fresh(), $this->user);

        $this->assertSame(1, $emitidoA->numero);
        $this->assertSame(1, $emitidoB->numero);
    }

    // ---- Q/R: snapshots, imutabilidade ----

    public function test_q_emissao_congela_snapshots(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor('Nome Original');
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $fornecedor->update(['nome' => 'Nome Alterado Depois']);
        $rcItem->update(['descricao_snapshot' => 'Descrição Alterada Depois']);

        $historico = PedidoCompra::with('itens')->findOrFail($emitido->id);
        $this->assertSame('Nome Original', $historico->fornecedor_nome_snapshot);
        $this->assertNotSame('Descrição Alterada Depois', $historico->itens->first()->descricao_snapshot);
    }

    public function test_r_pedido_emitido_e_imutavel(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->expectException(PedidoCompraImutavelException::class);
        $this->atualizarPedido->alterarQuantidade($emitido->itens->first(), 50);
    }

    // ---- S/T/U/V/W: previsão de entrega, necessidade, folga ----

    public function test_s_data_prevista_entrega_e_registrada(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-09-15', null, null, null, $this->user);

        $this->assertSame('2026-09-15', $pedido->data_prevista_entrega->toDateString());
    }

    public function test_t_data_projetada_atendimento_usa_pedido_emitido_mais_tarde(): void
    {
        $rcItem = $this->rcItemEmitido(100);
        $fornecedor = $this->criarFornecedor();
        $rc = $rcItem->requisicaoCompra;

        $p1 = $this->criarPedido->execute($rc, $fornecedor, '2026-09-10', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($p1, $rcItem, 30);
        $this->emitirPedido->execute($p1->fresh(), $this->user);

        $p2 = $this->criarPedido->execute($rc, $fornecedor, '2026-09-25', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($p2, $rcItem->fresh(), 30);
        $this->emitirPedido->execute($p2->fresh(), $this->user);

        // rascunho não emitido nunca entra na conta, mesmo com data mais tarde
        $p3 = $this->criarPedido->execute($rc, $fornecedor, '2026-12-31', null, null, null, $this->user);

        $this->assertSame('2026-09-25', $rc->fresh(['pedidos'])->dataProjetadaAtendimento()->toDateString());
    }

    public function test_t2_sem_pedido_emitido_cai_para_fim_previsto_do_processo(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $rc = $rcItem->requisicaoCompra()->with('etapas')->first();

        $this->assertEquals($rc->fimPrevisto()?->toDateString(), $rc->dataProjetadaAtendimento()?->toDateString());
    }

    public function test_u_folga_positiva(): void
    {
        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'inicio_planejado' => '2026-10-30',
        ]);
        $pacote->atividades()->attach($atividade->id);

        $rcItem = $this->rcItemEmitido(60, $pacote);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-09-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $folga = $pacote->fresh(['atividades', 'requisicoesCompra.pedidos'])->folgaAtendimento();
        $this->assertNotNull($folga);
        $this->assertGreaterThan(0, $folga);
    }

    public function test_v_folga_negativa(): void
    {
        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'inicio_planejado' => '2026-09-01',
        ]);
        $pacote->atividades()->attach($atividade->id);

        $rcItem = $this->rcItemEmitido(60, $pacote);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-10-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $folga = $pacote->fresh(['atividades', 'requisicoesCompra.pedidos'])->folgaAtendimento();
        $this->assertNotNull($folga);
        $this->assertLessThan(0, $folga);
    }

    public function test_w_reprogramacao_muda_folga_sem_alterar_pedido(): void
    {
        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'inicio_planejado' => '2026-10-30',
        ]);
        $pacote->atividades()->attach($atividade->id);

        $rcItem = $this->rcItemEmitido(60, $pacote);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-09-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $folgaAntes = $pacote->fresh(['atividades', 'requisicoesCompra.pedidos'])->folgaAtendimento();

        $atividade->update(['inicio_planejado' => '2026-09-01']);

        $folgaDepois = $pacote->fresh(['atividades', 'requisicoesCompra.pedidos'])->folgaAtendimento();

        $this->assertNotEquals($folgaAntes, $folgaDepois);
        $this->assertSame('2026-09-15', $emitido->fresh()->data_prevista_entrega->toDateString());
    }

    // ---- X/Y: delete ----

    public function test_x_pedido_emitido_nao_pode_ser_excluido(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->expectException(PedidoCompraImutavelException::class);
        $emitido->delete();
    }

    public function test_y_rascunho_delete_libera_saldo(): void
    {
        $rcItem = $this->rcItemEmitido(100);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 80);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('excluirPedidoRascunho', $pedido->id);

        $this->assertSame(100.0, $rcItem->fresh()->saldoOficialParaPedido());

        $novoPedido = $this->criarPedido->execute($rcItem->requisicaoCompra()->first(), $fornecedor, '2026-12-01', null, null, null, $this->user);
        $item = $this->atualizarPedido->adicionarItem($novoPedido, $rcItem->fresh(), 100);
        $this->assertNotNull($item->id);
    }

    // ---- Z/AA: cross-obra/cross-tenant ----

    public function test_z_criar_pedido_para_rc_de_outra_obra_a_partir_da_pagina_errada_bloqueado(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);
        $rcItemB = $this->rcItemEmitido(60, obra: $outraObra);
        $fornecedorB = $this->criarFornecedor('B', $outraObra);
        $pedidoB = $this->criarPedido->execute($rcItemB->requisicaoCompra, $fornecedorB, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedidoB, $rcItemB, 30);

        try {
            Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
                ->set('pedidoDetalheId', $pedidoB->id)
                ->call('emitirPedido');
            $this->fail('Esperava ModelNotFoundException.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // esperado
        }

        $this->assertSame(StatusPedidoCompra::Rascunho, $pedidoB->fresh()->status);
    }

    public function test_aa_cross_tenant_pedido_nao_visivel(): void
    {
        $pedidoOutroTenant = \App\Support\TenantContext::actingAs(Tenant::factory()->create(), function () {
            $tenant = \App\Models\Tenant::first();
            $user = User::factory()->create(['tenant_id' => $tenant->id]);
            $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
            $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'X', 'descricao' => 'X']);
            $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
            $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM-X']);
            $itemTo = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'X', 'descricao' => 'X', 'quantidade' => 100]);
            $rp = (new CriarRequisicaoPlanejamento())->execute($obra->id, null, $user->id);
            $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $itemTo->id, 100);
            (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $user);
            $pacote = ItemSuprimento::create(['obra_id' => $obra->id, 'nome' => 'X']);
            $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 100);
            $fluxo = FluxoSuprimento::create(['tenant_id' => $tenant->id, 'nome' => 'F']);
            $fluxo->etapas()->create(['tenant_id' => $tenant->id, 'ordem' => 1, 'nome' => 'C', 'prazo_dias_uteis' => 1]);
            $rc = (new CriarRequisicaoCompra())->execute($pacote, $fluxo, null, $user);
            (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 50);
            $emitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $user);
            $fornecedor = Fornecedor::create(['obra_id' => $obra->id, 'nome' => 'X']);

            return (new CriarPedidoCompra())->execute($emitida, $fornecedor, null, null, null, null, $user);
        });

        $this->assertNull(PedidoCompra::find($pedidoOutroTenant->id));
    }

    // ---- AB/AC: autorização ----

    public function test_ab_usuario_sem_editar_nao_ve_botoes_de_mutacao(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $clienteLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $clienteLeitura, Papel::ClienteLeitura->value);
        $this->actingAs($clienteLeitura);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirRcDetalhe', $rcItem->requisicao_compra_id)
            ->assertDontSee('Criar Rascunho de Pedido');
    }

    // ---- AD: Pacote com várias RCs/Pedidos ----

    public function test_ad_pacote_com_varias_rcs_e_pedidos_permanecem_independentes(): void
    {
        $pacote = $this->criarPacote();
        $fornecedor = $this->criarFornecedor();

        $rcItem1 = $this->rcItemEmitido(60, $pacote);
        $p1 = $this->criarPedido->execute($rcItem1->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($p1, $rcItem1, 30);
        $this->emitirPedido->execute($p1->fresh(), $this->user);

        $rcItem2 = $this->rcItemEmitido(60, $pacote);
        $p2 = $this->criarPedido->execute($rcItem2->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);

        $this->assertSame(2, RequisicaoCompra::where('item_suprimento_id', $pacote->id)->count());
        $this->assertSame(StatusPedidoCompra::Emitido, $p1->fresh()->status);
        $this->assertSame(StatusPedidoCompra::Rascunho, $p2->fresh()->status);
    }

    // ---- AE/AF: RC sem Pedido, Pedido parcial ----

    public function test_ae_rc_sem_pedido_nunca_quebra(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $rc = $rcItem->requisicaoCompra;

        $this->assertCount(0, $rc->pedidos);
        $this->assertSame($rc->fimPrevisto()?->toDateString(), $rc->dataProjetadaAtendimento()?->toDateString());
    }

    public function test_af_pedido_parcial_deixa_saldo_restante(): void
    {
        $rcItem = $this->rcItemEmitido(100);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 40);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->assertSame(60.0, $rcItem->fresh()->saldoOficialParaPedido());
    }

    // ---- AG: conciliação completa (verificação básica sem serviço dedicado nesta etapa) ----

    public function test_ag_cadeia_completa_de_conciliacao_navegavel(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);

        $this->assertNotNull($pedidoItem->requisicaoCompraItem->alocacao->requisicaoItem->itemTakeOff->lista->revisao->documento);
    }

    // ---- AH: performance ----

    /**
     * Mede o DELTA de queries entre uma RC com 5 itens e uma com 20 —
     * nunca um teto absoluto (o componente inteiro já dispara dezenas de
     * queries de outros computeds no mount, sem relação com este
     * recurso) — prova que `rcItensComSaldoParaPedido()` é O(1) em
     * queries, não O(N), mesmo padrão já usado em
     * `ConciliacaoAlocacaoTest`.
     */
    private function rcComNItens(int $n): RequisicaoCompra
    {
        $pacote = $this->criarPacote('Pacote Perf ' . uniqid());
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);

        for ($i = 0; $i < $n; $i++) {
            $alocacao = $this->alocacaoPronta(10, $pacote);
            $this->atualizarRc->adicionarItem($rc, $alocacao, 10);
        }

        return $this->emitirRc->execute($rc->fresh(), $this->user);
    }

    public function test_ah_saldo_em_lote_delta_de_queries_independe_de_n(): void
    {
        $rcPequena = $this->rcComNItens(5);
        $rcGrande = $this->rcComNItens(20);

        // DB::enableQueryLog()/getQueryLog()/flushQueryLog() em vez de
        // DB::listen() — listeners registrados via listen() se ACUMULAM
        // (nunca substituem um ao outro), corrompendo uma segunda medição
        // na mesma requisição de teste.
        DB::enableQueryLog();

        DB::flushQueryLog();
        $c1 = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])->call('abrirRcDetalhe', $rcPequena->id);
        $c1->instance()->rcItensComSaldoParaPedido;
        $queriesPequena = count(DB::getQueryLog());

        DB::flushQueryLog();
        $c2 = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])->call('abrirRcDetalhe', $rcGrande->id);
        $c2->instance()->rcItensComSaldoParaPedido;
        $queriesGrande = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(3, abs($queriesGrande - $queriesPequena));
    }

    // ---- AI/AJ: zero Restricao/prontidão ----

    public function test_ai_aj_zero_restricao_zero_prontidao(): void
    {
        $rcItem = $this->rcItemEmitido(60);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->assertSame(0, Restricao::count());
        $this->assertSame(0, PlanoAcao::count());
        $this->assertSame(0, InconsistenciaAvanco::count());
    }

    // ---- AK: legado intocado ----

    public function test_ak_mecanismo_legado_do_pacote_intocado_com_pedido_existindo(): void
    {
        $fluxoLegado = $this->criarFluxo([['Legada', 5]]);
        $pacote = ItemSuprimento::create([
            'obra_id' => $this->obra->id, 'nome' => 'Legado', 'codigo' => 'Legado' . uniqid(),
            'fluxo_suprimento_id' => $fluxoLegado->id,
        ]);
        $pacote->etapas()->create([
            'tenant_id' => $this->tenant->id, 'etapa_fluxo_suprimento_id' => $fluxoLegado->etapas->first()->id,
            'ordem' => 1, 'nome' => 'Legada', 'prazo_dias_uteis' => 5,
        ]);
        $statusAntes = $pacote->fresh()->status;

        $rcItem = $this->rcItemEmitido(60, $pacote);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcItem->requisicaoCompra, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedido, $rcItem, 30);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->assertSame($statusAntes, $pacote->fresh()->status);
        $this->assertCount(1, $pacote->fresh(['etapas'])->etapas);
    }
}
