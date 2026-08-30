<?php

namespace Tests\Feature;

use App\Actions\Estoque\AdicionarItemInesperadoInventario;
use App\Actions\Estoque\AprovarAjusteInventario;
use App\Actions\Estoque\CancelarInventarioEstoque;
use App\Actions\Estoque\ConcluirInventarioEstoque;
use App\Actions\Estoque\CriarInventarioEstoque;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\IniciarInventarioEstoque;
use App\Actions\Estoque\MoverInventarioParaAnalise;
use App\Actions\Estoque\RegistrarContagemInventario;
use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Actions\Estoque\RegistrarSaidaEstoque;
use App\Actions\Estoque\RegistrarTransferenciaEstoque;
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
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusInventarioEstoque;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\AjusteInventarioInvalidoException;
use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Exceptions\SaldoFisicoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\ContagemInventario;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\InventarioAjuste;
use App\Models\InventarioEstoque;
use App\Models\InventarioItem;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\ReservaEstoque;
use App\Models\Tenant;
use App\Models\UnidadeEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.7 — Inventário Físico + Divergências + Ajuste
 * Formal de Estoque. Cobertura A-AF do pedido (Seção 27).
 */
class InventarioEstoqueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    private CriarRequisicaoPlanejamento $criarRp;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizarRp;
    private EmitirRequisicaoPlanejamento $emitirRp;
    private AlocarRequisicaoAoPacote $alocar;
    private RegistrarEntradaEstoque $registrarEntrada;
    private RegistrarSaidaEstoque $registrarSaida;
    private RegistrarTransferenciaEstoque $registrarTransferencia;
    private CriarReservaEstoque $reservaAction;

    private CriarInventarioEstoque $criarInv;
    private IniciarInventarioEstoque $iniciarInv;
    private RegistrarContagemInventario $registrarContagem;
    private AdicionarItemInesperadoInventario $itemInesperadoAction;
    private MoverInventarioParaAnalise $moverAnalise;
    private AprovarAjusteInventario $aprovarAjuste;
    private ConcluirInventarioEstoque $concluirInv;
    private CancelarInventarioEstoque $cancelarInv;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);

        $this->criarRp = new CriarRequisicaoPlanejamento();
        $this->atualizarRp = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitirRp = new EmitirRequisicaoPlanejamento();
        $this->alocar = new AlocarRequisicaoAoPacote();
        $this->registrarEntrada = new RegistrarEntradaEstoque();
        $this->registrarSaida = new RegistrarSaidaEstoque();
        $this->registrarTransferencia = new RegistrarTransferenciaEstoque();
        $this->reservaAction = new CriarReservaEstoque();

        $this->criarInv = new CriarInventarioEstoque();
        $this->iniciarInv = new IniciarInventarioEstoque();
        $this->registrarContagem = new RegistrarContagemInventario();
        $this->itemInesperadoAction = new AdicionarItemInesperadoInventario();
        $this->moverAnalise = new MoverInventarioParaAnalise();
        $this->aprovarAjuste = new AprovarAjusteInventario();
        $this->concluirInv = new ConcluirInventarioEstoque();
        $this->cancelarInv = new CancelarInventarioEstoque();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (mesma toolkit já estabelecida em Estoque*Test) ----

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(array $overrides = [], ?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocalTerceiro(?Work $obra = null): LocalEstoque
    {
        $fornecedor = Fornecedor::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Fornecedor ' . uniqid()]);

        return LocalEstoque::create([
            'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Terceiro ' . uniqid(),
            'tipo' => TipoLocalEstoque::Terceiro->value, 'ativo' => true, 'fornecedor_id' => $fornecedor->id,
        ]);
    }

    private function criarPacoteSimples(?Work $obra = null): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote ' . uniqid()]);
    }

    private function criarItemTakeOffOrfao(?Material $material, ?Work $obra = null): ItemTakeOff
    {
        $obra ??= $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item',
            'quantidade' => 1000000, 'material_id' => $material?->id,
        ]);
    }

    private function criarRpItemEmitido(ItemTakeOff $item, float $quantidade, ?Work $obra = null): \App\Models\RequisicaoPlanejamentoItem
    {
        $obra ??= $this->obra;
        $rp = $this->criarRp->execute($obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidade);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $rpItem->fresh();
    }

    private function alocarNoPacote(\App\Models\RequisicaoPlanejamentoItem $rpItem, float $quantidade, ?ItemSuprimento $pacote = null, ?Work $obra = null): AlocacaoRequisicaoPacote
    {
        $pacote ??= $this->criarPacoteSimples($obra);

        return $this->alocar->alocar($rpItem, $pacote, $quantidade);
    }

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade, ?string $codigoLote = null, ?ItemSuprimento $pacoteExistente = null): ItemSuprimento
    {
        $item = $this->criarItemTakeOffOrfao($material, $this->obra);
        $rpItem = $this->criarRpItemEmitido($item, $quantidade);
        $alocacao = $this->alocarNoPacote($rpItem, $quantidade, $pacoteExistente);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);

        $this->registrarEntrada->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user, $codigoLote);

        return ItemSuprimento::find($alocacao->item_suprimento_id);
    }

    private function entradaSerial(Material $material, LocalEstoque $local, string $serial): ItemSuprimento
    {
        $item = $this->criarItemTakeOffOrfao($material, $this->obra);
        $rpItem = $this->criarRpItemEmitido($item, 1);
        $alocacao = $this->alocarNoPacote($rpItem, 1);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 1);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, 1)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, 1, Carbon::parse('2026-12-10'), $this->user);
        $this->registrarEntrada->execute($recebimento, $local, 1, Carbon::today(), $this->user, null, $serial);

        return ItemSuprimento::find($alocacao->item_suprimento_id);
    }

    private function criarEIniciar(LocalEstoque $local, bool $contagemCega = false): InventarioEstoque
    {
        $inv = $this->criarInv->execute($local, $this->user, 'Inventário Teste', $contagemCega);

        return $this->iniciarInv->execute($inv, $this->user);
    }

    private function contar(InventarioItem $item, float $quantidade, ?string $dataYmd = null): ContagemInventario
    {
        return $this->registrarContagem->execute($item, $quantidade, Carbon::parse($dataYmd ?? Carbon::today()->toDateString()), $this->user);
    }

    // =========================================================
    // A/B — criação e início
    // =========================================================

    public function test_a_criacao_nasce_em_rascunho_sem_itens(): void
    {
        $local = $this->criarLocal();
        $inv = $this->criarInv->execute($local, $this->user, 'Inv Teste', false);

        $this->assertSame(StatusInventarioEstoque::Rascunho, $inv->status);
        $this->assertNull($inv->numero);
        $this->assertSame(0, InventarioItem::where('inventario_estoque_id', $inv->id)->count());
    }

    public function test_a2_criacao_bloqueada_em_local_terceiro(): void
    {
        $local = $this->criarLocalTerceiro();

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->criarInv->execute($local, $this->user, null, false);
    }

    public function test_a3_criacao_bloqueada_em_local_inativo(): void
    {
        $local = $this->criarLocal(['ativo' => false]);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->criarInv->execute($local, $this->user, null, false);
    }

    public function test_b_iniciar_transiciona_e_atribui_numero(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);

        $inv = $this->criarInv->execute($local, $this->user, null, false);
        $iniciado = $this->iniciarInv->execute($inv, $this->user);

        $this->assertSame(StatusInventarioEstoque::EmContagem, $iniciado->status);
        $this->assertSame(1, $iniciado->numero);
        $this->assertNotNull($iniciado->iniciado_em);
        $this->assertSame($this->user->id, $iniciado->iniciado_por);
    }

    public function test_b2_iniciar_duas_vezes_bloqueado(): void
    {
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->iniciarInv->execute($inv, $this->user);
    }

    // =========================================================
    // C/D/E/F — snapshot (quantitativo, bobina por Local, bobina dividida, serial)
    // =========================================================

    public function test_c_snapshot_quantitativo_correto(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);

        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->where('material_id', $material->id)->firstOrFail();

        $this->assertEquals(100, (float) $item->quantidade_sistema_snapshot);
        $this->assertNull($item->unidade_estoque_id);
    }

    public function test_d_snapshot_bobina_usa_saldo_por_local_nunca_global(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        $invA = $this->criarEIniciar($localA);
        $itemA = InventarioItem::where('inventario_estoque_id', $invA->id)->firstOrFail();

        $this->assertEquals(700, (float) $itemA->quantidade_sistema_snapshot);
        $this->assertNotEquals(1000, (float) $itemA->quantidade_sistema_snapshot);
    }

    public function test_e_bobina_dividida_dois_inventarios_independentes(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        $invA = $this->criarEIniciar($localA);
        $invB = $this->criarEIniciar($localB);

        $itemA = InventarioItem::where('inventario_estoque_id', $invA->id)->firstOrFail();
        $itemB = InventarioItem::where('inventario_estoque_id', $invB->id)->firstOrFail();

        $this->assertEquals(700, (float) $itemA->quantidade_sistema_snapshot);
        $this->assertEquals(300, (float) $itemB->quantidade_sistema_snapshot);
        $this->assertSame($unidade->id, $itemA->unidade_estoque_id);
        $this->assertSame($unidade->id, $itemB->unidade_estoque_id);
    }

    public function test_f_snapshot_serial(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $this->entradaSerial($material, $local, 'SER-1');
        $unidade = UnidadeEstoque::where('serial_unico', 'SER-1')->firstOrFail();

        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();

        $this->assertEquals(1, (float) $item->quantidade_sistema_snapshot);
        $this->assertSame($unidade->id, $item->unidade_estoque_id);
    }

    // =========================================================
    // G/H/I — contagem exata / falta / sobra
    // =========================================================

    public function test_g_contagem_exata_diferenca_zero(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();

        $this->contar($item, 100);

        $this->assertEquals(0.0, $item->fresh()->diferenca());
    }

    public function test_h_falta_diferenca_negativa(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();

        $this->contar($item, 97);

        $this->assertEquals(-3.0, $item->fresh()->diferenca());
    }

    public function test_i_sobra_diferenca_positiva(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();

        $this->contar($item, 105);

        $this->assertEquals(5.0, $item->fresh()->diferenca());
    }

    // =========================================================
    // J/K — recontagem / histórico
    // =========================================================

    public function test_j_recontagem_ultima_e_adotada_sem_apagar_a_primeira(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();

        $c1 = $this->contar($item, 92);
        $c2 = $this->contar($item, 99);

        $this->assertEquals(-1.0, $item->fresh()->diferenca());
        $this->assertNotNull(ContagemInventario::find($c1->id));
        $this->assertNotNull(ContagemInventario::find($c2->id));
        $this->assertSame(2, ContagemInventario::where('inventario_item_id', $item->id)->count());
    }

    public function test_k_historico_de_contagens_consultavel(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();

        $this->contar($item, 92);
        $this->contar($item, 95);
        $this->contar($item, 99);

        $historico = ContagemInventario::where('inventario_item_id', $item->id)->orderBy('created_at')->pluck('quantidade_contada')->map(fn ($v) => (float) $v)->all();
        $this->assertSame([92.0, 95.0, 99.0], $historico);
    }

    // =========================================================
    // L/M/N/O — justificativa / ajuste positivo/negativo / saldo pós-ajuste
    // =========================================================

    public function test_l_justificativa_obrigatoria_minimo_5_caracteres(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);

        $this->expectException(AjusteInventarioInvalidoException::class);
        $this->aprovarAjuste->execute($item->fresh(), 'oi', $this->user);
    }

    public function test_m_ajuste_positivo_cria_entrada(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 105);
        $this->moverAnalise->execute($inv, $this->user);

        $ajuste = $this->aprovarAjuste->execute($item->fresh(), 'Sobra encontrada na contagem física.', $this->user);

        $this->assertSame(TipoMovimentacaoEstoque::Entrada, $ajuste->movimentacaoEstoque->tipo);
        $this->assertEquals(5.0, (float) $ajuste->quantidade);
    }

    public function test_n_ajuste_negativo_cria_saida(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);

        $ajuste = $this->aprovarAjuste->execute($item->fresh(), 'Falta identificada na contagem física.', $this->user);

        $this->assertSame(TipoMovimentacaoEstoque::Saida, $ajuste->movimentacaoEstoque->tipo);
        $this->assertEquals(3.0, (float) $ajuste->quantidade);
    }

    public function test_o_saldo_apos_ajuste_reflete_a_correcao(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);
        $this->aprovarAjuste->execute($item->fresh(), 'Falta identificada na contagem física.', $this->user);

        $this->assertEquals(97.0, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_o2_sem_divergencia_nao_pode_ajustar(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 100);
        $this->moverAnalise->execute($inv, $this->user);

        $this->expectException(AjusteInventarioInvalidoException::class);
        $this->aprovarAjuste->execute($item->fresh(), 'Justificativa qualquer.', $this->user);
    }

    public function test_o3_item_ja_ajustado_nao_pode_ajustar_de_novo(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);
        $this->aprovarAjuste->execute($item->fresh(), 'Primeira justificativa válida.', $this->user);

        $this->expectException(AjusteInventarioInvalidoException::class);
        $this->aprovarAjuste->execute($item->fresh(), 'Segunda tentativa de justificativa.', $this->user);
    }

    public function test_o4_item_sem_contagem_nao_pode_ajustar(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->moverAnalise->execute($inv, $this->user);

        $this->expectException(AjusteInventarioInvalidoException::class);
        $this->aprovarAjuste->execute($item->fresh(), 'Justificativa qualquer válida.', $this->user);
    }

    public function test_o5_ajuste_so_permitido_em_analise(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);

        $this->expectException(AjusteInventarioInvalidoException::class);
        $this->aprovarAjuste->execute($item->fresh(), 'Justificativa qualquer válida.', $this->user);
    }

    // =========================================================
    // P/Q — movimentação histórica preservada / Reserva preservada
    // =========================================================

    public function test_p_movimentacoes_anteriores_permanecem_intocadas(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $movAntes = MovimentacaoEstoque::orderBy('created_at')->pluck('id')->all();

        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);
        $this->aprovarAjuste->execute($item->fresh(), 'Falta identificada na contagem física.', $this->user);

        foreach ($movAntes as $id) {
            $this->assertNotNull(MovimentacaoEstoque::find($id));
        }
        // A Entrada original nunca é reescrita — nova Saida é uma linha NOVA.
        $this->assertSame(2, MovimentacaoEstoque::count());
    }

    public function test_q_reserva_nunca_alterada_por_inventario_ou_ajuste(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $pacote = $this->entradaPronta($material, $local, 100);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 60, null, null, $this->user);

        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);
        $this->aprovarAjuste->execute($item->fresh(), 'Falta identificada na contagem física.', $this->user);

        $this->assertTrue($reserva->fresh()->estaAtiva());
        $this->assertEquals(60, (float) $reserva->fresh()->quantidade);
    }

    // =========================================================
    // R — déficit físico < reservado, exibido, nunca escolhido automaticamente
    // =========================================================

    public function test_r_deficit_apos_ajuste_negativo_fica_visivel_via_saldo_reserva(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $pacote = $this->entradaPronta($material, $local, 100);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 80, null, null, $this->user);

        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 70);
        $this->moverAnalise->execute($inv, $this->user);
        $this->aprovarAjuste->execute($item->fresh(), 'Falta identificada, possível avaria.', $this->user);

        $this->assertEquals(70.0, SaldoEstoque::porMaterialLocal($material, $local));
        $this->assertEquals(80.0, (float) $reserva->fresh()->quantidade);
        // físico (70) < reservado (80) — déficit de 10, nunca resolvido automaticamente.
        $this->assertEquals(-10.0, SaldoReserva::disponivelPorMaterialLocal($material, $local));
    }

    // =========================================================
    // S/T — Transferência antes/depois do snapshot
    // =========================================================

    public function test_s_transferencia_antes_do_snapshot_ja_reflete_no_snapshot(): void
    {
        $material = $this->criarMaterial();
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 100);
        $this->registrarTransferencia->execute($material, $localA, $localB, 40, Carbon::today(), $this->user);

        $invB = $this->criarEIniciar($localB);
        $itemB = InventarioItem::where('inventario_estoque_id', $invB->id)->firstOrFail();

        $this->assertEquals(40.0, (float) $itemB->quantidade_sistema_snapshot);
    }

    public function test_t_transferencia_depois_do_snapshot_nao_reescreve_snapshot(): void
    {
        $material = $this->criarMaterial();
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 100);

        $invA = $this->criarEIniciar($localA);
        $itemA = InventarioItem::where('inventario_estoque_id', $invA->id)->firstOrFail();
        $this->assertEquals(100.0, (float) $itemA->quantidade_sistema_snapshot);

        // Movimentação livre durante o inventário (decisão do usuário, Seção 16).
        $this->registrarTransferencia->execute($material, $localA, $localB, 40, Carbon::today(), $this->user);

        $this->assertEquals(100.0, (float) $itemA->fresh()->quantidade_sistema_snapshot, 'snapshot nunca reescrito');
        $this->assertEquals(60.0, SaldoEstoque::porMaterialLocal($material, $localA), 'saldo real já mudou');
    }

    public function test_t2_ajuste_negativo_recusado_se_saldo_fresco_ja_insuficiente(): void
    {
        $material = $this->criarMaterial();
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 100);

        $inv = $this->criarEIniciar($localA);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 90); // contador acha que faltam 10
        $this->moverAnalise->execute($inv, $this->user);

        // Entre a contagem e a aprovação, uma Transferência tira MAIS do que
        // o ajuste esperava — saldo fresco cai pra 5, mas o ajuste pediria -10.
        $this->registrarTransferencia->execute($material, $localA, $localB, 95, Carbon::today(), $this->user);

        $this->expectException(SaldoFisicoInsuficienteException::class);
        $this->aprovarAjuste->execute($item->fresh(), 'Falta identificada na contagem física.', $this->user);
    }

    // =========================================================
    // U/V — cancelamento / conclusão
    // =========================================================

    public function test_u_cancelamento_nao_apaga_historico_nem_gera_ajuste_nem_altera_saldo(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);

        $cancelado = $this->cancelarInv->execute($inv, 'Contagem cancelada por decisão da gerência.', $this->user);

        $this->assertSame(StatusInventarioEstoque::Cancelado, $cancelado->status);
        $this->assertNotNull(InventarioItem::find($item->id));
        $this->assertNotNull(ContagemInventario::where('inventario_item_id', $item->id)->first());
        $this->assertSame(0, InventarioAjuste::count());
        $this->assertEquals(100.0, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_u2_cancelamento_bloqueado_apos_concluido(): void
    {
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);
        $this->moverAnalise->execute($inv, $this->user);
        $this->concluirInv->execute($inv, $this->user);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->cancelarInv->execute($inv->fresh(), 'Motivo qualquer.', $this->user);
    }

    public function test_v_conclusao_nao_exige_100_por_cento_ajustado(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97); // divergência deliberadamente deixada sem ajuste
        $this->moverAnalise->execute($inv, $this->user);

        $concluido = $this->concluirInv->execute($inv, $this->user);

        $this->assertSame(StatusInventarioEstoque::Concluido, $concluido->status);
        $this->assertSame(0, InventarioAjuste::count());
    }

    // =========================================================
    // W — imutabilidade
    // =========================================================

    public function test_w_inventario_concluido_nunca_reescrito(): void
    {
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);
        $this->moverAnalise->execute($inv, $this->user);
        $concluido = $this->concluirInv->execute($inv, $this->user);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $concluido->update(['titulo' => 'Reescrito']);
    }

    public function test_w2_inventario_nunca_e_deletavel(): void
    {
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $inv->delete();
    }

    public function test_w3_item_de_inventario_nunca_reescrito_nem_deletado(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();

        try {
            $item->update(['quantidade_sistema_snapshot' => 999]);
            $this->fail('esperava InventarioEstoqueInvalidoException');
        } catch (InventarioEstoqueInvalidoException $e) {
        }

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $item->delete();
    }

    public function test_w4_contagem_nunca_reescrita_nem_deletada(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $contagem = $this->contar($item, 97);

        try {
            $contagem->update(['quantidade_contada' => 50]);
            $this->fail('esperava InventarioEstoqueInvalidoException');
        } catch (InventarioEstoqueInvalidoException $e) {
        }

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $contagem->delete();
    }

    public function test_w5_ajuste_nunca_reescrito_nem_deletado(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);
        $ajuste = $this->aprovarAjuste->execute($item->fresh(), 'Falta identificada na contagem física.', $this->user);

        try {
            $ajuste->update(['justificativa' => 'Reescrito']);
            $this->fail('esperava AjusteInventarioInvalidoException');
        } catch (AjusteInventarioInvalidoException $e) {
        }

        $this->expectException(AjusteInventarioInvalidoException::class);
        $ajuste->delete();
    }

    // =========================================================
    // X — concorrência
    // =========================================================

    public function test_x_dupla_aprovacao_do_mesmo_item_a_segunda_falha(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);

        $this->aprovarAjuste->execute($item->fresh(), 'Primeira aprovação válida.', $this->user);

        $this->expectException(AjusteInventarioInvalidoException::class);
        $this->aprovarAjuste->execute($item->fresh(), 'Segunda tentativa concorrente.', $this->user);
    }

    public function test_x2_ordem_de_lock_trava_recurso_fisico_antes_do_saldo(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);

        $queriesDeLock = [];
        DB::listen(function ($query) use (&$queriesDeLock) {
            if (str_contains($query->sql, 'lock in share mode') || str_contains(strtolower($query->sql), 'for update')) {
                $queriesDeLock[] = $query->sql;
            }
        });

        $this->aprovarAjuste->execute($item->fresh(), 'Falta identificada na contagem física.', $this->user);

        $lockLocais = array_filter($queriesDeLock, fn ($sql) => str_contains($sql, 'locais_estoque'));
        $this->assertNotEmpty($lockLocais, 'esperava um lock explícito no Local antes de revalidar o saldo');
    }

    // =========================================================
    // Y/Z — cross-obra / cross-tenant
    // =========================================================

    public function test_y_local_de_outra_obra_bloqueado_via_ui(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $localOutraObra = $this->criarLocal([], $outraObra);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->call('abrirModalNovoInventario')
            ->set('invLocalId', $localOutraObra->id)
            ->call('confirmarNovoInventario');

        $component->assertHasErrors('novoInventarioGeral');
        $this->assertSame(0, InventarioEstoque::count());
    }

    public function test_y2_item_de_outra_obra_bloqueado_ao_contar_via_ui(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);
        $localOutraObra = $this->criarLocal([], $outraObra);
        $material = $this->criarMaterial();
        $invOutraObra = $this->criarEIniciar($localOutraObra);
        // Item criado direto (sem a cadeia comercial completa, que exigiria
        // duplicar RP/RC/Pedido/Recebimento inteiramente escopados à outra
        // obra) — o guard testado aqui é de ISOLAMENTO por obra, não de
        // saldo/origem; um InventarioItem sem stock real ainda é válido pra
        // essa finalidade (a FK não exige saldo, só existência).
        $itemOutraObra = InventarioItem::create([
            'inventario_estoque_id' => $invOutraObra->id,
            'material_id' => $material->id,
            'unidade_estoque_id' => null,
            'quantidade_sistema_snapshot' => 50,
            'created_at' => now(),
        ]);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->call('abrirModalContagem', $itemOutraObra->id)
            ->set('contagemQuantidade', '50')
            ->set('contagemData', now()->toDateString())
            ->call('confirmarContagem');

        $component->assertHasErrors('contagemGeral');
        $this->assertSame(0, ContagemInventario::count());
    }

    public function test_z_cross_tenant_isolamento_estrutural(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $outroUser, Papel::GerentePlanejamento->value);

        $this->actingAs($outroUser);
        $localOutroTenant = $this->criarLocal([], $outraObra);
        $invOutroTenant = $this->criarInv->execute($localOutroTenant, $outroUser, null, false);

        $this->actingAs($this->user);
        $this->assertNull(InventarioEstoque::find($invOutroTenant->id));
    }

    // =========================================================
    // AA — UI (fluxo completo)
    // =========================================================

    public function test_aa_fluxo_completo_via_ui(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->call('abrirModalNovoInventario')
            ->set('invLocalId', $local->id)
            ->set('invTitulo', 'Inventário via UI')
            ->call('confirmarNovoInventario')
            ->assertHasNoErrors();

        $inv = InventarioEstoque::firstOrFail();
        $component->set('inventarioDetalheId', $inv->id)
            ->call('confirmarIniciarInventario')
            ->assertHasNoErrors();

        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $component->call('abrirModalContagem', $item->id)
            ->set('contagemQuantidade', '97')
            ->set('contagemData', now()->toDateString())
            ->call('confirmarContagem')
            ->assertHasNoErrors();

        $component->call('confirmarMoverParaAnalise')->assertHasNoErrors();

        $component->call('abrirModalAjuste', $item->id)
            ->set('ajusteJustificativa', 'Falta identificada na contagem física via UI.')
            ->call('confirmarAjuste')
            ->assertHasNoErrors();

        $component->call('confirmarConcluirInventario')->assertHasNoErrors();

        $this->assertSame(StatusInventarioEstoque::Concluido, $inv->fresh()->status);
        $this->assertEquals(97.0, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_aa2_usuario_sem_permissao_de_criar_nao_registra_inventario(): void
    {
        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $semPermissao, 'cliente_leitura');
        $this->actingAs($semPermissao);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->call('abrirModalNovoInventario')
            ->assertStatus(403);

        $this->assertSame(0, InventarioEstoque::count());
    }

    public function test_aa3_dupla_autorizacao_bloqueia_aprovar_ajuste_sem_a_segunda_permissao(): void
    {
        // Encarregado tem estoque.inventario|criar (conta), mas NÃO tem
        // estoque.inventario|editar nem estoque.movimentacao|editar — não
        // deveria conseguir aprovar Ajuste.
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);

        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);

        $this->actingAs($encarregado);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->call('abrirModalAjuste', $item->id)
            ->assertStatus(403);

        $this->assertSame(0, InventarioAjuste::count());
    }

    // =========================================================
    // Serial inesperado (Seção 8, decisão do usuário)
    // =========================================================

    public function test_serial_inesperado_registrado_como_texto_nunca_cria_unidade(): void
    {
        $materialSerial = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);

        $totalUnidadesAntes = UnidadeEstoque::count();

        $item = $this->itemInesperadoAction->execute($inv, $materialSerial, 'SERIAL-DESCONHECIDO-001', 1, Carbon::today(), $this->user);

        $this->assertSame($totalUnidadesAntes, UnidadeEstoque::count());
        $this->assertNull($item->unidade_estoque_id);
        $this->assertSame('SERIAL-DESCONHECIDO-001', $item->serial_texto_inesperado);
        $this->assertEquals(0.0, (float) $item->quantidade_sistema_snapshot);
        $this->assertTrue($item->ehSerialInesperado());
    }

    public function test_serial_inesperado_so_permitido_para_material_serializado(): void
    {
        $materialQuantitativo = $this->criarMaterial();
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->itemInesperadoAction->execute($inv, $materialQuantitativo, 'X', 1, Carbon::today(), $this->user);
    }

    public function test_serial_inesperado_nao_pode_gerar_ajuste_automatico(): void
    {
        $materialSerial = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);
        $item = $this->itemInesperadoAction->execute($inv, $materialSerial, 'SERIAL-X', 1, Carbon::today(), $this->user);
        $this->moverAnalise->execute($inv, $this->user);

        $this->expectException(AjusteInventarioInvalidoException::class);
        $this->aprovarAjuste->execute($item->fresh(), 'Encontrado fisicamente, origem desconhecida.', $this->user);
    }

    public function test_serial_inesperado_duplicado_bloqueado(): void
    {
        $materialSerial = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);
        $this->itemInesperadoAction->execute($inv, $materialSerial, 'SERIAL-DUP', 1, Carbon::today(), $this->user);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->itemInesperadoAction->execute($inv, $materialSerial, 'SERIAL-DUP', 1, Carbon::today(), $this->user);
    }

    // =========================================================
    // Contagem cega (Seção 9)
    // =========================================================

    public function test_contagem_cega_nao_esconde_o_snapshot_do_backend_so_da_ui(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local, contagemCega: true);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();

        $this->assertTrue($inv->fresh()->contagem_cega);
        // A Action/domínio sempre calcula a diferença real — "cega" é só
        // uma instrução de apresentação pra UI, nunca uma limitação de
        // dado gravado.
        $this->assertEquals(100.0, (float) $item->quantidade_sistema_snapshot);
    }

    // =========================================================
    // Local Terceiro bloqueado (Seção 19, decisão do usuário)
    // =========================================================

    public function test_local_terceiro_bloqueado_via_ui(): void
    {
        $localTerceiro = $this->criarLocalTerceiro();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->call('abrirModalNovoInventario')
            ->set('invLocalId', $localTerceiro->id)
            ->call('confirmarNovoInventario')
            ->assertHasErrors('novoInventarioGeral');

        $this->assertSame(0, InventarioEstoque::count());
    }

    // =========================================================
    // AB/AC — performance / zero N+1 (metodologia DELTA já estabelecida)
    // =========================================================

    public function test_ab_iniciar_inventario_com_muitas_posicoes_sem_crescimento_por_item(): void
    {
        $local10 = $this->criarLocal();
        for ($i = 0; $i < 10; $i++) {
            $this->entradaPronta($this->criarMaterial(), $local10, 10);
        }
        $inv10 = $this->criarInv->execute($local10, $this->user, null, false);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->iniciarInv->execute($inv10, $this->user);
        $q10 = count(DB::getQueryLog());

        $local100 = $this->criarLocal();
        for ($i = 0; $i < 100; $i++) {
            $this->entradaPronta($this->criarMaterial(), $local100, 10);
        }
        $inv100 = $this->criarInv->execute($local100, $this->user, null, false);

        DB::flushQueryLog();
        $this->iniciarInv->execute($inv100, $this->user);
        $q100 = count(DB::getQueryLog());

        fwrite(STDERR, "[AB] 10 posições: {$q10} queries | 100 posições: {$q100} queries\n");

        $this->assertLessThanOrEqual($q10 + 10, $q100, 'iniciar com 100 posições não deveria escalar proporcionalmente vs 10 (N+1 suspeito)');
        $this->assertSame(10, InventarioItem::where('inventario_estoque_id', $inv10->id)->count());
        $this->assertSame(100, InventarioItem::where('inventario_estoque_id', $inv100->id)->count());
    }

    public function test_ac_listagem_de_itens_sem_n_mais_1(): void
    {
        $local5 = $this->criarLocal();
        for ($i = 0; $i < 5; $i++) {
            $this->entradaPronta($this->criarMaterial(), $local5, 10);
        }
        $inv5 = $this->criarEIniciar($local5);
        foreach (InventarioItem::where('inventario_estoque_id', $inv5->id)->get() as $item) {
            $this->contar($item, 9);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $c5 = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])->set('inventarioDetalheId', $inv5->id);
        $linhas5 = $c5->instance()->itensInventarioDetalhe;
        $q5 = count(DB::getQueryLog());

        $local30 = $this->criarLocal();
        for ($i = 0; $i < 30; $i++) {
            $this->entradaPronta($this->criarMaterial(), $local30, 10);
        }
        $inv30 = $this->criarEIniciar($local30);
        foreach (InventarioItem::where('inventario_estoque_id', $inv30->id)->get()->take(15) as $item) {
            $this->contar($item, 9);
        }

        DB::flushQueryLog();
        $c30 = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])->set('inventarioDetalheId', $inv30->id);
        $linhas30 = $c30->instance()->itensInventarioDetalhe;
        $q30 = count(DB::getQueryLog());

        fwrite(STDERR, "[AC] 5 itens: {$q5} queries | 30 itens: {$q30} queries\n");

        $this->assertCount(5, $linhas5);
        $this->assertCount(30, $linhas30);
        $this->assertLessThanOrEqual($q5 + 5, $q30, 'esperava poucas queries fixas (ConciliacaoInventario em lote), nunca 1 por item');
    }

    // =========================================================
    // AD/AE/AF — arquitetura (nenhum saldo redundante, ledger imutável, zero 20.8)
    // =========================================================

    public function test_ad_nenhum_saldo_ou_diferenca_persistido_redundante(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('inventario_itens', 'diferenca'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('inventario_itens', 'saldo_atual'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('contagens_inventario', 'diferenca'));
    }

    public function test_ae_movimentacao_estoque_criada_pelo_ajuste_continua_imutavel(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);
        $ajuste = $this->aprovarAjuste->execute($item->fresh(), 'Falta identificada na contagem física.', $this->user);

        $this->expectException(\App\Exceptions\MovimentacaoEstoqueImutavelException::class);
        $ajuste->movimentacaoEstoque->update(['quantidade' => 999]);
    }

    public function test_af_zero_conceito_de_barcode_ou_20_8_no_codigo_de_producao(): void
    {
        $base = base_path('app');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        $proibidos = ['CodigoBarras', 'Barcode', 'LeituraCamera', 'ImpressaoEtiqueta', 'EtiquetaInventario'];

        $encontrados = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $conteudo = file_get_contents($file->getPathname());
            foreach ($proibidos as $termo) {
                if (str_contains($conteudo, $termo)) {
                    $encontrados[] = $file->getPathname() . ' :: ' . $termo;
                }
            }
        }

        $this->assertEmpty($encontrados, 'Conceitos da 20.8 encontrados prematuramente: ' . implode(', ', $encontrados));
    }

    public function test_af2_tipo_movimentacao_estoque_nunca_ganhou_case_de_ajuste(): void
    {
        $casos = array_map(fn ($c) => $c->name, TipoMovimentacaoEstoque::cases());
        $this->assertEqualsCanonicalizing(['Entrada', 'Saida'], $casos, 'Ajuste deve reaproveitar Entrada/Saida, nunca um case novo (decisão do usuário)');
    }

    // =========================================================
    // Zero efeito colateral em prontidão/Restrição
    // =========================================================

    public function test_zero_alteracao_de_restricao_ou_prontidao(): void
    {
        $local = $this->criarLocal();
        $material = $this->criarMaterial();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->contar($item, 97);
        $this->moverAnalise->execute($inv, $this->user);
        $this->aprovarAjuste->execute($item->fresh(), 'Falta identificada na contagem física.', $this->user);
        $this->concluirInv->execute($inv->fresh(), $this->user);

        $this->assertSame(0, DB::table('restricoes')->count());
    }
}
