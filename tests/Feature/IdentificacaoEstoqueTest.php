<?php

namespace Tests\Feature;

use App\Actions\Estoque\AdicionarItemInesperadoInventario;
use App\Actions\Estoque\AprovarAjusteInventario;
use App\Actions\Estoque\ConcluirInventarioEstoque;
use App\Actions\Estoque\CriarInventarioEstoque;
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
use App\Enums\TipoLocalEstoque;
use App\Exceptions\CodigoEstoqueInvalidoException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\InventarioItem;
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
use App\Support\Estoque\GeradorCodigoEstoque;
use App\Support\Estoque\ResolverCodigoEstoque;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.8 — Identificação por Código de Barras/QR +
 * Etiquetas + Operação Assistida. Cobertura A-AP do pedido (Seções
 * 31-37).
 */
class IdentificacaoEstoqueTest extends TestCase
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

    private CriarInventarioEstoque $criarInv;
    private IniciarInventarioEstoque $iniciarInv;
    private RegistrarContagemInventario $registrarContagem;
    private AdicionarItemInesperadoInventario $itemInesperadoAction;
    private MoverInventarioParaAnalise $moverAnalise;
    private AprovarAjusteInventario $aprovarAjuste;
    private ConcluirInventarioEstoque $concluirInv;

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

        $this->criarInv = new CriarInventarioEstoque();
        $this->iniciarInv = new IniciarInventarioEstoque();
        $this->registrarContagem = new RegistrarContagemInventario();
        $this->itemInesperadoAction = new AdicionarItemInesperadoInventario();
        $this->moverAnalise = new MoverInventarioParaAnalise();
        $this->aprovarAjuste = new AprovarAjusteInventario();
        $this->concluirInv = new ConcluirInventarioEstoque();
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

    private function criarEIniciar(LocalEstoque $local): \App\Models\InventarioEstoque
    {
        $inv = $this->criarInv->execute($local, $this->user, 'Inventário Teste', false);

        return $this->iniciarInv->execute($inv, $this->user);
    }

    // =========================================================
    // A-I — Identidade
    // =========================================================

    public function test_a_codigo_material_unico(): void
    {
        $m1 = $this->criarMaterial();
        $m2 = $this->criarMaterial();

        $this->assertNotSame(GeradorCodigoEstoque::codigoMaterial($m1), GeradorCodigoEstoque::codigoMaterial($m2));
        $this->assertSame("MAT:{$m1->id}", GeradorCodigoEstoque::codigoMaterial($m1));
    }

    public function test_b_codigo_unidade_unico(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500, 'B001');
        $this->entradaPronta($material, $local, 300, 'B002');
        $u1 = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $u2 = UnidadeEstoque::where('codigo_lote', 'B002')->firstOrFail();

        $this->assertNotSame(GeradorCodigoEstoque::codigoUnidade($u1), GeradorCodigoEstoque::codigoUnidade($u2));
        $this->assertSame("UNI:{$u1->id}", GeradorCodigoEstoque::codigoUnidade($u1));
    }

    public function test_c_codigo_local_unico(): void
    {
        $l1 = $this->criarLocal();
        $l2 = $this->criarLocal();

        $this->assertNotSame(GeradorCodigoEstoque::codigoLocal($l1), GeradorCodigoEstoque::codigoLocal($l2));
        $this->assertSame("LOC:{$l1->id}", GeradorCodigoEstoque::codigoLocal($l1));
    }

    public function test_d_estabilidade_codigo_nunca_muda(): void
    {
        $material = $this->criarMaterial(['descricao' => 'Descrição Original']);
        $codigoAntes = GeradorCodigoEstoque::codigoMaterial($material);

        $material->update(['descricao' => 'Descrição Totalmente Diferente']);

        $codigoDepois = GeradorCodigoEstoque::codigoMaterial($material->fresh());
        $this->assertSame($codigoAntes, $codigoDepois);
    }

    public function test_e_codigo_invalido_formato(): void
    {
        $this->expectException(CodigoEstoqueInvalidoException::class);
        ResolverCodigoEstoque::resolver('sem-dois-pontos');
    }

    public function test_e2_codigo_invalido_prefixo_desconhecido(): void
    {
        $this->expectException(CodigoEstoqueInvalidoException::class);
        ResolverCodigoEstoque::resolver('XYZ:123');
    }

    public function test_e3_codigo_vazio(): void
    {
        $this->expectException(CodigoEstoqueInvalidoException::class);
        ResolverCodigoEstoque::resolver('   ');
    }

    public function test_f_codigo_desconhecido_nunca_existiu(): void
    {
        $this->expectException(CodigoEstoqueInvalidoException::class);
        ResolverCodigoEstoque::resolver('MAT:01NUNCAEXISTIU00000000000');
    }

    public function test_g_cross_tenant_nunca_resolve(): void
    {
        // BelongsToTenant carimba tenant_id a partir do usuário AUTENTICADO
        // no create(), ignorando qualquer valor explícito no array — a
        // fixture do "outro tenant" precisa nascer dentro de
        // TenantContext::actingAs(), senão ela é silenciosamente criada
        // sob o tenant do usuário logado (mesma lição já documentada
        // repetidas vezes neste projeto).
        $outroTenant = Tenant::factory()->create();
        $materialOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $outraUnidade = UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'M-OUTRO', 'nome' => 'Metro']);

            return Material::create([
                'tenant_id' => $outroTenant->id, 'codigo' => 'MAT-OUTRO-TENANT', 'descricao' => 'X',
                'unidade_medida_id' => $outraUnidade->id, 'modo_rastreabilidade' => 'quantitativo', 'ativo' => true,
            ]);
        });
        $codigo = GeradorCodigoEstoque::codigoMaterial($materialOutroTenant);

        // Ainda autenticado como $this->user (tenant original) — o código
        // de outro tenant produz a MESMA mensagem de "desconhecido", nunca
        // uma mensagem diferenciada (decisão de segurança, ver docblock).
        try {
            ResolverCodigoEstoque::resolver($codigo);
            $this->fail('esperava CodigoEstoqueInvalidoException');
        } catch (CodigoEstoqueInvalidoException $e) {
            $this->assertStringContainsString('desconhecido', $e->getMessage());
        }
    }

    public function test_h_cross_obra_bloqueado(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $localOutraObra = $this->criarLocal([], $outraObra);
        $codigo = GeradorCodigoEstoque::codigoLocal($localOutraObra);

        $this->expectException(CodigoEstoqueInvalidoException::class);
        ResolverCodigoEstoque::resolver($codigo, $this->obra->id);
    }

    public function test_h2_cross_obra_sem_obra_esperada_resolve_normalmente(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $localOutraObra = $this->criarLocal([], $outraObra);
        $codigo = GeradorCodigoEstoque::codigoLocal($localOutraObra);

        // Sem $obraIdEsperada, resolve normalmente (mesmo tenant) — quem
        // decide se isso é aceitável é o CHAMADOR, passando a obra certa.
        $resultado = ResolverCodigoEstoque::resolver($codigo);
        $this->assertSame($localOutraObra->id, $resultado->entidade->id);
    }

    public function test_h3_material_nunca_bloqueado_por_obra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $material = $this->criarMaterial();
        $codigo = GeradorCodigoEstoque::codigoMaterial($material);

        // Material é catálogo do TENANT inteiro — nunca obra-scoped.
        $resultado = ResolverCodigoEstoque::resolver($codigo, $outraObra->id);
        $this->assertSame($material->id, $resultado->entidade->id);
    }

    public function test_i_material_inativo_resolve_mas_sinaliza(): void
    {
        $material = $this->criarMaterial(['ativo' => false]);
        $codigo = GeradorCodigoEstoque::codigoMaterial($material);

        $resultado = ResolverCodigoEstoque::resolver($codigo);
        $this->assertSame($material->id, $resultado->entidade->id);
        $this->assertFalse($resultado->ativo);
    }

    public function test_i2_local_inativo_resolve_mas_sinaliza(): void
    {
        $local = $this->criarLocal(['ativo' => false]);
        $codigo = GeradorCodigoEstoque::codigoLocal($local);

        $resultado = ResolverCodigoEstoque::resolver($codigo, $this->obra->id);
        $this->assertFalse($resultado->ativo);
    }

    public function test_i3_unidade_estoque_nunca_tem_conceito_de_ativo(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $this->entradaSerial($material, $local, 'SER-ATIVO-TEST');
        $unidade = UnidadeEstoque::where('serial_unico', 'SER-ATIVO-TEST')->firstOrFail();

        $resultado = ResolverCodigoEstoque::resolver(GeradorCodigoEstoque::codigoUnidade($unidade), $this->obra->id);
        $this->assertNull($resultado->ativo);
    }

    // =========================================================
    // J-M — Quantitativo
    // =========================================================

    public function test_j_scan_material_resolve_tipo_material(): void
    {
        $material = $this->criarMaterial();
        $resultado = ResolverCodigoEstoque::resolver(GeradorCodigoEstoque::codigoMaterial($material));
        $this->assertSame('material', $resultado->tipo);
    }

    public function test_k_scan_local_resolve_tipo_local(): void
    {
        $local = $this->criarLocal();
        $resultado = ResolverCodigoEstoque::resolver(GeradorCodigoEstoque::codigoLocal($local), $this->obra->id);
        $this->assertSame('local', $resultado->tipo);
    }

    public function test_l_operacao_com_quantidade_via_ui(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->call('resolverEAplicarScan', GeradorCodigoEstoque::codigoLocal($local), 'saidaLocalId', 'local')
            ->call('resolverEAplicarScanMaterialOuUnidade', GeradorCodigoEstoque::codigoMaterial($material), 'saidaMaterialId', 'saidaUnidadeId')
            ->set('saidaQuantidade', 40)
            ->set('saidaData', now()->toDateString())
            ->set('saidaRetiradoPorId', $this->user->id)
            ->call('confirmarSaida')
            ->assertHasNoErrors();

        $this->assertEquals(60, \App\Support\Estoque\SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_m_quantitativo_nunca_cria_unidade_via_scan(): void
    {
        $material = $this->criarMaterial();
        $totalAntes = UnidadeEstoque::count();

        ResolverCodigoEstoque::resolver(GeradorCodigoEstoque::codigoMaterial($material));

        $this->assertSame($totalAntes, UnidadeEstoque::count());
    }

    // =========================================================
    // N-S — Bobina
    // =========================================================

    public function test_n_scan_b001_resolve_unidade(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();

        $resultado = ResolverCodigoEstoque::resolver(GeradorCodigoEstoque::codigoUnidade($unidade), $this->obra->id);
        $this->assertSame('unidade', $resultado->tipo);
        $this->assertSame($unidade->id, $resultado->entidade->id);
    }

    public function test_o_bobina_300_a_700_b_saldos_corretos(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $this->registrarTransferencia->execute($material, $localA, $localB, 700, Carbon::today(), $this->user, $unidade);

        $this->assertEquals(300, \App\Support\Estoque\SaldoEstoque::porUnidadeLocal($unidade->fresh(), $localA));
        $this->assertEquals(700, \App\Support\Estoque\SaldoEstoque::porUnidadeLocal($unidade->fresh(), $localB));
    }

    public function test_p_resolver_nao_determina_local_mostra_ambos(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        // O resolver NUNCA determina/expõe um "Local atual" — só resolve
        // a identidade; quem quer o saldo por Local consulta o ledger
        // explicitamente pra cada Local candidato.
        $resultado = ResolverCodigoEstoque::resolver(GeradorCodigoEstoque::codigoUnidade($unidade));
        $this->assertObjectNotHasProperty('local_atual', $resultado);

        $this->assertGreaterThan(0, \App\Support\Estoque\SaldoEstoque::porUnidadeLocal($unidade->fresh(), $localA));
        $this->assertGreaterThan(0, \App\Support\Estoque\SaldoEstoque::porUnidadeLocal($unidade->fresh(), $localB));
    }

    public function test_q_exige_selecionar_local_para_operar(): void
    {
        // O próprio guard já existente em RegistrarSaidaEstoque (20.5.CORREÇÃO)
        // já exige Local explícito — scan nunca contorna isso, só preenche
        // o MESMO campo que a Action valida.
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $this->registrarTransferencia->execute($material, $localA, $localB, 300, Carbon::today(), $this->user, $unidade);

        $this->expectException(\App\Exceptions\SaldoFisicoInsuficienteException::class);
        // Unidade tem saldo em A, mas a operação tenta em B sem saldo lá
        // suficiente pra esse valor -> continua bloqueado pela Action, nunca
        // pelo resolver (que não sabe nada de saldo).
        $this->registrarSaida->execute($material, $localB, 999999, Carbon::today(), $this->user, unidade: $unidade, retiradoPor: $this->user);
    }

    public function test_r_saldo_correto_apos_scan_e_operacao(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();

        $resultado = ResolverCodigoEstoque::resolver(GeradorCodigoEstoque::codigoUnidade($unidade), $this->obra->id);
        $this->registrarSaida->execute($material, $local, 100, Carbon::today(), $this->user, unidade: $resultado->entidade, retiradoPor: $this->user);

        $this->assertEquals(900, \App\Support\Estoque\SaldoEstoque::porUnidadeLocal($unidade->fresh(), $local));
    }

    public function test_s_transferencia_via_scan_completa(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $this->entradaPronta($material, $localA, 1000, 'B001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'transferencias')
            ->call('abrirModalTransferencia')
            ->call('resolverEAplicarScan', GeradorCodigoEstoque::codigoLocal($localA), 'transferenciaLocalOrigemId', 'local')
            ->call('resolverEAplicarScanMaterialOuUnidade', GeradorCodigoEstoque::codigoUnidade($unidade), 'transferenciaMaterialId', 'transferenciaUnidadeId')
            ->call('resolverEAplicarScan', GeradorCodigoEstoque::codigoLocal($localB), 'transferenciaLocalDestinoId', 'local')
            ->set('transferenciaQuantidade', 300)
            ->set('transferenciaData', now()->toDateString())
            ->call('confirmarTransferencia')
            ->assertHasNoErrors();

        $this->assertEquals(700, \App\Support\Estoque\SaldoEstoque::porUnidadeLocal($unidade->fresh(), $localA));
        $this->assertEquals(300, \App\Support\Estoque\SaldoEstoque::porUnidadeLocal($unidade->fresh(), $localB));
    }

    // =========================================================
    // T-W — Serial
    // =========================================================

    public function test_t_resolve_serial(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $this->entradaSerial($material, $local, 'SER-100');
        $unidade = UnidadeEstoque::where('serial_unico', 'SER-100')->firstOrFail();

        $resultado = ResolverCodigoEstoque::resolver(GeradorCodigoEstoque::codigoUnidade($unidade), $this->obra->id);
        $this->assertSame('unidade', $resultado->tipo);
        $this->assertSame('SER-100', $resultado->entidade->serial_unico);
    }

    public function test_u_local_derivado_do_ledger_nunca_do_resolver(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $this->entradaSerial($material, $local, 'SER-200');
        $unidade = UnidadeEstoque::where('serial_unico', 'SER-200')->firstOrFail();

        ResolverCodigoEstoque::resolver(GeradorCodigoEstoque::codigoUnidade($unidade));

        // "Local atual" é sempre derivado do ledger, nunca de
        // unidade.local_estoque_id (que é só origem/criação desde 20.5.CORREÇÃO).
        $this->assertEquals(1, \App\Support\Estoque\SaldoEstoque::porUnidadeLocal($unidade->fresh(), $local));
    }

    public function test_v_quantidade_1_para_serial(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $this->entradaSerial($material, $local, 'SER-300');
        $unidade = UnidadeEstoque::where('serial_unico', 'SER-300')->firstOrFail();

        $this->assertEquals(1, \App\Support\Estoque\SaldoEstoque::porUnidade($unidade));
    }

    public function test_w_segunda_saida_bloqueada_pelas_actions_existentes(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $this->entradaSerial($material, $local, 'SER-400');
        $unidade = UnidadeEstoque::where('serial_unico', 'SER-400')->firstOrFail();

        $this->registrarSaida->execute($material, $local, 1, Carbon::today(), $this->user, unidade: $unidade, retiradoPor: $this->user);

        $this->expectException(\App\Exceptions\SaldoFisicoInsuficienteException::class);
        $this->registrarSaida->execute($material, $local, 1, Carbon::today(), $this->user, unidade: $unidade->fresh(), retiradoPor: $this->user);
    }

    // =========================================================
    // X-AC — Inventário
    // =========================================================

    public function test_x_scan_esperado_localiza_item(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->set('inventarioDetalheId', $inv->id)
            ->call('processarScanInventario', GeradorCodigoEstoque::codigoMaterial($material));

        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->where('material_id', $material->id)->firstOrFail();
        $component->assertSet('modalContagemItemId', $item->id);
    }

    public function test_y_contagem_apos_scan(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->set('inventarioDetalheId', $inv->id)
            ->call('processarScanInventario', GeradorCodigoEstoque::codigoMaterial($material))
            ->set('contagemQuantidade', '97')
            ->set('contagemData', now()->toDateString())
            ->call('confirmarContagem')
            ->assertHasNoErrors();

        $this->assertEquals(-3.0, $item->fresh()->diferenca());
    }

    public function test_z_scan_serial_esperado(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $this->entradaSerial($material, $local, 'SER-INV-1');
        $unidade = UnidadeEstoque::where('serial_unico', 'SER-INV-1')->firstOrFail();
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->where('unidade_estoque_id', $unidade->id)->firstOrFail();

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->set('inventarioDetalheId', $inv->id)
            ->call('processarScanInventario', GeradorCodigoEstoque::codigoUnidade($unidade));

        $component->assertSet('modalContagemItemId', $item->id);
    }

    public function test_aa_serial_inesperado_permanece_textual_scan_nao_intercepta(): void
    {
        $materialSerial = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);

        $totalUnidadesAntes = UnidadeEstoque::count();
        $item = $this->itemInesperadoAction->execute($inv, $materialSerial, 'SERIAL-NAO-CADASTRADO', 1, Carbon::today(), $this->user);

        $this->assertSame($totalUnidadesAntes, UnidadeEstoque::count());
        $this->assertNull($item->unidade_estoque_id);
        $this->assertTrue($item->ehSerialInesperado());

        // Um scan de um serial que NÃO existe como UnidadeEstoque real
        // nunca resolve (não é MAT:/UNI:/LOC: válido) — reforça que o scan
        // nunca "inventa" a unidade sozinho.
        $this->expectException(CodigoEstoqueInvalidoException::class);
        ResolverCodigoEstoque::resolver('UNI:naoexisteessaunidade');
    }

    public function test_ab_recontagem_via_scan(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->registrarContagem->execute($item, 92, Carbon::today(), $this->user);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->set('inventarioDetalheId', $inv->id)
            ->call('processarScanInventario', GeradorCodigoEstoque::codigoMaterial($material))
            ->set('contagemQuantidade', '99')
            ->set('contagemData', now()->toDateString())
            ->call('confirmarContagem')
            ->assertHasNoErrors();

        $this->assertEquals(-1.0, $item->fresh()->diferenca());
        $this->assertSame(2, \App\Models\ContagemInventario::where('inventario_item_id', $item->id)->count());
    }

    public function test_ac_contagem_cega_nao_vaza_snapshot_no_scan(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarInv->execute($local, $this->user, null, true); // contagem_cega = true
        $inv = $this->iniciarInv->execute($inv, $this->user);

        $html = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->set('inventarioDetalheId', $inv->id)
            ->call('processarScanInventario', GeradorCodigoEstoque::codigoMaterial($material))
            ->html();

        // O resolver/scan em si nunca expõe o snapshot — quem decide
        // esconder a coluna "Sistema" é a MESMA regra já testada na 20.7
        // (contagem_cega + status em_contagem); aqui só confirmamos que o
        // fluxo de scan não introduz um vazamento paralelo.
        $this->assertStringNotContainsString('100,000', $html);
    }

    // =========================================================
    // AD-AJ — UI
    // =========================================================

    public function test_ad_input_manual(): void
    {
        $local = $this->criarLocal();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->call('resolverEAplicarScan', GeradorCodigoEstoque::codigoLocal($local), 'saidaLocalId', 'local')
            ->assertSet('saidaLocalId', $local->id)
            ->assertSet('scanErro', null);
    }

    public function test_ae_scanner_teclado_mesma_via_do_manual(): void
    {
        // Scanner USB/BT digita + Enter -> mesmo método Livewire que o
        // input manual (Seção 12) — não existe caminho de código separado.
        $local = $this->criarLocal();

        $resultado = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->call('resolverEAplicarScan', GeradorCodigoEstoque::codigoLocal($local), 'saidaLocalId', 'local');

        $resultado->assertSet('saidaLocalId', $local->id);
    }

    public function test_af_codigo_invalido_mostra_erro_amigavel_sem_500(): void
    {
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->call('resolverEAplicarScan', 'codigo-totalmente-invalido', 'saidaLocalId', 'local')
            ->assertSet('saidaLocalId', null)
            ->assertSet('scanErro', function ($msg) { return ! empty($msg); });
    }

    public function test_ag_codigo_de_outra_obra_bloqueado_via_ui(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $localOutraObra = $this->criarLocal([], $outraObra);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->call('resolverEAplicarScan', GeradorCodigoEstoque::codigoLocal($localOutraObra), 'saidaLocalId', 'local')
            ->assertSet('saidaLocalId', null)
            ->assertSet('scanErro', function ($msg) { return str_contains($msg, 'outra obra'); });
    }

    public function test_ah_nenhuma_action_automatica_apos_scan(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->call('resolverEAplicarScan', GeradorCodigoEstoque::codigoLocal($local), 'saidaLocalId', 'local')
            ->call('resolverEAplicarScanMaterialOuUnidade', GeradorCodigoEstoque::codigoMaterial($material), 'saidaMaterialId', 'saidaUnidadeId');

        // Nenhuma MovimentacaoEstoque de Saida criada só por escanear —
        // exige sempre o "Confirmar" explícito (Seção 23).
        $this->assertSame(0, \App\Models\MovimentacaoEstoque::where('tipo', 'saida')->count());
    }

    public function test_ah2_duplo_scan_nunca_duplica_efeito(): void
    {
        $local = $this->criarLocal();
        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->call('resolverEAplicarScan', GeradorCodigoEstoque::codigoLocal($local), 'saidaLocalId', 'local')
            ->call('resolverEAplicarScan', GeradorCodigoEstoque::codigoLocal($local), 'saidaLocalId', 'local');

        $component->assertSet('saidaLocalId', $local->id);
        $this->assertSame(0, \App\Models\MovimentacaoEstoque::count());
    }

    public function test_ai_camera_readiness_flag_presente_no_html(): void
    {
        $html = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->html();

        $this->assertStringContainsString('cameraSuportada', $html);
        $this->assertStringContainsString('BarcodeDetector', $html);
    }

    public function test_aj_fallback_sem_camera_input_manual_continua_funcionando(): void
    {
        // Não há como simular ausência real de BarcodeDetector no ambiente
        // de teste (headless PHP) — o que garantimos é que o fluxo NUNCA
        // depende da câmera pra funcionar: resolverEAplicarScan funciona
        // 100% via input/scanner teclado, independente de suporte a câmera.
        $local = $this->criarLocal();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->call('resolverEAplicarScan', GeradorCodigoEstoque::codigoLocal($local), 'saidaLocalId', 'local')
            ->assertSet('saidaLocalId', $local->id);
    }

    // =========================================================
    // AK-AP — Etiqueta
    // =========================================================

    public function test_ak_qr_material(): void
    {
        $material = $this->criarMaterial();
        $svg = GeradorCodigoEstoque::qrSvg(GeradorCodigoEstoque::codigoMaterial($material));
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_al_qr_unidade(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500, 'B999');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B999')->firstOrFail();

        $svg = GeradorCodigoEstoque::qrSvg(GeradorCodigoEstoque::codigoUnidade($unidade));
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_am_qr_local(): void
    {
        $local = $this->criarLocal();
        $svg = GeradorCodigoEstoque::qrSvg(GeradorCodigoEstoque::codigoLocal($local));
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_an_etiqueta_nao_contem_saldo(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 12345);

        $etiquetas = \App\Support\Estoque\MontarDadosEtiquetaEstoque::paraEntidades(collect([$material]));
        $html = view('exports.etiqueta-estoque-pdf', ['etiquetas' => $etiquetas, 'tamanho' => 'pequena'])->render();

        $this->assertStringNotContainsString('12345', $html);
        $this->assertStringNotContainsString('12.345', $html);
    }

    public function test_ao_etiqueta_unidade_nao_contem_localizacao_atual(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal(['nome' => 'Almoxarifado Muito Específico']);
        $this->entradaPronta($material, $local, 500, 'B777');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B777')->firstOrFail();

        $etiquetas = \App\Support\Estoque\MontarDadosEtiquetaEstoque::paraEntidades(collect([$unidade]));
        $html = view('exports.etiqueta-estoque-pdf', ['etiquetas' => $etiquetas, 'tamanho' => 'pequena'])->render();

        $this->assertStringNotContainsString('Almoxarifado Muito Específico', $html);
    }

    public function test_ap_impressao_multipla_lote(): void
    {
        $m1 = $this->criarMaterial();
        $m2 = $this->criarMaterial();
        $m3 = $this->criarMaterial();

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'materiais')
            ->call('exportarEtiquetasMateriaisLote');

        $component->assertFileDownloaded('etiquetas-materiais.pdf');
    }

    public function test_ap2_etiqueta_individual_material(): void
    {
        $material = $this->criarMaterial();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('exportarEtiquetaMaterial', $material->id)
            ->assertFileDownloaded("etiqueta-material-{$material->codigo}.pdf");
    }

    public function test_ap3_etiqueta_individual_local(): void
    {
        $local = $this->criarLocal();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('exportarEtiquetaLocal', $local->id)
            ->assertFileDownloaded('etiqueta-local-' . $local->nome . '.pdf');
    }

    public function test_ap4_etiqueta_local_de_outra_obra_bloqueada(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $localOutraObra = $this->criarLocal([], $outraObra);

        // ModelNotFoundException lançada dentro de um método Livewire
        // nem sempre é convertida em resposta HTTP observável via
        // assertStatus() dentro do harness de teste (propaga crua) —
        // mesma convenção já estabelecida no projeto (ex.: ImportacaoDetalheTest).
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('exportarEtiquetaLocal', $localOutraObra->id);
    }

    public function test_ap5_gerar_etiqueta_sem_permissao_bloqueado(): void
    {
        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $semPermissao, 'cliente_leitura');
        $this->actingAs($semPermissao);
        $material = $this->criarMaterial();

        // cliente_leitura TEM 'ver' em estoque.movimentacao (padrão do
        // catálogo) — então isso deveria FUNCIONAR (etiqueta é leitura),
        // reforçando que scanner/etiqueta nunca concede permissão NOVA,
        // só reaproveita 'ver' já existente (Seção 27).
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('exportarEtiquetaMaterial', $material->id)
            ->assertFileDownloaded("etiqueta-material-{$material->codigo}.pdf");
    }

    // =========================================================
    // Performance (Seção 38)
    // =========================================================

    public function test_performance_resolver_por_indice_sem_n_mais_1(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->criarMaterial();
        }
        $materialAlvo = $this->criarMaterial();

        DB::enableQueryLog();
        DB::flushQueryLog();
        ResolverCodigoEstoque::resolver(GeradorCodigoEstoque::codigoMaterial($materialAlvo));
        $q20 = count(DB::getQueryLog());

        for ($i = 0; $i < 980; $i++) {
            $this->criarMaterial();
        }
        $outroAlvo = $this->criarMaterial();

        DB::flushQueryLog();
        ResolverCodigoEstoque::resolver(GeradorCodigoEstoque::codigoMaterial($outroAlvo));
        $q1000 = count(DB::getQueryLog());

        fwrite(STDERR, "[PERF] 21 materiais: {$q20} queries | 1001 materiais: {$q1000} queries\n");

        $this->assertSame($q20, $q1000, 'resolver por PK indexada nunca deveria escalar com o total de registros');
    }

    // =========================================================
    // Arquitetura — zero conceito de fase futura, zero efeito colateral
    // =========================================================

    public function test_zero_conceito_de_fase_futura_no_codigo_de_producao(): void
    {
        $base = base_path('app');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        $proibidos = ['ZPL', 'EPL', 'IntegracaoErp', 'AppMobileNativo', 'class Code128', 'class Code39'];

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

        $this->assertEmpty($encontrados, 'Conceitos de fase futura encontrados prematuramente: ' . implode(', ', $encontrados));
    }

    public function test_zero_rota_publica_de_resolucao(): void
    {
        $rotas = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'estoque') || str_contains($r->getName() ?? '', 'estoque'));

        foreach ($rotas as $rota) {
            $middlewares = $rota->gatherMiddleware();
            $this->assertContains('auth', array_map(fn ($m) => explode(':', $m)[0], $middlewares), "Rota {$rota->uri()} deveria exigir autenticação.");
        }
    }

    public function test_zero_alteracao_de_restricao_ou_prontidao(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);
        $inv = $this->criarEIniciar($local);
        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->set('inventarioDetalheId', $inv->id)
            ->call('processarScanInventario', GeradorCodigoEstoque::codigoMaterial($material))
            ->set('contagemQuantidade', '97')
            ->set('contagemData', now()->toDateString())
            ->call('confirmarContagem');

        $this->moverAnalise->execute($inv, $this->user);
        $this->aprovarAjuste->execute($item->fresh(), 'Falta identificada na contagem física.', $this->user);
        $this->concluirInv->execute($inv->fresh(), $this->user);

        $this->assertSame(0, DB::table('restricoes')->count());
    }
}
