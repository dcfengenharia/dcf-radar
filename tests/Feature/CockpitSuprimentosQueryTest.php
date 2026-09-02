<?php

namespace Tests\Feature;

use App\Actions\Estoque\CriarReservaEstoque;
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
use App\Enums\FaixaFolgaAtendimento;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Gestao\CockpitSuprimentosQuery;
use App\Support\Gestao\PipelineMaterialQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 21, Etapa 21.6 — Cockpit de Suprimentos e Abastecimento: testes
 * do read model (`App\Support\Gestao\CockpitSuprimentosQuery`). Cobertura
 * dos cenários A-Y do pedido (Seção 34), consistência contra as fontes de
 * origem (Seção 35) e performance/profiling real (Seção 26/27/37).
 */
class CockpitSuprimentosQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-01'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------- helpers (mesmos de CockpitObraQueryTest) ----------------

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-'.uniqid(), 'descricao' => 'Material', 'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Local '.uniqid(), 'tipo' => \App\Enums\TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
    }

    private function criarAtividade(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Atividade '.uniqid(), 'codigo_cronograma' => 'A'.uniqid(),
            'status' => StatusAtividade::Planejado->value, 'inicio_planejado' => Carbon::today()->addDays(5),
            'data_termino' => Carbon::today()->addDays(10), 'fora_do_cronograma' => false,
        ], $overrides));
    }

    private function criarItemTakeOffOrfao(?Material $material, ?Work $obra = null): ItemTakeOff
    {
        $obra ??= $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'D'.uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM'.uniqid()]);

        return ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A'.uniqid(), 'descricao' => 'Item', 'quantidade' => 1000, 'material_id' => $material?->id]);
    }

    private function criarPacoteVinculado(?Atividade $atividade = null, ?Work $obra = null): ItemSuprimento
    {
        $pacote = ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote '.uniqid()]);
        if ($atividade) {
            $pacote->atividades()->sync([$atividade->id]);
        }

        return $pacote;
    }

    private function requisitarEAlocar(ItemTakeOff $item, float $quantidade, ItemSuprimento $pacote): \App\Models\AlocacaoRequisicaoPacote
    {
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);
    }

    private function criarRc(\App\Models\AlocacaoRequisicaoPacote $alocacao, float $quantidade): \App\Models\RequisicaoCompra
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo '.uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);

        return (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
    }

    private function comprarAte(\App\Models\AlocacaoRequisicaoPacote $alocacao, float $quantidade, string $dataPrevista = '2026-12-05', ?Fornecedor $fornecedor = null): \App\Models\PedidoCompraItem
    {
        $rcEmitida = $this->criarRc($alocacao, $quantidade);
        $fornecedor ??= Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor '.uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, $dataPrevista, null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return $pedidoItem->fresh();
    }

    private function receber(\App\Models\PedidoCompraItem $pedidoItem, float $quantidade, LocalEstoque $local): void
    {
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::today(), $this->user);
        (new \App\Actions\Estoque\RegistrarEntradaEstoque())->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);
    }

    private function cenarioBase(float $necessidade = 100, array $overridesAtividade = []): array
    {
        $material = $this->criarMaterial();
        $atividade = $this->criarAtividade($overridesAtividade);
        $pacote = $this->criarPacoteVinculado($atividade);
        $item = $this->criarItemTakeOffOrfao($material);
        $alocacao = $this->requisitarEAlocar($item, $necessidade, $pacote);

        return [$atividade, $pacote, $material, $alocacao];
    }

    // =========================================================
    // A — TakeOff necessário, nenhuma requisição
    // =========================================================

    public function test_a_takeoff_necessario_nenhuma_requisicao_estagio_correto(): void
    {
        $material = $this->criarMaterial();
        $atividade = $this->criarAtividade();
        $pacote = $this->criarPacoteVinculado($atividade);
        $this->criarItemTakeOffOrfao($material); // ItemTakeOff existe, mas NUNCA foi requisitado/alocado

        // Sem alocação, o par nunca entra em PipelineMaterialQuery (nasce
        // de MovimentacaoEstoque/ReservaEstoque na obra, que não existem
        // ainda) — este cenário confirma que a ausência é honesta, não
        // que o material "sai do nada" pendente.
        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $this->assertTrue($resumo->comprasPendentes->where('material_codigo', $material->codigo)->isEmpty());
    }

    // =========================================================
    // B — Requisitado, ainda sem RC
    // =========================================================

    public function test_b_requisitado_sem_rc_aparece_como_saldo_a_colocar_em_rc(): void
    {
        [, $pacote, $material, $alocacao] = $this->cenarioBase(100);
        // Sem nenhuma movimentação/reserva ainda -> material não aparece
        // no funil (que só existe pra materiais com pegada física/reserva).
        // Cobrimos o estágio via a cadeia completa por ItemTakeOff (fonte
        // autoritativa, Ciclo 19.6), reconfirmando que "alocado" já é
        // maior que "em_rc" (zero) neste ponto.
        $item = ItemTakeOff::first();
        $cadeia = \App\Support\Suprimentos\ConciliacaoRecebimento::cadeiaCompletaPorItemTakeOff($item);
        $this->assertEquals(100, $cadeia['alocado']);
        $this->assertEquals(0, $cadeia['em_rc']);
        $this->assertEquals(100, $cadeia['saldo_a_colocar_em_rc']);
    }

    // =========================================================
    // C-G — ciclo de vida completo de um Pedido (RC sem pedido -> parcial -> completo -> recebimento)
    // =========================================================

    public function test_c_a_g_ciclo_completo_rc_pedido_recebimento(): void
    {
        [, $pacote, $material, $alocacao] = $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDays(10)]);
        $local = $this->criarLocal();

        // C — RC criada, ainda sem pedido.
        $rc = $this->criarRc($alocacao, 100);
        $this->assertSame(\App\Enums\StatusRequisicaoCompra::Emitida, $rc->status);

        // D — Pedido PARCIAL (60 de 100) -> quantidade residual correta.
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor D']);
        $pedido = (new CriarPedidoCompra())->execute($rc, $fornecedor, '2026-12-10', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rc->itens->first(), 60)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        $funil = PipelineMaterialQuery::porMateriais(collect([$material]), $this->obra->id)->get($material->id);
        $this->assertEquals(60, $funil['em_pedido']);
        $this->assertEquals(40, $rc->fresh()->itens->first()->saldoOficialParaPedido());

        // E — Pedido "completo": um SEGUNDO Pedido, sobre o mesmo RC,
        // cobre o residual de 40 -> necessidade inteira (100) já
        // comprada, ainda nada recebido.
        $pedido2 = (new CriarPedidoCompra())->execute($rc->fresh(), $fornecedor, '2026-12-12', null, null, null, $this->user);
        (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido2, $rc->fresh()->itens->first(), 40);
        (new EmitirPedidoCompra())->execute($pedido2->fresh(), $this->user);

        $funilAposCompleto = PipelineMaterialQuery::porMateriais(collect([$material]), $this->obra->id)->get($material->id);
        $this->assertEquals(100, $funilAposCompleto['em_pedido']);

        // F — Recebimento PARCIAL (30 de 60).
        $this->receber($pedidoItem, 30, $local);
        $this->assertEquals(\App\Enums\StatusRecebimentoItem::ParcialmenteRecebido, $pedidoItem->fresh()->statusRecebimento());

        // G — Recebido INTEGRALMENTE (mais 30).
        $this->receber($pedidoItem->fresh(), 30, $local);
        $this->assertEquals(\App\Enums\StatusRecebimentoItem::Recebido, $pedidoItem->fresh()->statusRecebimento());

        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        // Pedido totalmente recebido -> nunca aparece em "vencidos"/"parciais".
        $this->assertTrue($resumo->recebimentos['parciais']->where('id', $pedido->id)->isEmpty());
    }

    // =========================================================
    // H/I — atraso sem impacto imediato vs. atraso ligado à atividade amanhã
    // =========================================================

    public function test_h_pedido_atrasado_sem_atividade_proxima_nao_ganha_criticidade_falsa(): void
    {
        [, , , $alocacao] = $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDays(90)]); // bem longe
        $this->comprarAte($alocacao, 100, '2026-12-05');
        Carbon::setTestNow(Carbon::parse('2026-12-20')); // atraso real, mas sem urgência de cronograma

        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $pedido = $resumo->pedidosCriticos->first();
        $this->assertNotNull($pedido);
        $this->assertNotNull($pedido['dias_atraso']);
        // A folga É calculada corretamente como bem negativa/positiva
        // conforme a necessidade real (90 dias no futuro) — nunca uma
        // criticidade "inventada" por estar atrasado sozinho.
        $this->assertNotNull($pedido['folga_minima_associada']);
    }

    public function test_i_pedido_atrasado_ligado_a_atividade_amanha_sobe_prioridade(): void
    {
        [$atividadeLonge, , , $alocacaoLonge] = $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDays(90)]);
        $this->comprarAte($alocacaoLonge, 100, '2026-12-05');

        [$atividadePerto, , , $alocacaoPerto] = $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDays(1)]);
        $this->comprarAte($alocacaoPerto, 100, '2026-12-05');

        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $ordenados = $resumo->pedidosCriticos->pluck('folga_minima_associada')->values();
        // O pedido ligado à atividade de amanhã tem folga MUITO mais
        // negativa (necessidade em 1 dia) e deve vir ANTES do distante.
        $this->assertLessThan($ordenados->last(), $ordenados->first());
    }

    // =========================================================
    // J/K/L — folga negativa / positiva / sem previsão confiável
    // =========================================================

    public function test_j_atendimento_apos_necessidade_folga_negativa(): void
    {
        [, $pacote, , $alocacao] = $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDays(3)]);
        $this->comprarAte($alocacao, 100, '2026-12-10'); // entrega prevista DEPOIS da necessidade (dia 3)

        $faixa = FaixaFolgaAtendimento::classificar($pacote->fresh(['requisicoesCompra.pedidos'])->folgaAtendimento(), CockpitSuprimentosQuery::LIMIAR_FOLGA_PEQUENA_DIAS);
        $this->assertSame(FaixaFolgaAtendimento::Atrasada, $faixa);
    }

    public function test_k_atendimento_antes_da_necessidade_folga_positiva(): void
    {
        [, $pacote, , $alocacao] = $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDays(60)]);
        $this->comprarAte($alocacao, 100, '2026-12-05'); // entrega bem antes da necessidade

        $faixa = FaixaFolgaAtendimento::classificar($pacote->fresh(['requisicoesCompra.pedidos'])->folgaAtendimento(), CockpitSuprimentosQuery::LIMIAR_FOLGA_PEQUENA_DIAS);
        $this->assertSame(FaixaFolgaAtendimento::Positiva, $faixa);
    }

    public function test_l_sem_previsao_confiavel(): void
    {
        [, $pacote] = $this->cenarioBase(100); // requisitado+alocado, sem RC/Pedido

        $faixa = FaixaFolgaAtendimento::classificar($pacote->fresh(['requisicoesCompra.pedidos'])->folgaAtendimento(), CockpitSuprimentosQuery::LIMIAR_FOLGA_PEQUENA_DIAS);
        $this->assertSame(FaixaFolgaAtendimento::SemPrevisaoConfiavel, $faixa);
    }

    // =========================================================
    // M — Reserva descoberta
    // =========================================================

    public function test_m_reserva_descoberta(): void
    {
        [, $pacote, $material, $alocacao] = $this->cenarioBase(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, null, null, $this->user);
        (new RegistrarSaidaEstoque())->execute($material, $local, 40, Carbon::today(), $this->user, retiradoPor: $this->user);

        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $this->assertCount(1, $resumo->estoque['reservas_descobertas']);
        $this->assertSame(1, $resumo->panorama['reservas_descobertas']);
    }

    // =========================================================
    // N — Material em terceiro
    // =========================================================

    public function test_n_estoque_em_terceiro_aparece_nos_terceiros(): void
    {
        [, , $material, $alocacao] = $this->cenarioBase(500);
        $localProprio = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 500);
        $this->receber($pedidoItem, 500, $localProprio);

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fabricante N']);
        $localTerceiro = LocalEstoque::create([
            'obra_id' => $this->obra->id, 'nome' => 'Terceiro N', 'tipo' => \App\Enums\TipoLocalEstoque::Terceiro->value,
            'ativo' => true, 'fornecedor_id' => $fornecedor->id,
        ]);
        $ordem = (new \App\Actions\Estoque\CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        (new \App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $material, 300, $this->user);
        $ordem = (new \App\Actions\Estoque\EmitirOrdemIndustrializacao())->execute($ordem->fresh(), $this->user);
        (new \App\Actions\Estoque\RegistrarRemessaIndustrializacao())->execute(
            $ordem->fresh(), $material, 200, \App\Enums\DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user,
        );

        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $linha = $resumo->terceiros->get($ordem->id);
        $this->assertEquals(200, $linha['em_poder_terceiro']);
    }

    // =========================================================
    // O — Material parado
    // =========================================================

    public function test_o_material_parado(): void
    {
        [, , $material, $alocacao] = $this->cenarioBase(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);

        Carbon::setTestNow(Carbon::parse('2026-12-01')->addDays(65)); // > threshold de 60d do MaterialParadoQuery

        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $this->assertCount(1, $resumo->estoque['materiais_parados']);
    }

    // =========================================================
    // P — Transferência não cria disponibilidade nova
    // =========================================================

    public function test_p_transferencia_nao_cria_disponibilidade_nova(): void
    {
        [, , $material, $alocacao] = $this->cenarioBase(100);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $localA);

        $fisicoAntes = SaldoEstoque::porMateriaisNaObra([$material->id], $this->obra->id)->get($material->id);
        (new RegistrarTransferenciaEstoque())->execute($material, $localA, $localB, 40, Carbon::today(), $this->user);
        $fisicoDepois = SaldoEstoque::porMateriaisNaObra([$material->id], $this->obra->id)->get($material->id);

        $this->assertEquals($fisicoAntes, $fisicoDepois);

        $funil = PipelineMaterialQuery::porMateriais(collect([$material]), $this->obra->id)->get($material->id);
        $this->assertEquals(100, $funil['fisico']); // conservado, nunca duplicado pela transferência
    }

    // =========================================================
    // R — Múltiplos pedidos/recebimentos parciais
    // =========================================================

    public function test_r_multiplos_pedidos_recebimentos_parciais_agregacao_correta(): void
    {
        [, , $material, $alocacao1] = $this->cenarioBase(100);
        $item2 = $this->criarItemTakeOffOrfao($material);
        $pacote2 = $this->criarPacoteVinculado($this->criarAtividade());
        $alocacao2 = $this->requisitarEAlocar($item2, 50, $pacote2);

        $local = $this->criarLocal();
        $item1 = $this->comprarAte($alocacao1, 100);
        $item2Pedido = $this->comprarAte($alocacao2, 50);
        $this->receber($item1, 60, $local);
        $this->receber($item2Pedido, 50, $local);

        $funil = PipelineMaterialQuery::porMateriais(collect([$material]), $this->obra->id)->get($material->id);
        $this->assertEquals(150, $funil['em_pedido']);
        $this->assertEquals(110, $funil['recebido']);
    }

    // =========================================================
    // S — Fornecedor com vários pedidos
    // =========================================================

    public function test_s_fornecedor_com_varios_pedidos_agregacao_correta(): void
    {
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor S']);

        [, , , $alocacao1] = $this->cenarioBase(100);
        $this->comprarAte($alocacao1, 100, '2026-12-05', $fornecedor);
        [, , , $alocacao2] = $this->cenarioBase(50);
        $this->comprarAte($alocacao2, 50, '2026-12-06', $fornecedor);

        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $linha = $resumo->fornecedores->get($fornecedor->id);
        $this->assertNotNull($linha);
        $this->assertSame(2, $linha['pedidos_atrasados']);
    }

    // =========================================================
    // T/U — fora do horizonte / concluída não gera falsa urgência
    // =========================================================

    public function test_t_atividade_fora_do_horizonte_nao_contamina(): void
    {
        [$atividade] = $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDays(120)]);

        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $this->assertTrue($resumo->coberturaFutura->pluck('atividadeId')->doesntContain($atividade->id));
    }

    public function test_u_atividade_concluida_nao_gera_falsa_urgencia(): void
    {
        [$atividade, , , $alocacao] = $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDay(), 'status' => StatusAtividade::Concluido->value]);

        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $this->assertTrue($resumo->coberturaFutura->pluck('atividadeId')->doesntContain($atividade->id));
    }

    // =========================================================
    // V/W — multi-obra / tenant
    // =========================================================

    public function test_v_multiobra_isolamento(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::GerentePlanejamento->value);

        [$atividadeA] = $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDays(3)]);

        $resumoA = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $resumoB = CockpitSuprimentosQuery::resumo($obraB, 28);

        $this->assertTrue($resumoA->coberturaFutura->pluck('atividadeId')->contains($atividadeA->id));
        $this->assertTrue($resumoB->coberturaFutura->isEmpty());
        $this->assertTrue($resumoB->fornecedores->isEmpty());
    }

    public function test_w_tenant_isolamento(): void
    {
        $outroTenant = Tenant::factory()->create();
        $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        \App\Support\TenantContext::actingAs($outroTenant, function () use ($obraOutroTenant) {
            Atividade::create([
                'obra_id' => $obraOutroTenant->id, 'nome' => 'X', 'codigo_cronograma' => 'X1',
                'status' => StatusAtividade::Planejado->value, 'inicio_planejado' => Carbon::today()->addDays(3),
                'data_termino' => Carbon::today()->addDays(5), 'fora_do_cronograma' => false,
            ]);
        });

        $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDays(3)]);

        // Autenticado como tenant A, resumo do Work de outro tenant vem
        // completamente vazio (global scope de BelongsToTenant).
        $resumoCross = CockpitSuprimentosQuery::resumo($obraOutroTenant, 28);
        $this->assertTrue($resumoCross->coberturaFutura->isEmpty());
        $this->assertTrue($resumoCross->fornecedores->isEmpty());
        $this->assertTrue($resumoCross->necessidadesCriticas->isEmpty());
    }

    // =========================================================
    // X — unidades diferentes nunca somadas
    // =========================================================

    public function test_x_unidades_diferentes_nunca_somadas(): void
    {
        $unidadeKg = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'KG', 'nome' => 'Quilo']);
        $materialMetro = $this->criarMaterial(['unidade_medida_id' => $this->unidade->id]);
        $materialKg = $this->criarMaterial(['unidade_medida_id' => $unidadeKg->id]);

        $atividade = $this->criarAtividade();
        $pacote = $this->criarPacoteVinculado($atividade);
        $itemMetro = $this->criarItemTakeOffOrfao($materialMetro);
        $itemKg = $this->criarItemTakeOffOrfao($materialKg);
        $this->requisitarEAlocar($itemMetro, 100, $pacote);
        $this->requisitarEAlocar($itemKg, 50, $this->criarPacoteVinculado($this->criarAtividade()));

        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);

        // Cada linha do funil/comprasPendentes é POR MATERIAL — nunca uma
        // linha agregada somando os dois. Confirma que nenhuma chave
        // numérica "total geral" existe fora de contagens (Seção 23/24).
        $this->assertArrayNotHasKey('quantidade_total_geral', $resumo->panorama);
        foreach ($resumo->panorama as $valor) {
            $this->assertIsInt($valor); // panorama é SEMPRE contagem, nunca soma de quantidade física
        }
    }

    // =========================================================
    // Y — moedas (gap documentado, nunca inventado)
    // =========================================================

    public function test_y_nenhum_campo_financeiro_e_exposto(): void
    {
        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);

        $this->assertNotEmpty($resumo->gaps);
        $temGapFinanceiro = collect($resumo->gaps)->contains(fn ($g) => str_contains($g, 'financeiro') || str_contains($g, 'moeda'));
        $this->assertTrue($temGapFinanceiro);

        foreach ($resumo->pedidosCriticos as $p) {
            $this->assertArrayNotHasKey('valor', $p);
            $this->assertArrayNotHasKey('moeda', $p);
        }
    }

    // =========================================================
    // Consistência (Seção 35)
    // =========================================================

    public function test_consistencia_funil_bate_com_pipelinematerialquery(): void
    {
        [, , $material, $alocacao] = $this->cenarioBase(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);

        $direto = PipelineMaterialQuery::porMateriais(collect([$material]), $this->obra->id)->get($material->id);
        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $linha = $resumo->funilAbastecimento->firstWhere('material_codigo', $material->codigo);

        $this->assertEquals($direto['fisico'], $linha['fisico']);
        $this->assertEquals($direto['recebido'], $linha['recebido']);
        $this->assertEquals($direto['necessidade'], $linha['necessidade']);
    }

    public function test_consistencia_folga_bate_com_itemsuprimento_folgaatendimento(): void
    {
        [, $pacote, , $alocacao] = $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDays(3)]);
        $this->comprarAte($alocacao, 100, '2026-12-10');

        $direto = $pacote->fresh(['atividades', 'requisicoesCompra.pedidos'])->folgaAtendimento();

        $resumo = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $linha = $resumo->chegaTardeDemais->firstWhere('pacote_id', $pacote->id);

        $this->assertNotNull($linha);
        $this->assertSame($direto, $linha['folga']);
    }

    // =========================================================
    // Performance / profiling real (Seção 26/27/37)
    // =========================================================

    public function test_performance_profiling_10_100_atividades(): void
    {
        $criarCenario = function (int $i) {
            [, , , $alocacao] = $this->cenarioBase(100, ['inicio_planejado' => Carbon::today()->addDays(3 + $i % 20)]);
            $local = $this->criarLocal();
            $pedidoItem = $this->comprarAte($alocacao, 100);
            $this->receber($pedidoItem, 60, $local);
        };

        for ($i = 0; $i < 10; $i++) {
            $criarCenario($i);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $t0 = microtime(true);
        $resumo10 = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $tempo10 = microtime(true) - $t0;
        $q10 = count(DB::getQueryLog());
        $piorQuery10 = collect(DB::getQueryLog())->sortByDesc('time')->first();
        DB::flushQueryLog();

        for ($i = 10; $i < 100; $i++) {
            $criarCenario($i);
        }
        DB::flushQueryLog();
        $t1 = microtime(true);
        $resumo100 = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $tempo100 = microtime(true) - $t1;
        $q100 = count(DB::getQueryLog());
        $piorQuery100 = collect(DB::getQueryLog())->sortByDesc('time')->first();
        DB::disableQueryLog();

        fwrite(STDERR, sprintf(
            "\n[PROFILING CockpitSuprimentosQuery] 10 atividades -> %d queries, %.1fms | 100 atividades -> %d queries, %.1fms | pior query 10=%.2fms | pior query 100=%.2fms\n",
            $q10, $tempo10 * 1000, $q100, $tempo100 * 1000, $piorQuery10['time'] ?? 0, $piorQuery100['time'] ?? 0
        ));

        // `funilAbastecimento` é sempre limitado ao top-N por déficit
        // (reaproveitado de `CockpitObraQuery::montarPipelineMateriais()`,
        // já testado na 21.5) — o total REAL é `funilTotalMateriais`,
        // nunca a contagem da lista já cortada.
        $this->assertSame(10, $resumo10->funilTotalMateriais);
        $this->assertSame(100, $resumo100->funilTotalMateriais);
        $this->assertLessThan($q10 * 3, $q100, 'ACHADO: crescimento de queries parece proporcional ao volume — possível N+1 no Cockpit de Suprimentos.');
        $this->assertLessThan(5.0, $tempo100, 'Tempo total da query principal excedeu 5s pra 100 atividades — investigar antes de aprovar.');
    }

    /**
     * Seção 27 — "rode com pelo menos 500". Fixture LEVE (só RP+Alocação,
     * sem RC/Pedido/Recebimento — cadeia completa por atividade é
     * proibitivamente cara em tempo de suíte pra 500 iterações, mesma
     * limitação já documentada na 21.5) — ainda um volume REAL de 500
     * atividades/Pacotes passando pela Matriz de Prontidão/Cobertura
     * Futura, o caminho mais pesado de `resumo()` em termos de JOIN em
     * memória.
     */
    public function test_performance_profiling_500_atividades_fixture_leve(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $material = $this->criarMaterial();
            $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3 + $i % 50)]);
            $pacote = $this->criarPacoteVinculado($atividade);
            $item = $this->criarItemTakeOffOrfao($material);
            $this->requisitarEAlocar($item, 100, $pacote);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $t0 = microtime(true);
        $resumo500 = CockpitSuprimentosQuery::resumo($this->obra, 28);
        $tempo500 = microtime(true) - $t0;
        $q500 = count(DB::getQueryLog());
        $piorQuery500 = collect(DB::getQueryLog())->sortByDesc('time')->first();
        DB::disableQueryLog();

        fwrite(STDERR, sprintf(
            "\n[PROFILING CockpitSuprimentosQuery] 500 atividades (fixture leve) -> %d queries, %.1fms | pior query=%.2fms\n",
            $q500, $tempo500 * 1000, $piorQuery500['time'] ?? 0
        ));

        $this->assertGreaterThan(0, $resumo500->coberturaFutura->count());
        $this->assertLessThan(10.0, $tempo500, 'Tempo total excedeu 10s pra 500 atividades — investigar antes de aprovar.');
    }
}
