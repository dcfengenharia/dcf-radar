<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaPedidoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaRequisicaoCompra;
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
use App\Enums\EstadoGerencialNecessidade;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusReservaEstoque;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoMovimentacaoEstoque;
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
use App\Models\MovimentacaoEstoque;
use App\Models\ReservaEstoque;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Suprimentos\EstadoAtendimentoNecessidadeMaterialQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Motor Definitivo de Risco de Suprimentos V1 — decomposição
 * quantitativa exclusiva (Seções 3-21/35 do pedido de implementação).
 * Cobre a matriz A-R exigida, sempre verificando o invariante central:
 * SUM(9 categorias exclusivas) === quantidade_necessaria.
 */
class DecomposicaoQuantitativaNecessidadeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2027-01-01'));

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

    // ---- helpers (mesmo padrão de EstadoAtendimentoNecessidadeMaterialTest) ----

    private function criarAtividade(?string $inicioPlanejado = '2027-01-20'): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => $inicioPlanejado,
        ]);
    }

    private function criarMaterial(?UnidadeMedida $unidade = null): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Cabo',
            'unidade_medida_id' => ($unidade ?? $this->unidade)->id,
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

        return [$rc, $item, $parcela];
    }

    private function fornecedor(string $nome = 'Fornecedor'): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => $nome . ' ' . uniqid(), 'cnpj' => '00.000.000/0001-00']);
    }

    private function pedidoParaParcela($rc, $rcItem, AtividadeNecessidadeMaterial $necessidade, Fornecedor $fornecedor, float $quantidade, ?string $data): \App\Models\PedidoCompra
    {
        $pedido = (new CriarPedidoCompra())->execute($rc->fresh(), $fornecedor, $data, null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem->fresh(), $quantidade);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidade, $quantidade, $this->user);

        return (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
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

    private function linha(Atividade $atividade, AtividadeNecessidadeMaterial $necessidade): array
    {
        return EstadoAtendimentoNecessidadeMaterialQuery::porAtividade($atividade->fresh())
            ->firstWhere('necessidade.id', $necessidade->id);
    }

    private const CHAVES_EXCLUSIVAS = [
        'decomposicao_reservado',
        'decomposicao_disponivel',
        'decomposicao_recebida_aguardando_disponibilizacao',
        'decomposicao_dependente_no_prazo',
        'decomposicao_dependente_atrasado',
        'decomposicao_pedida_sem_prazo',
        'decomposicao_adjudicada_sem_pedido',
        'decomposicao_em_processo',
        'decomposicao_sem_cobertura',
        'decomposicao_informacao_insuficiente',
    ];

    private function assertInvariante(array $linha, float $necessaria): void
    {
        $soma = array_sum(array_map(fn ($k) => (float) $linha[$k], self::CHAVES_EXCLUSIVAS));
        $this->assertEqualsWithDelta($necessaria, $soma, 0.005, 'SUM(categorias exclusivas) deve ser exatamente igual à necessidade — nunca double counting, nunca perda de quantidade.');

        foreach (self::CHAVES_EXCLUSIVAS as $chave) {
            $this->assertGreaterThanOrEqual(0.0, $linha[$chave], "{$chave} nunca pode ser negativo.");
        }
    }

    // =========================================================================
    // A — necessidade100, reserva100, Pedido atrasado100 -> protegida
    // =========================================================================
    public function test_a_reserva_integral_protege_mesmo_com_pedido_atrasado(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $local = $this->criarLocal();
        $this->entradaEstoque($material, $local, 100);
        $pacote = $item->fresh()->requisicaoCompra->pacote;
        $this->reservar($pacote, $material, $local, 100, $necessidade);

        // Pedido comercialmente atrasado (promessa no passado, nunca recebido).
        $this->pedidoParaParcela($rc, $item, $necessidade, $this->fornecedor(), 100, '2026-12-01');

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(100.0, $linha['decomposicao_reservado']);
        $this->assertEquals(0.0, $linha['decomposicao_disponivel']);
        $this->assertEquals(0.0, $linha['decomposicao_dependente_atrasado']);
        $this->assertSame(EstadoGerencialNecessidade::Protegida, $linha['estado_gerencial']);
    }

    // =========================================================================
    // B — necessidade100, estoque livre150, reserva0, Pedido atrasado100
    // =========================================================================
    public function test_b_estoque_livre_suficiente_e_disponivel_nao_reservada(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $local = $this->criarLocal();
        $this->entradaEstoque($material, $local, 150);

        $this->pedidoParaParcela($rc, $item, $necessidade, $this->fornecedor(), 100, '2026-12-01');

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(0.0, $linha['decomposicao_reservado']);
        $this->assertEquals(100.0, $linha['decomposicao_disponivel'], 'Nunca conta os 150 livres — só até o necessário.');
        $this->assertEquals(0.0, $linha['decomposicao_dependente_atrasado']);
        $this->assertSame(EstadoGerencialNecessidade::DisponivelNaoReservada, $linha['estado_gerencial']);
    }

    // =========================================================================
    // C — necessidade100, estoque0, P1=40 cedo, P2=60 tarde
    // =========================================================================
    public function test_c_dois_pedidos_cedo_e_tarde_split_correto(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = $this->fornecedor();
        $this->pedidoParaParcela($rc, $item, $necessidade, $fornecedor, 40, '2027-01-10');
        $rc2 = $rc->fresh();
        $item2 = \App\Models\RequisicaoCompraItem::find($item->id);
        $this->pedidoParaParcela($rc2, $item2, $necessidade, $fornecedor, 60, '2027-01-30');

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(40.0, $linha['decomposicao_dependente_no_prazo']);
        $this->assertEquals(60.0, $linha['decomposicao_dependente_atrasado']);
        $this->assertSame(EstadoGerencialNecessidade::DependenteFornecimentoAtrasado, $linha['estado_gerencial']);
    }

    // =========================================================================
    // D — necessidade100, Pedido100, recebido40 atribuído, 60 dependentes
    // =========================================================================
    public function test_d_pedido_parcialmente_recebido_so_saldo_continua_dependente(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $pedido = $this->pedidoParaParcela($rc, $item, $necessidade, $this->fornecedor(), 100, '2027-01-10');
        $pedidoItem = $pedido->fresh(['itens'])->itens->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItem, 40, now()->subDay(), $this->user, null, null);

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(40.0, $linha['quantidade_recebida']);
        // 40 recebidos nunca continuam "dependentes de fornecimento futuro"
        // — mas também NUNCA viram "disponivel" ledger-confirmado (Achado 1
        // do Fechamento Adversarial Final: RecebimentoPedido não implica
        // MovimentacaoEstoque; nenhuma entrada em estoque foi registrada
        // aqui) — categoria própria, `decomposicao_disponivel` permanece 0.
        $this->assertEquals(0.0, $linha['decomposicao_disponivel'], 'Sem NENHUMA MovimentacaoEstoque registrada, o ledger físico continua em 0 — recebido comercial nunca vira estoque fictício.');
        $this->assertEquals(40.0, $linha['decomposicao_recebida_aguardando_disponibilizacao']);
        $this->assertEquals(60.0, $linha['decomposicao_dependente_no_prazo'], 'Só os 60 restantes continuam dependentes da promessa (no prazo, 10/01).');
        $this->assertEquals(0.0, $linha['decomposicao_dependente_atrasado']);
        $this->assertSame(EstadoGerencialNecessidade::DependenteFornecimentoNoPrazo, $linha['estado_gerencial']);
    }

    // =========================================================================
    // D2 — Achado 1 (Fechamento Adversarial Final): recebido 100% mas SEM
    // nenhuma MovimentacaoEstoque -> nunca "Protegida"/"DisponivelNaoReservada",
    // sempre a categoria própria RecebidaAguardandoDisponibilizacao. Cenário
    // probatório EXATO do pedido de fechamento (Ponto 3): necessidade=100,
    // ledger=0, reserva=0, pedido=100, recebido=100, sem entrada em estoque.
    // =========================================================================
    public function test_d2_recebido_integral_sem_movimentacao_estoque_nunca_vira_disponivel(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $pedido = $this->pedidoParaParcela($rc, $item, $necessidade, $this->fornecedor(), 100, '2027-01-10');
        $pedidoItem = $pedido->fresh(['itens'])->itens->first();
        // RecebimentoPedido registrado, NUNCA MovimentacaoEstoque — prova
        // de código: RegistrarRecebimentoPedido nunca cria MovimentacaoEstoque
        // (App\Actions\Suprimentos\RegistrarRecebimentoPedido), só
        // RegistrarEntradaEstoque (App\Actions\Estoque) faz isso, e é uma
        // ação humana SEPARADA, nunca disparada automaticamente aqui.
        (new RegistrarRecebimentoPedido())->execute($pedidoItem, 100, now()->subDay(), $this->user, null, null);

        // Confirma por query direta que zero MovimentacaoEstoque existe pra
        // este material/obra — o ledger físico está genuinamente vazio.
        $this->assertSame(0, \App\Models\MovimentacaoEstoque::where('material_id', $material->id)->count());

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(100.0, $linha['quantidade_recebida']);
        $this->assertEquals(0.0, $linha['decomposicao_disponivel'], 'Conceitualmente esperado: 0 — o ledger nunca confirma presença física.');
        $this->assertEquals(100.0, $linha['decomposicao_recebida_aguardando_disponibilizacao']);
        $this->assertSame(EstadoGerencialNecessidade::RecebidaAguardandoDisponibilizacao, $linha['estado_gerencial']);
        $this->assertNotSame(EstadoGerencialNecessidade::Protegida, $linha['estado_gerencial']);
        $this->assertNotSame(EstadoGerencialNecessidade::DisponivelNaoReservada, $linha['estado_gerencial']);

        // A descrição de Restrição também nunca soa como "risco de
        // chegada" pra este material já fisicamente recebido — mas também
        // nunca finge que é estoque ledger-confirmado.
        $pacote = $item->fresh()->requisicaoCompra->pacote;
        $pacote->atividades()->sync([$atividade->id]);
        $descricao = \App\Support\Suprimentos\DescricaoRestricaoSuprimento::paraPacoteEAtividade($atividade, $pacote->fresh(['atividades']), 'FALLBACK');
        $this->assertSame('FALLBACK', $descricao, 'Recebido (mesmo sem "Dar entrada") nunca gera frase de bloqueio de chegada.');
    }

    // =========================================================================
    // D3 — Achado 1, ponto 4 do pedido de fechamento: double counting.
    // Recebido=100 E MovimentacaoEstoque Entrada=100 (o mesmo material já
    // formalmente incorporado) -> disponivel deve ser 100, NUNCA 200.
    // =========================================================================
    public function test_d3_recebido_e_entrada_em_estoque_nunca_dobra_a_quantidade(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $pedido = $this->pedidoParaParcela($rc, $item, $necessidade, $this->fornecedor(), 100, '2027-01-10');
        $pedidoItem = $pedido->fresh(['itens'])->itens->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItem, 100, now()->subDay(), $this->user, null, null);

        $local = $this->criarLocal();
        $this->entradaEstoque($material, $local, 100);

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(100.0, $linha['decomposicao_disponivel'], 'Nunca 200 — o waterfall consome o "restante" uma única vez.');
        $this->assertEquals(0.0, $linha['decomposicao_recebida_aguardando_disponibilizacao'], 'Já refletido no ledger — nada sobra pra esta categoria.');
        $this->assertSame(EstadoGerencialNecessidade::DisponivelNaoReservada, $linha['estado_gerencial']);
    }

    // =========================================================================
    // F — necessidade100, adjudicado100, sem Pedido
    // =========================================================================
    public function test_f_adjudicado_sem_pedido(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item, $parcela] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $adjudicacao = (new CriarAdjudicacaoRequisicaoCompra())->execute($rc->fresh(), $this->fornecedor(), 'Decisão', null, null, $this->user);
        (new AtualizarAdjudicacaoRequisicaoCompra())->adicionarItem($adjudicacao, $item->fresh(), $parcela->fresh(), 100, $this->user);

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(100.0, $linha['decomposicao_adjudicada_sem_pedido']);
        $this->assertSame(EstadoGerencialNecessidade::PrePedido, $linha['estado_gerencial']);
    }

    // =========================================================================
    // G — necessidade100, RC100, sem adjudicação
    // =========================================================================
    public function test_g_em_rc_sem_adjudicacao(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(100.0, $linha['decomposicao_em_processo']);
        $this->assertSame(EstadoGerencialNecessidade::EmProcesso, $linha['estado_gerencial']);
    }

    // =========================================================================
    // H — necessidade100, sem RC
    // =========================================================================
    public function test_h_sem_cobertura_comercial(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(100.0, $linha['decomposicao_sem_cobertura']);
        $this->assertSame(EstadoGerencialNecessidade::NaoContratada, $linha['estado_gerencial']);
    }

    // =========================================================================
    // I — necessidade100, reserva40, livre20, Pedido40 no prazo (exemplo exato da Seção 17)
    // =========================================================================
    public function test_i_cobertura_parcial_em_tres_camadas_exemplo_da_secao_17(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $local = $this->criarLocal();
        $this->entradaEstoque($material, $local, 60); // 40 reservados + 20 livres
        $pacote = $item->fresh()->requisicaoCompra->pacote;
        $this->reservar($pacote, $material, $local, 40, $necessidade);

        $this->pedidoParaParcela($rc, $item, $necessidade, $this->fornecedor(), 40, '2027-01-10');

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(40.0, $linha['decomposicao_reservado']);
        $this->assertEquals(20.0, $linha['decomposicao_disponivel']);
        $this->assertEquals(40.0, $linha['decomposicao_dependente_no_prazo']);
        $this->assertEquals(0.0, $linha['decomposicao_sem_cobertura']);
    }

    // =========================================================================
    // J — duas atividades concorrendo pelo MESMO estoque livre (nunca soma como garantia)
    // =========================================================================
    public function test_j_concorrencia_por_estoque_livre_nunca_vira_garantia_dupla(): void
    {
        $material = $this->criarMaterial();

        $atividadeA = $this->criarAtividade('2027-01-20');
        $itoA = $this->criarItemTakeOff($material, 1000);
        $necessidadeA = $this->criarNecessidade($atividadeA, $itoA, 80);

        $atividadeB = $this->criarAtividade('2027-01-20');
        $itoB = $this->criarItemTakeOff($material, 1000);
        $necessidadeB = $this->criarNecessidade($atividadeB, $itoB, 80);

        $local = $this->criarLocal();
        $this->entradaEstoque($material, $local, 100); // só 100 livres, pra duas necessidades de 80 cada

        $linhaA = $this->linha($atividadeA, $necessidadeA);
        $linhaB = $this->linha($atividadeB, $necessidadeB);

        // Cada necessidade, isoladamente, vê os mesmos 100 livres como
        // oportunidade — nunca uma garantia (o motor nunca decrementa o
        // pool entre necessidades, exatamente como a Seção 6 exige: só
        // uma Reserva de verdade protegeria uma quantidade específica).
        $this->assertEquals(80.0, $linhaA['decomposicao_disponivel']);
        $this->assertEquals(80.0, $linhaB['decomposicao_disponivel']);
        $this->assertInvariante($linhaA, 80);
        $this->assertInvariante($linhaB, 80);
    }

    // =========================================================================
    // K — unidade incompatível: nunca falsa precisão
    // =========================================================================
    public function test_k_unidade_incompativel_vira_informacao_insuficiente(): void
    {
        $outraUnidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'KG', 'nome' => 'Quilograma']);
        $material = $this->criarMaterial($outraUnidade); // Material em KG
        $atividade = $this->criarAtividade('2027-01-20');
        $ito = $this->criarItemTakeOff($material, 1000);
        // Necessidade cadastrada em UN (incompatível com o Material em KG).
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(100.0, $linha['decomposicao_informacao_insuficiente']);
        $this->assertSame(EstadoGerencialNecessidade::InformacaoInsuficiente, $linha['estado_gerencial']);
    }

    // =========================================================================
    // Q — múltiplos recebimentos (2 eventos) somam corretamente
    // =========================================================================
    public function test_q_multiplos_recebimentos_do_mesmo_pedido_somam(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $pedido = $this->pedidoParaParcela($rc, $item, $necessidade, $this->fornecedor(), 100, '2027-01-10');
        $pedidoItem = $pedido->fresh(['itens'])->itens->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItem, 30, now()->subDays(3), $this->user, null, null);
        (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), 25, now()->subDay(), $this->user, null, null);

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(55.0, $linha['quantidade_recebida']);
        $this->assertEquals(0.0, $linha['decomposicao_disponivel'], 'Sem MovimentacaoEstoque, o ledger continua em 0 mesmo com 2 eventos de recebimento somados.');
        $this->assertEquals(55.0, $linha['decomposicao_recebida_aguardando_disponibilizacao']);
        $this->assertEquals(45.0, $linha['decomposicao_dependente_no_prazo']);
    }

    // =========================================================================
    // S — Ponto 10 do Fechamento Adversarial Final: 3 Pedidos NÃO
    // proporcionais (17/43/77), P1 cedo + P2 tarde + P3 cedo -> 94 no
    // prazo (17+77), 43 atrasado (43). Sem recebimento — prova que a
    // curva por parcela já era exata mesmo antes da correção do Achado 2
    // (o bug só se manifesta quando HÁ recebimento parcial entre Pedidos).
    // =========================================================================
    public function test_s_multiplos_pedidos_nao_proporcionais_sem_recebimento(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 137);
        [$rc, $item] = $this->rcComParcela($ito, $necessidade, 137, 137);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = $this->fornecedor();
        $this->pedidoParaParcela($rc, $item, $necessidade, $fornecedor, 17, '2027-01-10'); // P1 cedo
        $rc2 = $rc->fresh();
        $item2 = \App\Models\RequisicaoCompraItem::find($item->id);
        $this->pedidoParaParcela($rc2, $item2, $necessidade, $fornecedor, 43, '2027-01-30'); // P2 tarde
        $rc3 = $rc->fresh();
        $item3 = \App\Models\RequisicaoCompraItem::find($item->id);
        $this->pedidoParaParcela($rc3, $item3, $necessidade, $fornecedor, 77, '2027-01-05'); // P3 cedo

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 137);
        $this->assertEquals(94.0, $linha['decomposicao_dependente_no_prazo'], '17 (P1) + 77 (P3), nunca uma fração proporcional do total.');
        $this->assertEquals(43.0, $linha['decomposicao_dependente_atrasado']);
        $this->assertSame(EstadoGerencialNecessidade::DependenteFornecimentoAtrasado, $linha['estado_gerencial']);
    }

    // =========================================================================
    // T — Fechamento Adversarial Final, Achado 2 (Pontos 11/12): CENÁRIO
    // PROBATÓRIO EXATO do pedido — P1=40 cedo, recebido 40 (100%); P2=60
    // tarde, nunca recebido. Resultado OBRIGATÓRIO: 0 no prazo / 60
    // atrasado — NUNCA "24 no prazo / 36 atrasado" (o resultado que uma
    // proporção agregada incorreta produziria). O recebimento de P1 nunca
    // pode "vazar" proteção proporcional pra P2.
    // =========================================================================
    public function test_t_recebimento_integral_de_um_pedido_nunca_protege_proporcionalmente_outro(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = $this->fornecedor();
        $pedido1 = $this->pedidoParaParcela($rc, $item, $necessidade, $fornecedor, 40, '2027-01-10'); // P1 cedo
        $rc2 = $rc->fresh();
        $item2 = \App\Models\RequisicaoCompraItem::find($item->id);
        $this->pedidoParaParcela($rc2, $item2, $necessidade, $fornecedor, 60, '2027-01-30'); // P2 tarde

        $pedidoItem1 = $pedido1->fresh(['itens'])->itens->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItem1, 40, now()->subDay(), $this->user, null, null);

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(40.0, $linha['quantidade_recebida']);
        $this->assertEquals(40.0, $linha['decomposicao_recebida_aguardando_disponibilizacao']);
        $this->assertEquals(0.0, $linha['decomposicao_dependente_no_prazo'], 'P1 (cedo) já foi 100% recebido — zero, nunca 24.');
        $this->assertEquals(60.0, $linha['decomposicao_dependente_atrasado'], 'Só P2 (tarde, nunca recebido) permanece dependente — 60 inteiros, nunca 36.');
        $this->assertSame(EstadoGerencialNecessidade::DependenteFornecimentoAtrasado, $linha['estado_gerencial']);
    }

    // =========================================================================
    // U — Ponto 13: recebimento PARCIAL de um dos Pedidos reduz só a
    // própria fatia — P1=40 cedo, recebido 20 (parcial); P2=60 tarde,
    // nunca recebido. Esperado: 20 recebida-aguardando, 20 ainda cedo
    // (saldo de P1), 60 atrasado (P2 inteiro) — nunca redistribuído.
    // =========================================================================
    public function test_u_recebimento_parcial_de_um_pedido_reduz_so_sua_propria_fatia(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);
        [$rc, $item] = $this->rcComParcela($ito, $necessidade, 100, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = $this->fornecedor();
        $pedido1 = $this->pedidoParaParcela($rc, $item, $necessidade, $fornecedor, 40, '2027-01-10'); // P1 cedo
        $rc2 = $rc->fresh();
        $item2 = \App\Models\RequisicaoCompraItem::find($item->id);
        $this->pedidoParaParcela($rc2, $item2, $necessidade, $fornecedor, 60, '2027-01-30'); // P2 tarde

        $pedidoItem1 = $pedido1->fresh(['itens'])->itens->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItem1, 20, now()->subDay(), $this->user, null, null);

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 100);
        $this->assertEquals(20.0, $linha['quantidade_recebida']);
        $this->assertEquals(20.0, $linha['decomposicao_recebida_aguardando_disponibilizacao']);
        $this->assertEquals(20.0, $linha['decomposicao_dependente_no_prazo'], 'Só o saldo de P1 (40-20) continua cedo — nunca redistribuído sobre P2.');
        $this->assertEquals(60.0, $linha['decomposicao_dependente_atrasado'], 'P2 inteiro, intocado pelo recebimento de P1.');
    }

    // =========================================================================
    // W — Ponto 14: recebimento SEM atribuição segura (item com 2+
    // parcelas, sem distribuição explícita) nunca abate a pendência da
    // necessidade específica, e nunca inventa cobertura -> sinaliza
    // Informação Insuficiente (qualidade) sem otimismo por omissão.
    // =========================================================================
    public function test_w_recebimento_sem_atribuicao_segura_nunca_abate_pendencia_especifica(): void
    {
        // Duas Atividades (o unique de AtividadeNecessidadeMaterial é por
        // (atividade, ItemTakeOff) — usar 2 Atividades pro MESMO ito é o
        // jeito mais simples de conseguir 2 necessidades diferentes
        // compartilhando a MESMA parcela de RC/Pedido).
        $atividadeA = $this->criarAtividade('2027-01-20');
        $atividadeB = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidadeA = $this->criarNecessidade($atividadeA, $ito, 60);
        $necessidadeB = $this->criarNecessidade($atividadeB, $ito, 40);

        // UM único item de RC/Pedido (100), mas DUAS parcelas (60/40) —
        // nunca "1 parcela cobrindo o item inteiro", então a heurística
        // segura de atribuição de recebimento não se aplica a NENHUMA
        // das duas necessidades.
        $alocacao = $this->alocacaoPronta($ito, 100);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $rcItem = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 100);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($rcItem, $necessidadeA, 60, $this->user);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($rcItem->fresh(), $necessidadeB, 40, $this->user);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $this->fornecedor(), '2027-01-10', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), 100);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidadeA, 60, $this->user);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidadeB, 40, $this->user);
        $pedidoEmitido = (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        // Recebimento PARCIAL do item (50 de 100), SEM nenhuma
        // DistribuirRecebimentoPedidoPorParcela explícita — não há como
        // saber com certeza se os 50 recebidos pertencem à necessidade A,
        // à B, ou a uma mistura das duas.
        $pedidoItemFresh = $pedidoEmitido->fresh(['itens'])->itens->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItemFresh, 50, now()->subDay(), $this->user, null, null);

        $linhaA = $this->linha($atividadeA, $necessidadeA);
        $this->assertInvariante($linhaA, 60);
        $this->assertEquals(0.0, $linhaA['quantidade_recebida'], 'Não atribuível com certeza -> conta 0, nunca uma estimativa proporcional.');
        $this->assertEquals(0.0, $linhaA['decomposicao_recebida_aguardando_disponibilizacao']);
        $this->assertEquals(60.0, $linhaA['decomposicao_dependente_no_prazo'], 'Pendência INTEIRA de A permanece, nunca reduzida por um recebimento não atribuível.');
        $this->assertContains(\App\Enums\QualidadeInformacaoAtendimento::RecebimentoNaoAtribuivelPorParcela, $linhaA['qualidade_informacao']);

        $linhaB = $this->linha($atividadeB, $necessidadeB);
        $this->assertInvariante($linhaB, 40);
        $this->assertEquals(0.0, $linhaB['quantidade_recebida']);
        $this->assertEquals(40.0, $linhaB['decomposicao_dependente_no_prazo'], 'Pendência INTEIRA de B também permanece intocada.');
        $this->assertContains(\App\Enums\QualidadeInformacaoAtendimento::RecebimentoNaoAtribuivelPorParcela, $linhaB['qualidade_informacao']);
    }

    // =========================================================================
    // Necessidade Operacional (origem !== TakeOff) também respeita o invariante
    // =========================================================================
    public function test_necessidade_operacional_tambem_respeita_invariante(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $necessidade = (new AtualizarNecessidadeMaterialAtividade())->criarOperacional($atividade, $material, 50, 'Necessidade avulsa', $this->user);

        $local = $this->criarLocal();
        $this->entradaEstoque($material, $local, 20);

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 50);
        $this->assertEquals(20.0, $linha['decomposicao_disponivel']);
        $this->assertEquals(30.0, $linha['decomposicao_sem_cobertura']);
    }

    // =========================================================================
    // Zero necessidade nunca quebra o invariante (o domínio já proíbe
    // criar quantidade_necessaria=0 via AtualizarNecessidadeMaterialAtividade
    // — testado aqui como defesa em profundidade do read-model, direto no
    // model, contra dado legado/futuro que viole essa invariante).
    // =========================================================================
    public function test_necessidade_zero_nunca_quebra_invariante(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = AtividadeNecessidadeMaterial::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'atividade_id' => $atividade->id,
            'origem' => \App\Enums\OrigemNecessidadeMaterialAtividade::TakeOff->value,
            'item_take_off_id' => $ito->id,
            'unidade_medida_id' => $this->unidade->id,
            'quantidade_necessaria' => 0.0,
            'created_by_id' => $this->user->id,
        ]);

        $linha = $this->linha($atividade, $necessidade);
        $this->assertInvariante($linha, 0.0);
        $this->assertSame(EstadoGerencialNecessidade::Protegida, $linha['estado_gerencial']);
    }
}
