<?php

namespace Tests\Feature\Auditoria;

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
use App\Exceptions\OperacaoEstoqueDuplicadaException;
use App\Models\DocumentoEngenharia;
use App\Models\EntregaProdutoIndustrializado;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\ProducaoIndustrializada;
use App\Models\ProdutoIndustrializadoConsumo;
use App\Models\RemessaIndustrializacao;
use App\Models\Tenant;
use App\Models\UnidadeEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\SaldoEstoque;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A2.2, Seções 3-6 — idempotência real das 4
 * Actions de Industrialização (Remessa/Retorno, Produção, Consumo,
 * Entrega). Fecha o risco histórico reproduzido empiricamente em
 * `IndustrializacaoReproducaoRiscoTest.php`. Mesmo contrato validado na
 * A2.1 (`IdempotenciaOperacoesEstoqueTest.php`): mesma operation_id +
 * mesmo payload → no-op; mesma operation_id + payload divergente →
 * conflito; operation_ids diferentes + payload idêntico → 2 operações
 * legítimas distintas.
 */
class IdempotenciaIndustrializacaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-20 12:00:00'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (mesmo padrão de IndustrializacaoReproducaoRiscoTest) ----

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => $this->obra->id, 'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true,
        ]);
    }

    private function criarFornecedor(): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
    }

    private function criarLocalTerceiro(Fornecedor $fornecedor): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => $this->obra->id, 'nome' => 'Terceiro ' . uniqid(),
            'tipo' => TipoLocalEstoque::Terceiro->value, 'fornecedor_id' => $fornecedor->id, 'ativo' => true,
        ]);
    }

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade): void
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item',
            'quantidade' => 1000000, 'material_id' => $material->id,
        ]);

        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $rpItem = $rpItem->fresh();

        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem, $pacote, $quantidade);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'FornecedorCompra ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);
        (new RegistrarEntradaEstoque())->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);
    }

    /** @return array{0: \App\Models\OrdemIndustrializacao, 1: Fornecedor, 2: LocalEstoque, 3: LocalEstoque} */
    private function ordemEmitidaComProduto(Material $materiaPrima, Material $materialProduto, float $quantidadePrevista = 1000): array
    {
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $this->entradaPronta($materiaPrima, $localProprio, 1000);

        $ordem = (new CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        (new AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $materialProduto, $quantidadePrevista, $this->user);
        $ordem = (new EmitirOrdemIndustrializacao())->execute($ordem->fresh(), $this->user);

        return [$ordem, $fornecedor, $localProprio, $localTerceiro];
    }

    // =========================================================
    // A — RegistrarRemessaIndustrializacao (Envio + RetornoSobra)
    // =========================================================

    public function test_a1_retorno_com_operation_id_e_mesmo_payload_e_idempotente(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 500, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $operationId = (string) Str::ulid();
        $r1 = (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 200, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user, operationId: $operationId);
        $r2 = (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 200, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user, operationId: $operationId);

        $this->assertSame($r1->id, $r2->id);
        $this->assertSame(1, RemessaIndustrializacao::where('operation_id', $operationId)->count());
        $this->assertEqualsWithDelta(700.0, SaldoEstoque::porMaterialLocal($materiaPrima, $localProprio), 0.001, 'saldo correto: 500 enviado + 200 retornado, nunca 400 retornado');
    }

    public function test_a2_retorno_com_operation_id_e_payload_divergente_e_conflito(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 500, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $operationId = (string) Str::ulid();
        (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 200, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user, operationId: $operationId);

        $this->expectException(OperacaoEstoqueDuplicadaException::class);
        // MESMO operation_id, quantidade DIFERENTE.
        (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 100, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user, operationId: $operationId);
    }

    public function test_a3_dois_retornos_legitimos_com_operation_ids_diferentes_e_mesmo_payload(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 500, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $r1 = (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 200, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user, operationId: (string) Str::ulid());
        $r2 = (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 200, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user, operationId: (string) Str::ulid());

        $this->assertNotSame($r1->id, $r2->id);
        $this->assertSame(2, RemessaIndustrializacao::where('direcao', DirecaoRemessaIndustrializacao::RetornoSobra->value)->count());
        $this->assertEqualsWithDelta(900.0, SaldoEstoque::porMaterialLocal($materiaPrima, $localProprio), 0.001, '2 retornos LEGÍTIMOS e distintos continuam permitidos: 500 enviado + 400 retornado');
    }

    public function test_a4_sem_operation_id_continua_sempre_criando_novo_fato(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 500, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $r1 = (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 50, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user);
        $r2 = (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 50, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user);

        $this->assertNotSame($r1->id, $r2->id);
        $this->assertNull($r1->operation_id);
    }

    public function test_a5_unique_constraint_real_em_remessas_industrializacao(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        $this->entradaPronta($materiaPrima, $localProprio, 100);
        $operationId = (string) Str::ulid();

        RemessaIndustrializacao::create([
            'operation_id' => $operationId, 'obra_id' => $this->obra->id,
            'ordem_industrializacao_id' => $ordem->id, 'material_id' => $materiaPrima->id,
            'direcao' => DirecaoRemessaIndustrializacao::Envio, 'quantidade' => 10,
            'ocorrido_em' => Carbon::today(),
            'movimentacao_saida_id' => MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'saida', 'material_id' => $materiaPrima->id, 'local_estoque_id' => $localProprio->id, 'quantidade' => 10, 'ocorrido_em' => Carbon::today()])->id,
            'movimentacao_entrada_id' => MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $materiaPrima->id, 'local_estoque_id' => $localProprio->id, 'quantidade' => 10, 'ocorrido_em' => Carbon::today()])->id,
            'registrado_por' => $this->user->id,
        ]);

        $this->expectException(QueryException::class);
        RemessaIndustrializacao::create([
            'operation_id' => $operationId, 'obra_id' => $this->obra->id,
            'ordem_industrializacao_id' => $ordem->id, 'material_id' => $materiaPrima->id,
            'direcao' => DirecaoRemessaIndustrializacao::RetornoSobra, 'quantidade' => 20,
            'ocorrido_em' => Carbon::today(),
            'movimentacao_saida_id' => MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'saida', 'material_id' => $materiaPrima->id, 'local_estoque_id' => $localProprio->id, 'quantidade' => 20, 'ocorrido_em' => Carbon::today()])->id,
            'movimentacao_entrada_id' => MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $materiaPrima->id, 'local_estoque_id' => $localProprio->id, 'quantidade' => 20, 'ocorrido_em' => Carbon::today()])->id,
            'registrado_por' => $this->user->id,
        ]);
    }

    // =========================================================
    // B — RegistrarProducaoIndustrializada
    // =========================================================

    public function test_b1_producao_com_operation_id_e_mesmo_payload_e_idempotente(): void
    {
        $materiaPrima = $this->criarMaterial();
        $materialProduto = $this->criarMaterial();
        [$ordem, , , $localTerceiro] = $this->ordemEmitidaComProduto($materiaPrima, $materialProduto);
        $produto = $ordem->fresh()->produtos->first();
        $operationId = (string) Str::ulid();

        $p1 = (new RegistrarProducaoIndustrializada())->execute($produto, 30, Carbon::today(), $this->user, operationId: $operationId);
        $p2 = (new RegistrarProducaoIndustrializada())->execute($produto, 30, Carbon::today(), $this->user, operationId: $operationId);

        $this->assertSame($p1->id, $p2->id);
        $this->assertEqualsWithDelta(30.0, SaldoEstoque::porMaterialLocal($materialProduto, $localTerceiro), 0.001, 'saldo continua 30, nunca 60 — retry não duplicou a produção');
        $this->assertSame(1, MovimentacaoEstoque::where('material_id', $materialProduto->id)->count());
    }

    public function test_b2_producao_com_operation_id_e_payload_divergente_e_conflito(): void
    {
        $materiaPrima = $this->criarMaterial();
        $materialProduto = $this->criarMaterial();
        [$ordem] = $this->ordemEmitidaComProduto($materiaPrima, $materialProduto);
        $produto = $ordem->fresh()->produtos->first();
        $operationId = (string) Str::ulid();

        (new RegistrarProducaoIndustrializada())->execute($produto, 30, Carbon::today(), $this->user, operationId: $operationId);

        $this->expectException(OperacaoEstoqueDuplicadaException::class);
        (new RegistrarProducaoIndustrializada())->execute($produto, 45, Carbon::today(), $this->user, operationId: $operationId);
    }

    public function test_b3_duas_producoes_legitimas_com_operation_ids_diferentes_e_mesmo_payload(): void
    {
        $materiaPrima = $this->criarMaterial();
        $materialProduto = $this->criarMaterial();
        [$ordem, , , $localTerceiro] = $this->ordemEmitidaComProduto($materiaPrima, $materialProduto);
        $produto = $ordem->fresh()->produtos->first();

        $p1 = (new RegistrarProducaoIndustrializada())->execute($produto, 30, Carbon::today(), $this->user, operationId: (string) Str::ulid());
        $p2 = (new RegistrarProducaoIndustrializada())->execute($produto, 30, Carbon::today(), $this->user, operationId: (string) Str::ulid());

        $this->assertNotSame($p1->id, $p2->id);
        $this->assertEqualsWithDelta(60.0, SaldoEstoque::porMaterialLocal($materialProduto, $localTerceiro), 0.001, '2 produções LEGÍTIMAS e distintas somam 60, nunca colapsam em 30');
    }

    public function test_b4_retry_com_lote_nunca_deixa_unidade_orfa(): void
    {
        $materiaPrima = $this->criarMaterial();
        $materialProduto = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem] = $this->ordemEmitidaComProduto($materiaPrima, $materialProduto);
        $produto = $ordem->fresh()->produtos->first();
        $operationId = (string) Str::ulid();

        $p1 = (new RegistrarProducaoIndustrializada())->execute($produto, 30, Carbon::today(), $this->user, codigoLote: 'L001', operationId: $operationId);
        $p2 = (new RegistrarProducaoIndustrializada())->execute($produto, 30, Carbon::today(), $this->user, codigoLote: 'L001', operationId: $operationId);

        $this->assertSame($p1->id, $p2->id);
        $this->assertSame(1, UnidadeEstoque::where('material_id', $materialProduto->id)->where('codigo_lote', 'L001')->count(), 'retry nunca criou uma 2ª UnidadeEstoque especulativa');
    }

    // =========================================================
    // C — RegistrarConsumoIndustrializacao
    // =========================================================

    public function test_c1_consumo_com_operation_id_e_mesmo_payload_e_idempotente(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        $produto = $ordem->fresh()->produtos->first();
        $remessa = (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 500, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $operationId = (string) Str::ulid();

        $c1 = (new RegistrarConsumoIndustrializacao())->execute($produto, $remessa, 100, Carbon::today(), $this->user, operationId: $operationId);
        $c2 = (new RegistrarConsumoIndustrializacao())->execute($produto, $remessa, 100, Carbon::today(), $this->user, operationId: $operationId);

        $this->assertSame($c1->id, $c2->id);
        $this->assertEqualsWithDelta(400.0, SaldoEstoque::porMaterialLocal($materiaPrima, $localTerceiro), 0.001, 'consumo de 100 aplicado uma única vez sobre os 500 enviados');
        $this->assertSame(1, ProdutoIndustrializadoConsumo::where('remessa_industrializacao_id', $remessa->id)->count());
    }

    public function test_c2_consumo_com_operation_id_e_payload_divergente_e_conflito(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        $produto = $ordem->fresh()->produtos->first();
        $remessa = (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 500, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $operationId = (string) Str::ulid();

        (new RegistrarConsumoIndustrializacao())->execute($produto, $remessa, 100, Carbon::today(), $this->user, operationId: $operationId);

        $this->expectException(OperacaoEstoqueDuplicadaException::class);
        (new RegistrarConsumoIndustrializacao())->execute($produto, $remessa, 150, Carbon::today(), $this->user, operationId: $operationId);
    }

    public function test_c3_dois_consumos_legitimos_com_operation_ids_diferentes_e_mesmo_payload(): void
    {
        $materiaPrima = $this->criarMaterial();
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaComProduto($materiaPrima, $this->criarMaterial());
        $produto = $ordem->fresh()->produtos->first();
        $remessa = (new RegistrarRemessaIndustrializacao())->execute($ordem, $materiaPrima, 500, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        $c1 = (new RegistrarConsumoIndustrializacao())->execute($produto, $remessa, 100, Carbon::today(), $this->user, operationId: (string) Str::ulid());
        $c2 = (new RegistrarConsumoIndustrializacao())->execute($produto, $remessa, 100, Carbon::today(), $this->user, operationId: (string) Str::ulid());

        $this->assertNotSame($c1->id, $c2->id);
        $this->assertEqualsWithDelta(300.0, SaldoEstoque::porMaterialLocal($materiaPrima, $localTerceiro), 0.001, '2 consumos LEGÍTIMOS de 100 cada consomem 200 no total (500-200=300)');
    }

    // =========================================================
    // D — RegistrarEntregaProdutoIndustrializado
    // =========================================================

    public function test_d1_entrega_com_operation_id_e_mesmo_payload_e_idempotente(): void
    {
        $materiaPrima = $this->criarMaterial();
        $materialProduto = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $materialProduto);
        $produto = $ordem->fresh()->produtos->first();
        (new RegistrarProducaoIndustrializada())->execute($produto, 100, Carbon::today(), $this->user);
        $operationId = (string) Str::ulid();

        $e1 = (new RegistrarEntregaProdutoIndustrializado())->execute($produto, 40, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user, operationId: $operationId);
        $e2 = (new RegistrarEntregaProdutoIndustrializado())->execute($produto, 40, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user, operationId: $operationId);

        $this->assertSame($e1->id, $e2->id);
        $this->assertEqualsWithDelta(40.0, SaldoEstoque::porMaterialLocal($materialProduto, $localProprio), 0.001, 'entrega de 40 aplicada uma única vez');
        $this->assertSame(1, EntregaProdutoIndustrializado::where('produto_industrializado_id', $produto->id)->count());
    }

    public function test_d2_entrega_com_operation_id_e_payload_divergente_e_conflito(): void
    {
        $materiaPrima = $this->criarMaterial();
        $materialProduto = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $materialProduto);
        $produto = $ordem->fresh()->produtos->first();
        (new RegistrarProducaoIndustrializada())->execute($produto, 100, Carbon::today(), $this->user);
        $operationId = (string) Str::ulid();

        (new RegistrarEntregaProdutoIndustrializado())->execute($produto, 40, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user, operationId: $operationId);

        $this->expectException(OperacaoEstoqueDuplicadaException::class);
        (new RegistrarEntregaProdutoIndustrializado())->execute($produto, 50, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user, operationId: $operationId);
    }

    public function test_d3_duas_entregas_legitimas_com_operation_ids_diferentes_e_mesmo_payload(): void
    {
        $materiaPrima = $this->criarMaterial();
        $materialProduto = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $materialProduto);
        $produto = $ordem->fresh()->produtos->first();
        (new RegistrarProducaoIndustrializada())->execute($produto, 100, Carbon::today(), $this->user);

        $e1 = (new RegistrarEntregaProdutoIndustrializado())->execute($produto, 40, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user, operationId: (string) Str::ulid());
        $e2 = (new RegistrarEntregaProdutoIndustrializado())->execute($produto, 40, ModalidadeEntregaProduto::RetornoEstoqueObra, $localProprio, Carbon::today(), $this->user, operationId: (string) Str::ulid());

        $this->assertNotSame($e1->id, $e2->id);
        $this->assertEqualsWithDelta(80.0, SaldoEstoque::porMaterialLocal($materialProduto, $localProprio), 0.001, '2 entregas LEGÍTIMAS de 40 cada somam 80, nunca colapsam em 40');
    }

    public function test_d4_entrega_direta_ao_campo_propaga_operation_id_pra_saida_interna_sem_duplicar(): void
    {
        $materiaPrima = $this->criarMaterial();
        $materialProduto = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($materiaPrima, $materialProduto);
        $produto = $ordem->fresh()->produtos->first();
        (new RegistrarProducaoIndustrializada())->execute($produto, 100, Carbon::today(), $this->user);
        $frente = \App\Models\FrenteTrabalho::create(['obra_id' => $this->obra->id, 'nome' => 'Frente ' . uniqid()]);
        $operationId = (string) Str::ulid();

        $e1 = (new RegistrarEntregaProdutoIndustrializado())->execute(
            $produto, 40, ModalidadeEntregaProduto::EntregaDiretaCampo, $localProprio, Carbon::today(), $this->user,
            frenteCampo: $frente, retiradoPor: $this->user, operationId: $operationId
        );
        $e2 = (new RegistrarEntregaProdutoIndustrializado())->execute(
            $produto, 40, ModalidadeEntregaProduto::EntregaDiretaCampo, $localProprio, Carbon::today(), $this->user,
            frenteCampo: $frente, retiradoPor: $this->user, operationId: $operationId
        );

        $this->assertSame($e1->id, $e2->id);
        // 1 saída de campo real (via RegistrarSaidaEstoque), nunca 2 —
        // a mesma operation_id protege a camada interna também.
        $this->assertSame(1, MovimentacaoEstoque::where('tipo', 'saida')->where('retirado_por', $this->user->id)->count());
    }
}
