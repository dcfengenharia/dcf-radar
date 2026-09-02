<?php

namespace Tests\Feature;

use App\Actions\Estoque\CriarReservaEstoque;
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
use App\Enums\EstadoCoberturaMaterial;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Enums\TipoLocalEstoque;
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
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use App\Support\Gestao\CoberturaMaterialAtividadeQuery;
use App\Support\Gestao\PipelineMaterialQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 21, Etapa 21.1 — cobertura A-L do pedido + performance +
 * consistência contra os serviços operacionais existentes.
 */
class CoberturaMaterialAtividadeTest extends TestCase
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

    // ---------------- helpers ----------------

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

    private function criarAtividade(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Atividade ' . uniqid(),
            'codigo_cronograma' => 'A' . uniqid(),
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => Carbon::today()->addDays(5),
            'data_termino' => Carbon::today()->addDays(10),
            'fora_do_cronograma' => false,
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
            'quantidade' => 1000, 'material_id' => $material?->id,
        ]);
    }

    private function criarPacoteVinculado(Atividade $atividade, ?Work $obra = null): ItemSuprimento
    {
        $pacote = ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote ' . uniqid()]);
        $pacote->atividades()->sync([$atividade->id]);

        return $pacote;
    }

    private function criarRpItemEmitido(ItemTakeOff $item, float $quantidade, ?Work $obra = null): \App\Models\RequisicaoPlanejamentoItem
    {
        $obra ??= $this->obra;
        $rp = (new CriarRequisicaoPlanejamento())->execute($obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return $rpItem->fresh();
    }

    private function alocarNoPacote($rpItem, float $quantidade, ItemSuprimento $pacote): \App\Models\AlocacaoRequisicaoPacote
    {
        return (new AlocarRequisicaoAoPacote())->alocar($rpItem, $pacote, $quantidade);
    }

    /** Cadeia formal completa até RC Emitida + Pedido Emitido, sem receber ainda. Retorna o PedidoCompraItem. */
    private function comprarAte(\App\Models\AlocacaoRequisicaoPacote $alocacao, float $quantidade, ?Work $obra = null): \App\Models\PedidoCompraItem
    {
        $obra ??= $this->obra;
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return $pedidoItem->fresh();
    }

    private function receber(\App\Models\PedidoCompraItem $pedidoItem, float $quantidade, LocalEstoque $local): void
    {
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::today(), $this->user);
        (new RegistrarEntradaEstoque())->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);
    }

    /**
     * Monta uma cadeia completa: Atividade -> Pacote -> RP(100) -> Alocação(100).
     * Devolve [Atividade, Pacote, Material, Alocação].
     */
    private function cenarioBase(float $necessidade = 100): array
    {
        $material = $this->criarMaterial();
        $atividade = $this->criarAtividade();
        $pacote = $this->criarPacoteVinculado($atividade);
        $item = $this->criarItemTakeOffOrfao($material);
        $rpItem = $this->criarRpItemEmitido($item, $necessidade);
        $alocacao = $this->alocarNoPacote($rpItem, $necessidade, $pacote);

        return [$atividade, $pacote, $material, $alocacao];
    }

    private function linhaDaAtividade(Atividade $atividade, int $horizonteDias = 28): ?array
    {
        return CoberturaMaterialAtividadeQuery::porObra($this->obra, $horizonteDias)
            ->firstWhere('atividade_id', $atividade->id);
    }

    // =========================================================
    // Cenário A — atividade coberta
    // =========================================================

    public function test_a_atividade_coberta(): void
    {
        [$atividade, $pacote, $material, $alocacao] = $this->cenarioBase(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, null, null, $this->user);

        $linha = $this->linhaDaAtividade($atividade);

        $this->assertNotNull($linha);
        $this->assertSame(EstadoCoberturaMaterial::Coberto, $linha['estado_agregado']);
    }

    // =========================================================
    // Cenário B — cobertura parcial
    // =========================================================

    public function test_b_cobertura_parcial_deixa_falta_explicita(): void
    {
        [$atividade, $pacote, $material, $alocacao] = $this->cenarioBase(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 60, null, null, $this->user);

        $linha = $this->linhaDaAtividade($atividade);

        $this->assertSame(EstadoCoberturaMaterial::ParcialmenteCoberto, $linha['estado_agregado']);
        $par = $linha['pares']->first();
        $this->assertEquals(100, $par['demanda']);
        $this->assertEquals(60, $par['reservado_pacote']);
        $this->assertEquals(40, round($par['demanda'] - $par['reservado_pacote'], 3), 'falta deve ficar explícita: 40');
    }

    // =========================================================
    // Cenário C — comprado mas não recebido
    // =========================================================

    public function test_c_comprado_mas_nao_recebido_nao_e_pronto(): void
    {
        [$atividade, , , $alocacao] = $this->cenarioBase(100);
        $this->comprarAte($alocacao, 100);

        $linha = $this->linhaDaAtividade($atividade);

        $this->assertNotEquals(EstadoCoberturaMaterial::Coberto, $linha['estado_agregado']);
        $this->assertSame(EstadoCoberturaMaterial::CompradoAguardandoRecebimento, $linha['estado_agregado']);
    }

    // =========================================================
    // Cenário D — recebido, mas SEM reserva ainda (não vira cobertura automática)
    // =========================================================

    public function test_d_recebido_sem_reserva_nao_e_cobertura_automatica(): void
    {
        [$atividade, , , $alocacao] = $this->cenarioBase(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);

        $linha = $this->linhaDaAtividade($atividade);

        // Recebido fisicamente, mas NINGUÉM reservou formalmente pra este
        // Pacote ainda -- "material em algum lugar da obra" não é
        // suficiente pra considerar a atividade coberta (Seção 8/9).
        $this->assertSame(EstadoCoberturaMaterial::RecebidoAguardandoDisponibilizacao, $linha['estado_agregado']);
        $this->assertNotEquals(EstadoCoberturaMaterial::Coberto, $linha['estado_agregado']);

        // Confirma exatamente quando PASSA a significar cobertura física:
        // assim que a Reserva formal é criada.
        [, $pacote, $material] = [null, ItemSuprimento::first(), Material::first()];
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, null, null, $this->user);
        $linhaDepois = $this->linhaDaAtividade($atividade);
        $this->assertSame(EstadoCoberturaMaterial::Coberto, $linhaDepois['estado_agregado']);
    }

    // =========================================================
    // Cenário E — reserva descoberta (déficit apos consumo emergencial)
    // =========================================================

    public function test_e_reserva_descoberta_por_consumo_emergencial(): void
    {
        [$atividade, $pacote, $material, $alocacao] = $this->cenarioBase(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        $reserva = (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, null, null, $this->user);

        $linhaAntes = $this->linhaDaAtividade($atividade);
        $this->assertSame(EstadoCoberturaMaterial::Coberto, $linhaAntes['estado_agregado']);

        // Consumo emergencial: saída LIVRE (sem reserva) reduz o físico
        // abaixo do que está reservado.
        (new RegistrarSaidaEstoque())->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPor: $this->user);

        $linhaDepois = $this->linhaDaAtividade($atividade);
        $this->assertSame(EstadoCoberturaMaterial::DeficitAposConsumoEmergencial, $linhaDepois['estado_agregado']);
        $this->assertGreaterThan(0, $linhaDepois['pares']->first()['deficit_obra_material'], 'recomposição necessária deve aparecer');
    }

    // =========================================================
    // Cenário F — aplicação em Frente diferente do planejado (desvio factual)
    // =========================================================

    public function test_f_aplicacao_em_frente_diferente_e_desvio_factual_sem_linguagem_acusatoria(): void
    {
        [$atividade, $pacote, $material, $alocacao] = $this->cenarioBase(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        $frenteA = FrenteTrabalho::create(['obra_id' => $this->obra->id, 'nome' => 'Frente A']);
        $frenteB = FrenteTrabalho::create(['obra_id' => $this->obra->id, 'nome' => 'Frente B']);
        $destinacaoA = (new \App\Actions\Estoque\AtualizarDestinacaoPlanejada())->criar($pacote, $material, $frenteA, 100, $this->user);
        $reserva = (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, null, $destinacaoA, $this->user);

        $saida = (new RegistrarSaidaEstoque())->execute($material, $local, 100, Carbon::today(), $this->user, reserva: $reserva, pacote: $pacote, retiradoPor: $this->user);
        (new \App\Actions\Estoque\RegistrarAplicacaoMaterialEstoque())->execute($saida, $frenteB, 100, Carbon::today(), $this->user);

        $desvio = \App\Support\Estoque\DesviosAplicacao::porSaida($saida->fresh());

        // Fato bruto, nunca linguagem acusatória embutida no dado (a
        // camada de apresentação decide o texto; aqui só números).
        $this->assertEquals(0, $desvio['aderente']);
        $this->assertEquals(100, $desvio['total_desviado']);
        $this->assertArrayHasKey($frenteB->id, $desvio['desviado_por_frente']);
    }

    // =========================================================
    // Cenário G — bobina (não double-count)
    // =========================================================

    public function test_g_bobina_multi_local_nao_double_count(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $atividade = $this->criarAtividade();
        $pacote = $this->criarPacoteVinculado($atividade);
        $item = $this->criarItemTakeOffOrfao($material);
        $rpItem = $this->criarRpItemEmitido($item, 1000);
        $alocacao = $this->alocarNoPacote($rpItem, 1000, $pacote);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 1000);
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, 1000, Carbon::today(), $this->user);
        (new RegistrarEntradaEstoque())->execute($recebimento, $localA, 1000, Carbon::today(), $this->user, 'B001');
        $unidade = \App\Models\UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        (new \App\Actions\Estoque\RegistrarTransferenciaEstoque())->execute($material, $localA, $localB, 400, Carbon::today(), $this->user, $unidade);

        $pipeline = PipelineMaterialQuery::porMateriais(collect([$material]), $this->obra->id)->first();

        // 1000 continua sendo 1000 -- nunca 1000 (LocalA antes) + 1000
        // (LocalB depois) = 2000 por engano de dupla contagem.
        $this->assertEquals(1000, $pipeline['fisico']);
    }

    // =========================================================
    // Cenário H — transferência não aumenta estoque da obra
    // =========================================================

    public function test_h_transferencia_nao_aumenta_estoque_da_obra(): void
    {
        [, , $material, $alocacao] = $this->cenarioBase(500);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 500);
        $this->receber($pedidoItem, 500, $localA);

        $antes = SaldoEstoque::porMateriaisNaObra([$material->id], $this->obra->id)[$material->id] ?? 0.0;
        (new \App\Actions\Estoque\RegistrarTransferenciaEstoque())->execute($material, $localA, $localB, 200, Carbon::today(), $this->user);
        $depois = SaldoEstoque::porMateriaisNaObra([$material->id], $this->obra->id)[$material->id] ?? 0.0;

        $this->assertEquals($antes, $depois, 'transferência entre Locais da MESMA obra nunca deve alterar o físico agregado da obra');
    }

    // =========================================================
    // Cenário I — terceiro (industrialização): custódia distinguível
    // =========================================================

    public function test_i_material_em_terceiro_tem_custodia_distinguivel(): void
    {
        [, , $material, $alocacao] = $this->cenarioBase(300);
        $localProprio = $this->criarLocal();
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor Terceiro']);
        $localTerceiro = LocalEstoque::create([
            'obra_id' => $this->obra->id, 'nome' => 'Terceiro', 'tipo' => TipoLocalEstoque::Terceiro->value,
            'fornecedor_id' => $fornecedor->id, 'ativo' => true,
        ]);
        $pedidoItem = $this->comprarAte($alocacao, 300);
        $this->receber($pedidoItem, 300, $localProprio);

        $ordem = (new \App\Actions\Estoque\CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        (new \App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $this->criarMaterial(), 10, $this->user);
        $ordem = (new \App\Actions\Estoque\EmitirOrdemIndustrializacao())->execute($ordem->fresh(), $this->user);
        (new \App\Actions\Estoque\RegistrarRemessaIndustrializacao())->execute($ordem, $material, 200, \App\Enums\DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        // O material continua pertencendo à cadeia física correta (saldo
        // total da obra é o mesmo — 300, só mudou de Local/custódia), mas
        // a custódia É distinguível por Local (300-200=100 próprio, 200 terceiro).
        $totalObra = SaldoEstoque::porMateriaisNaObra([$material->id], $this->obra->id)[$material->id] ?? 0.0;
        $this->assertEquals(300, $totalObra);
        $this->assertEquals(100, SaldoEstoque::porMaterialLocal($material, $localProprio));
        $this->assertEquals(200, SaldoEstoque::porMaterialLocal($material, $localTerceiro));
    }

    // =========================================================
    // Cenário J — atividade fora do horizonte não entra no risco da janela
    // =========================================================

    public function test_j_atividade_fora_do_horizonte_nao_aparece(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(60)]);
        $this->criarPacoteVinculado($atividade);

        $linha = $this->linhaDaAtividade($atividade, 28);

        $this->assertNull($linha, 'atividade com início a 60 dias não deveria aparecer num horizonte de 28');

        // Mas aparece com um horizonte maior.
        $linhaComHorizonteMaior = CoberturaMaterialAtividadeQuery::porObra($this->obra, 90)
            ->firstWhere('atividade_id', $atividade->id);
        $this->assertNotNull($linhaComHorizonteMaior);
    }

    // =========================================================
    // Cenário K — atividade concluída não gera falsa urgência
    // =========================================================

    public function test_k_atividade_concluida_nao_gera_falsa_urgencia(): void
    {
        $atividade = $this->criarAtividade([
            'status' => StatusAtividade::Concluido->value,
            'inicio_planejado' => Carbon::today()->addDays(3),
        ]);
        $this->criarPacoteVinculado($atividade);

        $linha = $this->linhaDaAtividade($atividade);

        $this->assertNull($linha, 'atividade já concluída nunca deve aparecer como pendência de material');
    }

    // =========================================================
    // Cenário L — informação insuficiente (nunca "pronto")
    // =========================================================

    public function test_l_sem_pacote_vinculado_e_informacao_insuficiente_nunca_pronto(): void
    {
        $atividade = $this->criarAtividade();
        // Nenhum Pacote vinculado -- relação Atividade<->Material não
        // curada pelo usuário ainda.

        $linha = $this->linhaDaAtividade($atividade);

        $this->assertNotNull($linha);
        $this->assertSame(EstadoCoberturaMaterial::InformacaoInsuficiente, $linha['estado_agregado']);
        $this->assertNotEquals(EstadoCoberturaMaterial::Coberto, $linha['estado_agregado']);
    }

    public function test_l2_pacote_vinculado_sem_nenhuma_alocacao_e_informacao_insuficiente(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarPacoteVinculado($atividade);
        // Pacote existe e está vinculado, mas nunca recebeu nenhuma
        // Alocação de material -- ainda não dá pra saber "de qual
        // material" a atividade depende.

        $linha = $this->linhaDaAtividade($atividade);

        $this->assertSame(EstadoCoberturaMaterial::InformacaoInsuficiente, $linha['estado_agregado']);
    }

    // =========================================================
    // Performance — DELTA, sem N+1
    // =========================================================

    public function test_performance_delta_5_50_atividades_sem_n_mais_1(): void
    {
        $criarNAtividades = function (int $n) {
            for ($i = 0; $i < $n; $i++) {
                [$atividade, $pacote, $material, $alocacao] = $this->cenarioBase(100);
                $local = $this->criarLocal();
                $pedidoItem = $this->comprarAte($alocacao, 100);
                $this->receber($pedidoItem, 100, $local);
                (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, null, null, $this->user);
            }
        };

        $criarNAtividades(5);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $resultado5 = CoberturaMaterialAtividadeQuery::porObra($this->obra, 28);
        $q5 = count(DB::getQueryLog());
        DB::flushQueryLog();

        $criarNAtividades(45); // total agora 50
        DB::flushQueryLog();
        $resultado50 = CoberturaMaterialAtividadeQuery::porObra($this->obra, 28);
        $q50 = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n[DELTA CoberturaMaterialAtividade] 5 atividades -> {$q5} queries | 50 atividades -> {$q50} queries\n");

        $this->assertCount(5, $resultado5);
        $this->assertCount(50, $resultado50);
        $this->assertLessThan($q5 * 4, $q50, 'ACHADO: crescimento de queries parece proporcional ao volume — possível N+1');
    }

    // =========================================================
    // Consistência — a camada gerencial nunca diverge dos serviços operacionais
    // =========================================================

    public function test_consistencia_pipeline_bate_com_saldoestoque_e_saldoreserva(): void
    {
        [, $pacote, $material, $alocacao] = $this->cenarioBase(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 70, null, null, $this->user);

        $pipeline = PipelineMaterialQuery::porMateriais(collect([$material]), $this->obra->id)->first();

        $this->assertEquals(SaldoEstoque::porMaterialLocal($material, $local), $pipeline['fisico']);
        $this->assertEquals(SaldoReserva::porMaterialLocal($material, $local), $pipeline['reservado']);
    }
}
