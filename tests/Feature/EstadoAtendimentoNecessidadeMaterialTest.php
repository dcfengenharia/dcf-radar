<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaPedidoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarPrevisaoEntregaPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Actions\Suprimentos\RegistrarRecebimentoPedido;
use App\Enums\EstadoComercialNecessidadeMaterial;
use App\Enums\EstadoCompromissoNecessidadeMaterial;
use App\Enums\EstadoNecessidadeMaterialAtividade;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\QualidadeInformacaoAtendimento;
use App\Enums\TipoLocalEstoque;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\RequisicaoCompra;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Suprimentos\EstadoAtendimentoNecessidadeMaterialQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Etapa 3 — Read-model de Atendimento (Seções 34/35/36).
 */
class EstadoAtendimentoNecessidadeMaterialTest extends TestCase
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

    private function criarAtividade(?string $inicioPlanejado = '2027-01-20', bool $foraDoCronograma = false): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => $inicioPlanejado,
            'fora_do_cronograma' => $foraDoCronograma,
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

    private function rcComParcela(ItemTakeOff $ito, AtividadeNecessidadeMaterial $necessidade, float $quantidadeRc, float $quantidadeParcela): array
    {
        $alocacao = $this->alocacaoPronta($ito, $quantidadeRc);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $item = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidadeRc);
        $parcela = (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($item, $necessidade, $quantidadeParcela, $this->user);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        return [$rc->fresh(), $item->fresh(), $parcela->fresh()];
    }

    private function fornecedor(string $nome = 'Fornecedor'): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => $nome . ' ' . uniqid(), 'cnpj' => '00.000.000/0001-00']);
    }

    /** Pedido Emitido consumindo `$quantidade` da parcela informada, com data prevista `$data` (ou null). */
    private function pedidoParaParcela(RequisicaoCompra $rc, $rcItem, AtividadeNecessidadeMaterial $necessidade, Fornecedor $fornecedor, float $quantidade, ?string $data): \App\Models\PedidoCompra
    {
        $pedido = (new CriarPedidoCompra())->execute($rc->fresh(), $fornecedor, $data, null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem->fresh(), $quantidade);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidade, $quantidade, $this->user);

        return (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
    }

    private function linha(Atividade $atividade, AtividadeNecessidadeMaterial $necessidade): array
    {
        return EstadoAtendimentoNecessidadeMaterialQuery::porAtividade($atividade->fresh())
            ->firstWhere('necessidade.id', $necessidade->id);
    }

    // ---- L/M — antes/depois da necessidade ----

    public function test_l_necessidade100_pedido100_promessa_antes_e_no_prazo(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcComParcela($ito, $necessidade, 100, 100);

        $this->pedidoParaParcela($rc, $item, $necessidade, $this->fornecedor(), 100, '2027-01-10');

        $linha = $this->linha($atividade, $necessidade);

        $this->assertSame(EstadoComercialNecessidadeMaterial::PedidaNoPrazo, $linha['estado_comercial']);
        $this->assertEquals(10, $linha['folga_dias']);
        $this->assertEquals(100.0, $linha['qtd_prometida_ate_data_necessidade']);
        $this->assertEquals(0.0, $linha['qtd_prometida_depois_data_necessidade']);
    }

    public function test_m_necessidade100_pedido100_promessa_depois_fora_do_prazo(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcComParcela($ito, $necessidade, 100, 100);

        $this->pedidoParaParcela($rc, $item, $necessidade, $this->fornecedor(), 100, '2027-01-30');

        $linha = $this->linha($atividade, $necessidade);

        $this->assertSame(EstadoComercialNecessidadeMaterial::PedidaEmRisco, $linha['estado_comercial']);
        $this->assertEquals(-10, $linha['folga_dias']);
        $this->assertEquals(0.0, $linha['qtd_prometida_ate_data_necessidade']);
        $this->assertEquals(100.0, $linha['qtd_prometida_depois_data_necessidade']);
    }

    // ---- N — P1 cedo + P2 tarde, tratamento quantitativo ----

    public function test_n_p1_40_cedo_e_p2_60_tarde_nunca_classifica_tudo_como_no_prazo(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcComParcela($ito, $necessidade, 100, 100);

        $fornecedor = $this->fornecedor();
        $this->pedidoParaParcela($rc, $item, $necessidade, $fornecedor, 40, '2027-01-10');
        $rc2 = $rc->fresh();
        $item2 = \App\Models\RequisicaoCompraItem::find($item->id);
        $this->pedidoParaParcela($rc2, $item2, $necessidade, $fornecedor, 60, '2027-01-30');

        $linha = $this->linha($atividade, $necessidade);

        $this->assertEquals(40.0, $linha['qtd_prometida_ate_data_necessidade']);
        $this->assertEquals(60.0, $linha['qtd_prometida_depois_data_necessidade']);
        $this->assertEquals(0.0, $linha['qtd_sem_prazo']);
        $this->assertEquals(0.0, $linha['qtd_nao_pedida']);
        $this->assertNull($linha['data_prometida_relevante']);
        $this->assertNull($linha['folga_dias']);
        $this->assertContains(QualidadeInformacaoAtendimento::PromessaAmbiguaMultiploPedido, $linha['qualidade_informacao']);
        // Nunca "PedidaNoPrazo" só porque existe 1 Pedido cedo — o risco dos 60 tardios precisa aparecer.
        $this->assertSame(EstadoComercialNecessidadeMaterial::PedidaEmRisco, $linha['estado_comercial']);
    }

    // ---- O — Pedido sem prazo ----

    public function test_o_pedido_sem_prazo_nunca_e_classificado_como_atrasado_por_inferencia(): void
    {
        // EmitirPedidoCompra (Etapa 19.5.CORREÇÃO, já pré-existente neste domínio)
        // exige data_prevista_entrega antes de emitir — "Pedido Emitido sem
        // previsão" só é alcançável hoje como dado LEGADO (anterior a essa
        // correção), nunca via fluxo normal de emissão. Simulado aqui do mesmo
        // jeito que outros cenários de legado já testados neste projeto:
        // emite normalmente e força o campo pra null direto no model.
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcComParcela($ito, $necessidade, 100, 100);

        $pedido = $this->pedidoParaParcela($rc, $item, $necessidade, $this->fornecedor(), 100, '2027-01-10');
        $pedido->forceFill(['data_prevista_entrega' => null])->save();

        $linha = $this->linha($atividade, $necessidade);

        $this->assertSame(EstadoComercialNecessidadeMaterial::PedidaSemPrazo, $linha['estado_comercial']);
        $this->assertEquals(100.0, $linha['qtd_sem_prazo']);
        $this->assertNull($linha['folga_dias']);
    }

    // ---- P — adjudicado sem Pedido ----

    public function test_p_adjudicado_sem_pedido_e_estagio_proprio_sem_data_inventada(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcComParcela($ito, $necessidade, 100, 100);

        $fornecedor = $this->fornecedor();
        $adjudicacao = (new CriarAdjudicacaoRequisicaoCompra())->execute($rc, $fornecedor, 'Decisão', null, null, $this->user);
        (new AtualizarAdjudicacaoRequisicaoCompra())->adicionarItem($adjudicacao, $item, $parcela, 100);

        $linha = $this->linha($atividade, $necessidade);

        $this->assertSame(EstadoComercialNecessidadeMaterial::Adjudicada, $linha['estado_comercial']);
        $this->assertEquals(100.0, $linha['quantidade_adjudicada']);
        $this->assertEquals(0.0, $linha['quantidade_pedida']);
        $this->assertNull($linha['data_prometida_relevante']);
    }

    // ---- Q — recebimento parcial ----

    public function test_q_necessidade100_pedido100_recebido40_e_parcial(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcComParcela($ito, $necessidade, 100, 100);

        $pedido = $this->pedidoParaParcela($rc, $item, $necessidade, $this->fornecedor(), 100, '2027-01-10');
        $pedidoItem = $pedido->fresh(['itens'])->itens->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItem, 40, now()->subDay(), $this->user, null, null);

        $linha = $this->linha($atividade, $necessidade);

        $this->assertSame(EstadoComercialNecessidadeMaterial::RecebidaParcial, $linha['estado_comercial']);
        $this->assertEquals(40.0, $linha['quantidade_recebida']);
        $this->assertEmpty($linha['qualidade_informacao']); // item tem 1 única parcela -> atribuível com certeza
    }

    // ---- R/S — compromisso via reserva específica, nunca estoque livre ----

    public function test_r_necessidade100_reservado100_e_compromisso_integral(): void
    {
        [$atividade, $necessidade, $material] = $this->cenarioComEstoque(100, 100, 0);

        $linha = $this->linha($atividade, $necessidade);

        $this->assertSame(EstadoCompromissoNecessidadeMaterial::ReservadaIntegralmente, $linha['estado_compromisso']);
        $this->assertSame(EstadoNecessidadeMaterialAtividade::Coberta, $linha['estado_fisico']);
    }

    public function test_s_estoque_livre_sem_reserva_nunca_e_compromisso(): void
    {
        [$atividade, $necessidade, $material] = $this->cenarioComEstoque(100, 0, 100);

        $linha = $this->linha($atividade, $necessidade);

        $this->assertSame(EstadoCompromissoNecessidadeMaterial::NaoReservada, $linha['estado_compromisso']);
        $this->assertSame(EstadoNecessidadeMaterialAtividade::DisponivelParaReserva, $linha['estado_fisico']);
    }

    /** @return array{0: Atividade, 1: AtividadeNecessidadeMaterial, 2: Material} */
    private function cenarioComEstoque(float $necessario, float $reservado, float $livreExtra): array
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, $necessario);

        $local = LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Almoxarifado', 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);

        $totalFisico = $reservado + $livreExtra;
        if ($totalFisico > 0) {
            \App\Models\MovimentacaoEstoque::create([
                'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'material_id' => $material->id,
                'local_estoque_id' => $local->id, 'tipo' => \App\Enums\TipoMovimentacaoEstoque::Entrada->value,
                'quantidade' => $totalFisico, 'ocorrido_em' => now(), 'registrado_por_id' => $this->user->id,
            ]);
        }

        if ($reservado > 0) {
            $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
            \App\Models\ReservaEstoque::create([
                'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'item_suprimento_id' => $pacote->id,
                'material_id' => $material->id, 'local_estoque_id' => $local->id, 'necessidade_atividade_id' => $necessidade->id,
                'quantidade' => $reservado, 'status' => \App\Enums\StatusReservaEstoque::Ativa->value, 'created_by_id' => $this->user->id,
            ]);
        }

        return [$atividade, $necessidade, $material];
    }

    // ---- W — necessidade sem vínculo comercial ----

    public function test_w_necessidade_sem_vinculo_comercial_nunca_inventa_rc_ou_pedido(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $necessidade = (new AtualizarNecessidadeMaterialAtividade())->criarOperacional($atividade, $material, 50, 'Necessidade avulsa', $this->user);

        $linha = $this->linha($atividade, $necessidade);

        $this->assertSame(EstadoComercialNecessidadeMaterial::NaoContratada, $linha['estado_comercial']);
        $this->assertEquals(0.0, $linha['quantidade_em_rc']);
        $this->assertEquals(0.0, $linha['quantidade_adjudicada']);
        $this->assertEquals(0.0, $linha['quantidade_pedida']);
    }

    // ---- Proveniência (Seção 35) — nunca por Material/Pacote/fornecedor em comum ----

    public function test_proveniencia_pedido_de_outra_necessidade_do_mesmo_material_nunca_conta(): void
    {
        $atividadeA = $this->criarAtividade('2027-01-20');
        $atividadeB = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();

        $itoA = $this->criarItemTakeOff($material, 1000);
        $necessidadeA = $this->criarNecessidade($atividadeA, $itoA, 100);
        [$rcA, $itemA, $parcelaA] = $this->rcComParcela($itoA, $necessidadeA, 100, 100);
        $this->pedidoParaParcela($rcA, $itemA, $necessidadeA, $this->fornecedor(), 100, '2027-01-10');

        // Necessidade B, MESMO Material, mas SEM nenhuma cadeia própria de RC/Pedido.
        $itoB = $this->criarItemTakeOff($material, 1000);
        $necessidadeB = $this->criarNecessidade($atividadeB, $itoB, 50);

        $linhaB = $this->linha($atividadeB, $necessidadeB);

        // O Pedido de A nunca "vaza" pra B só por compartilhar o Material.
        $this->assertEquals(0.0, $linhaB['quantidade_pedida']);
        $this->assertSame(EstadoComercialNecessidadeMaterial::NaoContratada, $linhaB['estado_comercial']);
    }

    // ---- Histórico com múltiplos Pedidos (Seção 36) ----

    public function test_historico_com_multiplos_pedidos_usa_previsao_vigente_mas_preserva_original(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcComParcela($ito, $necessidade, 100, 100);

        $fornecedor = $this->fornecedor();
        $pedidoA = $this->pedidoParaParcela($rc, $item, $necessidade, $fornecedor, 40, '2027-01-10');
        $rc2 = $rc->fresh();
        $item2 = \App\Models\RequisicaoCompraItem::find($item->id);
        $this->pedidoParaParcela($rc2, $item2, $necessidade, $fornecedor, 60, '2027-01-15');

        // A revisada pra 25/10 (bem depois da necessidade 20/01... usamos data compatível com o teste: revisão pra depois de 20/01)
        (new AtualizarPrevisaoEntregaPedidoCompra())->execute($pedidoA->fresh(), \Carbon\Carbon::parse('2027-01-25'), $this->user, 'Fornecedor revisou', null);

        $linha = $this->linha($atividade, $necessidade);

        // Read-model usa a previsão VIGENTE de A (25/01, depois da necessidade).
        $this->assertEquals(60.0, $linha['qtd_prometida_ate_data_necessidade']); // só B (15/01)
        $this->assertEquals(40.0, $linha['qtd_prometida_depois_data_necessidade']); // A revisado (25/01)

        // Histórico de A continua mostrando a promessa ORIGINAL (10/01).
        $historicoA = \App\Models\PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedidoA->id)->orderBy('created_at')->pluck('data_prevista')->map->toDateString();
        $this->assertSame(['2027-01-10', '2027-01-25'], $historicoA->all());
    }
}
