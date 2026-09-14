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
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Actions\Suprimentos\RegistrarRecebimentoPedido;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusReservaEstoque;
use App\Enums\StatusRestricao;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoMovimentacaoEstoque;
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
use App\Models\MovimentacaoEstoque;
use App\Models\Restricao;
use App\Models\ReservaEstoque;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Services\SuprimentoScheduler;
use App\Support\SincronizarRestricaoCadeiaSuprimento;
use App\Support\SincronizarRestricaoSuprimento;
use App\Support\Suprimentos\DescricaoRestricaoSuprimento;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fechamento Adversarial Final do Motor Definitivo de Risco de
 * Suprimentos V1 — Seção 15/16/17/18/19 do pedido de fechamento. Matriz
 * DIRETA de Restrição (A-L), testando o efeito real de ponta a ponta
 * (criação/manutenção/resolução/reabertura) via
 * `App\Support\SincronizarRestricaoCadeiaSuprimento::sincronizarPacote()`
 * — o mecanismo que opera sobre a CADEIA FORMAL (RP→Pacote→RC→Pedido)
 * que o Motor V1 (AtividadeNecessidadeMaterial/decomposição quantitativa)
 * também usa — nunca apenas cobertura indireta via outros testes.
 */
class RestricaoMatrizDiretaSuprimentosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2027-02-01'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (mesmo padrão de DecomposicaoQuantitativaNecessidadeTest) ----

    /** Necessidade já VENCIDA (hoje >= inicio_planejado) — obrigatória pra Condição C da cadeia formal disparar. */
    private function criarAtividade(string $inicioPlanejado = '2027-01-15'): Atividade
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
            'descricao' => 'Cabo 70 mm²',
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

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Almoxarifado', 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
    }

    private function entradaEstoque(Material $material, LocalEstoque $local, float $quantidade): void
    {
        MovimentacaoEstoque::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'material_id' => $material->id,
            'local_estoque_id' => $local->id, 'tipo' => TipoMovimentacaoEstoque::Entrada->value,
            'quantidade' => $quantidade, 'ocorrido_em' => now(), 'registrado_por_id' => $this->user->id,
        ]);
    }

    private function reservar(ItemSuprimento $pacote, Material $material, LocalEstoque $local, float $quantidade, AtividadeNecessidadeMaterial $necessidade): ReservaEstoque
    {
        return ReservaEstoque::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'item_suprimento_id' => $pacote->id,
            'material_id' => $material->id, 'local_estoque_id' => $local->id, 'necessidade_atividade_id' => $necessidade->id,
            'quantidade' => $quantidade, 'status' => StatusReservaEstoque::Ativa->value, 'created_by_id' => $this->user->id,
        ]);
    }

    /**
     * Monta a cadeia formal completa (RP->Alocação->RC->Pedido Emitido)
     * pra UMA necessidade, com uma única parcela cobrindo o item inteiro
     * — pacote sempre vinculado à Atividade (pré-requisito de
     * `SincronizarRestricaoCadeiaSuprimento::sincronizarPacote()`).
     */
    private function pacoteComPedido(Atividade $atividade, ItemTakeOff $ito, AtividadeNecessidadeMaterial $necessidade, float $quantidade, ?string $dataPrevista): ItemSuprimento
    {
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $pacote->atividades()->sync([$atividade->id]);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);

        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $item = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($item, $necessidade, $quantidade, $this->user);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $this->fornecedor(), $dataPrevista, null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidade, $quantidade, $this->user);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return $pacote->fresh(['atividades']);
    }

    private function restricaoCadeia(Atividade $atividade, ItemSuprimento $pacote): ?Restricao
    {
        return Restricao::where('atividade_id', $atividade->id)
            ->where('origem_cadeia_suprimento_id', $pacote->id)
            ->first();
    }

    /**
     * Achado real durante a construção da matriz: `EmitirPedidoCompra`
     * já dispara `SincronizarRestricaoCadeiaSuprimento::sincronizarPacote()`
     * automaticamente (Ciclo 19.7) — o pacote de teste `pacoteComPedido()`
     * portanto já pode ter criado E resolvido uma Restrição antes mesmo
     * do teste chamar o sincronizador de novo (ex.: material ficou
     * pendente no instante da emissão, depois passou a estar coberto
     * quando o teste adiciona Reserva/estoque). `Restricao` nunca é
     * apagada (domínio: só muda status) — "nenhum bloqueio" correto
     * significa NENHUMA linha ainda ABERTA/EmTratamento/AguardandoTerceiros,
     * nunca "nenhuma linha existe".
     */
    private function assertSemBloqueioAberto(Atividade $atividade, ItemSuprimento $pacote, string $mensagem = ''): void
    {
        $restricao = $this->restricaoCadeia($atividade, $pacote);
        $bloqueioAberto = $restricao && in_array($restricao->status, [
            StatusRestricao::Aberta, StatusRestricao::EmTratamento, StatusRestricao::AguardandoTerceiros,
        ], true);

        $this->assertFalse($bloqueioAberto, $mensagem ?: 'Nenhuma Restrição da cadeia formal pode continuar ABERTA aqui.');
    }

    // =========================================================================
    // A — necessidade100, reserva100, Pedido atrasado100 -> NUNCA restrição
    // automática de chegada (fisicamente protegida por Reserva específica).
    // =========================================================================
    public function test_a_reserva_integral_nunca_gera_restricao_mesmo_com_pedido_atrasado(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        $pacote = $this->pacoteComPedido($atividade, $ito, $necessidade, 100, '2026-12-01'); // já atrasado

        $local = $this->criarLocal();
        $this->entradaEstoque($material, $local, 100);
        $this->reservar($pacote, $material, $local, 100, $necessidade);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);

        // Achado do fechamento: `EmitirPedidoCompra` (dentro do helper
        // `pacoteComPedido()`) já dispara o sincronizador automaticamente
        // — no instante da emissão, antes de Reserva/estoque existirem,
        // ele legitimamente ABRE a Restrição (material ainda não coberto).
        // O ponto sob teste aqui é que, com a Reserva específica cobrindo
        // 100% da necessidade, o sincronizador seguinte SEMPRE a resolve —
        // nunca a deixa aberta, mesmo com o Pedido comercialmente atrasado.
        $this->assertSemBloqueioAberto($atividade, $pacote, 'Reserva específica cobre a necessidade -> nunca bloqueia, mesmo com o Pedido comercialmente atrasado.');
    }

    // =========================================================================
    // B — necessidade100, estoque livre100, reserva0, Pedido atrasado100 ->
    // NUNCA restrição (fisicamente disponível, mesmo sem Reserva formal).
    // =========================================================================
    public function test_b_estoque_livre_suficiente_nunca_gera_restricao_mesmo_com_pedido_atrasado(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        $pacote = $this->pacoteComPedido($atividade, $ito, $necessidade, 100, '2026-12-01'); // atrasado

        $local = $this->criarLocal();
        $this->entradaEstoque($material, $local, 100);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);

        $this->assertSemBloqueioAberto($atividade, $pacote);
    }

    // =========================================================================
    // C — necessidade100, estoque0, Pedido atrasado100 -> Restrição bloqueante.
    // =========================================================================
    public function test_c_sem_cobertura_fisica_e_pedido_atrasado_gera_restricao_bloqueante(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        $pacote = $this->pacoteComPedido($atividade, $ito, $necessidade, 100, '2026-12-01'); // atrasado

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);

        $restricao = $this->restricaoCadeia($atividade, $pacote);
        $this->assertNotNull($restricao);
        $this->assertTrue($restricao->bloqueante);
        $this->assertSame(StatusRestricao::Aberta, $restricao->status);
    }

    // =========================================================================
    // D — necessidade100, Pedido100 sem prazo, estoque0 -> Restrição com
    // causa "sem prazo".
    // =========================================================================
    public function test_d_pedido_sem_prazo_gera_restricao_com_causa_sem_prazo(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        $pacote = $this->pacoteComPedido($atividade, $ito, $necessidade, 100, '2026-12-01');
        \App\Models\PedidoCompra::whereHas('itens', fn ($q) => $q->whereNotNull('requisicao_compra_item_id'))
            ->latest('created_at')->first()?->forceFill(['data_prevista_entrega' => null])->save();

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);

        $restricao = $this->restricaoCadeia($atividade, $pacote);
        $this->assertNotNull($restricao);
        $this->assertStringContainsString('sem previsão de entrega', $restricao->descricao);
    }

    // =========================================================================
    // E — necessidade100, sem Pedido nenhum -> a CADEIA FORMAL
    // (SincronizarRestricaoCadeiaSuprimento) NUNCA bloqueia por este
    // critério (limitação residual JÁ DOCUMENTADA no próprio código:
    // Condição C só enxerga Pedido->Recebimento, nunca RP/RC sozinhas) —
    // provado aqui, não presumido. O mecanismo LEGADO
    // (SincronizarRestricaoSuprimento, baseado em Fluxo/Etapa) É quem
    // cobre "ausência total de contratação" hoje.
    // =========================================================================
    public function test_e_sem_pedido_nenhum_cadeia_formal_nunca_bloqueia_legado_cobre(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);

        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, 100);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Sem Pedido']);
        $pacote->atividades()->sync([$atividade->id]);
        (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 100);
        // Nunca cria RC nem Pedido -> total_pedidos = 0.

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);
        $this->assertNull(
            $this->restricaoCadeia($atividade, $pacote),
            'Limitação residual documentada: Condição C exige ao menos 1 Pedido — RP/RC sozinhas nunca disparam a cadeia formal.'
        );

        // Mecanismo legado (Fluxo/Etapa) cobre esta lacuna: com o item em
        // risco comercial (statusDoItem EmRisco/Atrasado), ele bloqueia.
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Legado']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Solicitação', 'prazo_dias_uteis' => 30]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 2, 'nome' => 'Pedido', 'prazo_dias_uteis' => 1]);
        $pacote->update(['fluxo_suprimento_id' => $fluxo->id]);
        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($pacote->fresh());
        $scheduler->congelarPrevisto($pacote->fresh(['atividades']));

        SincronizarRestricaoSuprimento::sincronizarItem($pacote->fresh(['atividades']), $this->user->id);
        $restricaoLegado = Restricao::where('atividade_id', $atividade->id)->where('origem_suprimento_item_id', $pacote->id)->first();
        $this->assertNotNull($restricaoLegado, 'O mecanismo legado cobre a ausência total de contratação que a cadeia formal ainda não cobre.');
        $this->assertStringContainsString('nenhuma Requisição de Compra emitida', $restricaoLegado->descricao);
    }

    // =========================================================================
    // F — necessidade100, Pedido100, recebido40 (explicitamente atribuído),
    // saldo60 atrasado -> a descrição da Restrição descreve SOMENTE 60,
    // NUNCA "100 atrasadas" (Ponto 16 do fechamento).
    // =========================================================================
    public function test_f_recebimento_parcial_descricao_reflete_so_o_saldo_exposto(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        $pacote = $this->pacoteComPedido($atividade, $ito, $necessidade, 100, '2026-12-01'); // atrasado

        $pedidoItem = \App\Models\PedidoCompraItem::whereHas('pedidoCompra')->latest('created_at')->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItem, 40, now()->subDay(), $this->user, null, null);

        $descricao = DescricaoRestricaoSuprimento::paraPacoteEAtividade($atividade, $pacote->fresh(['atividades']), 'FALLBACK');

        $this->assertStringContainsString('60', $descricao);
        $this->assertStringNotContainsString('100', $descricao, 'Nunca "100 unidades atrasadas" quando só 60 continuam expostas.');

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);
        $restricao = $this->restricaoCadeia($atividade, $pacote);
        $this->assertNotNull($restricao);
        $this->assertStringContainsString('60', $restricao->descricao);
        $this->assertStringNotContainsString('100', $restricao->descricao);
    }

    // =========================================================================
    // G — recebimento existe mas NÃO é atribuível por parcela (item com 2+
    // parcelas) -> NUNCA inventa cobertura específica; texto continua
    // refletindo a pendência INTEIRA da necessidade (nenhuma redução
    // otimista sobre um recebimento que não se sabe de quem é).
    // =========================================================================
    public function test_g_recebimento_nao_atribuivel_nunca_inventa_cobertura(): void
    {
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidadeA = $this->criarNecessidade($atividadeA, $ito, 60);
        $necessidadeB = $this->criarNecessidade($atividadeB, $ito, 40);

        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, 100);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote G']);
        $pacote->atividades()->sync([$atividadeA->id, $atividadeB->id]);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 100);

        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $rcItem = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 100);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($rcItem, $necessidadeA, 60, $this->user);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($rcItem->fresh(), $necessidadeB, 40, $this->user);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $this->fornecedor(), '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), 100);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidadeA, 60, $this->user);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidadeB, 40, $this->user);
        $pedidoEmitido = (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        $pedidoItemFresh = $pedidoEmitido->fresh(['itens'])->itens->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItemFresh, 50, now()->subDay(), $this->user, null, null);

        $descricaoA = DescricaoRestricaoSuprimento::paraPacoteEAtividade($atividadeA, $pacote->fresh(['atividades']), 'FALLBACK');
        $this->assertStringContainsString('60', $descricaoA, 'A pendência de A permanece inteira (60), nunca reduzida por um recebimento não atribuível.');
    }

    // =========================================================================
    // H — a causa some (Pedido passa a estar no prazo/necessidade recua) ->
    // a Restrição SOMENTE AUTOMÁTICA se auto-resolve.
    // =========================================================================
    public function test_h_causa_desaparece_auto_resolve_restricao_automatica(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        $pacote = $this->pacoteComPedido($atividade, $ito, $necessidade, 100, '2026-12-01');

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);
        $this->assertNotNull($this->restricaoCadeia($atividade, $pacote));

        // Recebe o total -> nenhum material pendente restante.
        $pedidoItem = \App\Models\PedidoCompraItem::whereHas('pedidoCompra')->latest('created_at')->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItem, 100, now()->subDay(), $this->user, null, null);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);

        $restricao = $this->restricaoCadeia($atividade, $pacote);
        $this->assertNotNull($restricao, 'Mesma linha, nunca apagada.');
        $this->assertSame(StatusRestricao::Resolvida, $restricao->status);
        $this->assertNotNull($restricao->resolvida_em);
    }

    // =========================================================================
    // I — a causa retorna (novo Pedido atrasado depois de resolvida) ->
    // reabre a MESMA linha lógica (novo episódio), nunca duplica.
    // =========================================================================
    public function test_i_causa_retorna_reabre_a_mesma_linha_nunca_duplica(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        $pacote = $this->pacoteComPedido($atividade, $ito, $necessidade, 100, '2026-12-01');

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);
        $restricaoOriginal = $this->restricaoCadeia($atividade, $pacote);
        $this->assertNotNull($restricaoOriginal);
        $idOriginal = $restricaoOriginal->id;

        $pedidoItem = \App\Models\PedidoCompraItem::whereHas('pedidoCompra')->latest('created_at')->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItem, 100, now()->subDay(), $this->user, null, null);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);
        $this->assertSame(StatusRestricao::Resolvida, $this->restricaoCadeia($atividade, $pacote)->status);

        // A causa retorna: uma SEGUNDA necessidade/atividade nova entra na
        // cadeia do MESMO Pacote (novo ito, nova AtividadeNecessidadeMaterial
        // — a Restrição é por par Atividade+Pacote, então precisamos de uma
        // atividade nova pra este segundo ciclo comercial), com Pedido
        // novo, atrasado, nunca recebido -> `ConciliacaoRecebimento::
        // porPacote($pacote)` volta a enxergar material formal pendente
        // pro MESMO Pacote.
        $atividade2 = $this->criarAtividade();
        $ito2 = $this->criarItemTakeOff($material, 1000);
        $necessidade2 = $this->criarNecessidade($atividade2, $ito2, 50);

        $rp2 = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem2 = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp2, $ito2->id, 50);
        (new EmitirRequisicaoPlanejamento())->execute($rp2->fresh(), $this->user);
        $pacote->atividades()->syncWithoutDetaching([$atividade2->id]);
        $alocacao2 = (new AlocarRequisicaoAoPacote())->alocar($rpItem2->fresh(), $pacote->fresh(), 50);

        $rc2 = (new CriarRequisicaoCompra())->execute($alocacao2->pacote, $this->criarFluxo(), null, $this->user);
        $rcItem2 = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc2, $alocacao2, 50);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($rcItem2, $necessidade2, 50, $this->user);
        $rc2Emitida = (new EmitirRequisicaoCompra())->execute($rc2->fresh(), $this->user);

        $pedido2 = (new CriarPedidoCompra())->execute($rc2Emitida, $this->fornecedor(), '2026-11-01', null, null, null, $this->user);
        $pedidoItem2 = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido2, $rc2Emitida->itens->first(), 50);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem2->fresh(), $necessidade2, 50, $this->user);
        (new EmitirPedidoCompra())->execute($pedido2->fresh(), $this->user);
        // Nunca recebido -> material formal pendente de novo pro Pacote.

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);

        $restricaoReaberta = $this->restricaoCadeia($atividade, $pacote);
        $this->assertNotNull($restricaoReaberta);
        $this->assertSame($idOriginal, $restricaoReaberta->id, 'A MESMA linha reabre — nunca uma segunda Restrição pro par (atividade, pacote).');
        $this->assertSame(StatusRestricao::Aberta, $restricaoReaberta->status);
        $this->assertNull($restricaoReaberta->resolvida_em);
    }

    // =========================================================================
    // J — Restrição MANUAL (sem origem_cadeia_suprimento_id) equivalente ->
    // NUNCA tocada pelo sincronizador automático.
    // =========================================================================
    public function test_j_restricao_manual_equivalente_nunca_e_tocada(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        $pacote = $this->pacoteComPedido($atividade, $ito, $necessidade, 100, '2026-12-01');

        $categoria = \App\Models\CategoriaRestricao::create([
            'tenant_id' => $this->tenant->id, 'nome' => 'Materiais Manual', 'pilar_lean' => \App\Enums\PilarLean::Materiais->value,
        ]);
        $manual = Restricao::create([
            'atividade_id' => $atividade->id,
            'categoria_id' => $categoria->id,
            'descricao' => 'Restrição manual criada pelo planejador — texto igual ao automático por coincidência.',
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
            // origem_cadeia_suprimento_id/origem_suprimento_item_id nunca preenchidos.
        ]);

        // Recebe tudo -> a cadeia formal resolveria uma restrição SUA,
        // mas nunca deveria enxergar/tocar a manual.
        $pedidoItem = \App\Models\PedidoCompraItem::whereHas('pedidoCompra')->latest('created_at')->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItem, 100, now()->subDay(), $this->user, null, null);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);

        $manual->refresh();
        $this->assertSame(StatusRestricao::Aberta, $manual->status, 'A manual nunca é resolvida/tocada pelo sincronizador automático.');
        $this->assertNull($manual->resolvida_em);
    }

    // =========================================================================
    // K — outra Atividade/Material nunca é afetada por uma sincronização.
    // =========================================================================
    public function test_k_outra_atividade_e_outro_material_nunca_sao_afetados(): void
    {
        $atividadeAlvo = $this->criarAtividade();
        $atividadeOutra = $this->criarAtividade();
        $material = $this->criarMaterial();
        $outroMaterial = $this->criarMaterial();

        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividadeAlvo, $ito, 100);
        $pacote = $this->pacoteComPedido($atividadeAlvo, $ito, $necessidade, 100, '2026-12-01');

        $itoOutro = $this->criarItemTakeOff($outroMaterial, 1000);
        $necessidadeOutra = $this->criarNecessidade($atividadeOutra, $itoOutro, 50);
        $pacoteOutro = $this->pacoteComPedido($atividadeOutra, $itoOutro, $necessidadeOutra, 50, '2026-12-01');

        // `pacoteComPedido()` já disparou o sincronizador automaticamente
        // (EmitirPedidoCompra) pros DOIS Pacotes — cada um já tem sua
        // PRÓPRIA Restrição aberta, criada pela SUA PRÓPRIA emissão, nunca
        // pela do outro. Capturamos o estado de `pacoteOutro` ANTES de
        // sincronizar `pacote` de novo, pra provar que nada muda nele.
        $restricaoOutraAntes = $this->restricaoCadeia($atividadeOutra, $pacoteOutro);
        $this->assertNotNull($restricaoOutraAntes, 'A própria emissão do Pedido de pacoteOutro já criou sua Restrição — não relacionada ao Pacote-alvo.');
        $updatedAtAntes = $restricaoOutraAntes->updated_at;

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);

        // A sincronização do Pacote-alvo nunca cria/mexe na Restrição da
        // outra Atividade/outro Pacote/outro Material — zero linha nova,
        // zero alteração na já existente.
        $restricaoOutraDepois = $this->restricaoCadeia($atividadeOutra, $pacoteOutro);
        $this->assertNotNull($restricaoOutraDepois);
        $this->assertSame($restricaoOutraAntes->id, $restricaoOutraDepois->id);
        $this->assertTrue($updatedAtAntes->equalTo($restricaoOutraDepois->updated_at), 'Sincronizar o Pacote-alvo nunca toca a Restrição de outro par (atividade, pacote).');

        // E a Restrição original do alvo continua intacta (par distinto).
        $restricaoAlvo = $this->restricaoCadeia($atividadeAlvo, $pacote);
        $this->assertNotNull($restricaoAlvo);
        $this->assertNotEquals($restricaoAlvo->id, $restricaoOutraDepois->id);
    }

    // =========================================================================
    // L — Pedido comercialmente atrasado + necessidade fisicamente
    // protegida -> SEM bloqueio operacional (nunca cria/mantém Restrição),
    // mas o ESTADO COMERCIAL do Pedido continua "atrasado" (dimensões
    // nunca confundidas).
    // =========================================================================
    public function test_l_comercialmente_atrasado_mas_fisicamente_protegido_nunca_bloqueia_mas_comercial_continua_atrasado(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        $pacote = $this->pacoteComPedido($atividade, $ito, $necessidade, 100, '2026-12-01'); // já vencido, nunca recebido

        $local = $this->criarLocal();
        $this->entradaEstoque($material, $local, 100);
        $this->reservar($pacote, $material, $local, 100, $necessidade);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(['atividades']), $this->user->id);
        $this->assertSemBloqueioAberto($atividade, $pacote, 'Sem bloqueio operacional — a necessidade está fisicamente protegida.');

        $pedido = \App\Models\PedidoCompra::whereHas('itens', fn ($q) => $q->whereNotNull('requisicao_compra_item_id'))->latest('created_at')->first();
        $this->assertNotNull($pedido->diasAtrasoAtual(), 'O estado COMERCIAL do Pedido continua atrasado — dimensão distinta do bloqueio operacional.');
    }
}
