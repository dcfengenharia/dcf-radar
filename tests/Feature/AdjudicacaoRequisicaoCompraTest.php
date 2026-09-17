<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaPedidoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarProvenienciaAdjudicacaoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusAdjudicacaoRequisicaoCompra;
use App\Exceptions\AdjudicacaoConsumidaPorPedidoException;
use App\Exceptions\RequisicaoCompraAdjudicacaoInvalidaException;
use App\Exceptions\SaldoAdjudicacaoFornecedorInsuficienteException;
use App\Exceptions\SaldoAdjudicacaoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Material;
use App\Models\PedidoCompraItem;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraAdjudicacao;
use App\Models\RequisicaoCompraAdjudicacaoItem;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoCompraItemParcela;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Etapa 2 (Adjudicação Quantitativa + Dossiê Documental) — cobertura das
 * Seções 35/36 do pedido: A-L (adjudicação em si) + M-T (Pedido ×
 * Adjudicação), condensada em cenários representativos.
 */
class AdjudicacaoRequisicaoCompraTest extends TestCase
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
    private CriarRequisicaoCompra $criarRc;
    private AtualizarRascunhoRequisicaoCompra $atualizarRc;
    private EmitirRequisicaoCompra $emitirRc;
    private CriarPedidoCompra $criarPedido;
    private AtualizarRascunhoPedidoCompra $atualizarPedido;
    private EmitirPedidoCompra $emitirPedido;
    private AtualizarNecessidadeMaterialAtividade $necessidadeAction;
    private AtualizarDistribuicaoParcelaRequisicaoCompra $parcelaRc;
    private AtualizarDistribuicaoParcelaPedidoCompra $parcelaPedido;
    private CriarAdjudicacaoRequisicaoCompra $criarAdjudicacao;
    private AtualizarAdjudicacaoRequisicaoCompra $atualizarAdjudicacao;
    private AtualizarProvenienciaAdjudicacaoPedidoCompra $atualizarProveniencia;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->criarRc = new CriarRequisicaoCompra();
        $this->atualizarRc = new AtualizarRascunhoRequisicaoCompra();
        $this->emitirRc = new EmitirRequisicaoCompra();
        $this->criarPedido = new CriarPedidoCompra();
        $this->atualizarPedido = new AtualizarRascunhoPedidoCompra();
        $this->emitirPedido = new EmitirPedidoCompra();
        $this->necessidadeAction = new AtualizarNecessidadeMaterialAtividade();
        $this->parcelaRc = new AtualizarDistribuicaoParcelaRequisicaoCompra();
        $this->parcelaPedido = new AtualizarDistribuicaoParcelaPedidoCompra();
        $this->criarAdjudicacao = new CriarAdjudicacaoRequisicaoCompra();
        $this->atualizarAdjudicacao = new AtualizarAdjudicacaoRequisicaoCompra();
        $this->atualizarProveniencia = new AtualizarProvenienciaAdjudicacaoPedidoCompra();
    }

    // ---- helpers (mesmo padrão de RastreabilidadeParcelaNecessidadeTest) ----

    private function criarAtividade(?Work $obra = null): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
        ]);
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Cabo 3x25',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
    }

    private function criarItemTakeOff(Material $material, float $quantidade, ?Work $obra = null): ItemTakeOff
    {
        $obraAlvo = $obra ?? $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obraAlvo->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'Documento']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id,
            'codigo' => 'A' . uniqid(),
            'descricao' => 'Cabo 3x25 mm²',
            'unidade_medida_id' => $this->unidade->id,
            'quantidade' => $quantidade,
            'material_id' => $material->id,
        ]);
    }

    private function criarPacote(?Work $obra = null): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote ' . uniqid(), 'codigo' => 'PAC' . uniqid()]);
    }

    private function criarNecessidade(Atividade $atividade, ItemTakeOff $itemTakeOff, float $quantidade): AtividadeNecessidadeMaterial
    {
        return $this->necessidadeAction->criarTakeOff($atividade, $itemTakeOff, $quantidade, $this->user);
    }

    private function alocacaoPronta(ItemTakeOff $itemTakeOff, float $quantidadeAlocada, ?ItemSuprimento $pacote = null, ?Work $obra = null): AlocacaoRequisicaoPacote
    {
        $obraAlvo = $obra ?? $this->obra;
        $rp = $this->criarRp->execute($obraAlvo->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $itemTakeOff->id, $quantidadeAlocada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $this->alocar->alocar($rpItem->fresh(), $pacote ?? $this->criarPacote($obraAlvo), $quantidadeAlocada);
    }

    private function criarFluxo(): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        return $fluxo->fresh(['etapas']);
    }

    private function rcComItemRascunho(AlocacaoRequisicaoPacote $alocacao, float $quantidade): array
    {
        $rc = $this->criarRc->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $item = $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);

        return [$rc, $item];
    }

    private function criarFornecedor(?Work $obra = null, string $nome = 'Fornecedor'): Fornecedor
    {
        return Fornecedor::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => $nome . ' ' . uniqid(), 'cnpj' => '00.000.000/0001-00']);
    }

    /** RC Emitida com 1 item detalhado por Atividade (1 parcela). */
    private function rcEmitidaComParcela(ItemTakeOff $ito, AtividadeNecessidadeMaterial $necessidade, float $quantidadeRc, float $quantidadeParcela, ?ItemSuprimento $pacote = null): array
    {
        $alocacao = $this->alocacaoPronta($ito, $quantidadeRc, $pacote);
        [$rc, $item] = $this->rcComItemRascunho($alocacao, $quantidadeRc);
        $parcela = $this->parcelaRc->adicionarParcela($item, $necessidade, $quantidadeParcela, $this->user);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        return [$rc->fresh(), $item->fresh(), $parcela->fresh()];
    }

    /** RC Emitida com 1 item SEM nenhuma distribuição por Atividade. */
    private function rcEmitidaSemParcela(AlocacaoRequisicaoPacote $alocacao, float $quantidade): array
    {
        [$rc, $item] = $this->rcComItemRascunho($alocacao, $quantidade);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        return [$rc->fresh(), $item->fresh()];
    }

    // =========================================================================
    // A-L — Adjudicação em si
    // =========================================================================

    public function test_a_adjudicar_uma_parcela_inteira_a_um_fornecedor(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedor = $this->criarFornecedor();

        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'Menor preço', null, null, $this->user);
        $itemAdj = $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, $parcela, 300, $this->user);

        $this->assertSame(300.0, (float) $itemAdj->quantidade);
        $this->assertSame($parcela->id, $itemAdj->requisicao_compra_item_parcela_id);
        $this->assertEquals(0.0, $parcela->fresh()->saldoAdjudicavel());
    }

    public function test_b_dividir_uma_parcela_entre_dois_fornecedores(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedorX = $this->criarFornecedor(nome: 'X');
        $fornecedorY = $this->criarFornecedor(nome: 'Y');

        $adjX = $this->criarAdjudicacao->execute($rc, $fornecedorX, 'Metade X', null, null, $this->user);
        $adjY = $this->criarAdjudicacao->execute($rc, $fornecedorY, 'Metade Y', null, null, $this->user);

        $this->atualizarAdjudicacao->adicionarItem($adjX, $item, $parcela, 150, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjY, $item, $parcela, 150, $this->user);

        $this->assertEquals(0.0, $parcela->fresh()->saldoAdjudicavel());
        $this->assertEquals(150.0, $parcela->fresh()->quantidadeAdjudicadaAoFornecedor($fornecedorX->id));
        $this->assertEquals(150.0, $parcela->fresh()->quantidadeAdjudicadaAoFornecedor($fornecedorY->id));
    }

    public function test_c_uma_adjudicacao_pode_conter_varias_parcelas(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidadeA = $this->criarNecessidade($atividadeA, $ito, 300);
        $necessidadeB = $this->criarNecessidade($atividadeB, $ito, 300);

        $alocacao = $this->alocacaoPronta($ito, 600);
        [$rc, $item] = $this->rcComItemRascunho($alocacao, 600);
        $parcelaA = $this->parcelaRc->adicionarParcela($item, $necessidadeA, 300, $this->user);
        $parcelaB = $this->parcelaRc->adicionarParcela($item, $necessidadeB, 300, $this->user);
        $this->emitirRc->execute($rc->fresh(), $this->user);
        $item = $item->fresh();

        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc->fresh(), $fornecedor, 'Ganhou tudo', null, null, $this->user);

        $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, $parcelaA->fresh(), 300, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, $parcelaB->fresh(), 300, $this->user);

        $this->assertSame(2, $adjudicacao->itens()->count());
    }

    public function test_d_varias_adjudicacoes_para_uma_mesma_rc(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedorX = $this->criarFornecedor(nome: 'X');
        $fornecedorY = $this->criarFornecedor(nome: 'Y');

        $this->criarAdjudicacao->execute($rc, $fornecedorX, 'Decisão 1', null, null, $this->user);
        $this->criarAdjudicacao->execute($rc, $fornecedorY, 'Decisão 2', null, null, $this->user);

        $this->assertSame(2, RequisicaoCompraAdjudicacao::where('requisicao_compra_id', $rc->id)->count());
    }

    public function test_e_soma_adjudicada_acima_da_quota_da_parcela_e_rejeitada(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);

        $this->expectException(SaldoAdjudicacaoInsuficienteException::class);
        $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, $parcela, 301, $this->user);
    }

    public function test_f_concorrencia_nao_permite_over_adjudication(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 100, 100);
        $fornecedorA = $this->criarFornecedor(nome: 'A');
        $fornecedorB = $this->criarFornecedor(nome: 'B');
        $adjA = $this->criarAdjudicacao->execute($rc, $fornecedorA, 'A tenta 70', null, null, $this->user);
        $adjB = $this->criarAdjudicacao->execute($rc, $fornecedorB, 'B tenta 70', null, null, $this->user);

        // A trava a parcela primeiro (dentro de sua própria transação já
        // commitada) e consome 70 — reprova a disciplina de lock: como as
        // duas Actions travam a MESMA linha (RequisicaoCompraItemParcela)
        // antes de somar, é estruturalmente impossível as duas transações
        // lerem o MESMO saldo "cheio" simultaneamente. Sequencialmente,
        // confirma que o resultado nunca ultrapassa 100 no total.
        $this->atualizarAdjudicacao->adicionarItem($adjA, $item, $parcela, 70, $this->user);

        $this->expectException(SaldoAdjudicacaoInsuficienteException::class);
        $this->atualizarAdjudicacao->adicionarItem($adjB, $item, $parcela->fresh(), 70, $this->user);
    }

    public function test_g_fornecedor_de_outra_obra_e_rejeitado(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $fornecedorDeOutraObra = $this->criarFornecedor($outraObra);

        $this->expectException(RequisicaoCompraAdjudicacaoInvalidaException::class);
        $this->criarAdjudicacao->execute($rc, $fornecedorDeOutraObra, 'X', null, null, $this->user);
    }

    public function test_g2_fornecedor_de_outro_tenant_e_rejeitado(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);

        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $fornecedorDeOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant, $outraObra) {
            return Fornecedor::create(['tenant_id' => $outroTenant->id, 'obra_id' => $outraObra->id, 'nome' => 'Estranho']);
        });

        $this->expectException(RequisicaoCompraAdjudicacaoInvalidaException::class);
        $this->criarAdjudicacao->execute($rc, $fornecedorDeOutroTenant, 'X', null, null, $this->user);
    }

    public function test_h_parcela_de_outra_rc_e_rejeitada(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();

        $itoA = $this->criarItemTakeOff($material, 1000);
        $necessidadeA = $this->criarNecessidade($atividadeA, $itoA, 300);
        [$rcA, $itemA, $parcelaA] = $this->rcEmitidaComParcela($itoA, $necessidadeA, 300, 300);

        $itoB = $this->criarItemTakeOff($material, 1000);
        $necessidadeB = $this->criarNecessidade($atividadeB, $itoB, 300);
        [$rcB, $itemB, $parcelaB] = $this->rcEmitidaComParcela($itoB, $necessidadeB, 300, 300);

        $fornecedor = $this->criarFornecedor();
        $adjudicacaoDeA = $this->criarAdjudicacao->execute($rcA, $fornecedor, 'X', null, null, $this->user);

        // Tenta adjudicar, na adjudicação da RC A, o item/parcela da RC B.
        $this->expectException(RequisicaoCompraAdjudicacaoInvalidaException::class);
        $this->atualizarAdjudicacao->adicionarItem($adjudicacaoDeA, $itemB, $parcelaB, 100, $this->user);
    }

    public function test_i_parcela_nao_pertencente_ao_item_informado_e_rejeitada(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidadeA = $this->criarNecessidade($atividadeA, $ito, 300);
        $necessidadeB = $this->criarNecessidade($atividadeB, $ito, 300);

        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta($ito, 600, $pacote);
        [$rc, $item1] = $this->rcComItemRascunho($alocacao, 300);
        $parcelaDoItem1 = $this->parcelaRc->adicionarParcela($item1, $necessidadeA, 300, $this->user);

        // um segundo item da MESMA rc (mesmo Pacote), outra alocação
        $ito2 = $this->criarItemTakeOff($material, 1000);
        $alocacao2 = $this->alocacaoPronta($ito2, 300, $pacote);
        $item2 = $this->atualizarRc->adicionarItem($rc, $alocacao2, 300);
        $this->parcelaRc->adicionarParcela($item2, $necessidadeB, 300, $this->user);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc->fresh(), $fornecedor, 'X', null, null, $this->user);

        $this->expectException(RequisicaoCompraAdjudicacaoInvalidaException::class);
        $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item2->fresh(), $parcelaDoItem1->fresh(), 100, $this->user);
    }

    public function test_j_cancelar_adjudicacao_sem_pedido_libera_saldo(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, $parcela, 300, $this->user);

        $this->assertEquals(0.0, $parcela->fresh()->saldoAdjudicavel());

        $this->atualizarAdjudicacao->cancelar($adjudicacao->fresh(), $this->user, 'Mudança de decisão');

        $this->assertEquals(300.0, $parcela->fresh()->saldoAdjudicavel());
        $this->assertSame(StatusAdjudicacaoRequisicaoCompra::Cancelada, $adjudicacao->fresh()->status);
        $this->assertNotNull($adjudicacao->fresh()->cancelado_em);

        // Uma nova adjudicação pode usar o saldo liberado (Seção 9 —
        // reconsideração é NOVA linha, nunca sobrescreve a antiga).
        $novaAdjudicacao = $this->criarAdjudicacao->execute($rc->fresh(), $this->criarFornecedor(), 'Nova decisão', null, null, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($novaAdjudicacao, $item->fresh(), $parcela->fresh(), 300, $this->user);
        $this->assertEquals(0.0, $parcela->fresh()->saldoAdjudicavel());
    }

    public function test_k_adjudicacao_ja_consumida_por_pedido_nao_pode_ser_apagada(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
        $itemAdj = $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, $parcela, 300, $this->user);

        $pedido = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 300);
        $parcelaPedido = $this->parcelaPedido->adicionarParcela($pedidoItem, $necessidade, 300, $this->user);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), $parcelaPedido->fresh(), $itemAdj->fresh(), 300, $this->user);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->expectException(AdjudicacaoConsumidaPorPedidoException::class);
        $this->atualizarAdjudicacao->removerItem($itemAdj->fresh(), $this->user);
    }

    public function test_k2_adjudicacao_ja_consumida_por_pedido_nao_pode_ser_cancelada(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
        $itemAdj = $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, $parcela, 300, $this->user);

        $pedido = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 300);
        $parcelaPedido = $this->parcelaPedido->adicionarParcela($pedidoItem, $necessidade, 300, $this->user);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), $parcelaPedido->fresh(), $itemAdj->fresh(), 300, $this->user);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->expectException(AdjudicacaoConsumidaPorPedidoException::class);
        $this->atualizarAdjudicacao->cancelar($adjudicacao->fresh(), $this->user, null);
    }

    public function test_l_historico_de_reconsideracao_preservado(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'Decisão original', null, null, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, $parcela, 300, $this->user);

        $this->atualizarAdjudicacao->cancelar($adjudicacao->fresh(), $this->user, 'Reconsiderado');

        // A linha original CONTINUA no banco, íntegra — nunca deletada.
        $adjudicacaoFresh = RequisicaoCompraAdjudicacao::find($adjudicacao->id);
        $this->assertNotNull($adjudicacaoFresh);
        $this->assertSame('Decisão original', $adjudicacaoFresh->justificativa);
        $this->assertSame(StatusAdjudicacaoRequisicaoCompra::Cancelada, $adjudicacaoFresh->status);
        $this->assertSame(1, $adjudicacaoFresh->itens()->count());
    }

    public function test_m_adjudicacao_nao_deletavel_diretamente(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);

        $this->expectException(\App\Exceptions\RequisicaoCompraAdjudicacaoImutavelException::class);
        $adjudicacao->delete();
    }

    // ---- Granularidade coerente ----

    public function test_n_item_com_parcelas_exige_adjudicacao_por_parcela(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc, $item] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);

        $this->expectException(RequisicaoCompraAdjudicacaoInvalidaException::class);
        $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, null, 300, $this->user);
    }

    public function test_o_item_sem_parcelas_exige_adjudicacao_direta_no_item(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 300);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 300);
        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);

        $itemAdj = $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, null, 300, $this->user);

        $this->assertNull($itemAdj->requisicao_compra_item_parcela_id);
        $this->assertSame($item->id, $itemAdj->requisicao_compra_item_id);
        $this->assertEquals(0.0, $item->fresh()->saldoAdjudicavelSemParcela());
    }

    public function test_o2_item_sem_parcelas_com_adjudicacao_de_parcela_e_rejeitado(): void
    {
        // parcela de OUTRO item, mas usada como "com detalhamento" contra
        // um item sem nenhuma parcela própria — a checagem de coerência
        // roda ANTES da checagem de pertencimento, então isso também cai
        // no mesmo erro didático.
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $itoComParcela = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $itoComParcela, 300);
        [$rcComParcela, , $parcelaExistente] = $this->rcEmitidaComParcela($itoComParcela, $necessidade, 300, 300);

        $itoSemParcela = $this->criarItemTakeOff($material, 300);
        $alocacaoSemParcela = $this->alocacaoPronta($itoSemParcela, 300);
        [$rcSemParcela, $itemSemParcela] = $this->rcEmitidaSemParcela($alocacaoSemParcela, 300);

        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rcSemParcela, $fornecedor, 'X', null, null, $this->user);

        $this->expectException(RequisicaoCompraAdjudicacaoInvalidaException::class);
        $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $itemSemParcela, $parcelaExistente, 300, $this->user);
    }

    public function test_p_rc_rascunho_nao_pode_ser_adjudicada(): void
    {
        $pacote = $this->criarPacote();
        $rc = $this->criarRc->execute($pacote, null, null, $this->user);
        $fornecedor = $this->criarFornecedor();

        $this->expectException(RequisicaoCompraAdjudicacaoInvalidaException::class);
        $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
    }

    // =========================================================================
    // M-T — Pedido × Adjudicação
    // =========================================================================

    public function test_q_pedido_do_fornecedor_a_so_consome_quota_adjudicada_ao_a(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedorA = $this->criarFornecedor(nome: 'A');
        $fornecedorB = $this->criarFornecedor(nome: 'B');
        $adjA = $this->criarAdjudicacao->execute($rc, $fornecedorA, 'A ganha 60', null, null, $this->user);
        $adjB = $this->criarAdjudicacao->execute($rc, $fornecedorB, 'B ganha 40', null, null, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjA, $item, $parcela, 60, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjB, $item->fresh(), $parcela->fresh(), 40, $this->user);

        $pedidoA = $this->criarPedido->execute($rc, $fornecedorA, '2027-01-15', null, null, null, $this->user);
        $pedidoItemA = $this->atualizarPedido->adicionarItem($pedidoA, $item->fresh(), 60);
        $parcelaPedidoA = $this->parcelaPedido->adicionarParcela($pedidoItemA, $necessidade, 60, $this->user);

        $this->assertEquals(60.0, (float) $parcelaPedidoA->quantidade);
    }

    public function test_r_pedido_a_tentando_70_com_apenas_60_adjudicados_e_rejeitado(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedorA = $this->criarFornecedor(nome: 'A');
        $fornecedorB = $this->criarFornecedor(nome: 'B');
        $adjA = $this->criarAdjudicacao->execute($rc, $fornecedorA, 'A ganha 60', null, null, $this->user);
        $adjB = $this->criarAdjudicacao->execute($rc, $fornecedorB, 'B ganha 40', null, null, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjA, $item, $parcela, 60, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjB, $item->fresh(), $parcela->fresh(), 40, $this->user);

        $pedidoA = $this->criarPedido->execute($rc, $fornecedorA, '2027-01-15', null, null, null, $this->user);
        $pedidoItemA = $this->atualizarPedido->adicionarItem($pedidoA, $item->fresh(), 70);

        $this->expectException(\App\Exceptions\SaldoAdjudicacaoFornecedorInsuficienteException::class);
        $this->parcelaPedido->adicionarParcela($pedidoItemA, $necessidade, 70, $this->user);
    }

    public function test_s_pedido_a_60_e_pedido_b_40_permitido(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 300);
        [$rc, $item, $parcela] = $this->rcEmitidaComParcela($ito, $necessidade, 300, 300);
        $fornecedorA = $this->criarFornecedor(nome: 'A');
        $fornecedorB = $this->criarFornecedor(nome: 'B');
        $adjA = $this->criarAdjudicacao->execute($rc, $fornecedorA, 'A', null, null, $this->user);
        $adjB = $this->criarAdjudicacao->execute($rc, $fornecedorB, 'B', null, null, $this->user);
        $itemAdjA = $this->atualizarAdjudicacao->adicionarItem($adjA, $item, $parcela, 60, $this->user);
        $itemAdjB = $this->atualizarAdjudicacao->adicionarItem($adjB, $item->fresh(), $parcela->fresh(), 40, $this->user);

        $pedidoA = $this->criarPedido->execute($rc, $fornecedorA, '2027-01-15', null, null, null, $this->user);
        $pedidoItemA = $this->atualizarPedido->adicionarItem($pedidoA, $item->fresh(), 60);
        $parcelaPedidoA = $this->parcelaPedido->adicionarParcela($pedidoItemA, $necessidade, 60, $this->user);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItemA->fresh(), $parcelaPedidoA->fresh(), $itemAdjA->fresh(), 60, $this->user);
        $this->emitirPedido->execute($pedidoA->fresh(), $this->user);

        $pedidoB = $this->criarPedido->execute($rc->fresh(), $fornecedorB, '2027-01-15', null, null, null, $this->user);
        $pedidoItemB = $this->atualizarPedido->adicionarItem($pedidoB, $item->fresh(), 40);
        $parcelaPedidoB = $this->parcelaPedido->adicionarParcela($pedidoItemB, $necessidade, 40, $this->user);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItemB->fresh(), $parcelaPedidoB->fresh(), $itemAdjB->fresh(), 40, $this->user);
        $emitido = $this->emitirPedido->execute($pedidoB->fresh(), $this->user);

        $this->assertSame(\App\Enums\StatusPedidoCompra::Emitido, $emitido->status);
    }

    public function test_t_mesmo_fornecedor_pode_receber_multiplos_pedidos_sem_ultrapassar_adjudicacao(): void
    {
        // RCItem = 1000 (teto 2, mais folgado) mas o fornecedor só foi
        // adjudicado 700 (teto 3, mais apertado) — isola de propósito o
        // teto de adjudicação do teto de saldo bruto da RC.
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 2000);
        $alocacao = $this->alocacaoPronta($ito, 1000);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 1000);
        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'Ganhou 700 de 1000', null, null, $this->user);
        $itemAdj = $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, null, 700, $this->user);

        $pedido1 = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem1 = $this->atualizarPedido->adicionarItem($pedido1, $item->fresh(), 600);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem1->fresh(), null, $itemAdj->fresh(), 600, $this->user);
        $this->emitirPedido->execute($pedido1->fresh(), $this->user);

        $this->assertEquals(100.0, $item->fresh()->saldoAdjudicadoParaFornecedor($fornecedor->id));

        // 2º pedido do MESMO fornecedor, dentro do saldo bruto da RC (400
        // disponíveis) mas ACIMA do que ainda resta adjudicado a ele (100).
        $pedido2 = $this->criarPedido->execute($rc->fresh(), $fornecedor, '2027-01-20', null, null, null, $this->user);

        $this->expectException(SaldoAdjudicacaoFornecedorInsuficienteException::class);
        $this->atualizarPedido->adicionarItem($pedido2, $item->fresh(), 200);
    }

    public function test_u_compatibilidade_rc_sem_adjudicacao_continua_funcionando(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 500);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 500);
        $fornecedor = $this->criarFornecedor();

        // Nenhuma adjudicação registrada — o teto 3 nunca dispara.
        $pedido = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $item = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 500);
        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->assertSame(\App\Enums\StatusPedidoCompra::Emitido, $emitido->status);
    }

    public function test_v_pedido_emitido_nao_pode_ser_excluido_cancelamento_nao_existe_no_dominio(): void
    {
        // Fresh-read confirmado (Seção 28 do pedido): StatusPedidoCompra só
        // tem Rascunho|Emitido — não existe "Cancelado". Um Pedido Emitido
        // nunca é excluído (Observer), então a quota consumida permanece
        // permanentemente consumida — nunca "devolvida" silenciosamente.
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 300);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 300);
        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
        $itemAdj = $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, null, 300, $this->user);

        $pedido = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $item->fresh(), 300);
        $this->atualizarProveniencia->adicionarConsumo($pedidoItem->fresh(), null, $itemAdj->fresh(), 300, $this->user);
        $emitido = $this->emitirPedido->execute($pedido->fresh(), $this->user);

        $this->expectException(\App\Exceptions\PedidoCompraImutavelException::class);
        $emitido->delete();
    }

    public function test_w_pedido_rascunho_excluido_nunca_afetou_quota_pois_nunca_contou(): void
    {
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $alocacao = $this->alocacaoPronta($ito, 300);
        [$rc, $item] = $this->rcEmitidaSemParcela($alocacao, 300);
        $fornecedor = $this->criarFornecedor();
        $adjudicacao = $this->criarAdjudicacao->execute($rc, $fornecedor, 'X', null, null, $this->user);
        $this->atualizarAdjudicacao->adicionarItem($adjudicacao, $item, null, 300, $this->user);

        $pedidoRascunho = $this->criarPedido->execute($rc, $fornecedor, '2027-01-15', null, null, null, $this->user);
        $this->atualizarPedido->adicionarItem($pedidoRascunho, $item->fresh(), 300);

        $this->assertEquals(300.0, $item->fresh()->saldoAdjudicadoParaFornecedor($fornecedor->id));

        $pedidoRascunho->delete();

        $this->assertEquals(300.0, $item->fresh()->saldoAdjudicadoParaFornecedor($fornecedor->id));
    }
}
