<?php

namespace Tests\Feature\Auditoria;

use App\Actions\Estoque\RegistrarEntradaEstoque;
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
use App\Actions\Suprimentos\RegistrarConclusaoEtapaRequisicaoCompra;
use App\Actions\Suprimentos\RegistrarRecebimentoPedido;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\TipoLocalEstoque;
use App\Exceptions\EntradaEstoqueInvalidaException;
use App\Exceptions\RecebimentoPedidoInvalidoException;
use App\Exceptions\SaidaEstoqueInvalidaException;
use App\Exceptions\TransferenciaEstoqueInvalidaException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\PedidoCompraItem;
use App\Models\RecebimentoPedido;
use App\Models\RequisicaoCompraEtapa;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Tempo\RelogioNegocio;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A2.2, Seção 11 — matriz explícita de fronteira
 * horária BRT/UTC para as 5 Actions que já usam `RelogioNegocio::
 * dataEstaNoFuturo()` (comparação por DATA DE CALENDÁRIO, nunca por
 * instante absoluto, desde a A2/A2.1): `RegistrarEntradaEstoque`,
 * `RegistrarSaidaEstoque`, `RegistrarTransferenciaEstoque`,
 * `RegistrarRecebimentoPedido`, `RegistrarConclusaoEtapaRequisicaoCompra`.
 *
 * Cada uma das 5 Actions é exercitada, EXPLICITAMENTE, nos 4 instantes
 * pedidos (domingo 20:59 BRT / 21:01 BRT / 23:59 BRT e segunda 00:01 BRT
 * — a fronteira exata onde UTC e o calendário brasileiro discordam sobre
 * "que dia é hoje"), sempre com fuso EXPLÍCITO (`America/Sao_Paulo`),
 * nunca dependendo do fuso da máquina que roda a suíte. Em cada janela:
 * "hoje" (Brasil) nunca é rejeitada como futura; "amanhã" (Brasil) é
 * sempre rejeitada; "ontem" continua permitida pela regra já existente.
 *
 * A referência de calendário reaproveita a MESMA semana já usada em
 * `TimezoneNegocioTest.php` (segunda 2026-12-14 a domingo 2026-12-20) —
 * as datas fixas internas de cada fixture (`'2026-12-01'`/`'2026-12-10'`,
 * herdadas de `IdempotenciaOperacoesEstoqueTest.php`) permanecem sempre
 * no passado em qualquer um dos 4 instantes testados aqui.
 */
class FronteiraTemporalAcoesEstoqueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidadeM;

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
    private RegistrarRecebimentoPedido $registrarRecebimento;
    private RegistrarEntradaEstoque $registrarEntrada;
    private RegistrarSaidaEstoque $registrarSaida;
    private RegistrarTransferenciaEstoque $registrarTransferencia;
    private RegistrarConclusaoEtapaRequisicaoCompra $registrarConclusaoEtapa;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-12-15 12:00:00'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidadeM = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);

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
        $this->registrarRecebimento = new RegistrarRecebimentoPedido();
        $this->registrarEntrada = new RegistrarEntradaEstoque();
        $this->registrarSaida = new RegistrarSaidaEstoque();
        $this->registrarTransferencia = new RegistrarTransferenciaEstoque();
        $this->registrarConclusaoEtapa = new RegistrarConclusaoEtapaRequisicaoCompra();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function instantesBrtProvider(): array
    {
        return [
            'domingo 20:59 BRT' => ['2026-12-20 20:59:00'],
            'domingo 21:01 BRT (UTC já virou o dia, Brasil ainda não)' => ['2026-12-20 21:01:00'],
            'domingo 23:59 BRT' => ['2026-12-20 23:59:00'],
            'segunda 00:01 BRT (virada real)' => ['2026-12-21 00:01:00'],
        ];
    }

    // ---- helpers (mesmo padrão de IdempotenciaOperacoesEstoqueTest) ----

    private function criarPacote(): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid(), 'codigo' => 'PAC' . uniqid()]);
    }

    private function criarFornecedor(): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid(), 'cnpj' => '00.000.000/0001-00']);
    }

    private function criarFluxo(): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        return $fluxo->fresh(['etapas']);
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidadeM->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
    }

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => $this->obra->id,
            'nome' => 'Almoxarifado ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value,
            'ativo' => true,
        ]);
    }

    private function alocacaoPronta(float $quantidadeAlocada, ItemSuprimento $pacote, Material $material): AlocacaoRequisicaoPacote
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id,
            'codigo' => 'A' . uniqid(),
            'descricao' => 'Item A',
            'quantidade' => 1000,
            'material_id' => $material->id,
        ]);
        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidadeAlocada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $this->alocar->alocar($rpItem->fresh(), $pacote, $quantidadeAlocada);
    }

    /** @return array{0: RecebimentoPedido, 1: Material} */
    private function recebimentoComMaterial(float $quantidade, Material $material): array
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(max($quantidade, 100), $pacote, $material);
        $rc = $this->criarRc->execute($pacote, $this->criarFluxo(), null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $pedido = $this->criarPedido->execute($rcEmitida, $this->criarFornecedor(), '2026-12-01', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $rcItem, $quantidade);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = $this->registrarRecebimento->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);

        return [$recebimento, $material];
    }

    /** @return array{0: RecebimentoPedido, 1: Material} */
    private function recebimentoPronto(float $quantidade): array
    {
        return $this->recebimentoComMaterial($quantidade, $this->criarMaterial());
    }

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade): void
    {
        [$recebimento] = $this->recebimentoComMaterial($quantidade, $material);
        $this->registrarEntrada->execute($recebimento, $local, $quantidade, Carbon::parse('2026-12-11'), $this->user);
    }

    /** Um Item de Pedido Compra JÁ EMITIDO, ainda sem nenhum recebimento — pronto pra RegistrarRecebimentoPedido. */
    private function pedidoItemPronto(float $quantidade): PedidoCompraItem
    {
        $material = $this->criarMaterial();
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(max($quantidade, 100), $pacote, $material);
        $rc = $this->criarRc->execute($pacote, $this->criarFluxo(), null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $pedido = $this->criarPedido->execute($rcEmitida, $this->criarFornecedor(), '2026-12-01', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $rcItem, $quantidade);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);

        return $pedidoItem->fresh();
    }

    /** Uma RequisicaoCompraEtapa Pendente, numa RC recém-Emitida (1 etapa só, via criarFluxo()). */
    private function etapaPendentePronta(): RequisicaoCompraEtapa
    {
        $material = $this->criarMaterial();
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote, $material);
        $rc = $this->criarRc->execute($pacote, $this->criarFluxo(), null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 100);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);

        return $rcEmitida->etapas->first();
    }

    // =========================================================
    // A — RegistrarEntradaEstoque
    // =========================================================

    /** @dataProvider instantesBrtProvider */
    public function test_a_entrada_estoque_fronteira_temporal(string $instanteBrt): void
    {
        Carbon::setTestNow(Carbon::parse($instanteBrt, 'America/Sao_Paulo'));
        $hoje = RelogioNegocio::hoje();

        [$recebimentoHoje] = $this->recebimentoPronto(100);
        $movHoje = $this->registrarEntrada->execute($recebimentoHoje, $this->criarLocal(), 40, $hoje->copy(), $this->user);
        $this->assertNotNull($movHoje->id, "[{$instanteBrt}] entrada de HOJE não pode ser rejeitada como futura");

        [$recebimentoAmanha] = $this->recebimentoPronto(100);
        try {
            $this->registrarEntrada->execute($recebimentoAmanha, $this->criarLocal(), 40, $hoje->copy()->addDay(), $this->user);
            $this->fail("[{$instanteBrt}] entrada de AMANHÃ deveria ter sido rejeitada");
        } catch (EntradaEstoqueInvalidaException $e) {
            $this->assertStringContainsString('futuro', $e->getMessage());
        }

        [$recebimentoOntem] = $this->recebimentoPronto(100);
        $movOntem = $this->registrarEntrada->execute($recebimentoOntem, $this->criarLocal(), 40, $hoje->copy()->subDay(), $this->user);
        $this->assertNotNull($movOntem->id, "[{$instanteBrt}] entrada de ONTEM deveria ser permitida");
    }

    // =========================================================
    // B — RegistrarSaidaEstoque
    // =========================================================

    /** @dataProvider instantesBrtProvider */
    public function test_b_saida_estoque_fronteira_temporal(string $instanteBrt): void
    {
        Carbon::setTestNow(Carbon::parse($instanteBrt, 'America/Sao_Paulo'));
        $hoje = RelogioNegocio::hoje();

        $materialHoje = $this->criarMaterial();
        $localHoje = $this->criarLocal();
        $this->entradaPronta($materialHoje, $localHoje, 100);
        $movHoje = $this->registrarSaida->execute($materialHoje, $localHoje, 30, $hoje->copy(), $this->user, retiradoPor: $this->user);
        $this->assertNotNull($movHoje->id, "[{$instanteBrt}] saída de HOJE não pode ser rejeitada como futura");

        $materialAmanha = $this->criarMaterial();
        $localAmanha = $this->criarLocal();
        $this->entradaPronta($materialAmanha, $localAmanha, 100);
        try {
            $this->registrarSaida->execute($materialAmanha, $localAmanha, 30, $hoje->copy()->addDay(), $this->user, retiradoPor: $this->user);
            $this->fail("[{$instanteBrt}] saída de AMANHÃ deveria ter sido rejeitada");
        } catch (SaidaEstoqueInvalidaException $e) {
            $this->assertStringContainsString('futuro', $e->getMessage());
        }

        $materialOntem = $this->criarMaterial();
        $localOntem = $this->criarLocal();
        $this->entradaPronta($materialOntem, $localOntem, 100);
        $movOntem = $this->registrarSaida->execute($materialOntem, $localOntem, 30, $hoje->copy()->subDay(), $this->user, retiradoPor: $this->user);
        $this->assertNotNull($movOntem->id, "[{$instanteBrt}] saída de ONTEM deveria ser permitida");
    }

    // =========================================================
    // C — RegistrarTransferenciaEstoque
    // =========================================================

    /** @dataProvider instantesBrtProvider */
    public function test_c_transferencia_estoque_fronteira_temporal(string $instanteBrt): void
    {
        Carbon::setTestNow(Carbon::parse($instanteBrt, 'America/Sao_Paulo'));
        $hoje = RelogioNegocio::hoje();

        $materialHoje = $this->criarMaterial();
        $origemHoje = $this->criarLocal();
        $destinoHoje = $this->criarLocal();
        $this->entradaPronta($materialHoje, $origemHoje, 100);
        $transfHoje = $this->registrarTransferencia->execute($materialHoje, $origemHoje, $destinoHoje, 30, $hoje->copy(), $this->user);
        $this->assertNotNull($transfHoje->id, "[{$instanteBrt}] transferência de HOJE não pode ser rejeitada como futura");

        $materialAmanha = $this->criarMaterial();
        $origemAmanha = $this->criarLocal();
        $destinoAmanha = $this->criarLocal();
        $this->entradaPronta($materialAmanha, $origemAmanha, 100);
        try {
            $this->registrarTransferencia->execute($materialAmanha, $origemAmanha, $destinoAmanha, 30, $hoje->copy()->addDay(), $this->user);
            $this->fail("[{$instanteBrt}] transferência de AMANHÃ deveria ter sido rejeitada");
        } catch (TransferenciaEstoqueInvalidaException $e) {
            $this->assertStringContainsString('futuro', $e->getMessage());
        }

        $materialOntem = $this->criarMaterial();
        $origemOntem = $this->criarLocal();
        $destinoOntem = $this->criarLocal();
        $this->entradaPronta($materialOntem, $origemOntem, 100);
        $transfOntem = $this->registrarTransferencia->execute($materialOntem, $origemOntem, $destinoOntem, 30, $hoje->copy()->subDay(), $this->user);
        $this->assertNotNull($transfOntem->id, "[{$instanteBrt}] transferência de ONTEM deveria ser permitida");
    }

    // =========================================================
    // D — RegistrarRecebimentoPedido
    // =========================================================

    /** @dataProvider instantesBrtProvider */
    public function test_d_recebimento_pedido_fronteira_temporal(string $instanteBrt): void
    {
        Carbon::setTestNow(Carbon::parse($instanteBrt, 'America/Sao_Paulo'));
        $hoje = RelogioNegocio::hoje();

        $itemHoje = $this->pedidoItemPronto(100);
        $recHoje = $this->registrarRecebimento->execute($itemHoje, 40, $hoje->copy(), $this->user);
        $this->assertNotNull($recHoje->id, "[{$instanteBrt}] recebimento de HOJE não pode ser rejeitado como futuro");

        $itemAmanha = $this->pedidoItemPronto(100);
        try {
            $this->registrarRecebimento->execute($itemAmanha, 40, $hoje->copy()->addDay(), $this->user);
            $this->fail("[{$instanteBrt}] recebimento de AMANHÃ deveria ter sido rejeitado");
        } catch (RecebimentoPedidoInvalidoException $e) {
            $this->assertStringContainsString('futuro', $e->getMessage());
        }

        $itemOntem = $this->pedidoItemPronto(100);
        $recOntem = $this->registrarRecebimento->execute($itemOntem, 40, $hoje->copy()->subDay(), $this->user);
        $this->assertNotNull($recOntem->id, "[{$instanteBrt}] recebimento de ONTEM deveria ser permitido");
    }

    // =========================================================
    // E — RegistrarConclusaoEtapaRequisicaoCompra
    // =========================================================

    /** @dataProvider instantesBrtProvider */
    public function test_e_conclusao_etapa_requisicao_compra_fronteira_temporal(string $instanteBrt): void
    {
        Carbon::setTestNow(Carbon::parse($instanteBrt, 'America/Sao_Paulo'));
        $hoje = RelogioNegocio::hoje();

        $etapaHoje = $this->etapaPendentePronta();
        $concluidaHoje = $this->registrarConclusaoEtapa->execute($etapaHoje, $this->user, $hoje->copy()->toDateString());
        $this->assertNotNull($concluidaHoje->data_realizada, "[{$instanteBrt}] conclusão de HOJE não pode ser rejeitada como futura");

        $etapaAmanha = $this->etapaPendentePronta();
        try {
            $this->registrarConclusaoEtapa->execute($etapaAmanha, $this->user, $hoje->copy()->addDay()->toDateString());
            $this->fail("[{$instanteBrt}] conclusão de AMANHÃ deveria ter sido rejeitada");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('futuro', $e->getMessage());
        }

        $etapaOntem = $this->etapaPendentePronta();
        $concluidaOntem = $this->registrarConclusaoEtapa->execute($etapaOntem, $this->user, $hoje->copy()->subDay()->toDateString());
        $this->assertNotNull($concluidaOntem->data_realizada, "[{$instanteBrt}] conclusão de ONTEM deveria ser permitida");

        // Sem data explícita (default RelogioNegocio::hoje(), Seção 2 da
        // A2.1) também nunca pode ser rejeitada como futura, em nenhuma
        // das 4 janelas.
        $etapaDefault = $this->etapaPendentePronta();
        $concluidaDefault = $this->registrarConclusaoEtapa->execute($etapaDefault, $this->user);
        $this->assertNotNull($concluidaDefault->data_realizada, "[{$instanteBrt}] conclusão sem data explícita (default) não pode ser rejeitada como futura");
    }
}
