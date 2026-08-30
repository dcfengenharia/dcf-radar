<?php

namespace Tests\Feature;

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
use App\Enums\TipoLocalEstoque;
use App\Exceptions\EntradaEstoqueInvalidaException;
use App\Exceptions\ItemTakeOffMaterialImutavelException;
use App\Exceptions\MovimentacaoEstoqueImutavelException;
use App\Exceptions\SaldoRecebimentoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\PedidoCompraItem;
use App\Models\RecebimentoPedido;
use App\Models\Tenant;
use App\Models\UnidadeEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\SaldoEstoque;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.1 — Fundação do Estoque (Material/LocalEstoque/
 * UnidadeEstoque/MovimentacaoEstoque + entrada a partir de
 * RecebimentoPedido). Cobertura condensada da matriz A-AQ do pedido,
 * mesma convenção de RecebimentoPedidoTest/PedidoCompraTest.
 */
class EstoqueFundacaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidadeM;
    private UnidadeMedida $unidadeUn;

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
    private RegistrarEntradaEstoque $registrarEntrada;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-12-15'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidadeM = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
        $this->unidadeUn = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);

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
        $this->registrarEntrada = new RegistrarEntradaEstoque();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers ----

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
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste ' . uniqid()]);
        foreach ($etapas as $indice => [$nome, $prazo]) {
            $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => $indice + 1, 'nome' => $nome, 'prazo_dias_uteis' => $prazo]);
        }
        return $fluxo->fresh(['etapas']);
    }

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidadeM->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(array $overrides = [], ?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Almoxarifado ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value,
            'ativo' => true,
        ], $overrides));
    }

    /** Mesmo helper de RecebimentoPedidoTest, expondo o ItemTakeOff por referência. */
    private function alocacaoPronta(float $quantidadeAlocada, ?ItemSuprimento $pacote = null, ?Work $obra = null, float $quantidadePrevista = 1000, ?ItemTakeOff &$itemTakeOffRef = null, ?Material $material = null): AlocacaoRequisicaoPacote
    {
        $obraAlvo = $obra ?? $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obraAlvo->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id,
            'codigo' => 'A' . uniqid(),
            'descricao' => 'Item A',
            'quantidade' => $quantidadePrevista,
            'material_id' => $material?->id,
        ]);
        $itemTakeOffRef = $item;
        $rp = $this->criarRp->execute($obraAlvo->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidadeAlocada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $this->alocar->alocar($rpItem->fresh(), $pacote ?? $this->criarPacote(obra: $obraAlvo), $quantidadeAlocada);
    }

    /**
     * Monta a cadeia completa até um recebimento físico registrado,
     * retornando [RecebimentoPedido, ItemTakeOff, PedidoCompraItem].
     *
     * @return array{0: RecebimentoPedido, 1: ItemTakeOff, 2: PedidoCompraItem}
     */
    private function recebimentoPronto(float $quantidade, ?Material $material = null, ?Work $obra = null): array
    {
        $itemTakeOffRef = null;
        $pacote = $this->criarPacote(obra: $obra);
        $alocacao = $this->alocacaoPronta(max($quantidade, 100), $pacote, $obra, 1000, $itemTakeOffRef, $material);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = $this->criarFornecedor(obra: $obra);
        $pedido = $this->criarPedido->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $rcItem, $quantidade);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = $this->registrarRecebimento->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);

        return [$recebimento, $itemTakeOffRef, $pedidoItem];
    }

    // =========================================================
    // A-G: Material
    // =========================================================

    public function test_a_criar_material(): void
    {
        $material = $this->criarMaterial(['codigo' => 'CABO-3X16']);

        $this->assertDatabaseHas('materiais', ['id' => $material->id, 'codigo' => 'CABO-3X16']);
        $this->assertSame(ModoRastreabilidadeMaterial::Quantitativo, $material->modo_rastreabilidade);
    }

    public function test_b_unique_estrutural_codigo(): void
    {
        $this->criarMaterial(['codigo' => 'DUP-001']);

        $this->expectException(QueryException::class);
        $this->criarMaterial(['codigo' => 'DUP-001']);
    }

    public function test_c_mesmo_material_em_dois_item_take_off(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();

        [$recebimento1] = $this->recebimentoPronto(500, $material);
        [$recebimento2] = $this->recebimentoPronto(300, $material);

        $this->registrarEntrada->execute($recebimento1, $local, 500, Carbon::today(), $this->user);
        $this->registrarEntrada->execute($recebimento2, $local, 300, Carbon::today(), $this->user);

        $this->assertEqualsWithDelta(800.0, SaldoEstoque::porMaterial($material), 0.001);
    }

    public function test_d_item_sem_material_nao_entra_em_estoque(): void
    {
        [$recebimento] = $this->recebimentoPronto(100, null);
        $local = $this->criarLocal();

        $this->expectException(EntradaEstoqueInvalidaException::class);
        $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::today(), $this->user);
    }

    public function test_e_material_inativo_bloqueia_nova_entrada(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();
        $material->update(['ativo' => false]);

        $this->expectException(EntradaEstoqueInvalidaException::class);
        $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::today(), $this->user);
    }

    public function test_f_historico_preservado_apos_inativacao(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();

        $movimentacao = $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::today(), $this->user);
        $material->update(['ativo' => false]);

        $this->assertDatabaseHas('movimentacoes_estoque', ['id' => $movimentacao->id, 'quantidade' => 50.000]);
        $this->assertEqualsWithDelta(50.0, SaldoEstoque::porMaterial($material->fresh()), 0.001);
    }

    public function test_g_cross_tenant_material(): void
    {
        $outroTenant = Tenant::factory()->create();
        $materialOutroTenant = TenantContext::actingAs($outroTenant, fn () => Material::create([
            'tenant_id' => $outroTenant->id,
            'codigo' => 'OUTRO-TENANT',
            'descricao' => 'X',
            'unidade_medida_id' => TenantContext::actingAs($outroTenant, fn () => UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'UN', 'nome' => 'Unidade'])->id),
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
        ]));

        $this->assertNull(Material::find($materialOutroTenant->id));
    }

    // =========================================================
    // H-L: LocalEstoque
    // =========================================================

    public function test_h_criar_local(): void
    {
        $local = $this->criarLocal(['nome' => 'Container 01', 'tipo' => TipoLocalEstoque::Container->value]);
        $this->assertDatabaseHas('locais_estoque', ['id' => $local->id, 'nome' => 'Container 01']);
    }

    public function test_i_obra_scoped(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $localA = $this->criarLocal(obra: $this->obra);
        $localB = $this->criarLocal(obra: $outraObra);

        $this->assertSame($this->obra->id, $localA->obra_id);
        $this->assertSame($outraObra->id, $localB->obra_id);
        $this->assertNotSame($localA->obra_id, $localB->obra_id);
    }

    public function test_j_local_inativo_bloqueia_entrada(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();
        $local->update(['ativo' => false]);

        $this->expectException(EntradaEstoqueInvalidaException::class);
        $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::today(), $this->user);
    }

    public function test_k_cross_obra_local_bloqueado(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material, $this->obra);
        $localDeOutraObra = $this->criarLocal(obra: $outraObra);

        $this->expectException(EntradaEstoqueInvalidaException::class);
        $this->registrarEntrada->execute($recebimento, $localDeOutraObra, 50, Carbon::today(), $this->user);
    }

    public function test_l_historico_preservado_local_inativo(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();

        $movimentacao = $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::today(), $this->user);
        $local->update(['ativo' => false]);

        $this->assertDatabaseHas('movimentacoes_estoque', ['id' => $movimentacao->id]);
    }

    // =========================================================
    // M-X: Entrada
    // =========================================================

    public function test_m_entrada_quantitativa_basica(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();

        $movimentacao = $this->registrarEntrada->execute($recebimento, $local, 100, Carbon::today(), $this->user);

        $this->assertNull($movimentacao->unidade_estoque_id);
        $this->assertEqualsWithDelta(100.0, SaldoEstoque::porMaterialLocal($material, $local), 0.001);
    }

    public function test_n_entrada_parcial(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();

        $this->registrarEntrada->execute($recebimento, $local, 60, Carbon::today(), $this->user);

        $this->assertEqualsWithDelta(40.0, SaldoEstoque::pendenteDeIncorporacao($recebimento->fresh()), 0.001);
    }

    public function test_o_multiplas_entradas_mesmo_recebimento(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$recebimento] = $this->recebimentoPronto(1000, $material);
        $local = $this->criarLocal();

        $this->registrarEntrada->execute($recebimento, $local, 500, Carbon::today(), $this->user, codigoLote: 'B-001');
        $this->registrarEntrada->execute($recebimento->fresh(), $local, 500, Carbon::today(), $this->user, codigoLote: 'B-002');

        $this->assertSame(2, MovimentacaoEstoque::where('recebimento_pedido_id', $recebimento->id)->count());
        $this->assertEqualsWithDelta(0.0, SaldoEstoque::pendenteDeIncorporacao($recebimento->fresh()), 0.001);
    }

    public function test_p_over_entrada_bloqueada(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();
        $this->registrarEntrada->execute($recebimento, $local, 90, Carbon::today(), $this->user);

        $this->expectException(SaldoRecebimentoInsuficienteException::class);
        $this->registrarEntrada->execute($recebimento->fresh(), $local, 20, Carbon::today(), $this->user);
    }

    public function test_q_limite_exato_passa(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();
        $this->registrarEntrada->execute($recebimento, $local, 60, Carbon::today(), $this->user);
        $this->registrarEntrada->execute($recebimento->fresh(), $local, 40, Carbon::today(), $this->user);

        $this->assertEqualsWithDelta(0.0, SaldoEstoque::pendenteDeIncorporacao($recebimento->fresh()), 0.001);
    }

    /** Prova estrutural: lock em recebimentos_pedido acontece ANTES do INSERT em movimentacoes_estoque. */
    public function test_r_ordem_de_lock_estrutural(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::today(), $this->user);

        DB::flushQueryLog();

        $indiceLock = null;
        $indiceInsert = null;
        foreach ($queries as $i => $sql) {
            if ($indiceLock === null && str_contains($sql, 'recebimentos_pedido') && str_contains(strtolower($sql), 'for update')) {
                $indiceLock = $i;
            }
            if ($indiceInsert === null && str_contains($sql, 'insert') && str_contains($sql, 'movimentacoes_estoque')) {
                $indiceInsert = $i;
            }
        }

        $this->assertNotNull($indiceLock, 'Lock FOR UPDATE em recebimentos_pedido não foi encontrado.');
        $this->assertNotNull($indiceInsert, 'INSERT em movimentacoes_estoque não foi encontrado.');
        $this->assertLessThan($indiceInsert, $indiceLock, 'O lock precisa acontecer ANTES do insert da movimentação.');
    }

    public function test_s_data_retroativa_permitida(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();

        $movimentacao = $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::parse('2026-11-01'), $this->user);

        $this->assertEquals('2026-11-01', $movimentacao->ocorrido_em->toDateString());
    }

    public function test_t_data_futura_bloqueada(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();

        $this->expectException(EntradaEstoqueInvalidaException::class);
        $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::parse('2027-01-01'), $this->user);
    }

    public function test_u_historico_append_only_update_bloqueado(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();
        $movimentacao = $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::today(), $this->user);

        $movimentacao->quantidade = 999;
        $this->expectException(MovimentacaoEstoqueImutavelException::class);
        $movimentacao->save();
    }

    public function test_v_delete_bloqueado(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();
        $movimentacao = $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::today(), $this->user);

        $this->expectException(MovimentacaoEstoqueImutavelException::class);
        $movimentacao->delete();
    }

    public function test_w_forcedelete_bloqueado(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();
        $movimentacao = $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::today(), $this->user);

        $this->expectException(MovimentacaoEstoqueImutavelException::class);
        $movimentacao->forceDelete();
    }

    // =========================================================
    // Y-AG: Rastreabilidade
    // =========================================================

    public function test_y_quantitativo_sem_lote(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value]);
        [$recebimento] = $this->recebimentoPronto(1000, $material);
        $local = $this->criarLocal();

        $movimentacao = $this->registrarEntrada->execute($recebimento, $local, 1000, Carbon::today(), $this->user);

        $this->assertNull($movimentacao->unidade_estoque_id);
        $this->assertSame(0, UnidadeEstoque::where('material_id', $material->id)->count());
    }

    public function test_z_bobina_lote_criada(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$recebimento] = $this->recebimentoPronto(500, $material);
        $local = $this->criarLocal();

        $movimentacao = $this->registrarEntrada->execute($recebimento, $local, 500, Carbon::today(), $this->user, codigoLote: 'B-001');

        $this->assertNotNull($movimentacao->unidade_estoque_id);
        $this->assertDatabaseHas('unidades_estoque', ['id' => $movimentacao->unidade_estoque_id, 'codigo_lote' => 'B-001']);
    }

    public function test_z2_lote_obrigatorio_para_modo_lote(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$recebimento] = $this->recebimentoPronto(500, $material);
        $local = $this->criarLocal();

        $this->expectException(EntradaEstoqueInvalidaException::class);
        $this->registrarEntrada->execute($recebimento, $local, 500, Carbon::today(), $this->user);
    }

    public function test_aa_dois_lotes_mesmo_material(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$recebimento] = $this->recebimentoPronto(1000, $material);
        $local = $this->criarLocal();

        $mov1 = $this->registrarEntrada->execute($recebimento, $local, 500, Carbon::today(), $this->user, codigoLote: 'B-001');
        $mov2 = $this->registrarEntrada->execute($recebimento->fresh(), $local, 500, Carbon::today(), $this->user, codigoLote: 'B-002');

        $this->assertNotSame($mov1->unidade_estoque_id, $mov2->unidade_estoque_id);
        $this->assertSame(2, UnidadeEstoque::where('material_id', $material->id)->count());
    }

    public function test_ab_serializado_entrada(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value, 'unidade_medida_id' => $this->unidadeUn->id]);
        [$recebimento] = $this->recebimentoPronto(3, $material);
        $local = $this->criarLocal();

        $mov1 = $this->registrarEntrada->execute($recebimento, $local, 1, Carbon::today(), $this->user, serialUnico: 'SN-001');
        $mov2 = $this->registrarEntrada->execute($recebimento->fresh(), $local, 1, Carbon::today(), $this->user, serialUnico: 'SN-002');

        $this->assertNotNull($mov1->unidade_estoque_id);
        $this->assertNotNull($mov2->unidade_estoque_id);
        $this->assertNotSame($mov1->unidade_estoque_id, $mov2->unidade_estoque_id);
    }

    public function test_ac_serial_duplicado_bloqueado(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value, 'unidade_medida_id' => $this->unidadeUn->id]);
        [$recebimento] = $this->recebimentoPronto(3, $material);
        $local = $this->criarLocal();
        $this->registrarEntrada->execute($recebimento, $local, 1, Carbon::today(), $this->user, serialUnico: 'SN-DUP');

        $this->expectException(EntradaEstoqueInvalidaException::class);
        $this->registrarEntrada->execute($recebimento->fresh(), $local, 1, Carbon::today(), $this->user, serialUnico: 'SN-DUP');
    }

    public function test_ad_serial_quantidade_diferente_de_1_bloqueada(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value, 'unidade_medida_id' => $this->unidadeUn->id]);
        [$recebimento] = $this->recebimentoPronto(3, $material);
        $local = $this->criarLocal();

        $this->expectException(EntradaEstoqueInvalidaException::class);
        $this->registrarEntrada->execute($recebimento, $local, 2, Carbon::today(), $this->user, serialUnico: 'SN-003');
    }

    public function test_ae_saldo_por_lote(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$recebimento] = $this->recebimentoPronto(1000, $material);
        $local = $this->criarLocal();
        $mov = $this->registrarEntrada->execute($recebimento, $local, 500, Carbon::today(), $this->user, codigoLote: 'B-777');

        $this->assertEqualsWithDelta(500.0, SaldoEstoque::porUnidade($mov->unidadeEstoque), 0.001);
    }

    public function test_af_saldo_por_local(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(1000, $material);
        $localA = $this->criarLocal(['nome' => 'Local A']);
        $localB = $this->criarLocal(['nome' => 'Local B']);

        $this->registrarEntrada->execute($recebimento, $localA, 500, Carbon::today(), $this->user);
        $this->registrarEntrada->execute($recebimento->fresh(), $localB, 300, Carbon::today(), $this->user);

        $this->assertEqualsWithDelta(500.0, SaldoEstoque::porMaterialLocal($material, $localA), 0.001);
        $this->assertEqualsWithDelta(300.0, SaldoEstoque::porMaterialLocal($material, $localB), 0.001);
    }

    public function test_ag_saldo_consolidado_material(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(1000, $material);
        $localA = $this->criarLocal(['nome' => 'Local A']);
        $localB = $this->criarLocal(['nome' => 'Local B']);

        $this->registrarEntrada->execute($recebimento, $localA, 500, Carbon::today(), $this->user);
        $this->registrarEntrada->execute($recebimento->fresh(), $localB, 300, Carbon::today(), $this->user);

        $this->assertEqualsWithDelta(800.0, SaldoEstoque::porMaterial($material), 0.001);
    }

    // =========================================================
    // AH-AJ: Cadeia
    // =========================================================

    public function test_ah_navegacao_cadeia_completa(): void
    {
        $material = $this->criarMaterial();
        [$recebimento, $itemTakeOff] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();

        $movimentacao = $this->registrarEntrada->execute($recebimento, $local, 100, Carbon::today(), $this->user);

        $this->assertSame($recebimento->id, $movimentacao->recebimento_pedido_id);
        $this->assertSame($itemTakeOff->id, $movimentacao->item_take_off_id);
        $this->assertSame($material->id, $movimentacao->material_id);
        $this->assertNotNull($movimentacao->recebimentoPedido->pedidoCompraItem);
    }

    public function test_ai_duas_lms_mesmo_material_consolidam_saldo(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        [$recebimento1, $item1] = $this->recebimentoPronto(500, $material);
        [$recebimento2, $item2] = $this->recebimentoPronto(300, $material);

        $this->assertNotSame($item1->id, $item2->id);
        $this->assertSame($material->id, $item1->material_id);
        $this->assertSame($material->id, $item2->material_id);

        $this->registrarEntrada->execute($recebimento1, $local, 500, Carbon::today(), $this->user);
        $this->registrarEntrada->execute($recebimento2, $local, 300, Carbon::today(), $this->user);

        $this->assertEqualsWithDelta(800.0, SaldoEstoque::porMaterialLocal($material, $local), 0.001);
    }

    public function test_aj_material_id_imutavel_apos_uso_em_estoque(): void
    {
        $material = $this->criarMaterial();
        $outroMaterial = $this->criarMaterial();
        [$recebimento, $itemTakeOff] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();
        $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::today(), $this->user);

        $itemTakeOff->material_id = $outroMaterial->id;
        $this->expectException(ItemTakeOffMaterialImutavelException::class);
        $itemTakeOff->save();
    }

    public function test_aj2_material_id_imutavel_apos_pedido_emitido_mesmo_sem_entrada(): void
    {
        // Etapa 20.1.CORREÇÃO (Achado C2 da auditoria adversarial):
        // "recebimentoPronto()" já emite o Pedido de Compra — este teste
        // originalmente (20.1) afirmava que isso ainda era editável, porque
        // o corte de imutabilidade era "primeira entrada em estoque". A
        // auditoria provou esse corte insuficiente (o compromisso comercial
        // já existe antes de qualquer Movimentacao) — o corte real agora é
        // Pedido Compra Emitido, reforçado por
        // App\Support\Estoque\PoliticaAssociacaoMaterial::podeAlterarMaterial().
        // Nenhuma Entrada precisa existir pra bloquear a troca.
        $material = $this->criarMaterial();
        $outroMaterial = $this->criarMaterial();
        [, $itemTakeOff] = $this->recebimentoPronto(100, $material);

        $itemTakeOff->material_id = $outroMaterial->id;
        $this->expectException(ItemTakeOffMaterialImutavelException::class);
        $itemTakeOff->save();
    }

    // =========================================================
    // Delete de Material/Local referenciados (Seção 27/28)
    // =========================================================

    public function test_delete_material_com_item_take_off_bloqueado(): void
    {
        $material = $this->criarMaterial();
        [, $itemTakeOff] = $this->recebimentoPronto(100, $material);

        $this->expectException(QueryException::class);
        DB::table('materiais')->where('id', $material->id)->delete();
    }

    public function test_delete_local_com_movimentacao_bloqueado(): void
    {
        $material = $this->criarMaterial();
        [$recebimento] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();
        $this->registrarEntrada->execute($recebimento, $local, 50, Carbon::today(), $this->user);

        $this->expectException(QueryException::class);
        DB::table('locais_estoque')->where('id', $local->id)->delete();
    }

    // =========================================================
    // Performance
    // =========================================================

    public function test_ap_performance_saldo_em_lote_sem_n_mais_1(): void
    {
        $materiais = [];
        $local = $this->criarLocal();
        for ($i = 0; $i < 20; $i++) {
            $material = $this->criarMaterial();
            [$recebimento] = $this->recebimentoPronto(10, $material);
            $this->registrarEntrada->execute($recebimento, $local, 10, Carbon::today(), $this->user);
            $materiais[] = $material->id;
        }

        DB::enableQueryLog();
        $saldos = SaldoEstoque::porMateriais($materiais);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(20, $saldos->count());
        $this->assertLessThanOrEqual(2, $queries, 'SaldoEstoque::porMateriais() não deve escalar 1 query por material.');
    }

    public function test_aq_performance_muitas_movimentacoes_mesmo_material(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        [$recebimento] = $this->recebimentoPronto(1000, $material);
        $this->registrarEntrada->execute($recebimento, $local, 1000, Carbon::today(), $this->user);

        // Simula histórico grande via outras entradas (recebimentos distintos, mesmo material/local).
        for ($i = 0; $i < 20; $i++) {
            [$recebimentoExtra] = $this->recebimentoPronto(10, $material);
            $this->registrarEntrada->execute($recebimentoExtra, $local, 10, Carbon::today(), $this->user);
        }

        DB::enableQueryLog();
        $saldo = SaldoEstoque::porMaterialLocal($material, $local);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEqualsWithDelta(1200.0, $saldo, 0.001);
        $this->assertSame(1, $queries, 'porMaterialLocal() precisa ser 1 única query agregada, independente do volume de movimentações.');
    }

    // =========================================================
    // Zero regressão Ciclo 19 / prontidão
    // =========================================================

    public function test_zero_regressao_prontidao_atividade_intocada(): void
    {
        $material = $this->criarMaterial();
        [$recebimento, $itemTakeOff] = $this->recebimentoPronto(100, $material);
        $local = $this->criarLocal();
        $this->registrarEntrada->execute($recebimento, $local, 100, Carbon::today(), $this->user);

        // Nenhuma Atividade é criada/alterada por este fluxo — a asserção
        // real é que o fluxo inteiro de entrada em estoque não lança
        // nenhuma exceção relacionada a prontidão/Restrição, e nenhuma
        // linha de "restricoes" é criada por ele.
        $this->assertSame(0, DB::table('restricoes')->where('tenant_id', $this->tenant->id)->count());
    }
}
