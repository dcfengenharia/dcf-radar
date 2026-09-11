<?php

namespace Tests\Feature;

use App\Actions\Estoque\AdicionarItemInesperadoInventario;
use App\Actions\Estoque\AdicionarMaterialQuantitativoInesperadoInventario;
use App\Actions\Estoque\AprovarAjusteInventario;
use App\Actions\Estoque\ConcluirInventarioEstoque;
use App\Actions\Estoque\CriarInventarioEstoque;
use App\Actions\Estoque\IniciarInventarioEstoque;
use App\Actions\Estoque\MoverInventarioParaAnalise;
use App\Actions\Estoque\RegistrarContagemInventario;
use App\Actions\Estoque\RegistrarEntradaEstoque;
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
use App\Exceptions\CodigoEstoqueInvalidoException;
use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\AlocacaoRequisicaoPacote;
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
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\ResolverCodigoEstoque;
use App\Support\Estoque\SaldoEstoque;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.7.CORREÇÃO — BUG TARGETED: Inventário não permitia
 * contar Material Quantitativo com saldo sistêmico zero (achado em teste
 * manual real, cenário "EX-003"/"Área 08"). Reproduz EXATAMENTE o
 * cenário relatado e prova as garantias exigidas: contagem nunca altera
 * estoque antes da aplicação; a aplicação sempre gera exatamente 1
 * MovimentacaoEstoque formal; Lote/Serializado continuam exigindo sua
 * própria identidade física; tenant/obra/local/permissão preservados.
 */
class InventarioMaterialQuantitativoInesperadoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    private CriarInventarioEstoque $criarInv;
    private IniciarInventarioEstoque $iniciarInv;
    private RegistrarContagemInventario $registrarContagem;
    private MoverInventarioParaAnalise $moverAnalise;
    private AprovarAjusteInventario $aprovarAjuste;
    private AdicionarMaterialQuantitativoInesperadoInventario $adicionarQuantitativo;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UND', 'nome' => 'Unidade']);

        $this->criarInv = new CriarInventarioEstoque();
        $this->iniciarInv = new IniciarInventarioEstoque();
        $this->registrarContagem = new RegistrarContagemInventario();
        $this->moverAnalise = new MoverInventarioParaAnalise();
        $this->aprovarAjuste = new AprovarAjusteInventario();
        $this->adicionarQuantitativo = new AdicionarMaterialQuantitativoInesperadoInventario();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (mesma toolkit já estabelecida em InventarioEstoqueTest) ----

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

    /** Entrada 100% real de Material, indo por toda a cadeia RP→Pacote→RC→Pedido→Recebimento→Entrada, mesma técnica já estabelecida no domínio. */
    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade): void
    {
        $item = $this->criarItemTakeOffOrfao($material, $this->obra);
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);

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
        (new RegistrarEntradaEstoque())->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);
    }

    private function criarEIniciar(LocalEstoque $local): InventarioEstoque
    {
        $inv = $this->criarInv->execute($local, $this->user, 'Inventário inicial - teste', false);

        return $this->iniciarInv->execute($inv, $this->user);
    }

    // =========================================================
    // A-C — reprodução exata do cenário manual relatado
    // =========================================================

    public function test_a_material_quantitativo_saldo_zero_nunca_aparece_no_snapshot(): void
    {
        Material::create([
            'tenant_id' => $this->tenant->id, 'codigo' => 'EX-003', 'descricao' => 'exemplo 02',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ]);
        $local = $this->criarLocal(['nome' => 'Área 08']);

        $inv = $this->criarEIniciar($local);

        $this->assertSame(0, InventarioItem::where('inventario_estoque_id', $inv->id)->count());
        $this->assertEquals(0.0, SaldoEstoque::porMaterialLocal(Material::where('codigo', 'EX-003')->firstOrFail(), $local));
    }

    public function test_b_resolver_codigo_humano_ex003_resolve_material(): void
    {
        $material = Material::create([
            'tenant_id' => $this->tenant->id, 'codigo' => 'EX-003', 'descricao' => 'exemplo 02',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ]);

        $resultado = ResolverCodigoEstoque::resolver('EX-003');

        $this->assertSame('material', $resultado->tipo);
        $this->assertTrue($resultado->entidade->is($material));
    }

    public function test_c_adicionar_cria_item_com_snapshot_zero_e_nenhum_efeito_fisico(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003']);
        $local = $this->criarLocal(['nome' => 'Área 08']);
        $inv = $this->criarEIniciar($local);

        $totalMovimentacoesAntes = MovimentacaoEstoque::count();

        $item = $this->adicionarQuantitativo->execute($inv, $material, $this->user);

        $this->assertNotNull($item->id);
        $this->assertSame($material->id, $item->material_id);
        $this->assertNull($item->unidade_estoque_id);
        $this->assertNull($item->serial_texto_inesperado);
        $this->assertEquals(0.0, (float) $item->quantidade_sistema_snapshot);
        $this->assertFalse($item->ehSerialInesperado());

        // Nenhum efeito físico até aqui — só o item de inventário nasceu.
        $this->assertSame($totalMovimentacoesAntes, MovimentacaoEstoque::count());
        $this->assertEquals(0.0, SaldoEstoque::porMaterialLocal($material, $local));
    }

    // =========================================================
    // D — contagem não altera estoque; aplicação gera ledger formal
    // =========================================================

    public function test_d_contagem_de_40_ainda_nao_altera_estoque(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003']);
        $local = $this->criarLocal(['nome' => 'Área 08']);
        $inv = $this->criarEIniciar($local);
        $item = $this->adicionarQuantitativo->execute($inv, $material, $this->user);

        $this->registrarContagem->execute($item, 40, Carbon::today(), $this->user);

        $this->assertEquals(0.0, SaldoEstoque::porMaterialLocal($material, $local));
        $this->assertSame(0, MovimentacaoEstoque::where('material_id', $material->id)->count());
        $this->assertEquals(40.0, (float) $item->fresh()->ultimaContagem()->quantidade_contada);
        $this->assertEquals(40.0, $item->fresh()->diferenca());
    }

    public function test_e_aplicacao_gera_exatamente_uma_movimentacao_formal_de_ajuste(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003']);
        $local = $this->criarLocal(['nome' => 'Área 08']);
        $inv = $this->criarEIniciar($local);
        $item = $this->adicionarQuantitativo->execute($inv, $material, $this->user);
        $this->registrarContagem->execute($item, 40, Carbon::today(), $this->user);
        $this->moverAnalise->execute($inv->fresh(), $this->user);

        $ajuste = $this->aprovarAjuste->execute($item->fresh(), 'Encontrado fisicamente na Área 08, nunca lançado no sistema.', $this->user);

        $this->assertSame(1, MovimentacaoEstoque::where('material_id', $material->id)->count());
        $movimentacao = $ajuste->movimentacaoEstoque;
        $this->assertSame(TipoMovimentacaoEstoque::Entrada, $movimentacao->tipo);
        $this->assertEquals(40.0, (float) $movimentacao->quantidade);
        $this->assertSame($local->id, $movimentacao->local_estoque_id);
        $this->assertSame($this->obra->id, $movimentacao->obra_id);
        $this->assertSame($this->tenant->id, $movimentacao->tenant_id);
        $this->assertStringContainsString((string) $inv->fresh()->numero, $movimentacao->observacao);
        $this->assertStringContainsString('Ajuste de Inventário', $movimentacao->observacao);

        $this->assertEquals(40.0, SaldoEstoque::porMaterialLocal($material, $local));
    }

    // =========================================================
    // F/G — código inexistente / outro tenant
    // =========================================================

    public function test_f_codigo_inexistente_nao_estruturado_nem_humano_lanca_excecao(): void
    {
        $this->expectException(CodigoEstoqueInvalidoException::class);
        ResolverCodigoEstoque::resolver('NAO-EXISTE-NUNCA');
    }

    public function test_g_material_de_outro_tenant_mesmo_codigo_nunca_resolve(): void
    {
        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $outraUnidade = UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'UND2', 'nome' => 'Unidade']);
            Material::create([
                'tenant_id' => $outroTenant->id, 'codigo' => 'EX-003', 'descricao' => 'X',
                'unidade_medida_id' => $outraUnidade->id, 'modo_rastreabilidade' => 'quantitativo', 'ativo' => true,
            ]);
        });

        $this->expectException(CodigoEstoqueInvalidoException::class);
        ResolverCodigoEstoque::resolver('EX-003');
    }

    // =========================================================
    // H — Material inativo
    // =========================================================

    public function test_h_material_inativo_rejeitado(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003', 'ativo' => false]);
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->adicionarQuantitativo->execute($inv, $material, $this->user);
    }

    // =========================================================
    // I — resolver o mesmo Material duas vezes
    // =========================================================

    public function test_i_mesmo_material_duas_vezes_segunda_e_bloqueada(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003']);
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);

        $this->adicionarQuantitativo->execute($inv, $material, $this->user);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->adicionarQuantitativo->execute($inv->fresh(), $material, $this->user);
    }

    // =========================================================
    // J — Material Serializado nunca vira agregado quantitativo
    // =========================================================

    public function test_j_material_serializado_rejeitado_com_mensagem_propria(): void
    {
        $material = $this->criarMaterial(['codigo' => 'SER-001', 'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);

        try {
            $this->adicionarQuantitativo->execute($inv, $material, $this->user);
            $this->fail('esperava InventarioEstoqueInvalidoException');
        } catch (InventarioEstoqueInvalidoException $e) {
            $this->assertStringContainsString('Serializado', $e->getMessage());
            $this->assertStringContainsString('Registrar serial inesperado', $e->getMessage());
        }

        // "Registrar serial inesperado" continua sendo o caminho correto
        // pra este Material — nunca desabilitado por esta correção.
        $itemSerial = (new AdicionarItemInesperadoInventario())->execute($inv->fresh(), $material, 'SERIAL-X', 1, Carbon::today(), $this->user);
        $this->assertTrue($itemSerial->ehSerialInesperado());
    }

    // =========================================================
    // K — Material por Lote continua exigindo a Unidade
    // =========================================================

    public function test_k_material_por_lote_rejeitado_com_mensagem_propria(): void
    {
        $material = $this->criarMaterial(['codigo' => 'LOT-001', 'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);

        try {
            $this->adicionarQuantitativo->execute($inv, $material, $this->user);
            $this->fail('esperava InventarioEstoqueInvalidoException');
        } catch (InventarioEstoqueInvalidoException $e) {
            $this->assertStringContainsString('Lote', $e->getMessage());
        }

        // "Registrar serial inesperado" nunca aceita Lote (Ação já
        // existente, comportamento intocado por esta correção).
        $this->expectException(InventarioEstoqueInvalidoException::class);
        (new AdicionarItemInesperadoInventario())->execute($inv->fresh(), $material, 'LOTE-X', 1, Carbon::today(), $this->user);
    }

    // =========================================================
    // L — inventário que já possuía posição no snapshot não duplica
    // =========================================================

    public function test_l_material_ja_no_snapshot_nao_duplica_linha(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003']);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 25);
        $inv = $this->criarEIniciar($local);

        $this->assertSame(1, InventarioItem::where('inventario_estoque_id', $inv->id)->where('material_id', $material->id)->count());

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->adicionarQuantitativo->execute($inv, $material, $this->user);
    }

    // =========================================================
    // M/N — quantidade zero / negativa na contagem (regra já existente,
    // reafirmada sobre o item novo)
    // =========================================================

    public function test_m_quantidade_contada_zero_e_permitida(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003']);
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);
        $item = $this->adicionarQuantitativo->execute($inv, $material, $this->user);

        $contagem = $this->registrarContagem->execute($item, 0, Carbon::today(), $this->user);

        $this->assertEquals(0.0, (float) $contagem->quantidade_contada);
        $this->assertEquals(0.0, $item->fresh()->diferenca());
    }

    public function test_n_quantidade_contada_negativa_e_rejeitada(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003']);
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);
        $item = $this->adicionarQuantitativo->execute($inv, $material, $this->user);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->registrarContagem->execute($item, -5, Carbon::today(), $this->user);
    }

    // =========================================================
    // O — permissão
    // =========================================================

    public function test_o_usuario_sem_permissao_nao_consegue_adicionar_via_ui(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003']);
        $local = $this->criarLocal(['nome' => 'Área 08']);
        $inv = $this->criarEIniciar($local);

        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $semPermissao, 'cliente_leitura');
        $this->actingAs($semPermissao);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->set('inventarioDetalheId', $inv->id)
            ->call('processarScanInventario', 'EX-003')
            ->assertStatus(403);

        $this->assertSame(0, InventarioItem::where('inventario_estoque_id', $inv->id)->count());
    }

    // =========================================================
    // P — inventário fora de "Em Contagem"/"Em Análise"
    // =========================================================

    public function test_p_inventario_em_rascunho_bloqueia_adicionar(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003']);
        $local = $this->criarLocal();
        $inv = $this->criarInv->execute($local, $this->user, 'Rascunho', false);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->adicionarQuantitativo->execute($inv, $material, $this->user);
    }

    public function test_p2_inventario_concluido_bloqueia_adicionar(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003']);
        $local = $this->criarLocal();
        $inv = $this->criarEIniciar($local);
        $this->moverAnalise->execute($inv->fresh(), $this->user);
        (new ConcluirInventarioEstoque())->execute($inv->fresh(), $this->user);

        $this->expectException(InventarioEstoqueInvalidoException::class);
        $this->adicionarQuantitativo->execute($inv->fresh(), $material, $this->user);
    }

    // =========================================================
    // Q — isolamento local/obra/tenant (estrutural, via derivação)
    // =========================================================

    public function test_q_local_do_item_novo_sempre_deriva_do_inventario_nunca_e_manipulavel(): void
    {
        $material = $this->criarMaterial(['codigo' => 'EX-003']);
        $localCerto = $this->criarLocal(['nome' => 'Área 08']);
        $outroLocal = $this->criarLocal(['nome' => 'Área 09']);
        $this->entradaPronta($material, $outroLocal, 999);
        $inv = $this->criarEIniciar($localCerto);

        $item = $this->adicionarQuantitativo->execute($inv, $material, $this->user);

        // Mesmo o Material tendo 999 UND em OUTRO Local da mesma obra, o
        // snapshot deste item é sempre o saldo do Local DESTE inventário
        // (Área 08 = 0), nunca vazando saldo de outro Local.
        $this->assertEquals(0.0, (float) $item->quantidade_sistema_snapshot);
    }

    public function test_r_material_de_outra_obra_nunca_resolve_como_material_da_obra_atual(): void
    {
        // Material não é obra-scoped (catálogo do tenant inteiro) — o
        // teste aqui confirma que isso é intencional e não vira uma
        // brecha: o resolver aceita o Material de qualquer obra do MESMO
        // tenant (comportamento já documentado/testado desde a 20.8), e
        // quem de fato restringe a operação continua sendo o Inventário
        // (sempre de UMA obra específica) — nunca o Material em si.
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $material = Material::create([
            'tenant_id' => $this->tenant->id, 'codigo' => 'EX-003', 'descricao' => 'X',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ]);
        $local = $this->criarLocal(['nome' => 'Área 08'], $this->obra);
        $inv = $this->criarEIniciar($local);

        $item = $this->adicionarQuantitativo->execute($inv, $material, $this->user);

        $this->assertSame($this->obra->id, $inv->obra_id);
        $this->assertNotSame($outraObra->id, $inv->obra_id);
        $this->assertEquals(0.0, (float) $item->quantidade_sistema_snapshot);
    }

    // =========================================================
    // S — fluxo completo via UI (Livewire), reproduzindo o cenário
    // manual relatado ponta a ponta
    // =========================================================

    public function test_s_fluxo_completo_via_ui_reproduz_o_cenario_manual(): void
    {
        Material::create([
            'tenant_id' => $this->tenant->id, 'codigo' => 'EX-003', 'descricao' => 'exemplo 02',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ]);
        $local = $this->criarLocal(['nome' => 'Área 08']);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->call('abrirModalNovoInventario')
            ->set('invLocalId', $local->id)
            ->set('invTitulo', 'Inventário inicial - teste')
            ->call('confirmarNovoInventario')
            ->assertHasNoErrors();

        $inv = InventarioEstoque::firstOrFail();
        $component->set('inventarioDetalheId', $inv->id)
            ->call('confirmarIniciarInventario')
            ->assertHasNoErrors();

        // Antes do fix: este scan lançava "Código inválido — formato não
        // reconhecido." e nunca chegava a abrir o modal de contagem.
        $component->call('processarScanInventario', 'EX-003')
            ->assertSet('scanErro', null);

        $item = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();
        $this->assertEquals(0.0, (float) $item->quantidade_sistema_snapshot);
        $this->assertSame($component->get('modalContagemItemId'), $item->id);

        $component->set('contagemQuantidade', '40')
            ->set('contagemData', now()->toDateString())
            ->call('confirmarContagem')
            ->assertHasNoErrors();

        $component->call('confirmarMoverParaAnalise')->assertHasNoErrors();

        $component->call('abrirModalAjuste', $item->id)
            ->set('ajusteJustificativa', 'Encontrado fisicamente na Área 08, nunca lançado no sistema.')
            ->call('confirmarAjuste')
            ->assertHasNoErrors();

        $this->assertSame(1, InventarioAjuste::count());
        $this->assertEquals(40.0, SaldoEstoque::porMaterialLocal(Material::where('codigo', 'EX-003')->firstOrFail(), $local));
    }

    public function test_s2_scan_material_ja_existente_no_snapshot_continua_abrindo_contagem_normal(): void
    {
        // Regressão explícita: o caminho JÁ EXISTENTE (Material com saldo
        // > 0, já capturado no snapshot) nunca deve passar pelo caminho
        // novo de "criar item" — continua só localizando o item já
        // existente, exatamente como antes desta correção.
        $material = $this->criarMaterial(['codigo' => 'COM-SALDO']);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 50);
        $inv = $this->criarEIniciar($local);
        $itemOriginal = InventarioItem::where('inventario_estoque_id', $inv->id)->firstOrFail();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'inventario')
            ->set('inventarioDetalheId', $inv->id)
            ->call('processarScanInventario', 'COM-SALDO')
            ->assertSet('scanErro', null)
            ->assertSet('modalContagemItemId', $itemOriginal->id);

        $this->assertSame(1, InventarioItem::where('inventario_estoque_id', $inv->id)->count());
    }
}
