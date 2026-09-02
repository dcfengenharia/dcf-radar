<?php

namespace Tests\Feature;

use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\RegistrarAplicacaoMaterialEstoque;
use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Actions\Estoque\RegistrarSaidaEstoque;
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
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\SeveridadeSituacao;
use App\Enums\StatusAtividade;
use App\Enums\StatusOrdemIndustrializacao;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoSituacaoGerencial;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\CentralProntidao\CentralProntidaoQuery;
use App\Support\Gestao\CockpitObraQuery;
use App\Support\Gestao\CoberturaMaterialAtividadeQuery;
use App\Support\Gestao\PipelineMaterialQuery;
use App\Support\Gestao\SituacoesGerenciaisQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 21, Etapa 21.5 — Cockpit Executivo da Obra: testes do read model
 * (`App\Support\Gestao\CockpitObraQuery`). Cobertura A-P (Seção 35),
 * consistência contra os serviços de origem (Seção 36) e performance
 * (Seção 37). Cenários N (deep-link) ficam em `CockpitPageTest.php`
 * (camada de UI/rota).
 */
class CockpitObraQueryTest extends TestCase
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

    // ---------------- helpers (mesmos de SituacoesGerenciaisQueryTest) ----------------

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-'.uniqid(), 'descricao' => 'Material', 'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Local '.uniqid(), 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
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

    private function comprarAte(\App\Models\AlocacaoRequisicaoPacote $alocacao, float $quantidade, string $dataPrevista = '2026-12-05'): \App\Models\PedidoCompraItem
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo '.uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor '.uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, $dataPrevista, null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return $pedidoItem->fresh();
    }

    private function receber(\App\Models\PedidoCompraItem $pedidoItem, float $quantidade, LocalEstoque $local): void
    {
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::today(), $this->user);
        (new RegistrarEntradaEstoque())->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);
    }

    private function cenarioMaterialCritico(float $necessidade = 100, array $overridesAtividade = []): array
    {
        $material = $this->criarMaterial();
        $atividade = $this->criarAtividade($overridesAtividade);
        $pacote = $this->criarPacoteVinculado($atividade);
        $item = $this->criarItemTakeOffOrfao($material);
        $alocacao = $this->requisitarEAlocar($item, $necessidade, $pacote);

        return [$atividade, $pacote, $material, $alocacao];
    }

    private function criarDocumentoBloqueante(Atividade $atividade, ?Work $obra = null): array
    {
        $doc = DocumentoEngenharia::create(['obra_id' => ($obra ?? $this->obra)->id, 'codigo' => 'DOC-'.uniqid(), 'descricao' => 'Doc']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $doc->atividades()->sync([$atividade->id]);

        return [$doc, $rev];
    }

    // =========================================================
    // A — Obra saudável
    // =========================================================

    public function test_a_obra_saudavel_nenhuma_situacao_critica_estado_vazio(): void
    {
        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertSame(0, $resumo->panorama['total']);
        $this->assertTrue($resumo->riscos->isEmpty());
        $this->assertTrue($resumo->acoesHoje->isEmpty());
        $this->assertSame(0, $resumo->totalInformativas);
        $this->assertSame(0, $resumo->prontidao['2']->total);
        $this->assertTrue($resumo->matrizAtividades->isEmpty());
        $this->assertTrue($resumo->fornecedores->isEmpty());
        $this->assertTrue($resumo->industrializacao->isEmpty());
        $this->assertSame(0, $resumo->inventario['em_contagem']);
        $this->assertNotEmpty($resumo->gaps); // gaps sempre documentados, mesmo em obra saudável
    }

    // =========================================================
    // B — Atividade amanhã sem material -> topo de "pode parar a obra"
    // =========================================================

    public function test_b_atividade_amanha_sem_material_aparece_no_topo_de_riscos(): void
    {
        [$atividade] = $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDay()]);

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertNotEmpty($resumo->riscos);
        $primeiro = $resumo->riscos->first();
        $this->assertSame(TipoSituacaoGerencial::MaterialCritico, $primeiro->tipo);
        $this->assertSame($atividade->id, $primeiro->entidadeId);
        $this->assertContains($primeiro->severidade, [SeveridadeSituacao::Critica, SeveridadeSituacao::Alta]);
    }

    // =========================================================
    // C — Atividade em 7 dias parcialmente coberta -> quantidade faltante
    // =========================================================

    public function test_c_atividade_parcialmente_coberta_quantidade_faltante_correta(): void
    {
        [$atividade, $pacote, $material, $alocacao] = $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(7)]);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 60, null, null, $this->user);

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $linha = $resumo->matrizAtividades->firstWhere('atividadeId', $atividade->id);
        $this->assertNotNull($linha);
        $this->assertSame(\App\Enums\EstadoCoberturaMaterial::ParcialmenteCoberto, $linha->prontidaoMaterial);
        $materialCritico = collect($linha->materiaisCriticos)->firstWhere('material_id', $material->id);
        $this->assertSame(40.0, $materialCritico['faltante']);
    }

    // =========================================================
    // D — Atividade fora do horizonte não contamina
    // =========================================================

    public function test_d_atividade_fora_do_horizonte_nao_contamina(): void
    {
        [$atividadeFora] = $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(120)]);
        [$atividadeDentro] = $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(3)]);

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertNull($resumo->matrizAtividades->firstWhere('atividadeId', $atividadeFora->id));
        $this->assertNotNull($resumo->matrizAtividades->firstWhere('atividadeId', $atividadeDentro->id));
        $this->assertTrue($resumo->riscos->pluck('entidadeId')->doesntContain($atividadeFora->id));
    }

    // =========================================================
    // E — Comprado não recebido: nunca "disponível"
    // =========================================================

    public function test_e_comprado_nao_recebido_nunca_aparece_como_disponivel(): void
    {
        [$atividade, , , $alocacao] = $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(5)]);
        $this->comprarAte($alocacao, 100); // Pedido emitido, NUNCA recebido

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $linha = $resumo->matrizAtividades->firstWhere('atividadeId', $atividade->id);
        $this->assertSame(\App\Enums\EstadoCoberturaMaterial::CompradoAguardandoRecebimento, $linha->prontidaoMaterial);

        // Bucket de prontidão 2/4/8 — "descobertas", nunca "cobertas".
        $this->assertSame(0, $resumo->prontidao['2']->cobertas);
        $this->assertSame(1, $resumo->prontidao['2']->descobertas);
    }

    // =========================================================
    // F — Pedido atrasado crítico, ligado à necessidade futura
    // =========================================================

    public function test_f_pedido_atrasado_critico_aparece_em_riscos(): void
    {
        [, , , $alocacao] = $this->cenarioMaterialCritico(100);
        $this->comprarAte($alocacao, 100, '2026-12-05');

        Carbon::setTestNow(Carbon::parse('2026-12-25')); // 20 dias de atraso -> Critica

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $pedidoAtrasado = $resumo->riscos->first(fn ($s) => $s->tipo === TipoSituacaoGerencial::PedidoAtrasado);
        $this->assertNotNull($pedidoAtrasado);
        $this->assertSame(SeveridadeSituacao::Critica, $pedidoAtrasado->severidade);
    }

    // =========================================================
    // G — Reserva descoberta: destaque de recomposição
    // =========================================================

    public function test_g_reserva_descoberta_aparece_no_bloco_estoque(): void
    {
        [, $pacote, $material, $alocacao] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, null, null, $this->user);
        (new RegistrarSaidaEstoque())->execute($material, $local, 30, Carbon::today(), $this->user, retiradoPor: $this->user);

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertCount(1, $resumo->estoque['reservas_descobertas']);
        $this->assertEquals(30, $resumo->estoque['reservas_descobertas']->first()->quantidade);
        // ReservaDescoberta é sempre Crítica -> deveria também aparecer em riscos.
        $this->assertTrue($resumo->riscos->pluck('tipo')->contains(TipoSituacaoGerencial::ReservaDescoberta));
    }

    // =========================================================
    // H — Material em terceiro: custódia apresentada corretamente
    // =========================================================

    public function test_h_material_em_terceiro_custodia_apresentada(): void
    {
        [, , $material, $alocacao] = $this->cenarioMaterialCritico(500);
        $localProprio = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 500);
        $this->receber($pedidoItem, 500, $localProprio);

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fabricante X']);
        $localTerceiro = LocalEstoque::create([
            'obra_id' => $this->obra->id, 'nome' => 'Terceiro X', 'tipo' => TipoLocalEstoque::Terceiro->value,
            'ativo' => true, 'fornecedor_id' => $fornecedor->id,
        ]);

        $ordem = (new \App\Actions\Estoque\CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        (new \App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $material, 300, $this->user);
        $ordem = (new \App\Actions\Estoque\EmitirOrdemIndustrializacao())->execute($ordem->fresh(), $this->user);

        (new \App\Actions\Estoque\RegistrarRemessaIndustrializacao())->execute(
            $ordem->fresh(), $material, 200, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user,
        );

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $linha = $resumo->industrializacao->get($ordem->id);
        $this->assertNotNull($linha);
        $this->assertEquals(200, $linha['enviado']);
        $this->assertEquals(200, $linha['em_poder_terceiro']);
        $this->assertSame('desconhecido', $linha['prazo_industrializacao']);
        $this->assertSame('Fabricante X', $linha['fornecedor_nome']);
    }

    // =========================================================
    // I — Documento bloqueante no bloco gerencial
    // =========================================================

    public function test_i_documento_bloqueante_aparece_no_bloco_engenharia(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(5)]);
        $this->criarDocumentoBloqueante($atividade);

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertCount(1, $resumo->engenharia);
        $this->assertSame(TipoSituacaoGerencial::DocumentoBloqueante, $resumo->engenharia->first()->tipo);
    }

    // =========================================================
    // J — Inventário pendente aparece como decisão
    // =========================================================

    public function test_j_inventario_aguardando_decisao_aparece_como_decisao(): void
    {
        [, , , $alocacao] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);

        $inv = (new \App\Actions\Estoque\CriarInventarioEstoque())->execute($local, $this->user, 'Inv Cockpit', false);
        $inv = (new \App\Actions\Estoque\IniciarInventarioEstoque())->execute($inv, $this->user);
        $item = $inv->fresh()->itens->first();
        (new \App\Actions\Estoque\RegistrarContagemInventario())->execute($item, 90, Carbon::today(), $this->user);
        (new \App\Actions\Estoque\MoverInventarioParaAnalise())->execute($inv->fresh(), $this->user);

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertCount(1, $resumo->inventario['aguardando_decisao']);
        // InventarioAguardandoDecisao é sempre Alta -> deve estar em acoesHoje OU riscos (nunca em informativas).
        $chave = $resumo->inventario['aguardando_decisao']->first()->chaveLogica;
        $emAlgumBlocoAcionavel = $resumo->riscos->pluck('chaveLogica')->contains($chave)
            || $resumo->acoesHoje->pluck('chaveLogica')->contains($chave);
        $this->assertTrue($emAlgumBlocoAcionavel);
    }

    // =========================================================
    // K — Planejado x Real: linguagem neutra
    // =========================================================

    public function test_k_planejado_x_real_linguagem_neutra(): void
    {
        [, $pacote, $material, $alocacao] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        $frenteA = FrenteTrabalho::create(['obra_id' => $this->obra->id, 'nome' => 'Frente A']);
        $frenteB = FrenteTrabalho::create(['obra_id' => $this->obra->id, 'nome' => 'Frente B']);
        $destinacao = (new \App\Actions\Estoque\AtualizarDestinacaoPlanejada())->criar($pacote, $material, $frenteA, 100, $this->user);
        $reserva = (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, null, $destinacao, $this->user);
        $saida = (new RegistrarSaidaEstoque())->execute($material, $local, 100, Carbon::today(), $this->user, reserva: $reserva, pacote: $pacote, retiradoPor: $this->user);
        (new RegistrarAplicacaoMaterialEstoque())->execute($saida, $frenteB, 100, Carbon::today(), $this->user);

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertCount(1, $resumo->estoque['desvios_aplicacao']);
        $descricao = $resumo->estoque['desvios_aplicacao']->first()->descricao;
        $this->assertStringNotContainsStringIgnoringCase('incorret', $descricao);
        $this->assertStringNotContainsStringIgnoringCase('erro', $descricao);
    }

    // =========================================================
    // L — Informação insuficiente: nunca "pronta"
    // =========================================================

    public function test_l_informacao_insuficiente_nunca_classificada_como_pronta(): void
    {
        // Atividade SEM nenhum Pacote vinculado -> InformacaoInsuficiente,
        // nunca contada como "cobertas".
        $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertSame(1, $resumo->prontidao['2']->total);
        $this->assertSame(0, $resumo->prontidao['2']->cobertas);
        $this->assertSame(1, $resumo->prontidao['2']->informacaoInsuficiente);
        // Denominador do percentual NUNCA inclui informação insuficiente.
        $this->assertNull($resumo->prontidao['2']->percentualCoberturaAvaliavel);

        $linha = $resumo->matrizAtividades->first();
        $this->assertSame(\App\Enums\EstadoCoberturaMaterial::InformacaoInsuficiente, $linha->prontidaoMaterial);
    }

    // =========================================================
    // M — 2/4/8 semanas: contagens independentes e corretas
    // =========================================================

    public function test_m_horizontes_2_4_8_semanas_contagens_independentes(): void
    {
        $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(10)]);  // só em 2,4,8
        $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(20)]); // só em 4,8
        $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(40)]); // só em 8

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertSame(1, $resumo->prontidao['2']->total);
        $this->assertSame(2, $resumo->prontidao['4']->total);
        $this->assertSame(3, $resumo->prontidao['8']->total);
    }

    // =========================================================
    // O — Multi-obra: isolamento
    // =========================================================

    public function test_o_multiobra_isolamento(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::GerentePlanejamento->value);

        $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(3)]);
        $atividadeB = Atividade::create([
            'obra_id' => $obraB->id, 'nome' => 'Atividade B', 'codigo_cronograma' => 'B1',
            'status' => StatusAtividade::Planejado->value, 'inicio_planejado' => Carbon::today()->addDays(3),
            'data_termino' => Carbon::today()->addDays(5), 'fora_do_cronograma' => false,
        ]);
        $this->criarDocumentoBloqueante($atividadeB, $obraB);

        $resumoA = CockpitObraQuery::resumo($this->obra, 28);
        $resumoB = CockpitObraQuery::resumo($obraB, 28);

        $this->assertSame($this->obra->id, $resumoA->obraId);
        $this->assertSame($obraB->id, $resumoB->obraId);
        $this->assertTrue($resumoA->matrizAtividades->pluck('atividadeId')->doesntContain($atividadeB->id));
        $this->assertTrue($resumoB->matrizAtividades->pluck('atividadeId')->doesntContain(
            $resumoA->matrizAtividades->first()?->atividadeId
        ));
    }

    // =========================================================
    // P — Tenant: isolamento
    // =========================================================

    public function test_p_tenant_isolamento(): void
    {
        [$outroTenant, , $obraOutroTenant] = $this->criarOutroTenantComObra();

        $atividadeOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($obraOutroTenant) {
            return Atividade::create([
                'obra_id' => $obraOutroTenant->id, 'nome' => 'Atividade Outro Tenant', 'codigo_cronograma' => 'X1',
                'status' => StatusAtividade::Planejado->value, 'inicio_planejado' => Carbon::today()->addDays(3),
                'data_termino' => Carbon::today()->addDays(5), 'fora_do_cronograma' => false,
            ]);
        });

        $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(3)]);

        $resumoA = CockpitObraQuery::resumo($this->obra, 28);
        $this->assertCount(1, $resumoA->matrizAtividades);

        // Autenticado como usuário do TENANT A, mas passando o `Work` do
        // OUTRO tenant pro read model (cenário de ID manipulado, Seção 33)
        // — o global scope de BelongsToTenant precisa filtrar tudo pra
        // ZERO, nunca vazar a atividade do outro tenant.
        $resumoCrossTenant = CockpitObraQuery::resumo($obraOutroTenant, 28);
        $this->assertTrue($resumoCrossTenant->matrizAtividades->isEmpty());
        $this->assertTrue($resumoCrossTenant->riscos->isEmpty());
        $this->assertSame(0, $resumoCrossTenant->panorama['total']);

        // Confirmado, do lado de dentro do outro tenant, que a atividade
        // de fato existe (não é um falso negativo por fixture quebrada).
        \App\Support\TenantContext::actingAs($outroTenant, function () use ($obraOutroTenant, $atividadeOutroTenant) {
            $resumoDeDentro = CockpitObraQuery::resumo($obraOutroTenant, 28);
            $this->assertTrue($resumoDeDentro->matrizAtividades->pluck('atividadeId')->contains($atividadeOutroTenant->id));
        });
    }

    private function criarOutroTenantComObra(): array
    {
        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        return [$outroTenant, $outroUser, $obraOutroTenant];
    }

    // =========================================================
    // Consistência (Seção 36) — Cockpit nunca diverge das fontes
    // =========================================================

    public function test_consistencia_cockpit_bate_com_situacoesgerenciaisquery(): void
    {
        [$atividade] = $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(3)]);
        $this->criarDocumentoBloqueante($atividade);

        $direto = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertSame($direto->count(), $resumo->panorama['total']);
        $this->assertSame(
            $direto->countBy(fn ($s) => $s->tipo->value)->toArray(),
            $resumo->panorama['por_tipo'],
        );
    }

    public function test_consistencia_cockpit_bate_com_coberturamaterialatividadequery(): void
    {
        $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(10)]);

        $direto = CoberturaMaterialAtividadeQuery::porObra($this->obra, 56);
        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertSame($direto->count(), $resumo->matrizAtividades->count());
        $this->assertSame(
            $direto->first()['estado_agregado'],
            $resumo->matrizAtividades->first()->prontidaoMaterial,
        );
    }

    public function test_consistencia_cockpit_pipeline_bate_com_pipelinematerialquery(): void
    {
        [, $pacote, $material, $alocacao] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);

        $direto = PipelineMaterialQuery::porMateriais(collect([$material]), $this->obra->id)->get($material->id);
        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $linhaCockpit = $resumo->pipelineMateriais->firstWhere('material_codigo', $material->codigo);
        $this->assertNotNull($linhaCockpit);
        $this->assertSame($direto['fisico'], $linhaCockpit['fisico']);
        $this->assertSame($direto['recebido'], $linhaCockpit['recebido']);
    }

    public function test_consistencia_matriz_bate_com_centralprontidaoquery_status_operacional(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(5)]);
        $this->criarDocumentoBloqueante($atividade);

        $direto = (new CentralProntidaoQuery())->paraObra($this->obra)->firstWhere('atividadeId', $atividade->id);
        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $linhaCockpit = $resumo->matrizAtividades->firstWhere('atividadeId', $atividade->id);
        $this->assertSame($direto->statusOperacional->label(), $linhaCockpit->statusOperacional);
    }

    // =========================================================
    // Performance (Seção 27/37) — query count estável
    // =========================================================

    /**
     * Seção 27/37 — 10 vs. 100 atividades (500 documentado como fora do
     * viável nesta suíte: cada `cenarioMaterialCritico()` já é uma cadeia
     * completa RP→Alocação, ~8 Actions/queries de ESCRITA por atividade —
     * 500 levaria vários minutos só pra montar o fixture, sem agregar
     * nada de novo à prova de ausência de N+1 que 10→100 já demonstra).
     * Mesmo idioma exato de `SituacoesGerenciaisQueryTest::
     * test_performance_delta_5_50_situacoes_sem_n_mais_1()` —
     * `DB::enableQueryLog()`/`flushQueryLog()`/`getQueryLog()`, nunca
     * `DB::listen()` acumulando listeners entre as duas medições.
     */
    public function test_performance_delta_10_100_atividades_sem_n_mais_1(): void
    {
        $criarCenario = function (int $i) {
            $this->cenarioMaterialCritico(100, ['inicio_planejado' => Carbon::today()->addDays(3 + $i % 20)]);
        };

        for ($i = 0; $i < 10; $i++) {
            $criarCenario($i);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $resumo10 = CockpitObraQuery::resumo($this->obra, 28);
        $q10 = count(DB::getQueryLog());
        DB::flushQueryLog();

        for ($i = 10; $i < 100; $i++) {
            $criarCenario($i);
        }
        DB::flushQueryLog();
        $resumo100 = CockpitObraQuery::resumo($this->obra, 28);
        $q100 = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n[DELTA CockpitObraQuery] 10 atividades -> {$q10} queries | 100 atividades -> {$q100} queries\n");

        $this->assertSame(10, $resumo10->matrizAtividades->count());
        $this->assertSame(100, $resumo100->matrizAtividades->count());
        $this->assertLessThan($q10 * 3, $q100, 'ACHADO: crescimento de queries parece proporcional ao volume — possível N+1 no Cockpit.');
    }
}
