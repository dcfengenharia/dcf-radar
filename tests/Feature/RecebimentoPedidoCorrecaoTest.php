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
use App\Exceptions\RecebimentoPedidoImutavelException;
use App\Exceptions\RecebimentoPedidoInvalidoException;
use App\Exceptions\SaldoPedidoInsuficienteException;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\InconsistenciaAvanco;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\PedidoCompraItem;
use App\Models\PlanoAcao;
use App\Models\RecebimentoPedido;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.6.CORREÇÃO — fecha os 2 achados C da auditoria
 * adversarial da 19.6: (C1) RecebimentoPedido não era estruturalmente
 * append-only (só `deleting()` era bloqueado); (C2)
 * `dataConclusaoRecebimento()` usava ordem de registro em vez de
 * cronologia física de `recebido_em`. Também endereça o D de data futura
 * sem validação.
 */
class RecebimentoPedidoCorrecaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private RegistrarRecebimentoPedido $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-12-15'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        $this->registrar = new RegistrarRecebimentoPedido();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedidoItemEmitido(float $quantidade, ?ItemSuprimento $pacote = null, ?string $dataPrevistaEntrega = '2026-10-10'): PedidoCompraItem
    {
        $pacoteAlvo = $pacote ?? ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote' . uniqid()]);
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item A', 'quantidade' => 1000]);
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, max($quantidade, 100));
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacoteAlvo, max($quantidade, 100));
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($pacoteAlvo, $fluxo, null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'F', 'cnpj' => '00.000.000/0001-00']);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, $dataPrevistaEntrega, null, null, null, $this->user);
        $itemPedido = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        return $itemPedido->fresh();
    }

    // ---- A-D: append-only real (Eloquent save/update) ----

    public function test_a_save_com_quantidade_alterada_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrar->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);

        $this->expectException(RecebimentoPedidoImutavelException::class);
        $evento->quantidade_recebida = 999;
        $evento->save();
    }

    public function test_b_update_de_instancia_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrar->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);

        $this->expectException(RecebimentoPedidoImutavelException::class);
        $evento->update(['quantidade_recebida' => 999]);
    }

    public function test_c_alterar_quantidade_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrar->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);

        try {
            $evento->update(['quantidade_recebida' => 50]);
            $this->fail('esperava RecebimentoPedidoImutavelException');
        } catch (RecebimentoPedidoImutavelException $e) {
            // esperado
        }

        $this->assertEqualsWithDelta(20.0, RecebimentoPedido::find($evento->id)->quantidade_recebida, 0.001);
    }

    public function test_d_alterar_data_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrar->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);

        $this->expectException(RecebimentoPedidoImutavelException::class);
        $evento->update(['recebido_em' => '2026-11-01']);
    }

    public function test_e_alterar_observacao_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrar->execute($item, 20, Carbon::parse('2026-10-15'), $this->user, 'original');

        try {
            $evento->update(['observacao' => 'REESCRITO SEM TRILHA']);
            $this->fail('esperava RecebimentoPedidoImutavelException');
        } catch (RecebimentoPedidoImutavelException $e) {
            // esperado
        }

        $this->assertSame('original', RecebimentoPedido::find($evento->id)->observacao);
    }

    // ---- F/G: delete/forceDelete continuam bloqueados ----

    public function test_f_delete_continua_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrar->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);

        $this->expectException(RecebimentoPedidoImutavelException::class);
        $evento->delete();
    }

    public function test_g_forcedelete_continua_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrar->execute($item, 20, Carbon::parse('2026-10-15'), $this->user);

        $this->expectException(RecebimentoPedidoImutavelException::class);
        $evento->forceDelete();
    }

    // ---- H: row intacta depois de todas as tentativas ----

    public function test_h_row_intacta_apos_todas_as_tentativas_de_mutacao(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrar->execute($item, 20, Carbon::parse('2026-10-15'), $this->user, 'obs original', 'Depósito A');
        $original = $evento->replicate();

        foreach ([
            fn () => $evento->fresh()->update(['quantidade_recebida' => 1]),
            fn () => $evento->fresh()->update(['recebido_em' => '2026-01-01']),
            fn () => $evento->fresh()->update(['observacao' => 'x']),
            fn () => $evento->fresh()->update(['local_recebimento' => 'x']),
            fn () => $evento->fresh()->delete(),
            fn () => $evento->fresh()->forceDelete(),
        ] as $tentativa) {
            try {
                $tentativa();
            } catch (RecebimentoPedidoImutavelException $e) {
                // esperado
            }
        }

        $reload = RecebimentoPedido::find($evento->id);
        $this->assertNotNull($reload);
        $this->assertEqualsWithDelta(20.0, $reload->quantidade_recebida, 0.001);
        $this->assertEquals('2026-10-15', $reload->recebido_em->toDateString());
        $this->assertSame('obs original', $reload->observacao);
        $this->assertSame('Depósito A', $reload->local_recebimento);
        $this->assertSame($original->registrado_por, $reload->registrado_por);
    }

    // ---- I: cronologia 10/12/11 conclui em 12 (cenário exato do pedido) ----

    public function test_i_cronologia_10_12_11_conclui_em_12(): void
    {
        $item = $this->pedidoItemEmitido(100);

        $this->registrar->execute($item, 40, Carbon::parse('2026-10-10'), $this->user); // registrado 1º
        $this->registrar->execute($item->fresh(), 30, Carbon::parse('2026-10-12'), $this->user); // registrado 2º
        $this->registrar->execute($item->fresh(), 30, Carbon::parse('2026-10-11'), $this->user); // backdatado, registrado 3º

        $conclusao = $item->fresh(['recebimentos'])->dataConclusaoRecebimento();

        $this->assertEquals('2026-10-12', $conclusao->toDateString());
        $this->assertNotEquals('2026-10-11', $conclusao->toDateString());
    }

    // ---- J/K: primeira/última entrega corretas ----

    public function test_j_k_primeira_e_ultima_entrega_no_cenario_adversarial(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $this->registrar->execute($item, 40, Carbon::parse('2026-10-10'), $this->user);
        $this->registrar->execute($item->fresh(), 30, Carbon::parse('2026-10-12'), $this->user);
        $this->registrar->execute($item->fresh(), 30, Carbon::parse('2026-10-11'), $this->user);

        $item = $item->fresh(['recebimentos']);
        $this->assertEquals('2026-10-10', $item->primeiraEntregaEm()->toDateString());
        $this->assertEquals('2026-10-12', $item->ultimaEntregaEm()->toDateString());
    }

    // ---- L: mesmo dia — desempate não muda a DATA ----

    public function test_l_mesmo_dia_desempate_nao_muda_a_data(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $this->registrar->execute($item, 40, Carbon::parse('2026-10-10'), $this->user);
        $this->registrar->execute($item->fresh(), 30, Carbon::parse('2026-10-10'), $this->user);
        $this->registrar->execute($item->fresh(), 30, Carbon::parse('2026-10-10'), $this->user);

        $conclusao = $item->fresh(['recebimentos'])->dataConclusaoRecebimento();
        $this->assertEquals('2026-10-10', $conclusao->toDateString());
    }

    // ---- M: Pedido — data completa usa MAX correto (cenário do pedido: A=10/10, B=15/10, C=12/10 -> 15/10) ----

    public function test_m_pedido_data_completa_usa_max_correto(): void
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'PacoteM']);
        $alocacaoA = (function () use ($pacote) {
            $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DA' . uniqid(), 'descricao' => 'D']);
            $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
            $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LMA' . uniqid()]);
            $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item A', 'quantidade' => 1000]);
            $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
            $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, 100);
            (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
            return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 100);
        })();
        $alocacaoB = (function () use ($pacote) {
            $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DB' . uniqid(), 'descricao' => 'D']);
            $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
            $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LMB' . uniqid()]);
            $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'B' . uniqid(), 'descricao' => 'Item B', 'quantidade' => 1000]);
            $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
            $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, 100);
            (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
            return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 100);
        })();
        $alocacaoC = (function () use ($pacote) {
            $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DC' . uniqid(), 'descricao' => 'D']);
            $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
            $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LMC' . uniqid()]);
            $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'C' . uniqid(), 'descricao' => 'Item C', 'quantidade' => 1000]);
            $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
            $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, 100);
            (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
            return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 100);
        })();

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'FluxoM']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($pacote, $fluxo, null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacaoA, 50);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc->fresh(), $alocacaoB, 50);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc->fresh(), $alocacaoC, 50);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        [$rcItemA, $rcItemB, $rcItemC] = $rcEmitida->itens->all();

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'F', 'cnpj' => '00.000.000/0001-00']);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-10-01', null, null, null, $this->user);
        $itemA = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItemA, 50);
        $itemB = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido->fresh(), $rcItemB, 50);
        $itemC = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido->fresh(), $rcItemC, 50);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        $this->registrar->execute($itemA->fresh(), 50, Carbon::parse('2026-10-10'), $this->user);
        $this->registrar->execute($itemB->fresh(), 50, Carbon::parse('2026-10-15'), $this->user);
        $this->registrar->execute($itemC->fresh(), 50, Carbon::parse('2026-10-12'), $this->user);

        $this->assertEquals('2026-10-15', $pedido->fresh()->dataEntregaCompleta()->toDateString());
    }

    // ---- N: atraso final correto no backdate (12/10, mesmo com 11/10 registrado depois de 12/10) ----

    public function test_n_atraso_final_correto_apos_backdate(): void
    {
        $item = $this->pedidoItemEmitido(100, dataPrevistaEntrega: '2026-10-10');
        $this->registrar->execute($item, 40, Carbon::parse('2026-10-10'), $this->user);
        $this->registrar->execute($item->fresh(), 30, Carbon::parse('2026-10-12'), $this->user);
        $this->registrar->execute($item->fresh(), 30, Carbon::parse('2026-10-11'), $this->user);

        $pedido = $item->fresh()->pedidoCompra;
        $this->assertSame(2, $pedido->diasAtrasoFinal());
        $this->assertNotSame(1, $pedido->diasAtrasoFinal());
    }

    // ---- O/P: retroativo e hoje permitidos ----

    public function test_o_data_retroativa_permitida(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrar->execute($item, 20, Carbon::today()->subDays(30), $this->user);
        $this->assertNotNull($evento->id);
    }

    public function test_p_hoje_permitido(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrar->execute($item, 20, Carbon::today(), $this->user);
        $this->assertNotNull($evento->id);
    }

    // ---- Q/R: amanhã e futuro distante bloqueados ----

    public function test_q_amanha_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $this->expectException(RecebimentoPedidoInvalidoException::class);
        $this->registrar->execute($item, 20, Carbon::today()->addDay(), $this->user);
    }

    public function test_r_futuro_distante_bloqueado(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $this->expectException(RecebimentoPedidoInvalidoException::class);
        $this->registrar->execute($item, 20, Carbon::today()->addYears(2), $this->user);
    }

    // ---- S: futuro — zero write ----

    public function test_s_futuro_zero_write(): void
    {
        $item = $this->pedidoItemEmitido(100);

        try {
            $this->registrar->execute($item, 50, Carbon::today()->addDay(), $this->user);
        } catch (RecebimentoPedidoInvalidoException $e) {
            // esperado
        }

        $this->assertSame(0, RecebimentoPedido::where('pedido_compra_item_id', $item->id)->count());
        $this->assertEqualsWithDelta(100.0, $item->fresh()->saldoAReceber(), 0.001);
    }

    // ---- T: backdate ainda respeita over-recebimento ----

    public function test_t_backdate_ainda_respeita_over_recebimento(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $this->registrar->execute($item, 80, Carbon::today(), $this->user);

        $this->expectException(SaldoPedidoInsuficienteException::class);
        $this->registrar->execute($item->fresh(), 30, Carbon::today()->subDay(), $this->user);
    }

    // ---- U: completo bloqueia recebimento extra, mesmo com data anterior ----

    public function test_u_completo_bloqueia_recebimento_extra_mesmo_com_data_anterior(): void
    {
        $item = $this->pedidoItemEmitido(50);
        $this->registrar->execute($item, 50, Carbon::today(), $this->user);

        $this->expectException(SaldoPedidoInsuficienteException::class);
        $this->registrar->execute($item->fresh(), 1, Carbon::today()->subDays(5), $this->user);
    }

    // ---- V: UI — futuro mostra erro amigável, zero 500 ----

    public function test_v_ui_data_futura_mostra_toast_sem_500(): void
    {
        $item = $this->pedidoItemEmitido(50);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $item->pedidoCompra->requisicaoCompra->item_suprimento_id)
            ->call('abrirRcDetalhe', $item->pedidoCompra->requisicao_compra_id)
            ->call('abrirPedidoDetalhe', $item->pedido_compra_id)
            ->call('abrirRecebimentoItem', $item->id)
            ->set('recebimentoQuantidadeNova', '10')
            ->set('recebimentoDataNova', Carbon::today()->addDay()->toDateString())
            ->call('registrarRecebimento', $item->id)
            ->assertOk()
            ->assertDispatched('show-toast');

        $this->assertSame(0, RecebimentoPedido::where('pedido_compra_item_id', $item->id)->count());
    }

    // ---- W: concorrência continua protegida ----

    public function test_w_concorrencia_continua_protegida(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $this->registrar->execute($item, 60, Carbon::today(), $this->user); // saldo=40

        $this->registrar->execute($item->fresh(), 30, Carbon::today(), $this->user); // total=90

        $this->expectException(SaldoPedidoInsuficienteException::class);
        $this->registrar->execute($item->fresh(), 30, Carbon::today(), $this->user); // ultrapassaria 100
    }

    // ---- X/Y: zero Restricao / zero prontidão ----

    public function test_x_y_zero_restricao_zero_prontidao_apos_correcao(): void
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'PacoteXY']);
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => now()->addDays(5)]);
        $item = $this->pedidoItemEmitido(50, $pacote);
        $pacote->atividades()->attach($atividade->id);

        $prontaAntes = $atividade->fresh()->estaPronta();
        $this->registrar->execute($item, 50, Carbon::today(), $this->user);

        $this->assertSame(0, Restricao::count());
        $this->assertSame(0, PlanoAcao::count());
        $this->assertSame(0, InconsistenciaAvanco::count());
        $this->assertSame($prontaAntes, $atividade->fresh()->estaPronta());
    }

    // ---- Z: legado intacto ----

    public function test_z_legado_intacto_apos_correcao(): void
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'PacoteLegado']);
        $fluxoLegado = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Legado']);
        $fluxoLegado->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 3]);
        $pacote->update(['fluxo_suprimento_id' => $fluxoLegado->id]);
        (new \App\Services\SuprimentoScheduler())->criarEtapasDoItem($pacote->fresh());

        $etapasAntes = $pacote->fresh()->etapas()->count();
        $item = $this->pedidoItemEmitido(50, $pacote);
        $this->registrar->execute($item, 50, Carbon::today(), $this->user);

        $this->assertSame($etapasAntes, $pacote->fresh()->etapas()->count());
    }

    // ---- Bônus: caracterização documentada do bypass de Query Builder (seção 29) ----

    public function test_bypass_query_builder_e_limitacao_estrutural_documentada(): void
    {
        $item = $this->pedidoItemEmitido(100);
        $evento = $this->registrar->execute($item, 20, Carbon::today(), $this->user);

        // Confirma a limitação técnica REAL (Observers Eloquent nunca
        // interceptam Query Builder cru) — não finge que foi bloqueado.
        // Banco é limpo dentro da mesma transação de teste (RefreshDatabase).
        $linhasAfetadas = \Illuminate\Support\Facades\DB::table('recebimentos_pedido')
            ->where('id', $evento->id)
            ->update(['observacao' => 'bypass caracterizado em teste']);

        $this->assertSame(1, $linhasAfetadas);

        // Reverte manualmente pra não deixar o teste com efeito colateral
        // silencioso sobre a asserção seguinte (defesa de higiene, não
        // parte da garantia de domínio).
        \Illuminate\Support\Facades\DB::table('recebimentos_pedido')
            ->where('id', $evento->id)
            ->update(['observacao' => null]);

        $this->assertNull(RecebimentoPedido::find($evento->id)->observacao);
    }
}
