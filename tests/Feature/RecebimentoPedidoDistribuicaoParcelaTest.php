<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaPedidoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\DistribuirRecebimentoPedidoPorParcela;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Actions\Suprimentos\RegistrarRecebimentoPedido;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\QualidadeInformacaoAtendimento;
use App\Exceptions\RecebimentoConciliacaoFechadaException;
use App\Exceptions\RecebimentoConciliacaoInvalidaException;
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
use App\Models\PedidoCompra;
use App\Models\PedidoCompraItem;
use App\Models\PedidoCompraItemParcela;
use App\Models\RecebimentoPedido;
use App\Models\RecebimentoPedidoParcela;
use App\Models\RequisicaoCompra;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Suprimentos\EstadoAtendimentoNecessidadeMaterialQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fechamento Adversarial Etapa 3 (Seção 23, itens H-W) — proveniência
 * quantitativa entre RecebimentoPedido (fato físico, granularidade de
 * PedidoCompraItem) e PedidoCompraItemParcela (destino lógico,
 * granularidade de necessidade), via a ponte explícita
 * RecebimentoPedidoParcela/DistribuirRecebimentoPedidoPorParcela.
 */
class RecebimentoPedidoDistribuicaoParcelaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
    }

    private function criarAtividade(?string $inicioPlanejado = '2027-01-20'): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => $inicioPlanejado,
        ]);
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Cabo',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
    }

    private function criarItemTakeOff(Material $material, float $quantidade): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'Documento']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Cabo',
            'unidade_medida_id' => $this->unidade->id, 'quantidade' => $quantidade, 'material_id' => $material->id,
        ]);
    }

    private function criarNecessidade(Atividade $atividade, ItemTakeOff $ito, float $quantidade): AtividadeNecessidadeMaterial
    {
        return (new AtualizarNecessidadeMaterialAtividade())->criarTakeOff($atividade, $ito, $quantidade, $this->user);
    }

    private function alocacaoPronta(ItemTakeOff $ito, float $quantidade): AlocacaoRequisicaoPacote
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);
    }

    private function criarFluxo(): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        return $fluxo->fresh(['etapas']);
    }

    private function fornecedor(): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid(), 'cnpj' => '00.000.000/0001-00']);
    }

    /**
     * 1 Pedido, 1 PedidoCompraItem, 1 ÚNICA parcela (não cobre o item
     * inteiro quando $qtdParcela < $qtdTotal) — cenário do achado da
     * Seção 19.
     */
    private function pedidoComParcelaParcial(ItemTakeOff $ito, AtividadeNecessidadeMaterial $necessidade, float $qtdTotal, float $qtdParcela): array
    {
        $alocacao = $this->alocacaoPronta($ito, $qtdTotal);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $rcItem = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $qtdTotal);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($rcItem, $necessidade, $qtdParcela, $this->user);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $pedido = (new CriarPedidoCompra())->execute($rc->fresh(), $this->fornecedor(), '2027-01-10', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem->fresh(), $qtdTotal);
        $parcela = (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidade, $qtdParcela, $this->user);
        $pedidoEmitido = (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return [$pedidoEmitido->fresh(), $pedidoEmitido->itens->first(), $parcela->fresh()];
    }

    /**
     * 1 Pedido, 1 ÚNICO PedidoCompraItem servindo 2 necessidades via 2
     * PedidoCompraItemParcela — o cenário crítico do pedido original
     * (Seção 7): PedidoItem=A+B, sem como saber, sem distribuição
     * explícita, quanto cada necessidade recebeu.
     */
    private function pedidoComDuasParcelasMesmoItem(
        ItemTakeOff $ito,
        AtividadeNecessidadeMaterial $necA,
        float $qtdA,
        AtividadeNecessidadeMaterial $necB,
        float $qtdB,
    ): array {
        $qtdTotal = $qtdA + $qtdB;
        $alocacao = $this->alocacaoPronta($ito, $qtdTotal);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $rcItem = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $qtdTotal);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($rcItem, $necA, $qtdA, $this->user);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($rcItem->fresh(), $necB, $qtdB, $this->user);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $pedido = (new CriarPedidoCompra())->execute($rc->fresh(), $this->fornecedor(), '2027-01-10', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem->fresh(), $qtdTotal);
        $parcelaA = (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necA, $qtdA, $this->user);
        $parcelaB = (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necB, $qtdB, $this->user);
        $pedidoEmitido = (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return [$pedidoEmitido->fresh(), $pedidoEmitido->itens->first(), $parcelaA->fresh(), $parcelaB->fresh()];
    }

    private function linha(Atividade $atividade, AtividadeNecessidadeMaterial $necessidade): array
    {
        return EstadoAtendimentoNecessidadeMaterialQuery::porAtividade($atividade->fresh())
            ->firstWhere('necessidade.id', $necessidade->id);
    }

    // ---- H — 1 parcela que NÃO cobre o item inteiro nunca herda 100% do recebimento ----

    public function test_h_parcela_unica_que_nao_cobre_item_inteiro_nunca_atribui_recebimento_total(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 40);

        [$pedido, $pedidoItem, $parcela] = $this->pedidoComParcelaParcial($ito, $necessidade, 100, 40);

        (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 100, now()->subDay(), $this->user, null, null);

        $linha = $this->linha($atividade, $necessidade);

        $this->assertEquals(0.0, $linha['quantidade_recebida'], 'Uma parcela de 40 num item de 100 nunca pode herdar 100% do recebimento — obviamente não.');
        $this->assertContains(QualidadeInformacaoAtendimento::RecebimentoNaoAtribuivelPorParcela, $linha['qualidade_informacao']);
    }

    // ---- I — A40+B60 no MESMO item, recebimento40 sem distribuição — Informação Insuficiente, nunca inferência ----

    public function test_i_duas_parcelas_mesmo_item_sem_distribuicao_nunca_infere_destino(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();
        // Mesmo ItemTakeOff pras 2 necessidades — é a Requisição de
        // Compra sobre este ItemTakeOff que vira o MESMO
        // PedidoCompraItem (2 ItemTakeOff diferentes gerariam 2 RCItens/
        // PedidoItens distintos, nunca o cenário crítico de item
        // compartilhado que este teste precisa reproduzir).
        $ito = $this->criarItemTakeOff($material, 1000);
        $necA = $this->criarNecessidade($atividadeA, $ito, 40);
        $necB = $this->criarNecessidade($atividadeB, $ito, 60);

        [$pedido, $pedidoItem, $parcelaA, $parcelaB] = $this->pedidoComDuasParcelasMesmoItem($ito, $necA, 40, $necB, 60);

        (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 40, now()->subDay(), $this->user, null, null);

        $linhaA = $this->linha($atividadeA, $necA);
        $linhaB = $this->linha($atividadeB, $necB);

        $this->assertEquals(0.0, $linhaA['quantidade_recebida']);
        $this->assertEquals(0.0, $linhaB['quantidade_recebida']);
        $this->assertContains(QualidadeInformacaoAtendimento::RecebimentoNaoAtribuivelPorParcela, $linhaA['qualidade_informacao']);
        $this->assertContains(QualidadeInformacaoAtendimento::RecebimentoNaoAtribuivelPorParcela, $linhaB['qualidade_informacao']);
    }

    // ---- J — distribuir 40 → A explicitamente resolve a ambiguidade só para A ----

    public function test_j_distribuir_recebimento_explicitamente_resolve_so_a_necessidade_informada(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necA = $this->criarNecessidade($atividadeA, $ito, 40);
        $necB = $this->criarNecessidade($atividadeB, $ito, 60);

        [$pedido, $pedidoItem, $parcelaA, $parcelaB] = $this->pedidoComDuasParcelasMesmoItem($ito, $necA, 40, $necB, 60);

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 40, now()->subDay(), $this->user, null, null);
        (new DistribuirRecebimentoPedidoPorParcela())->execute($recebimento, $parcelaA, 40, $this->user);

        $linhaA = $this->linha($atividadeA, $necA);
        $linhaB = $this->linha($atividadeB, $necB);

        $this->assertEquals(40.0, $linhaA['quantidade_recebida']);
        $this->assertEquals(0.0, $linhaB['quantidade_recebida']);
        $this->assertNotContains(QualidadeInformacaoAtendimento::RecebimentoNaoAtribuivelPorParcela, $linhaA['qualidade_informacao']);
    }

    // ---- K/L/M/N — múltiplos recebimentos, cardinalidade N:N, soma final determinística ----

    public function test_k_l_m_n_multiplos_recebimentos_distribuidos_produzem_soma_final_deterministica(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necA = $this->criarNecessidade($atividadeA, $ito, 40);
        $necB = $this->criarNecessidade($atividadeB, $ito, 60);

        [$pedido, $pedidoItem, $parcelaA, $parcelaB] = $this->pedidoComDuasParcelasMesmoItem($ito, $necA, 40, $necB, 60);
        $acao = new DistribuirRecebimentoPedidoPorParcela();

        // R1 = 30 → A
        $r1 = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 30, now()->subDays(3), $this->user, null, null);
        $acao->execute($r1, $parcelaA, 30, $this->user);

        // R2 = 50 (10 → A, 40 → B)
        $r2 = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 50, now()->subDays(2), $this->user, null, null);
        $acao->execute($r2, $parcelaA, 10, $this->user);
        $acao->execute($r2, $parcelaB, 40, $this->user);

        // R3 = 20 → B
        $r3 = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 20, now()->subDay(), $this->user, null, null);
        $acao->execute($r3, $parcelaB, 20, $this->user);

        $linhaA = $this->linha($atividadeA, $necA);
        $linhaB = $this->linha($atividadeB, $necB);

        $this->assertEquals(40.0, $linhaA['quantidade_recebida'], 'A deveria ter recebido exatamente 40/40 (30+10), nunca proporcional.');
        $this->assertEquals(60.0, $linhaB['quantidade_recebida'], 'B deveria ter recebido exatamente 60/60 (40+20), nunca proporcional.');
    }

    // ---- O — TETO A: distribuição nunca excede o que o recebimento efetivamente recebeu ----

    public function test_o_over_allocation_do_recebimento_e_bloqueado(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);

        [$pedido, $pedidoItem, $parcela] = $this->pedidoComParcelaParcial($ito, $necessidade, 100, 100);
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 40, now()->subDay(), $this->user, null, null);

        $this->expectException(RecebimentoConciliacaoInvalidaException::class);
        (new DistribuirRecebimentoPedidoPorParcela())->execute($recebimento, $parcela, 41, $this->user);
    }

    // ---- P — TETO B: distribuição nunca excede a quantidade da própria parcela ----

    public function test_p_over_allocation_da_parcela_e_bloqueado(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necA = $this->criarNecessidade($atividadeA, $ito, 40);
        $necB = $this->criarNecessidade($atividadeB, $ito, 60);

        [$pedido, $pedidoItem, $parcelaA, $parcelaB] = $this->pedidoComDuasParcelasMesmoItem($ito, $necA, 40, $necB, 60);
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 100, now()->subDay(), $this->user, null, null);

        $this->expectException(RecebimentoConciliacaoInvalidaException::class);
        (new DistribuirRecebimentoPedidoPorParcela())->execute($recebimento, $parcelaA, 41, $this->user);
    }

    // ---- Q — TETO C: nunca atribuir recebimento a parcela de outro PedidoItem/Pedido ----

    public function test_q_distribuicao_cross_pedido_e_bloqueada(): void
    {
        $atividadeA = $this->criarAtividade();
        $material = $this->criarMaterial();
        $itoA = $this->criarItemTakeOff($material, 1000);
        $itoOutro = $this->criarItemTakeOff($material, 1000);
        $necA = $this->criarNecessidade($atividadeA, $itoA, 40);
        $necOutra = $this->criarNecessidade($atividadeA, $itoOutro, 40);

        [$pedidoA, $itemA, $parcelaA] = $this->pedidoComParcelaParcial($itoA, $necA, 40, 40);
        [$pedidoOutro, $itemOutro, $parcelaOutro] = $this->pedidoComParcelaParcial($itoOutro, $necOutra, 40, 40);

        $recebimentoA = (new RegistrarRecebimentoPedido())->execute($itemA->fresh(), 40, now()->subDay(), $this->user, null, null);

        $this->expectException(RecebimentoConciliacaoInvalidaException::class);
        (new DistribuirRecebimentoPedidoPorParcela())->execute($recebimentoA, $parcelaOutro, 10, $this->user);
    }

    // ---- R — cross-obra/tenant: distribuição nunca alcança recurso de outra obra/tenant ----

    public function test_r_distribuicao_e_escopada_ao_tenant_autenticado(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 40);

        [$pedido, $pedidoItem, $parcela] = $this->pedidoComParcelaParcial($ito, $necessidade, 40, 40);
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 40, now()->subDay(), $this->user, null, null);
        (new DistribuirRecebimentoPedidoPorParcela())->execute($recebimento, $parcela, 40, $this->user);

        $outroTenant = Tenant::factory()->create();
        $outroUsuario = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($outroUsuario);

        $this->assertNull(RecebimentoPedidoParcela::where('recebimento_pedido_id', $recebimento->id)->first());
        $this->assertNull(RecebimentoPedido::find($recebimento->id));
    }

    // ---- S — recebimento legado sem distribuição continua válido, nunca distribuído retroativamente ----

    public function test_s_recebimento_legado_sem_distribuicao_continua_valido(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necA = $this->criarNecessidade($atividadeA, $ito, 40);
        $necB = $this->criarNecessidade($atividadeB, $ito, 60);

        [$pedido, $pedidoItem, $parcelaA, $parcelaB] = $this->pedidoComDuasParcelasMesmoItem($ito, $necA, 40, $necB, 60);
        (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 100, now()->subDay(), $this->user, null, null);

        // "Legado": nenhuma distribuição foi feita, e nunca será
        // inventada — o item aparece corretamente como 100% recebido
        // (fato global, visível na tela de Suprimentos), mas o read-model
        // de necessidade nunca inventa uma divisão.
        $this->assertEquals(100.0, $pedidoItem->fresh()->quantidadeRecebida());
        $linhaA = $this->linha($atividadeA, $necA);
        $linhaB = $this->linha($atividadeB, $necB);
        $this->assertEquals(0.0, $linhaA['quantidade_recebida']);
        $this->assertEquals(0.0, $linhaB['quantidade_recebida']);
    }

    // ---- T/U — distribuição nunca cria Reserva nem Movimentação de Estoque ----

    public function test_t_u_distribuicao_nunca_cria_reserva_nem_movimentacao_estoque(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 40);

        [$pedido, $pedidoItem, $parcela] = $this->pedidoComParcelaParcial($ito, $necessidade, 40, 40);
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 40, now()->subDay(), $this->user, null, null);
        (new DistribuirRecebimentoPedidoPorParcela())->execute($recebimento, $parcela, 40, $this->user);

        $this->assertSame(0, \App\Models\ReservaEstoque::count());
        $this->assertSame(0, \App\Models\MovimentacaoEstoque::count());
    }

    // ---- V — remoção enquanto aberto é permitida; nunca reescreve o histórico do recebimento em si ----

    public function test_v_remover_distribuicao_enquanto_aberto_nunca_altera_o_recebimento_original(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necA = $this->criarNecessidade($atividadeA, $ito, 40);
        $necB = $this->criarNecessidade($atividadeB, $ito, 60);

        [$pedido, $pedidoItem, $parcelaA, $parcelaB] = $this->pedidoComDuasParcelasMesmoItem($ito, $necA, 40, $necB, 60);
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 40, now()->subDay(), $this->user, null, null);
        $distribuicao = (new DistribuirRecebimentoPedidoPorParcela())->execute($recebimento, $parcelaA, 20, $this->user);

        (new DistribuirRecebimentoPedidoPorParcela())->remover($distribuicao);

        $this->assertEquals(40.0, $recebimento->fresh()->quantidade_recebida, 'Remover uma distribuição nunca altera a quantidade recebida original.');
        $this->assertEquals(0.0, $this->linha($atividadeA, $necA)['quantidade_recebida']);
    }

    // ---- Fechado — nunca permite nova distribuição, edição ou remoção ----

    public function test_recebimento_100_por_cento_distribuido_fica_fechado_para_novas_distribuicoes(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necA = $this->criarNecessidade($atividadeA, $ito, 40);
        $necB = $this->criarNecessidade($atividadeB, $ito, 60);

        [$pedido, $pedidoItem, $parcelaA, $parcelaB] = $this->pedidoComDuasParcelasMesmoItem($ito, $necA, 40, $necB, 60);
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 40, now()->subDay(), $this->user, null, null);
        $acao = new DistribuirRecebimentoPedidoPorParcela();
        $distribuicao = $acao->execute($recebimento, $parcelaA, 40, $this->user);

        $this->expectException(RecebimentoConciliacaoFechadaException::class);
        $acao->remover($distribuicao);
    }

    public function test_recebimento_fechado_bloqueia_nova_distribuicao(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necA = $this->criarNecessidade($atividadeA, $ito, 40);
        $necB = $this->criarNecessidade($atividadeB, $ito, 60);

        [$pedido, $pedidoItem, $parcelaA, $parcelaB] = $this->pedidoComDuasParcelasMesmoItem($ito, $necA, 40, $necB, 60);
        // Recebimento de 40, distribuído por completo pra A.
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 40, now()->subDay(), $this->user, null, null);
        (new DistribuirRecebimentoPedidoPorParcela())->execute($recebimento, $parcelaA, 40, $this->user);

        $this->expectException(RecebimentoConciliacaoInvalidaException::class);
        (new DistribuirRecebimentoPedidoPorParcela())->execute($recebimento, $parcelaB, 1, $this->user);
    }

    // ---- Concorrência (Seção 14) — ordem de lock determinística: RecebimentoPedido sempre primeiro ----

    public function test_ordem_de_lock_e_deterministica_recebimento_antes_da_parcela(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 40);

        [$pedido, $pedidoItem, $parcela] = $this->pedidoComParcelaParcial($ito, $necessidade, 40, 40);
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 40, now()->subDay(), $this->user, null, null);

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        (new DistribuirRecebimentoPedidoPorParcela())->execute($recebimento, $parcela, 40, $this->user);

        $indiceLockRecebimento = collect($queries)->search(fn ($sql) => str_contains($sql, 'recebimentos_pedido') && str_contains($sql, 'for update'));
        $indiceLockParcela = collect($queries)->search(fn ($sql) => str_contains($sql, 'pedido_compra_item_parcelas') && str_contains($sql, 'for update'));

        $this->assertNotFalse($indiceLockRecebimento, 'Esperava um lockForUpdate() sobre recebimentos_pedido.');
        $this->assertNotFalse($indiceLockParcela, 'Esperava um lockForUpdate() sobre pedido_compra_item_parcelas.');
        $this->assertLessThan($indiceLockParcela, $indiceLockRecebimento, 'RecebimentoPedido precisa ser travado ANTES de PedidoCompraItemParcela — ordem determinística, sempre a mesma em todo caller.');
    }

    // ---- W — read-model só usa a heurística segura quando genuinamente inequívoca ----

    public function test_w_heuristica_1_parcela_so_se_aplica_quando_parcela_cobre_item_inteiro(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 40);

        // Parcela ÚNICA e cobre o item por COMPLETO (40=40) — heurística
        // segura continua válida e continua funcionando sem distribuição
        // explícita nenhuma.
        [$pedido, $pedidoItem, $parcela] = $this->pedidoComParcelaParcial($ito, $necessidade, 40, 40);
        (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 40, now()->subDay(), $this->user, null, null);

        $linha = $this->linha($atividade, $necessidade);

        $this->assertEquals(40.0, $linha['quantidade_recebida']);
        $this->assertNotContains(QualidadeInformacaoAtendimento::RecebimentoNaoAtribuivelPorParcela, $linha['qualidade_informacao']);
    }
}
