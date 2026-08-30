<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarDestinacaoPlanejada;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\LiberarReservaEstoque;
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
use App\Enums\TipoLocalEstoque;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.6.CORREÇÃO — fecha o resíduo conhecido da 20.6
 * (achado registrado, não corrigido lá): `saldoFisicoPreviewSaida()`
 * (unidade lote/serial) usava saldo GLOBAL em vez do saldo físico NO
 * LOCAL selecionado — o ledger é a autoridade de localização
 * quantitativa desde a 20.5.CORREÇÃO, mas essa prévia visual nunca
 * tinha sido corrigida junto.
 *
 * Auditoria (Seção 11 do pedido) encontrou 2 previews IRMÃS com a MESMA
 * causa raiz, no mesmo arquivo: `saldoNaoReservadoPreviewSaida()` (modal
 * de Saída) e `saldoDisponivelPreviewReserva()` (modal de Reserva) — as
 * duas também corrigidas aqui, mesma causa raiz, nenhuma regra de
 * negócio nova (só a extensão mecânica já usada em toda a Etapa 20 —
 * `SaldoEstoque::porUnidadeLocal()`/novo par irmão
 * `SaldoReserva::porUnidadeLocal()`/`disponivelPorUnidadeLocal()`).
 *
 * Transferência (`saldoOrigemPreviewTransferencia`) e Industrialização
 * (`unidadesDisponiveisParaRemessa`) já estavam corretas desde que
 * nasceram (20.6/20.5.CORREÇÃO) — confirmado por grep, sem alteração.
 */
