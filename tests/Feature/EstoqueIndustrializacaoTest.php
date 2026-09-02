<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao;
use App\Actions\Estoque\CriarOrdemIndustrializacao;
use App\Actions\Estoque\EmitirOrdemIndustrializacao;
use App\Actions\Estoque\RegistrarConsumoIndustrializacao;
use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Actions\Estoque\RegistrarEntregaProdutoIndustrializado;
use App\Actions\Estoque\RegistrarProducaoIndustrializada;
use App\Actions\Estoque\RegistrarRemessaIndustrializacao;
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
use App\Enums\DirecaoRemessaIndustrializacao;
use App\Enums\ModalidadeEntregaProduto;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\TipoLocalEstoque;
use App\Exceptions\ConsumoIndustrializacaoInvalidaException;
use App\Exceptions\EntregaProdutoIndustrializadoInvalidaException;
use App\Exceptions\LocalEstoqueInvalidoException;
use App\Exceptions\OrdemIndustrializacaoImutavelException;
use App\Exceptions\OrdemIndustrializacaoInvalidaException;
use App\Exceptions\ProducaoIndustrializadaInvalidaException;
use App\Exceptions\RemessaIndustrializacaoInvalidaException;
use App\Exceptions\ReservaEstoqueInvalidaException;
use App\Exceptions\SaidaEstoqueInvalidaException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\OrdemIndustrializacao;
use App\Models\ProdutoIndustrializado;
use App\Models\ProdutoIndustrializadoConsumo;
use App\Models\RemessaIndustrializacao;
use App\Models\Tenant;
use App\Models\UnidadeEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Industrializacao\GenealogiaIndustrializacao;
use App\Support\Industrializacao\SaldoMateriaPrimaIndustrializacao;
use App\Support\Industrializacao\SaldoProdutoIndustrializado;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.5 — Industrialização em Terceiros. Cobertura A-AT
 * do pedido (Seções 49-55).
 */
class EstoqueIndustrializacaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    private CriarOrdemIndustrializacao $criarOrdem;
    private AtualizarRascunhoOrdemIndustrializacao $rascunhoOrdem;
    private EmitirOrdemIndustrializacao $emitirOrdem;
    private RegistrarRemessaIndustrializacao $registrarRemessa;
    private RegistrarConsumoIndustrializacao $registrarConsumo;
    private RegistrarProducaoIndustrializada $registrarProducao;
    private RegistrarEntregaProdutoIndustrializado $registrarEntrega;
    private RegistrarEntradaEstoque $registrarEntrada;

    private CriarRequisicaoPlanejamento $criarRp;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizarRp;
    private EmitirRequisicaoPlanejamento $emitirRp;
    private AlocarRequisicaoAoPacote $alocar;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);

        $this->criarOrdem = new CriarOrdemIndustrializacao();
        $this->rascunhoOrdem = new AtualizarRascunhoOrdemIndustrializacao();
        $this->emitirOrdem = new EmitirOrdemIndustrializacao();
        $this->registrarRemessa = new RegistrarRemessaIndustrializacao();
        $this->registrarConsumo = new RegistrarConsumoIndustrializacao();
        $this->registrarProducao = new RegistrarProducaoIndustrializada();
        $this->registrarEntrega = new RegistrarEntregaProdutoIndustrializado();
        $this->registrarEntrada = new RegistrarEntradaEstoque();

        $this->criarRp = new CriarRequisicaoPlanejamento();
        $this->atualizarRp = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitirRp = new EmitirRequisicaoPlanejamento();
        $this->alocar = new AlocarRequisicaoAoPacote();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers ----

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true,
        ]);
    }

    private function criarFornecedor(?Work $obra = null): Fornecedor
    {
        return Fornecedor::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Fornecedor ' . uniqid()]);
    }

    private function criarLocalTerceiro(Fornecedor $fornecedor, ?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Terceiro ' . uniqid(),
            'tipo' => TipoLocalEstoque::Terceiro->value, 'fornecedor_id' => $fornecedor->id, 'ativo' => true,
        ]);
    }

    private function criarFrente(?Work $obra = null): FrenteTrabalho
    {
        return FrenteTrabalho::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Frente ' . uniqid()]);
    }

    private function criarDocumentoRevisao(?Work $obra = null): \App\Models\DocumentoEngenhariaRevisao
    {
        $obra ??= $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'Desenho de Fabricação']);

        return $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão']);
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

    private function alocarNoPacote(\App\Models\RequisicaoPlanejamentoItem $rpItem, float $quantidade, ?Work $obra = null): AlocacaoRequisicaoPacote
    {
        $pacote = ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote ' . uniqid()]);

        return $this->alocar->alocar($rpItem, $pacote, $quantidade);
    }

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade, ?Work $obra = null, ?string $codigoLote = null, ?string $serialUnico = null): void
    {
        $obra ??= $this->obra;
        $item = $this->criarItemTakeOffOrfao($material, $obra);
        $rpItem = $this->criarRpItemEmitido($item, $quantidade, $obra);
        $alocacao = $this->alocarNoPacote($rpItem, $quantidade, $obra);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $obra->id, 'nome' => 'FornecedorCompra ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);

        $this->registrarEntrada->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user, $codigoLote, $serialUnico);
    }

    /** @return array{0: OrdemIndustrializacao, 1: Fornecedor, 2: LocalEstoque, 3: LocalEstoque} ordem emitida, fornecedor, local próprio, local terceiro */
    private function ordemEmitidaComProduto(Material $materiaPrima, Material $materialProduto, float $quantidadePrevista = 100): array
    {
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $this->entradaPronta($materiaPrima, $localProprio, 1000);

        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        $this->rascunhoOrdem->adicionarProduto($ordem, $materialProduto, $quantidadePrevista, $this->user);
        $ordem = $this->emitirOrdem->execute($ordem->fresh(), $this->user);

        return [$ordem, $fornecedor, $localProprio, $localTerceiro];
    }

    // =========================================================
    // A-H: Ordem (Seção 49)
    // =========================================================

    public function test_a_criar_ordem_rascunho(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);

        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);

        $this->assertTrue($ordem->estaRascunho());
        $this->assertNull($ordem->numero);
    }

    public function test_b_fornecedor_correto_vinculado(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);

        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);

        $this->assertSame($fornecedor->id, $ordem->fornecedor_id);
        $this->assertSame($local->id, $ordem->local_terceiro_id);
    }

    public function test_c_cross_obra_bloqueado(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $fornecedorOutraObra = $this->criarFornecedor($outraObra);
        $localOutraObra = $this->criarLocalTerceiro($fornecedorOutraObra, $outraObra);

        $this->expectException(OrdemIndustrializacaoInvalidaException::class);
        $this->criarOrdem->execute($this->obra, $fornecedorOutraObra, $localOutraObra, $this->user);
    }

    public function test_d_cross_tenant_isolamento_estrutural(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);
        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);

        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($outroUser);

        $this->assertNull(OrdemIndustrializacao::find($ordem->id));
    }

    public function test_e_multiplos_produtos_na_mesma_ordem(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);
        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);

        $produtoX = $this->criarMaterial(['codigo' => 'PROD-X-' . uniqid()]);
        $produtoY = $this->criarMaterial(['codigo' => 'PROD-Y-' . uniqid()]);

        $this->rascunhoOrdem->adicionarProduto($ordem, $produtoX, 10, $this->user);
        $this->rascunhoOrdem->adicionarProduto($ordem, $produtoY, 20, $this->user);

        $this->assertCount(2, $ordem->fresh()->produtos);
    }

    public function test_f_multiplos_documentos_revisoes_por_produto(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);
        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);

        $produtoX = $this->criarMaterial(['codigo' => 'PROD-X-' . uniqid()]);
        $produtoY = $this->criarMaterial(['codigo' => 'PROD-Y-' . uniqid()]);
        $revA = $this->criarDocumentoRevisao();
        $revB = $this->criarDocumentoRevisao();

        $px = $this->rascunhoOrdem->adicionarProduto($ordem, $produtoX, 10, $this->user, $revA);
        $py = $this->rascunhoOrdem->adicionarProduto($ordem, $produtoY, 20, $this->user, $revB);

        $this->assertSame($revA->id, $px->documento_engenharia_revisao_id);
        $this->assertSame($revB->id, $py->documento_engenharia_revisao_id);
    }

    public function test_g_r1_historico_apos_r2_vigente(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);
        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);
        $produto = $this->criarMaterial();
        $r1 = $this->criarDocumentoRevisao();

        $produtoIndustr = $this->rascunhoOrdem->adicionarProduto($ordem, $produto, 10, $this->user, $r1);

        // R2 nasce depois, mas o Produto já criado continua ligado a R1.
        $r2 = $r1->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now()->addDay(), 'descricao' => 'Nova emissão']);

        $this->assertSame($r1->id, $produtoIndustr->fresh()->documento_engenharia_revisao_id);
        $this->assertNotSame($r2->id, $produtoIndustr->fresh()->documento_engenharia_revisao_id);
    }

    public function test_h_imutabilidade_pos_emissao(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);
        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);
        $produto = $this->criarMaterial();
        $this->rascunhoOrdem->adicionarProduto($ordem, $produto, 10, $this->user);
        $ordemEmitida = $this->emitirOrdem->execute($ordem->fresh(), $this->user);

        $this->assertNotNull($ordemEmitida->numero);

        $this->expectException(OrdemIndustrializacaoImutavelException::class);
        $ordemEmitida->delete();
    }

    public function test_h2_produto_congelado_apos_emissao(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);
        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);
        $produto = $this->criarMaterial();
        $produtoIndustr = $this->rascunhoOrdem->adicionarProduto($ordem, $produto, 10, $this->user);
        $this->emitirOrdem->execute($ordem->fresh(), $this->user);

        $this->expectException(OrdemIndustrializacaoImutavelException::class);
        $this->rascunhoOrdem->alterarQuantidadePrevista($produtoIndustr->fresh(), 999);
    }

    public function test_h3_emitir_sem_produto_bloqueado(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);
        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);

        $this->expectException(OrdemIndustrializacaoInvalidaException::class);
        $this->emitirOrdem->execute($ordem, $this->user);
    }

    public function test_h4_numeracao_sequencial_por_obra(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);

        $ordem1 = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);
        $this->rascunhoOrdem->adicionarProduto($ordem1, $this->criarMaterial(), 10, $this->user);
        $ordem1 = $this->emitirOrdem->execute($ordem1->fresh(), $this->user);

        $ordem2 = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);
        $this->rascunhoOrdem->adicionarProduto($ordem2, $this->criarMaterial(), 10, $this->user);
        $ordem2 = $this->emitirOrdem->execute($ordem2->fresh(), $this->user);

        $this->assertSame(1, $ordem1->numero);
        $this->assertSame(2, $ordem2->numero);
    }

    // =========================================================
    // I-Q: Remessa (Seção 50)
    // =========================================================

    public function test_i_remessa_parcial(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());

        $remessa = $this->registrarRemessa->execute($ordem, $material, 200, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->assertEquals(200, (float) $remessa->quantidade);
        $this->assertSame(DirecaoRemessaIndustrializacao::Envio, $remessa->direcao);
    }

    public function test_j_multiplas_remessas(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());

        $this->registrarRemessa->execute($ordem, $material, 200, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $this->registrarRemessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->assertCount(2, $ordem->fresh()->remessas);
    }

    public function test_k_remessa_lote(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $this->entradaPronta($material, $localProprio, 500, null, 'LOTE-001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'LOTE-001')->first();

        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        $this->rascunhoOrdem->adicionarProduto($ordem, $this->criarMaterial(), 10, $this->user);
        $ordem = $this->emitirOrdem->execute($ordem->fresh(), $this->user);

        $remessa = $this->registrarRemessa->execute($ordem, $material, 500, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade);

        // Ciclo 20.5.CORREÇÃO: `local_estoque_id` NUNCA mais é mutado
        // pela remessa (continua apontando pro Local de criação/origem)
        // — "onde a unidade está" é sempre derivado do ledger.
        $this->assertSame($localProprio->id, $unidade->fresh()->local_estoque_id);
        $this->assertEquals(0, SaldoEstoque::porUnidadeLocal($unidade->fresh(), $localProprio));
        $this->assertEquals(500, SaldoEstoque::porUnidadeLocal($unidade->fresh(), $localTerceiro));
        $this->assertEquals(500, SaldoEstoque::porUnidade($unidade->fresh()));
    }

    public function test_l_remessa_serial(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $this->entradaPronta($material, $localProprio, 1, null, null, 'SN-001');
        $unidade = UnidadeEstoque::where('serial_unico', 'SN-001')->first();

        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        $this->rascunhoOrdem->adicionarProduto($ordem, $this->criarMaterial(), 10, $this->user);
        $ordem = $this->emitirOrdem->execute($ordem->fresh(), $this->user);

        $this->registrarRemessa->execute($ordem, $material, 1, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade);

        // Ciclo 20.5.CORREÇÃO: `local_estoque_id` NUNCA mais é mutado
        // pela remessa — "onde a unidade está" é sempre derivado do ledger.
        $this->assertSame($localProprio->id, $unidade->fresh()->local_estoque_id);
        $this->assertEquals(0, SaldoEstoque::porUnidadeLocal($unidade->fresh(), $localProprio));
        $this->assertEquals(1, SaldoEstoque::porUnidadeLocal($unidade->fresh(), $localTerceiro));
    }

    public function test_m_remessa_quantitativo(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());

        $remessa = $this->registrarRemessa->execute($ordem, $material, 100, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->assertNull($remessa->unidade_estoque_id);
    }

    public function test_n_over_remessa_bloqueada(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());

        $this->expectException(RemessaIndustrializacaoInvalidaException::class);
        $this->registrarRemessa->execute($ordem, $material, 1000.5, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
    }

    public function test_o_saldo_na_obra_reduz(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());

        $this->registrarRemessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->assertEquals(700, SaldoEstoque::porMaterialLocal($material, $localProprio));
    }

    public function test_p_saldo_em_terceiro_aumenta(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());

        $this->registrarRemessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->assertEquals(300, SaldoEstoque::porMaterialLocal($material, $localTerceiro));
        $this->assertEquals(1000, SaldoEstoque::porMaterial($material));
    }

    public function test_q_remessa_append_only(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());
        $remessa = $this->registrarRemessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->expectException(RemessaIndustrializacaoInvalidaException::class);
        $remessa->update(['quantidade' => 999]);
    }

    public function test_q2_retorno_sobra_reduz_terceiro_aumenta_obra(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());
        $this->registrarRemessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->registrarRemessa->execute($ordem, $material, 100, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user);

        $this->assertEquals(200, SaldoEstoque::porMaterialLocal($material, $localTerceiro));
        $this->assertEquals(800, SaldoEstoque::porMaterialLocal($material, $localProprio));
    }

    public function test_q3_retorno_maior_que_saldo_terceiro_bloqueado(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());
        $this->registrarRemessa->execute($ordem, $material, 100, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->expectException(RemessaIndustrializacaoInvalidaException::class);
        $this->registrarRemessa->execute($ordem, $material, 101, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user);
    }

    public function test_q4_saida_normal_bloqueada_em_local_terceiro(): void
    {
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        (new \App\Actions\Estoque\RegistrarSaidaEstoque())->execute(
            $this->criarMaterial(), $localTerceiro, 10, Carbon::today(), $this->user, retiradoPor: $this->user
        );
    }

    public function test_q5_reserva_bloqueada_em_local_terceiro(): void
    {
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote']);

        $this->expectException(ReservaEstoqueInvalidaException::class);
        (new \App\Actions\Estoque\CriarReservaEstoque())->execute($pacote, $this->criarMaterial(), $localTerceiro, 10, null, null, $this->user);
    }

    public function test_q6_local_terceiro_exige_fornecedor(): void
    {
        $this->expectException(LocalEstoqueInvalidoException::class);
        LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Sem fornecedor', 'tipo' => TipoLocalEstoque::Terceiro->value, 'ativo' => true]);
    }

    public function test_q7_local_proprio_nao_aceita_fornecedor(): void
    {
        $fornecedor = $this->criarFornecedor();

        $this->expectException(LocalEstoqueInvalidoException::class);
        LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Almox', 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'fornecedor_id' => $fornecedor->id, 'ativo' => true]);
    }

    // =========================================================
    // R-W: Produção (Seção 51)
    // =========================================================

    public function test_r_produto_previsto(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 50);

        $produto = $ordem->fresh()->produtos->first();
        $this->assertEquals(50, (float) $produto->quantidade_prevista);
        $this->assertEquals(0, SaldoProdutoIndustrializado::produzido($produto));
    }

    public function test_s_producao_parcial(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 50);
        $produto = $ordem->fresh()->produtos->first();

        $this->registrarProducao->execute($produto, 20, Carbon::today(), $this->user);

        $this->assertEquals(20, SaldoProdutoIndustrializado::produzido($produto));
    }

    public function test_t_multiplos_eventos_de_producao(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 50);
        $produto = $ordem->fresh()->produtos->first();

        $this->registrarProducao->execute($produto, 20, Carbon::today(), $this->user);
        $this->registrarProducao->execute($produto, 15, Carbon::today(), $this->user);

        $this->assertEquals(35, SaldoProdutoIndustrializado::produzido($produto));
        $this->assertCount(2, $produto->fresh()->producoes);
    }

    public function test_u_over_producao_permitida_sem_limite(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 10);
        $produto = $ordem->fresh()->produtos->first();

        $this->registrarProducao->execute($produto, 999, Carbon::today(), $this->user);

        $this->assertEquals(999, SaldoProdutoIndustrializado::produzido($produto));
    }

    public function test_v_produto_serializado(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        [$ordem, , , $localTerceiro] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 3);
        $produto = $ordem->fresh()->produtos->first();

        $producao = $this->registrarProducao->execute($produto, 1, Carbon::today(), $this->user, null, 'SPOOL-001');

        $unidade = UnidadeEstoque::find($producao->unidade_estoque_id);
        $this->assertSame('SPOOL-001', $unidade->serial_unico);
        $this->assertSame($localTerceiro->id, $unidade->local_estoque_id);
    }

    public function test_w_producao_ordem_nao_emitida_bloqueada(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);
        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);
        $produto = $this->rascunhoOrdem->adicionarProduto($ordem, $this->criarMaterial(), 10, $this->user);

        $this->expectException(ProducaoIndustrializadaInvalidaException::class);
        $this->registrarProducao->execute($produto, 5, Carbon::today(), $this->user);
    }

    // =========================================================
    // X-AB: Consumo (Seção 52)
    // =========================================================

    public function test_x_consumo_parcial(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        $remessa = $this->registrarRemessa->execute($ordem, $materiaPrima, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $produto = $ordem->fresh()->produtos->first();

        $this->registrarConsumo->execute($produto, $remessa, 200, Carbon::today(), $this->user);

        $this->assertEquals(100, SaldoEstoque::porMaterialLocal($materiaPrima, $ordem->fresh()->localTerceiro));
    }

    public function test_y_multiplos_consumos(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        $remessa = $this->registrarRemessa->execute($ordem, $materiaPrima, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $produto = $ordem->fresh()->produtos->first();

        $this->registrarConsumo->execute($produto, $remessa, 100, Carbon::today(), $this->user);
        $this->registrarConsumo->execute($produto, $remessa, 150, Carbon::today(), $this->user);

        $this->assertEquals(250, (float) ProdutoIndustrializadoConsumo::where('remessa_industrializacao_id', $remessa->id)->sum('quantidade_consumida'));
    }

    public function test_z_over_consumo_bloqueado(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        $remessa = $this->registrarRemessa->execute($ordem, $materiaPrima, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarConsumo->execute($produto, $remessa, 200, Carbon::today(), $this->user);

        $this->expectException(ConsumoIndustrializacaoInvalidaException::class);
        $this->registrarConsumo->execute($produto, $remessa, 101, Carbon::today(), $this->user);
    }

    public function test_aa_consumo_por_produto_genealogia(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        $remessa = $this->registrarRemessa->execute($ordem, $materiaPrima, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $produto = $ordem->fresh()->produtos->first();

        $this->registrarConsumo->execute($produto, $remessa, 150, Carbon::today(), $this->user);

        $consumos = GenealogiaIndustrializacao::materiaPrimaDoProduto($produto);
        $this->assertCount(1, $consumos);
        $this->assertEquals(150, (float) $consumos->first()->quantidade_consumida);
    }

    public function test_ab_sobra_derivada_nao_persistida(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        $remessa = $this->registrarRemessa->execute($ordem, $materiaPrima, 1000, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarConsumo->execute($produto, $remessa, 850, Carbon::today(), $this->user);
        $this->registrarRemessa->execute($ordem, $materiaPrima, 100, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user);

        $saldo = SaldoMateriaPrimaIndustrializacao::porOrdemMaterial($ordem->fresh(), $materiaPrima);

        $this->assertEquals(1000, $saldo['enviado']);
        $this->assertEquals(850, $saldo['consumido']);
        $this->assertEquals(100, $saldo['devolvido']);
        $this->assertEquals(50, $saldo['diferenca_nao_classificada']);
        $this->assertEquals(50, $saldo['saldo_em_terceiro']);
    }

    public function test_ab2_so_consome_remessa_de_envio(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        $this->registrarRemessa->execute($ordem, $materiaPrima, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $retorno = $this->registrarRemessa->execute($ordem, $materiaPrima, 50, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user);
        $produto = $ordem->fresh()->produtos->first();

        $this->expectException(ConsumoIndustrializacaoInvalidaException::class);
        $this->registrarConsumo->execute($produto, $retorno, 10, Carbon::today(), $this->user);
    }

    // =========================================================
    // AC-AH: Entrega (Seção 53)
    // =========================================================

    public function test_ac_entrega_parcial(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 100);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarProducao->execute($produto, 24, Carbon::today(), $this->user);

        $this->registrarEntrega->execute($produto, 8, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user);

        $this->assertEquals(8, SaldoProdutoIndustrializado::entregue($produto));
        $this->assertEquals(16, SaldoProdutoIndustrializado::saldoPronto($produto));
    }

    public function test_ad_retorno_ao_estoque(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 100);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarProducao->execute($produto, 24, Carbon::today(), $this->user);

        $this->registrarEntrega->execute($produto, 24, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user);

        $this->assertEquals(24, SaldoEstoque::porMaterialLocal($produtoMaterial, $localProprio));
    }

    public function test_ae_produto_entra_no_saldo_fisico(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 100);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarProducao->execute($produto, 10, Carbon::today(), $this->user);

        $entrega = $this->registrarEntrega->execute($produto, 10, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user);

        $this->assertNotNull($entrega->movimentacao_entrada_destino_id);
        $this->assertNull($entrega->movimentacao_saida_campo_id);
    }

    public function test_af_entrega_direta_ao_campo(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 100);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarProducao->execute($produto, 10, Carbon::today(), $this->user);
        $frente = $this->criarFrente();

        $entrega = $this->registrarEntrega->execute(
            $produto, 10, ModalidadeEntregaProduto::EntregaDiretaCampo, $localProprio, Carbon::today(), $this->user,
            frenteCampo: $frente, retiradoPor: $this->user,
        );

        $this->assertNotNull($entrega->movimentacao_saida_campo_id);
        $this->assertEquals(0, SaldoEstoque::porMaterialLocal($produtoMaterial, $localProprio));
    }

    public function test_af2_entrega_direta_sem_frente_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 100);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarProducao->execute($produto, 10, Carbon::today(), $this->user);

        $this->expectException(EntregaProdutoIndustrializadoInvalidaException::class);
        $this->registrarEntrega->execute(
            $produto, 10, ModalidadeEntregaProduto::EntregaDiretaCampo, $localProprio, Carbon::today(), $this->user,
            retiradoPor: $this->user,
        );
    }

    public function test_ag_multiplas_entregas(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 100);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarProducao->execute($produto, 24, Carbon::today(), $this->user);

        $this->registrarEntrega->execute($produto, 8, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user);
        $this->registrarEntrega->execute($produto, 6, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user);
        $this->registrarEntrega->execute($produto, 10, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user);

        $this->assertEquals(24, SaldoProdutoIndustrializado::entregue($produto));
        $this->assertEquals(0, SaldoProdutoIndustrializado::saldoPronto($produto));
    }

    public function test_ag2_over_entrega_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 100);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarProducao->execute($produto, 24, Carbon::today(), $this->user);
        $this->registrarEntrega->execute($produto, 24, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user);

        $this->expectException(EntregaProdutoIndustrializadoInvalidaException::class);
        $this->registrarEntrega->execute($produto, 1, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user);
    }

    public function test_ah_historico_de_entrega_append_only(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $produtoMaterial, 100);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarProducao->execute($produto, 10, Carbon::today(), $this->user);
        $entrega = $this->registrarEntrega->execute($produto, 10, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user);

        $this->expectException(\App\Exceptions\EntregaProdutoIndustrializadoInvalidaException::class);
        $entrega->delete();
    }

    // =========================================================
    // AI-AN: Genealogia (Seção 54)
    // =========================================================

    public function test_ai_material_a_para_produto_x(): void
    {
        $materiaPrimaA = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrimaA, $this->criarMaterial());
        $remessaA = $this->registrarRemessa->execute($ordem, $materiaPrimaA, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $produtoX = $ordem->fresh()->produtos->first();

        $this->registrarConsumo->execute($produtoX, $remessaA, 200, Carbon::today(), $this->user);

        $produtosDaRemessa = GenealogiaIndustrializacao::produtosDaRemessa($remessaA);
        $this->assertCount(1, $produtosDaRemessa);
        $this->assertSame($produtoX->id, $produtosDaRemessa->first()->produto_industrializado_id);
    }

    public function test_aj_a_mais_b_para_x(): void
    {
        $materiaPrimaA = $this->criarMaterial();
        $materiaPrimaB = $this->criarMaterial();
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $this->entradaPronta($materiaPrimaA, $localProprio, 500);
        $this->entradaPronta($materiaPrimaB, $localProprio, 500);

        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        $this->rascunhoOrdem->adicionarProduto($ordem, $this->criarMaterial(), 10, $this->user);
        $ordem = $this->emitirOrdem->execute($ordem->fresh(), $this->user);
        $produtoX = $ordem->fresh()->produtos->first();

        $remessaA = $this->registrarRemessa->execute($ordem, $materiaPrimaA, 100, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $remessaB = $this->registrarRemessa->execute($ordem, $materiaPrimaB, 50, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->registrarConsumo->execute($produtoX, $remessaA, 100, Carbon::today(), $this->user);
        $this->registrarConsumo->execute($produtoX, $remessaB, 50, Carbon::today(), $this->user);

        $genealogia = GenealogiaIndustrializacao::materiaPrimaDoProduto($produtoX);
        $this->assertCount(2, $genealogia);
    }

    public function test_ak_a_mais_b_para_x_mais_y(): void
    {
        $materiaPrima = $this->criarMaterial();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $localProprio2 = $this->criarLocal();
        $this->entradaPronta($materiaPrima, $localProprio2, 500);
        $ordem2 = $this->criarOrdem->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        $produtoX = $this->rascunhoOrdem->adicionarProduto($ordem2, $this->criarMaterial(), 10, $this->user);
        $produtoY = $this->rascunhoOrdem->adicionarProduto($ordem2, $this->criarMaterial(), 10, $this->user);
        $ordem2 = $this->emitirOrdem->execute($ordem2->fresh(), $this->user);

        $remessa = $this->registrarRemessa->execute($ordem2, $materiaPrima, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio2, Carbon::today(), $this->user);
        $this->registrarConsumo->execute($produtoX, $remessa, 100, Carbon::today(), $this->user);
        $this->registrarConsumo->execute($produtoY, $remessa, 150, Carbon::today(), $this->user);

        $produtosDaRemessa = GenealogiaIndustrializacao::produtosDaRemessa($remessa);
        $this->assertCount(2, $produtosDaRemessa);
    }

    public function test_al_produto_para_documento_revisao(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $localProprio = $this->criarLocal();
        $this->entradaPronta($material, $localProprio, 500);
        $rev = $this->criarDocumentoRevisao();
        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        $produto = $this->rascunhoOrdem->adicionarProduto($ordem, $produtoMaterial, 10, $this->user, $rev);

        $this->assertSame($rev->id, $produto->fresh()->documentoRevisao->id);
    }

    public function test_am_produto_para_ordem_para_materia_prima(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        $remessa = $this->registrarRemessa->execute($ordem, $materiaPrima, 200, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarConsumo->execute($produto, $remessa, 100, Carbon::today(), $this->user);

        $this->assertSame($ordem->id, $produto->ordem->id);
        $consumo = $produto->fresh()->consumos->first();
        $this->assertSame($remessa->id, $consumo->remessa->id);
        $this->assertSame($materiaPrima->id, $consumo->remessa->material_id);
    }

    public function test_an_materia_prima_para_produtos_derivados(): void
    {
        $materiaPrima = $this->criarMaterial();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $localProprio = $this->criarLocal();
        $this->entradaPronta($materiaPrima, $localProprio, 500);
        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        $produtoX = $this->rascunhoOrdem->adicionarProduto($ordem, $this->criarMaterial(), 10, $this->user);
        $produtoY = $this->rascunhoOrdem->adicionarProduto($ordem, $this->criarMaterial(), 10, $this->user);
        $ordem = $this->emitirOrdem->execute($ordem->fresh(), $this->user);

        $remessa = $this->registrarRemessa->execute($ordem, $materiaPrima, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $this->registrarConsumo->execute($produtoX, $remessa, 100, Carbon::today(), $this->user);
        $this->registrarConsumo->execute($produtoY, $remessa, 150, Carbon::today(), $this->user);

        $derivados = GenealogiaIndustrializacao::produtosDaRemessa($remessa)->pluck('produto_industrializado_id');
        $this->assertContains($produtoX->id, $derivados);
        $this->assertContains($produtoY->id, $derivados);
    }

    // =========================================================
    // AO-AT: Custódia (Seção 55)
    // =========================================================

    public function test_ao_saldo_na_obra(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());

        $this->assertEquals(1000, SaldoEstoque::porMaterialLocal($material, $localProprio));
    }

    public function test_ap_saldo_no_terceiro(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());
        $this->registrarRemessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->assertEquals(300, SaldoEstoque::porMaterialLocal($material, $localTerceiro));
    }

    public function test_aq_retorno_reduz_terceiro(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());
        $this->registrarRemessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $this->registrarRemessa->execute($ordem, $material, 100, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user);

        $this->assertEquals(200, SaldoEstoque::porMaterialLocal($material, $localTerceiro));
    }

    public function test_ar_saldo_por_terceiro_nao_mistura_fornecedores(): void
    {
        $material = $this->criarMaterial();
        $localProprio = $this->criarLocal();
        $fornecedorA = $this->criarFornecedor();
        $fornecedorB = $this->criarFornecedor();
        $localTerceiroA = $this->criarLocalTerceiro($fornecedorA);
        $localTerceiroB = $this->criarLocalTerceiro($fornecedorB);
        $this->entradaPronta($material, $localProprio, 1000);

        $ordemA = $this->criarOrdem->execute($this->obra, $fornecedorA, $localTerceiroA, $this->user);
        $this->rascunhoOrdem->adicionarProduto($ordemA, $this->criarMaterial(), 10, $this->user);
        $ordemA = $this->emitirOrdem->execute($ordemA->fresh(), $this->user);

        $ordemB = $this->criarOrdem->execute($this->obra, $fornecedorB, $localTerceiroB, $this->user);
        $this->rascunhoOrdem->adicionarProduto($ordemB, $this->criarMaterial(), 10, $this->user);
        $ordemB = $this->emitirOrdem->execute($ordemB->fresh(), $this->user);

        $this->registrarRemessa->execute($ordemA, $material, 200, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $this->registrarRemessa->execute($ordemB, $material, 150, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->assertEquals(200, SaldoEstoque::porMaterialLocal($material, $localTerceiroA));
        $this->assertEquals(150, SaldoEstoque::porMaterialLocal($material, $localTerceiroB));
    }

    public function test_as_total_patrimonio_custodia(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());
        $this->registrarRemessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        // Total sob custódia (obra + terceiro) = SaldoEstoque::porMaterial(), nunca um serviço novo.
        $this->assertEquals(1000, SaldoEstoque::porMaterial($material));
    }

    public function test_at_terceiro_nunca_disponivel_para_campo(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());
        $this->registrarRemessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        (new \App\Actions\Estoque\RegistrarSaidaEstoque())->execute($material, $localTerceiro, 50, Carbon::today(), $this->user, retiradoPor: $this->user);
    }

    // =========================================================
    // Zero regressão (Seção 58)
    // =========================================================

    public function test_zero_efeito_colateral_em_restricao_prontidao(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());
        $remessa = $this->registrarRemessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $produto = $ordem->fresh()->produtos->first();
        $this->registrarConsumo->execute($produto, $remessa, 100, Carbon::today(), $this->user);
        $this->registrarProducao->execute($produto, 5, Carbon::today(), $this->user);
        $this->registrarEntrega->execute($produto, 5, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user);

        $this->assertSame(0, DB::table('restricoes')->count());
    }

    public function test_zero_conceito_de_20_6_no_codigo(): void
    {
        $base = base_path('app');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        // Ciclo 21, Etapa 21.2 — espaço à direita exige o nome EXATO do
        // case, fechando colisão de prefixo com `case InventarioAguardandoDecisao`
        // de App\Enums\TipoSituacaoGerencial (enum não relacionado).
        $proibidos = ['case Inventario ', 'case AjusteEstoque', 'TransferenciaInterna', 'CodigoBarras1D'];

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

        $this->assertEmpty($encontrados, 'Conceitos da 20.6+ encontrados prematuramente: ' . implode(', ', $encontrados));
    }
}
