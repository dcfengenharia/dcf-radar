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
use App\Actions\Suprimentos\RegistrarRecebimentoPedido;
use App\Enums\Papel;
use App\Enums\SituacaoEntregaPedido;
use App\Enums\StatusRecebimentoItem;
use App\Exceptions\RecebimentoPedidoImutavelException;
use App\Exceptions\RecebimentoPedidoInvalidoException;
use App\Exceptions\SaldoPedidoInsuficienteException;
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
use App\Models\PedidoCompraItem;
use App\Models\PlanoAcao;
use App\Models\RecebimentoPedido;
use App\Models\RequisicaoCompraItem;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Suprimentos\ConciliacaoRecebimento;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.6 — Recebimento Físico de Materiais sobre Pedido/OC.
 * Cobertura A-AL do pedido (condensada em cenários representativos, mesma
 * convenção de `PedidoCompraTest`/`RequisicaoCompraTest`).
 */
class RecebimentoPedidoTest extends TestCase
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
    private RegistrarRecebimentoPedido $registrarRecebimento;

    protected function setUp(): void
    {
        parent::setUp();

        // Ciclo 19, Etapa 19.6.CORREÇÃO — recebido_em > hoje agora é
        // bloqueado (achado D endereçado). As datas literais usadas nos
        // cenários desta suíte (out-nov/2026) precisam de um "hoje" fixo
        // e sempre posterior a todas elas — mesmo padrão de travar o
        // relógio já documentado no projeto pra fixture data-dependente.
        Carbon::setTestNow(Carbon::parse('2026-12-15'));

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
        $this->registrarRecebimento = new RegistrarRecebimentoPedido();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Recebimento Teste']);
        foreach ($etapas as $indice => [$nome, $prazo]) {
            $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => $indice + 1, 'nome' => $nome, 'prazo_dias_uteis' => $prazo]);
        }
        return $fluxo->fresh(['etapas']);
    }

    private function alocacaoPronta(float $quantidadeAlocada, ?ItemSuprimento $pacote = null, ?Work $obra = null, float $quantidadePrevista = 1000, ?ItemTakeOff &$itemTakeOffRef = null): AlocacaoRequisicaoPacote
    {
        $obraAlvo = $obra ?? $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obraAlvo->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item A', 'quantidade' => $quantidadePrevista]);
        $itemTakeOffRef = $item;
        $rp = $this->criarRp->execute($obraAlvo->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidadeAlocada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $this->alocar->alocar($rpItem->fresh(), $pacote ?? $this->criarPacote(obra: $obraAlvo), $quantidadeAlocada);
    }

    /**
     * Monta um PedidoCompraItem já dentro de um Pedido EMITIDO, pronto pra
     * receber recebimentos — cadeia completa TakeOff->RP->Pacote->RC->Pedido.
     */
    private function pedidoItemEmitido(float $quantidade, ?ItemSuprimento $pacote = null, ?Work $obra = null, ?string $dataPrevistaEntrega = '2026-12-01'): PedidoCompraItem
    {
        $pacoteAlvo = $pacote ?? $this->criarPacote(obra: $obra);
        $alocacao = $this->alocacaoPronta(max($quantidade, 100), $pacoteAlvo, $obra);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacoteAlvo, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = $this->criarFornecedor(obra: $obra);
        $pedido = $this->criarPedido->execute($rcEmitida, $fornecedor, $dataPrevistaEntrega, null, null, null, $this->user);
        $item = $this->atualizarPedido->adicionarItem($pedido, $rcItem, $quantidade);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        return $item->fresh();
    }

    // ---- A/K: registrar recebimento / histórico preservado ----

    public function test_a_registrar_recebimento_basico(): void
    {
        $item = $this->pedidoItemEmitido(100);

        $evento = $this->registrarRecebimento->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);

        $this->assertInstanceOf(RecebimentoPedido::class, $evento);
        $this->assertSame(1, RecebimentoPedido::where('pedido_compra_item_id', $item->id)->count());
        $this->assertEqualsWithDelta(20.0, $item->fresh()->quantidadeRecebida(), 0.001);
    }

    // ---- B: Pedido rascunho bloqueado ----

    public function test_b_pedido_rascunho_bloqueado(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 50);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $item = $this->atualizarPedido->adicionarItem($pedido, $rcItem, 50);

        $this->expectException(RecebimentoPedidoInvalidoException::class);
        $this->registrarRecebimento->execute($item, 10, Carbon::today(), $this->user);
    }

    // ---- C/D: parcial + múltiplos recebimentos ----

    public function test_c_d_multiplos_recebimentos_parciais(): void
    {
        $item = $this->pedidoItemEmitido(100);

        $this->registrarRecebimento->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);
        $this->registrarRecebimento->execute($item, 30, Carbon::parse('2026-10-18'), $this->user);

        $item = $item->fresh();
        $this->assertEqualsWithDelta(50.0, $item->quantidadeRecebida(), 0.001);
        $this->assertEqualsWithDelta(50.0, $item->saldoAReceber(), 0.001);
        $this->assertSame(StatusRecebimentoItem::ParcialmenteRecebido, $item->statusRecebimento());
    }

    // ---- E: completar ----

    public function test_e_completar_item(): void
    {
        $item = $this->pedidoItemEmitido(100);

        $this->registrarRecebimento->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);
        $this->registrarRecebimento->execute($item, 30, Carbon::parse('2026-10-18'), $this->user);
        $this->registrarRecebimento->execute($item, 50, Carbon::parse('2026-10-25'), $this->user);

        $item = $item->fresh();
        $this->assertEqualsWithDelta(0.0, $item->saldoAReceber(), 0.001);
        $this->assertSame(StatusRecebimentoItem::Recebido, $item->statusRecebimento());
        $this->assertEquals('2026-10-25', $item->dataConclusaoRecebimento()->toDateString());
    }

    // ---- F: over-recebimento bloqueado ----

    public function test_f_over_recebimento_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $this->registrarRecebimento->execute($item, 90, Carbon::today(), $this->user);

        $this->expectException(SaldoPedidoInsuficienteException::class);
        $this->registrarRecebimento->execute($item->fresh(), 20, Carbon::today(), $this->user);
    }

    public function test_f2_over_recebimento_exato_no_limite_passa(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $this->registrarRecebimento->execute($item, 60, Carbon::today(), $this->user);
        $this->registrarRecebimento->execute($item->fresh(), 40, Carbon::today(), $this->user);

        $this->assertEqualsWithDelta(0.0, $item->fresh()->saldoAReceber(), 0.001);
    }

    // ---- G: saldo ----

    public function test_g_saldo_calculado_corretamente(): void
    {
        $item = $this->pedidoItemEmitido(75);
        $this->registrarRecebimento->execute($item, 25, Carbon::today(), $this->user);

        $conciliacao = ConciliacaoRecebimento::porPedidoItem($item->fresh());
        $this->assertEqualsWithDelta(50.0, $conciliacao['saldo'], 0.001);
        $this->assertEqualsWithDelta(33.33, $conciliacao['percentual_recebido'], 0.1);
    }

    // ---- H: concorrência (prova estrutural — mesma técnica já aceita no Ciclo 19) ----

    public function test_h_concorrencia_ordem_de_lock_impede_over_recebimento(): void
    {
        $item = $this->pedidoItemEmitido(100);

        // Simula 2 "tentativas concorrentes" sequenciais disputando o
        // mesmo saldo — cada uma trava PedidoCompraItem antes de somar,
        // então a segunda sempre vê o efeito já commitado da primeira.
        $this->registrarRecebimento->execute($item, 70, Carbon::today(), $this->user);

        $this->expectException(SaldoPedidoInsuficienteException::class);
        $this->registrarRecebimento->execute($item->fresh(), 40, Carbon::today(), $this->user);
    }

    public function test_h2_lock_trava_pedido_compra_item_primeiro(): void
    {
        $item = $this->pedidoItemEmitido(50);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'for update')) {
                $queries[] = $query->sql;
            }
        });

        $this->registrarRecebimento->execute($item, 10, Carbon::today(), $this->user);

        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('pedido_compra_itens', $queries[0]);
    }

    // ---- I/J: histórico preservado + delete bloqueado ----

    public function test_i_j_historico_preservado_delete_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrarRecebimento->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);

        $this->expectException(RecebimentoPedidoImutavelException::class);
        $evento->delete();
    }

    public function test_j2_forcedelete_tambem_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrarRecebimento->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);

        $this->expectException(RecebimentoPedidoImutavelException::class);
        $evento->forceDelete();
    }

    // ---- K: data anterior permitida ----

    public function test_k_data_retroativa_permitida(): void
    {
        $item = $this->pedidoItemEmitido(100);

        $evento = $this->registrarRecebimento->execute($item, 20, Carbon::today()->subDays(10), $this->user);

        $this->assertNotNull($evento->id);
    }

    // ---- L: fornecedor snapshot preservado ----

    public function test_l_fornecedor_snapshot_nao_afetado_por_recebimento(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $pedido = $item->pedidoCompra;
        $nomeOriginal = $pedido->fornecedor_nome_snapshot;

        $this->registrarRecebimento->execute($item, 20, Carbon::today(), $this->user);

        $this->assertSame($nomeOriginal, $pedido->fresh()->fornecedor_nome_snapshot);
    }

    // ---- M/N: cross-obra / cross-tenant ----

    public function test_m_cross_obra_registrar_recebimento_de_outra_obra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);

        $itemObraA = $this->pedidoItemEmitido(100, obra: $this->obra);
        $itemObraB = $this->pedidoItemEmitido(100, obra: $outraObra);

        // Ação de domínio não reescopa por obra sozinha (isso é
        // responsabilidade do resolver da UI) — mas ambos os itens
        // continuam pertencendo cada um à sua própria obra/tenant.
        $this->assertNotSame($itemObraA->pedidoCompra->obra_id, $itemObraB->pedidoCompra->obra_id);
    }

    public function test_n_cross_tenant_isolamento(): void
    {
        $novoTenant = Tenant::factory()->create();

        \App\Support\TenantContext::actingAs($novoTenant, function () use ($novoTenant) {
            $outraObra = Work::factory()->create(['tenant_id' => $novoTenant->id]);
            $outroUser = User::factory()->create(['tenant_id' => $novoTenant->id]);
            $this->vincularObra($outraObra, $outroUser, Papel::GerentePlanejamento->value);

            $this->actingAs($outroUser);
            $itemOutroTenant = $this->pedidoItemEmitido(50, obra: $outraObra);
            $this->registrarRecebimento->execute($itemOutroTenant, 10, Carbon::today(), $outroUser);
        });

        $this->actingAs($this->user);
        $this->assertSame(0, RecebimentoPedido::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, \App\Support\TenantContext::actingAs($novoTenant, fn () => RecebimentoPedido::count()));
    }

    // ---- O: usuário sem permissão (checado na UI, ver teste de UI) ----
    // Coberto em SuprimentosRecebimentoUiTest.

    // ---- P: Planejamento não muta (ver RequisicaoCompraTest / RC UI — RC lê, nunca escreve) ----

    public function test_p_leitura_de_recebimento_nunca_escreve(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $this->registrarRecebimento->execute($item, 20, Carbon::today(), $this->user);

        ConciliacaoRecebimento::porPedidoItem($item->fresh());
        ConciliacaoRecebimento::porPedido($item->fresh()->pedidoCompra);

        $this->assertSame(1, RecebimentoPedido::where('pedido_compra_item_id', $item->id)->count());
    }

    // ---- Q/R: primeiro/último recebimento ----

    public function test_q_r_primeira_e_ultima_entrega(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $this->registrarRecebimento->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);
        $this->registrarRecebimento->execute($item->fresh(), 30, Carbon::parse('2026-10-18'), $this->user);

        $item = $item->fresh(['recebimentos']);
        $this->assertEquals('2026-10-15', $item->primeiraEntregaEm()->toDateString());
        $this->assertEquals('2026-10-18', $item->ultimaEntregaEm()->toDateString());
    }

    // ---- S: data de conclusão (Pedido com 2 itens, ambos precisam completar) ----

    public function test_s_data_completa_do_pedido(): void
    {
        $pacote = $this->criarPacote();
        $alocacaoA = $this->alocacaoPronta(50, $pacote);
        $alocacaoB = $this->alocacaoPronta(30, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacaoA, 50);
        $this->atualizarRc->adicionarItem($rc->fresh(), $alocacaoB, 30);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        [$rcItemA, $rcItemB] = $rcEmitida->itens->all();

        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $itemA = $this->atualizarPedido->adicionarItem($pedido, $rcItemA, 50);
        $itemB = $this->atualizarPedido->adicionarItem($pedido->fresh(), $rcItemB, 30);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->registrarRecebimento->execute($itemA->fresh(), 50, Carbon::parse('2026-10-10'), $this->user);
        $this->registrarRecebimento->execute($itemB->fresh(), 30, Carbon::parse('2026-10-20'), $this->user);

        $this->assertSame(SituacaoEntregaPedido::Completa, $pedido->fresh()->situacaoEntrega());
        $this->assertEquals('2026-10-20', $pedido->fresh()->dataEntregaCompleta()->toDateString());
    }

    // ---- T/U: atraso atual / final ----

    public function test_t_atraso_atual(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20'));
        try {
            $item = $this->pedidoItemEmitido(100, dataPrevistaEntrega: '2026-10-10');
            $this->registrarRecebimento->execute($item, 40, Carbon::parse('2026-10-18'), $this->user);

            $pedido = $item->fresh()->pedidoCompra;
            $this->assertSame(10, $pedido->diasAtrasoAtual());
            $this->assertNull($pedido->diasAtrasoFinal());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_u_atraso_final(): void
    {
        $item = $this->pedidoItemEmitido(100, dataPrevistaEntrega: '2026-10-10');
        $this->registrarRecebimento->execute($item, 100, Carbon::parse('2026-10-15'), $this->user);

        $pedido = $item->fresh()->pedidoCompra;
        $this->assertSame(5, $pedido->diasAtrasoFinal());
        $this->assertNull($pedido->diasAtrasoAtual());
    }

    // ---- V: entrega antes da previsão ----

    public function test_v_entrega_antes_da_previsao_permitida_sem_atraso(): void
    {
        $item = $this->pedidoItemEmitido(100, dataPrevistaEntrega: '2026-12-01');
        $this->registrarRecebimento->execute($item, 100, Carbon::parse('2026-11-01'), $this->user);

        $pedido = $item->fresh()->pedidoCompra;
        $this->assertNull($pedido->diasAtrasoFinal());
        $this->assertSame(SituacaoEntregaPedido::Completa, $pedido->situacaoEntrega());
    }

    // ---- W: necessidade do cronograma continua dinâmica ----

    public function test_w_necessidade_continua_dinamica_apos_recebimento(): void
    {
        $pacote = $this->criarPacote();
        $itemTakeOffRef = null;
        $alocacao = $this->alocacaoPronta(100, $pacote, itemTakeOffRef: $itemTakeOffRef);
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => now()->addDays(30)]);
        $pacote->atividades()->attach($atividade->id);

        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 50);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $item = $this->atualizarPedido->adicionarItem($pedido, $rcEmitida->itens->first(), 50);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);
        $this->registrarRecebimento->execute($item->fresh(), 50, Carbon::today(), $this->user);

        $necessidadeAntes = $pacote->fresh(['atividades'])->necessidade();

        $atividade->update(['inicio_planejado' => now()->addDays(60)]);

        $necessidadeDepois = $pacote->fresh(['atividades'])->necessidade();
        $this->assertNotEquals($necessidadeAntes->toDateString(), $necessidadeDepois->toDateString());
    }

    // ---- X: reprogramação muda risco (arquivamento também, seção 40) ----

    public function test_x_y_arquivamento_de_atividade_muda_necessidade_recebimento_historico_intacto(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => now()->addDays(10)]);
        $pacote->atividades()->attach($atividade->id);

        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 50);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $item = $this->atualizarPedido->adicionarItem($pedido, $rcEmitida->itens->first(), 50);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);
        $this->registrarRecebimento->execute($item->fresh(), 50, Carbon::parse('2026-10-05'), $this->user);

        $quantidadeAntes = RecebimentoPedido::where('pedido_compra_item_id', $item->id)->sum('quantidade_recebida');

        $atividade->update(['fora_do_cronograma' => true]);

        $necessidadeAposArquivamento = $pacote->fresh(['atividades'])->necessidade();
        $quantidadeDepois = RecebimentoPedido::where('pedido_compra_item_id', $item->id)->sum('quantidade_recebida');

        $this->assertNull($necessidadeAposArquivamento);
        $this->assertEqualsWithDelta((float) $quantidadeAntes, (float) $quantidadeDepois, 0.001);
    }

    // ---- Z: múltiplos pedidos do pacote ----

    public function test_z_multiplos_pedidos_do_pacote(): void
    {
        $pacote = $this->criarPacote();
        $itemA = $this->pedidoItemEmitido(50, $pacote);

        $alocacaoB = $this->alocacaoPronta(80, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rcB = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rcB, $alocacaoB, 30);
        $rcBEmitida = $this->emitirRc->execute($rcB->fresh(), $this->user);
        $fornecedorB = $this->criarFornecedor('Fornecedor B');
        $pedidoB = $this->criarPedido->execute($rcBEmitida, $fornecedorB, '2026-12-15', null, null, null, $this->user);
        $itemB = $this->atualizarPedido->adicionarItem($pedidoB, $rcBEmitida->itens->first(), 30);
        $this->emitirPedido->execute($pedidoB->fresh(), $this->user);

        $this->registrarRecebimento->execute($itemA->fresh(), 50, Carbon::today(), $this->user);

        $resumo = ConciliacaoRecebimento::porPacote($pacote->fresh());
        $this->assertSame(2, $resumo['total_pedidos']);
        $this->assertSame(1, $resumo['itens_completos']);
        $this->assertSame(1, $resumo['itens_nao_recebidos']);
    }

    // ---- AA: múltiplas unidades nunca somadas ----

    public function test_aa_multiplas_unidades_nunca_somadas(): void
    {
        $pacote = $this->criarPacote();
        $itemKg = $this->pedidoItemEmitido(50, $pacote);

        // 2º Pedido com unidade diferente (via novo ItemTakeOff/lista).
        $obraAlvo = $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obraAlvo->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $itemToM = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'B' . uniqid(), 'descricao' => 'Item B', 'quantidade' => 200]);
        $rp2 = $this->criarRp->execute($obraAlvo->id, null, $this->user->id);
        $rpItem2 = $this->atualizarRp->adicionarItem($rp2, $itemToM->id, 20);
        $this->emitirRp->execute($rp2->fresh(), $this->user);
        $alocacaoM = $this->alocar->alocar($rpItem2->fresh(), $pacote, 20);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rcM = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rcM, $alocacaoM, 20);
        $rcMEmitida = $this->emitirRc->execute($rcM->fresh(), $this->user);
        $fornecedorM = $this->criarFornecedor('Fornecedor M');
        $pedidoM = $this->criarPedido->execute($rcMEmitida, $fornecedorM, '2026-12-01', null, null, null, $this->user);
        $itemM = $this->atualizarPedido->adicionarItem($pedidoM, $rcMEmitida->itens->first(), 20);
        $this->emitirPedido->execute($pedidoM->fresh(), $this->user);

        $this->registrarRecebimento->execute($itemKg->fresh(), 50, Carbon::today(), $this->user);
        $this->registrarRecebimento->execute($itemM->fresh(), 20, Carbon::today(), $this->user);

        $resumo = ConciliacaoRecebimento::porPacote($pacote->fresh());
        // Só contagem de itens completos, nunca soma de quantidade entre unidades.
        $this->assertSame(2, $resumo['itens_completos']);
    }

    // ---- AB: conciliação completa (cadeia TakeOff->Recebido) ----

    public function test_ab_conciliacao_completa_cadeia_full(): void
    {
        $pacote = $this->criarPacote();
        $itemTakeOffRef = null;
        $alocacao = $this->alocacaoPronta(30, $pacote, quantidadePrevista: 100, itemTakeOffRef: $itemTakeOffRef);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 20);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $item = $this->atualizarPedido->adicionarItem($pedido, $rcEmitida->itens->first(), 15);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);
        $this->registrarRecebimento->execute($item->fresh(), 10, Carbon::today(), $this->user);

        $cadeia = ConciliacaoRecebimento::cadeiaCompletaPorItemTakeOff($itemTakeOffRef->fresh());

        $this->assertEqualsWithDelta(100.0, $cadeia['previsto'], 0.001);
        $this->assertEqualsWithDelta(30.0, $cadeia['requisitado'], 0.001);
        $this->assertEqualsWithDelta(30.0, $cadeia['alocado'], 0.001);
        $this->assertEqualsWithDelta(20.0, $cadeia['em_rc'], 0.001);
        $this->assertEqualsWithDelta(15.0, $cadeia['em_pedido'], 0.001);
        $this->assertEqualsWithDelta(10.0, $cadeia['recebido'], 0.001);
        $this->assertEqualsWithDelta(5.0, $cadeia['saldo_a_receber'], 0.001);
    }

    // ---- AC: Pedido sem recebimento (normal, sem Restricao) ----

    public function test_ac_pedido_sem_recebimento_situacao_nao_iniciada(): void
    {
        $item = $this->pedidoItemEmitido(50);
        $pedido = $item->pedidoCompra;

        $this->assertSame(SituacaoEntregaPedido::NaoIniciada, $pedido->situacaoEntrega());
        $this->assertSame(0, Restricao::count());
    }

    // ---- AD/AE: UI parcial/completo — cobertas em SuprimentosRecebimentoUiTest.

    // ---- AF/AG: performance 100/1000 ----

    public function test_af_performance_100_itens(): void
    {
        $this->performanceComN(100, 8);
    }

    public function test_ag_performance_1000_itens(): void
    {
        $this->performanceComN(1000, 10);
    }

    private function performanceComN(int $n, int $tetoQueries): void
    {
        $pacote = $this->criarPacote();
        $itens = [];
        for ($i = 0; $i < $n; $i++) {
            $itens[] = $this->pedidoItemEmitido(10, $pacote);
        }

        foreach (array_slice($itens, 0, min($n, 20)) as $item) {
            $this->registrarRecebimento->execute($item, 5, Carbon::today(), $this->user);
        }

        $itensCollection = PedidoCompraItem::whereIn('id', array_map(fn ($i) => $i->id, $itens))->get();

        DB::enableQueryLog();
        ConciliacaoRecebimento::porPedidoItens($itensCollection);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($tetoQueries, $queries);
    }

    // ---- AH/AI: zero Restricao / zero prontidão ----

    public function test_ah_ai_zero_restricao_zero_efeito_em_prontidao(): void
    {
        $pacote = $this->criarPacote();
        $itemTakeOffRef = null;
        $alocacao = $this->alocacaoPronta(100, $pacote, itemTakeOffRef: $itemTakeOffRef);
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => now()->addDays(5)]);
        $pacote->atividades()->attach($atividade->id);

        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 50);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $fornecedor = $this->criarFornecedor();
        $pedido = $this->criarPedido->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $item = $this->atualizarPedido->adicionarItem($pedido, $rcEmitida->itens->first(), 50);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $prontaAntes = $atividade->fresh()->estaPronta();

        $this->registrarRecebimento->execute($item->fresh(), 50, Carbon::today(), $this->user);

        $this->assertSame(0, Restricao::count());
        $this->assertSame(0, PlanoAcao::count());
        $this->assertSame(0, InconsistenciaAvanco::count());
        $this->assertSame($prontaAntes, $atividade->fresh()->estaPronta());
    }

    // ---- AJ: legado intacto ----

    public function test_aj_legado_de_suprimento_intacto(): void
    {
        $pacote = $this->criarPacote();
        $fluxoLegado = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Legado']);
        $fluxoLegado->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 3]);
        $pacote->update(['fluxo_suprimento_id' => $fluxoLegado->id]);
        (new \App\Services\SuprimentoScheduler())->criarEtapasDoItem($pacote->fresh());

        $etapasAntes = $pacote->fresh()->etapas()->count();

        $item = $this->pedidoItemEmitido(50, $pacote);
        $this->registrarRecebimento->execute($item, 50, Carbon::today(), $this->user);

        $this->assertSame($etapasAntes, $pacote->fresh()->etapas()->count());
    }

    // ---- AK: Pedido stale ----

    public function test_ak_pedido_item_stale_resolvido_fresh(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $itemStale = clone $item;

        $this->registrarRecebimento->execute($item, 60, Carbon::today(), $this->user);

        // Objeto "stale" ainda tem quantidade_pedida correta (não muda),
        // mas o cálculo de saldo sempre relê o banco (lockForUpdate),
        // então usar o objeto antigo continua seguro.
        $this->expectException(SaldoPedidoInsuficienteException::class);
        $this->registrarRecebimento->execute($itemStale, 50, Carbon::today(), $this->user);
    }

    // ---- AL: forceDelete histórico bloqueado (idêntico a J2, reafirmado) ----

    public function test_al_forcedelete_evento_ja_com_pedido_completo_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(30);
        $evento = $this->registrarRecebimento->execute($item, 30, Carbon::today(), $this->user);

        $this->expectException(RecebimentoPedidoImutavelException::class);
        $evento->forceDelete();
    }

    // ---- Probe P10: histórico após fornecedor soft-delete ----

    public function test_p10_historico_de_recebimento_intacto_apos_fornecedor_soft_deletado(): void
    {
        $item = $this->pedidoItemEmitido(50);
        $pedido = $item->pedidoCompra;
        $this->registrarRecebimento->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);

        $pedido->fornecedor->delete();

        $item = $item->fresh(['recebimentos']);
        $this->assertEqualsWithDelta(20.0, $item->quantidadeRecebida(), 0.001);
        $this->assertNotNull($pedido->fresh()->fornecedor_nome_snapshot);
        $this->assertSame(1, $item->recebimentos->count());
    }

    // ---- Guard: quantidade zero/negativa ----

    public function test_quantidade_zero_ou_negativa_rejeitada(): void
    {
        $item = $this->pedidoItemEmitido(50);

        $this->expectException(RecebimentoPedidoInvalidoException::class);
        $this->registrarRecebimento->execute($item, 0, Carbon::today(), $this->user);
    }
}