class EstoquePreviewLocalTest extends TestCase
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
    private LiberarReservaEstoque $liberarAction;
    private AtualizarDestinacaoPlanejada $destinacaoAction;

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
        $this->liberarAction = new LiberarReservaEstoque();
        $this->destinacaoAction = new AtualizarDestinacaoPlanejada();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (mesma toolkit de EstoqueSaidaTest/EstoqueSaidaCorrecaoTest) ----

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

    // =========================================================
    // A/B/C — Bobina dividida entre 2 Locais (Seção 4)
    // =========================================================

    public function test_a_bobina_dividida_saldo_global_e_1000(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        $this->assertEquals(1000, SaldoEstoque::porUnidade($unidade->fresh()));
    }

    public function test_b_preview_saida_no_local_a_mostra_700(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)
            ->set('saidaLocalId', $localA->id)
            ->set('saidaUnidadeId', $unidade->id);

        $this->assertEquals(700, $component->instance()->saldoFisicoPreviewSaida);
    }

    public function test_c_preview_saida_no_local_b_mostra_300(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)
            ->set('saidaLocalId', $localB->id)
            ->set('saidaUnidadeId', $unidade->id);

        $this->assertEquals(300, $component->instance()->saldoFisicoPreviewSaida);
    }

    // =========================================================
    // D — Quantitativo (Seção 5)
    // =========================================================

    public function test_d_quantitativo_preview_correto_em_cada_local(): void
    {
        $material = $this->criarMaterial();
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 40);
        $this->entradaPronta($material, $localB, 60);

        $previewA = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)->set('saidaLocalId', $localA->id)
            ->instance()->saldoFisicoPreviewSaida;

        $previewB = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)->set('saidaLocalId', $localB->id)
            ->instance()->saldoFisicoPreviewSaida;

        $this->assertEquals(40, $previewA);
        $this->assertEquals(60, $previewB);
    }

    // =========================================================
    // E — Serial (Seção 6)
    // =========================================================

    public function test_e_serial_nao_disponivel_no_local_sem_presenca_fisica(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaSerial($material, $localA, 'S1');
        $unidade = UnidadeEstoque::where('serial_unico', 'S1')->firstOrFail();
        $this->registrarTransferencia->execute($material, $localA, $localB, 1, Carbon::today(), $this->user, $unidade);

        // A = 0
        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)->set('saidaLocalId', $localA->id);
        $this->assertCount(0, $component->instance()->unidadesDisponiveisParaSaida);

        // B = 1
        $componentB = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)->set('saidaLocalId', $localB->id)
            ->set('saidaUnidadeId', $unidade->id);
        $this->assertEquals(1, $componentB->instance()->saldoFisicoPreviewSaida);
    }

    // =========================================================
    // F — Transferência altera o preview imediatamente (Seção 8)
    // =========================================================

    public function test_f_transferencia_altera_preview_sem_refresh_estrutural(): void
    {
        $material = $this->criarMaterial();
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 100);

        $antes = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)->set('saidaLocalId', $localB->id)
            ->instance()->saldoFisicoPreviewSaida;
        $this->assertEquals(0, $antes);

        $this->registrarTransferencia->execute($material, $localA, $localB, 40, Carbon::today(), $this->user);

        $depois = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)->set('saidaLocalId', $localB->id)
            ->instance()->saldoFisicoPreviewSaida;
        $this->assertEquals(40, $depois);
    }

    // =========================================================
    // G — saldo global permanece correto (Seção 10 do teste, item G)
    // =========================================================

    public function test_g_saldo_global_da_unidade_continua_correto(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        $this->assertEquals(1000, SaldoEstoque::porUnidade($unidade->fresh()));
        $this->assertEquals(1000, SaldoEstoque::porMaterial($material));
    }

    // =========================================================
    // H — Reserva não alterada; previews de Reserva também corrigidos
    // =========================================================

    public function test_h_reserva_nao_alterada_pela_correcao(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $reserva = $this->reservaAction->execute($pacote, $material, $localA, 200, $unidade, null, $this->user);

        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        $this->assertTrue($reserva->fresh()->estaAtiva());
        $this->assertEquals(200, (float) $reserva->fresh()->quantidade);
        $this->assertSame($localA->id, $reserva->fresh()->local_estoque_id);
    }

    public function test_h2_saldo_nao_reservado_preview_saida_escopado_ao_local(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        // Reserva de 200 no Local A — nunca deveria afetar o "não
        // reservado" de uma Saída no Local B.
        $this->reservaAction->execute($pacote, $material, $localA, 200, $unidade, null, $this->user);
        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        $componentB = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)->set('saidaLocalId', $localB->id)
            ->set('saidaUnidadeId', $unidade->id);
        $this->assertEquals(300, $componentB->instance()->saldoNaoReservadoPreviewSaida);

        $componentA = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)->set('saidaLocalId', $localA->id)
            ->set('saidaUnidadeId', $unidade->id);
        // Físico em A = 700, reservado em A = 200 -> não reservado = 500
        $this->assertEquals(500, $componentA->instance()->saldoNaoReservadoPreviewSaida);
    }

    public function test_h3_saldo_disponivel_preview_reserva_escopado_ao_local(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        $componentB = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'planejamento')->call('abrirModalReserva')
            ->set('reservaMaterialId', $material->id)->set('reservaLocalId', $localB->id)
            ->set('reservaUnidadeId', $unidade->id);
        $this->assertEquals(300, $componentB->instance()->saldoDisponivelPreviewReserva);

        $componentA = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'planejamento')->call('abrirModalReserva')
            ->set('reservaMaterialId', $material->id)->set('reservaLocalId', $localA->id)
            ->set('reservaUnidadeId', $unidade->id);
        $this->assertEquals(700, $componentA->instance()->saldoDisponivelPreviewReserva);
    }

    // =========================================================
    // I/J — cross-obra / cross-tenant
    // =========================================================

    public function test_i_cross_obra_local_de_outra_obra_nunca_aparece(): void
    {
        $material = $this->criarMaterial();
        $localA = $this->criarLocal();
        $this->entradaPronta($material, $localA, 100);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);
        $localOutraObra = $this->criarLocal([], $outraObra);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)
            ->set('saidaLocalId', $localOutraObra->id);

        // LocalEstoque::find() dentro do computed acha a linha (obra
        // diferente do MESMO tenant), mas o saldo por Local dessa obra
        // nunca vaza pra cá — sem entrada nesse Local, o preview é 0.
        $this->assertEquals(0, $component->instance()->saldoFisicoPreviewSaida);
    }

    public function test_j_cross_tenant_isolamento_do_saldo_por_local(): void
    {
        $material = $this->criarMaterial();
        $localA = $this->criarLocal();
        $this->entradaPronta($material, $localA, 100);

        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $outroUser, Papel::GerentePlanejamento->value);
        $this->actingAs($outroUser);

        $this->assertEquals(0.0, SaldoEstoque::porMaterialLocal($material, $localA));
    }

    // =========================================================
    // K — zero alteração de domínio
    // =========================================================

    public function test_k_zero_alteracao_de_dominio_so_leitura(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $reserva = $this->reservaAction->execute($pacote, $material, $localA, 200, $unidade, null, $this->user);
        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        $localOrigemAntes = $unidade->fresh()->local_estoque_id;

        // Só ler os previews várias vezes — nunca deve mutar nada.
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)->set('saidaLocalId', $localA->id)
            ->set('saidaUnidadeId', $unidade->id)
            ->instance()->saldoFisicoPreviewSaida;

        $this->assertSame($localOrigemAntes, $unidade->fresh()->local_estoque_id);
        $this->assertEquals(200, (float) $reserva->fresh()->quantidade);
        $this->assertTrue($reserva->fresh()->estaAtiva());
    }

    // =========================================================
    // L — zero conceito de 20.7 no código de produção
    // =========================================================

    public function test_l_zero_conceito_pos_20_6_correcao_no_codigo_de_producao(): void
    {
        // Mesmo padrão já estabelecido em EstoqueSaidaTest::test_ar_.../
        // EstoqueIndustrializacaoTest::test_zero_conceito_de_20_6_no_codigo()
        // — busca por TOKENS de implementação real (case de enum, nome de
        // classe), nunca a palavra crua isolada (que aparece legitimamente
        // em prosa de docblock explicando o que NÃO foi implementado ainda,
        // ex.: "quando uma fase futura adicionar Saída/Ajuste/Estorno").
        $base = base_path('app');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        $proibidos = ['case Inventario', 'case AjusteEstoque', 'case Estorno', 'TransferenciaInterna', 'CodigoBarras1D'];

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

        $this->assertEmpty($encontrados, 'Conceitos pós-20.6.CORREÇÃO encontrados prematuramente: ' . implode(', ', $encontrados));
    }
}
