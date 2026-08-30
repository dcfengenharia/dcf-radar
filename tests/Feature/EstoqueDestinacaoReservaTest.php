<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarDestinacaoPlanejada;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\LiberarReservaEstoque;
use App\Actions\Estoque\RegistrarEntradaEstoque;
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
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusReservaEstoque;
use App\Enums\TipoLocalEstoque;
use App\Exceptions\DestinacaoPlanejadaImutavelException;
use App\Exceptions\DestinacaoPlanejadaInvalidaException;
use App\Exceptions\ReservaEstoqueInvalidaException;
use App\Exceptions\SaldoDestinacaoInsuficienteException;
use App\Exceptions\SaldoFisicoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DestinacaoPlanejadaMaterial;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\PedidoCompraItem;
use App\Models\ReservaEstoque;
use App\Models\Tenant;
use App\Models\UnidadeEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\ConciliacaoDestinacao;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.2 — Destinação Planejada + Reserva de Estoque.
 * Cobertura A-AW do pedido (Seções 48-53).
 */
class EstoqueDestinacaoReservaTest extends TestCase
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
    private RegistrarEntradaEstoque $registrarEntrada;
    private AtualizarDestinacaoPlanejada $destinacaoAction;
    private CriarReservaEstoque $reservaAction;
    private LiberarReservaEstoque $liberarAction;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-15'));

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
        $this->registrarEntrada = new RegistrarEntradaEstoque();
        $this->destinacaoAction = new AtualizarDestinacaoPlanejada();
        $this->reservaAction = new CriarReservaEstoque();
        $this->liberarAction = new LiberarReservaEstoque();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers ----

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(array $overrides = [], ?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value,
            'ativo' => true,
        ], $overrides));
    }

    private function criarFrente(array $overrides = [], ?Work $obra = null): FrenteTrabalho
    {
        return FrenteTrabalho::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Frente ' . uniqid(),
        ], $overrides));
    }

    /**
     * Ciclo 20.2.CORREÇÃO (fecha o Achado B1) — Pacote "bare", sem
     * nenhuma alocação, só pra satisfazer o novo parâmetro obrigatório
     * de CriarReservaEstoque::execute() nos testes que testam a
     * dimensão FÍSICA (saldo/lock/liberação), onde qual Pacote é
     * irrelevante pro cenário sob teste.
     */
    private function criarPacoteSimples(?Work $obra = null): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote ' . uniqid()]);
    }

    private function criarItemTakeOffOrfao(?Material $material, ?Work $obra = null): ItemTakeOff
    {
        $obra ??= $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item',
            'quantidade' => 1000000, 'material_id' => $material?->id,
        ]);
    }

    private function criarRpItemEmitido(ItemTakeOff $item, float $quantidade, ?Work $obra = null): \App\Models\RequisicaoPlanejamentoItem
    {
        $obra ??= $this->obra;
        $rp = $this->criarRp->execute($obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidade);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $rpItem->fresh();
    }

    private function alocarPacote(\App\Models\RequisicaoPlanejamentoItem $rpItem, float $quantidade, ?Work $obra = null): AlocacaoRequisicaoPacote
    {
        $obra ??= $this->obra;
        $pacote = ItemSuprimento::create(['obra_id' => $obra->id, 'nome' => 'Pacote ' . uniqid()]);

        return $this->alocar->alocar($rpItem, $pacote, $quantidade);
    }

    /**
     * Monta um par Pacote+Material com demanda formal conhecida —
     * retorna [pacote, material, alocacao].
     */
    private function parFormal(float $quantidadeFormal, array $materialOverrides = [], ?Work $obra = null): array
    {
        $material = $this->criarMaterial($materialOverrides);
        $item = $this->criarItemTakeOffOrfao($material, $obra);
        $rpItem = $this->criarRpItemEmitido($item, $quantidadeFormal, $obra);
        $alocacao = $this->alocarPacote($rpItem, $quantidadeFormal, $obra);
        $pacote = ItemSuprimento::find($alocacao->item_suprimento_id);

        return [$pacote, $material, $alocacao];
    }

    /**
     * Monta uma cadeia comercial completa (RP->Alocação->RC->Pedido->
     * Recebimento) INDEPENDENTE, só pra chegar numa Entrada em estoque —
     * deliberadamente desacoplada de qualquer Pacote usado em testes de
     * Destinação (saldo físico nunca depende da cadeia formal usada pra
     * receber, mesma independência de dimensões do próprio desenho).
     */
    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade, ?string $codigoLote = null, ?string $serial = null): void
    {
        $item = $this->criarItemTakeOffOrfao($material, $this->obra);
        $rpItem = $this->criarRpItemEmitido($item, $quantidade);
        $alocacao = $this->alocarPacote($rpItem, $quantidade);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);

        $this->registrarEntrada->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user, $codigoLote, $serial);
    }

    // =========================================================
    // A-N: Destinação Planejada
    // =========================================================

    public function test_a_criar_destinacao(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();

        $d = $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);

        $this->assertDatabaseHas('destinacoes_planejadas_material', [
            'id' => $d->id, 'item_suprimento_id' => $pacote->id, 'material_id' => $material->id,
            'frente_trabalho_id' => $frente->id, 'quantidade_planejada' => 300,
        ]);
    }

    public function test_b_parcial_permitida(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();

        $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $this->destinacaoAction->criar($pacote, $material, $frenteB, 250, $this->user);

        $this->assertEqualsWithDelta(450.0, ConciliacaoDestinacao::saldoADestinar($pacote->id, $material->id), 0.001);
    }

    public function test_c_zero_destinacao_permitido(): void
    {
        [$pacote, $material] = $this->parFormal(1000);

        $this->assertEqualsWithDelta(1000.0, ConciliacaoDestinacao::saldoADestinar($pacote->id, $material->id), 0.001);
        $this->assertSame(0, DestinacaoPlanejadaMaterial::count());
    }

    public function test_d_varias_frentes(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $frenteC = $this->criarFrente();

        $this->destinacaoAction->criar($pacote, $material, $frenteA, 200, $this->user);
        $this->destinacaoAction->criar($pacote, $material, $frenteB, 300, $this->user);
        $this->destinacaoAction->criar($pacote, $material, $frenteC, 100, $this->user);

        $this->assertSame(3, DestinacaoPlanejadaMaterial::where('item_suprimento_id', $pacote->id)->count());
        $this->assertEqualsWithDelta(400.0, ConciliacaoDestinacao::saldoADestinar($pacote->id, $material->id), 0.001);
    }

    public function test_e_over_destinacao_bloqueada(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();

        $this->expectException(SaldoDestinacaoInsuficienteException::class);
        $this->destinacaoAction->criar($pacote, $material, $frente, 1100, $this->user);
    }

    public function test_f_limite_exato(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();

        $d = $this->destinacaoAction->criar($pacote, $material, $frente, 1000, $this->user);

        $this->assertNotNull($d);
        $this->assertEqualsWithDelta(0.0, ConciliacaoDestinacao::saldoADestinar($pacote->id, $material->id), 0.001);
    }

    public function test_g_alteracao_antes_de_reserva(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();
        $d = $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);

        $this->destinacaoAction->alterar($d, 200);

        $this->assertEqualsWithDelta(200.0, (float) $d->fresh()->quantidade_planejada, 0.001);
    }

    public function test_h_reduzir_abaixo_do_reservado_bloqueia(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();
        $d = $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);

        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $this->reservaAction->execute($pacote, $material, $local, 200, null, $d, $this->user);

        $this->expectException(DestinacaoPlanejadaImutavelException::class);
        $this->destinacaoAction->alterar($d, 100);
    }

    public function test_i_material_inativo(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $material->update(['ativo' => false]);
        $frente = $this->criarFrente();

        $this->expectException(DestinacaoPlanejadaInvalidaException::class);
        $this->destinacaoAction->criar($pacote, $material, $frente, 100, $this->user);
    }

    public function test_j_cross_obra(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $frenteDeOutraObra = $this->criarFrente([], $outraObra);

        $this->expectException(DestinacaoPlanejadaInvalidaException::class);
        $this->destinacaoAction->criar($pacote, $material, $frenteDeOutraObra, 100, $this->user);
    }

    public function test_k_cross_tenant(): void
    {
        // Pacote/Material/Frente pertencem ao tenant A ($this->tenant).
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();

        // Autentica como usuário de um tenant B distinto — o global
        // scope de BelongsToTenant faz ItemSuprimento::whereKey($pacote->id)
        // (dentro da Action) simplesmente não encontrar a linha do
        // tenant A, mesmo passando o objeto PHP já resolvido.
        $outroTenant = Tenant::factory()->create();
        $usuarioOutroTenant = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $usuarioOutroTenant, Papel::GerentePlanejamento->value);
        $this->actingAs($usuarioOutroTenant);

        $this->expectException(ModelNotFoundException::class);
        $this->destinacaoAction->criar($pacote, $material, $frente, 100, $usuarioOutroTenant);
    }

    public function test_l_mesma_frente_varios_materiais(): void
    {
        [$pacoteA, $materialA] = $this->parFormal(1000);
        [$pacoteB, $materialB] = $this->parFormal(500);
        $frente = $this->criarFrente();

        $this->destinacaoAction->criar($pacoteA, $materialA, $frente, 300, $this->user);
        $this->destinacaoAction->criar($pacoteB, $materialB, $frente, 200, $this->user);

        $this->assertSame(2, DestinacaoPlanejadaMaterial::where('frente_trabalho_id', $frente->id)->count());
    }

    public function test_m_mesmo_material_varias_frentes_agregacao(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();

        $this->destinacaoAction->criar($pacote, $material, $frenteA, 400, $this->user);
        $this->destinacaoAction->criar($pacote, $material, $frenteB, 350, $this->user);

        $this->assertEqualsWithDelta(750.0, ConciliacaoDestinacao::quantidadeDestinada($pacote->id, $material->id), 0.001);
    }

    public function test_n_unidade_decimal(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();

        $d = $this->destinacaoAction->criar($pacote, $material, $frente, 0.125, $this->user);

        $this->assertEqualsWithDelta(0.125, (float) $d->fresh()->quantidade_planejada, 0.0001);
    }

    // =========================================================
    // O-Z: Reserva de Estoque
    // =========================================================

    public function test_o_reserva_parcial(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);

        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->assertEqualsWithDelta(200.0, (float) $reserva->quantidade, 0.001);
    }

    public function test_p_multiplas_reservas(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);

        $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);
        $this->reservaAction->execute($pacote, $material, $local, 100, null, null, $this->user);

        $this->assertEqualsWithDelta(300.0, SaldoReserva::porMaterialLocal($material, $local), 0.001);
    }

    public function test_q_saldo_fisico_nao_muda(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $fisicoAntes = SaldoEstoque::porMaterialLocal($material, $local);

        $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->assertEqualsWithDelta($fisicoAntes, SaldoEstoque::porMaterialLocal($material, $local), 0.001);
        $this->assertEqualsWithDelta(500.0, SaldoEstoque::porMaterialLocal($material, $local), 0.001);
    }

    public function test_r_saldo_reservado_muda(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);

        $this->assertEqualsWithDelta(0.0, SaldoReserva::porMaterialLocal($material, $local), 0.001);
        $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);
        $this->assertEqualsWithDelta(200.0, SaldoReserva::porMaterialLocal($material, $local), 0.001);
    }

    public function test_s_saldo_disponivel_muda(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);

        $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->assertEqualsWithDelta(300.0, SaldoReserva::disponivelPorMaterialLocal($material, $local), 0.001);
    }

    public function test_t_over_reserva_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);

        $this->expectException(SaldoFisicoInsuficienteException::class);
        $this->reservaAction->execute($pacote, $material, $local, 600, null, null, $this->user);
    }

    public function test_u_limite_exato(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);

        $this->reservaAction->execute($pacote, $material, $local, 500, null, null, $this->user);

        $this->assertEqualsWithDelta(0.0, SaldoReserva::disponivelPorMaterialLocal($material, $local), 0.001);
    }

    public function test_v_concorrencia_ordem_de_lock_local(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);

        DB::enableQueryLog();
        $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);
        $log = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $indiceLockLocal = $log->search(fn ($q) => str_contains(strtolower($q['query']), 'locais_estoque') && str_contains(strtolower($q['query']), 'for update'));
        $indiceInsert = $log->search(fn ($q) => str_contains(strtolower($q['query']), 'insert into `reservas_estoque`'));

        $this->assertNotFalse($indiceLockLocal, 'Esperava um SELECT ... FOR UPDATE em locais_estoque.');
        $this->assertNotFalse($indiceInsert);
        $this->assertLessThan($indiceInsert, $indiceLockLocal, 'O lock em LocalEstoque precisa acontecer ANTES do insert da reserva.');
    }

    public function test_w_liberar_reserva(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->liberarAction->execute($reserva, $this->user, 'Não precisa mais');

        $fresh = $reserva->fresh();
        $this->assertSame(StatusReservaEstoque::Liberada, $fresh->status);
        $this->assertNotNull($fresh->liberado_em);
        $this->assertSame($this->user->id, $fresh->liberado_por);
        $this->assertEqualsWithDelta(500.0, SaldoReserva::disponivelPorMaterialLocal($material, $local), 0.001);
    }

    public function test_x_historico_preservado(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->liberarAction->execute($reserva, $this->user);

        $this->assertDatabaseHas('reservas_estoque', ['id' => $reserva->id, 'status' => 'liberada']);
        $this->assertSame(1, ReservaEstoque::where('id', $reserva->id)->count());
    }

    public function test_y_local_inativo(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $local->update(['ativo' => false]);

        $this->expectException(ReservaEstoqueInvalidaException::class);
        $this->reservaAction->execute($pacote, $material, $local, 100, null, null, $this->user);
    }

    public function test_z_sem_saldo_fisico_bloqueia(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();

        $this->expectException(SaldoFisicoInsuficienteException::class);
        $this->reservaAction->execute($pacote, $material, $local, 1, null, null, $this->user);
    }

    // =========================================================
    // AA-AH: Modos de rastreabilidade
    // =========================================================

    public function test_aa_quantitativo_sem_lote(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);

        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->assertNull($reserva->unidade_estoque_id);
    }

    public function test_ab_lote_bobina_parcial(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 1000, 'B-001');
        $unidade = UnidadeEstoque::where('material_id', $material->id)->where('codigo_lote', 'B-001')->firstOrFail();

        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, $unidade, null, $this->user);

        $this->assertSame($unidade->id, $reserva->unidade_estoque_id);
        $this->assertEqualsWithDelta(700.0, SaldoReserva::disponivelPorUnidade($unidade), 0.001);
    }

    public function test_ac_dois_lotes(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500, 'B-001');
        $this->entradaPronta($material, $local, 300, 'B-002');
        $unidadeA = UnidadeEstoque::where('codigo_lote', 'B-001')->firstOrFail();
        $unidadeB = UnidadeEstoque::where('codigo_lote', 'B-002')->firstOrFail();

        $this->reservaAction->execute($pacote, $material, $local, 400, $unidadeA, null, $this->user);

        $this->assertEqualsWithDelta(100.0, SaldoReserva::disponivelPorUnidade($unidadeA), 0.001);
        $this->assertEqualsWithDelta(300.0, SaldoReserva::disponivelPorUnidade($unidadeB), 0.001);
    }

    public function test_ad_serializado(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 1, null, 'SN-001');
        $unidade = UnidadeEstoque::where('serial_unico', 'SN-001')->firstOrFail();

        $reserva = $this->reservaAction->execute($pacote, $material, $local, 1, $unidade, null, $this->user);

        $this->assertEqualsWithDelta(1.0, (float) $reserva->quantidade, 0.001);
    }

    public function test_ae_serial_nao_duplica_reserva(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 1, null, 'SN-001');
        $unidade = UnidadeEstoque::where('serial_unico', 'SN-001')->firstOrFail();
        $this->reservaAction->execute($pacote, $material, $local, 1, $unidade, null, $this->user);

        $this->expectException(SaldoFisicoInsuficienteException::class);
        $this->reservaAction->execute($pacote, $material, $local, 1, $unidade, null, $this->user);
    }

    public function test_af_serial_quantidade_diferente_de_1_bloqueada(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 1, null, 'SN-001');
        $unidade = UnidadeEstoque::where('serial_unico', 'SN-001')->firstOrFail();

        $this->expectException(ReservaEstoqueInvalidaException::class);
        $this->reservaAction->execute($pacote, $material, $local, 0.5, $unidade, null, $this->user);
    }

    public function test_ag_mesmo_lote_multiplas_reservas_ate_saldo(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 1000, 'B-001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B-001')->firstOrFail();

        $this->reservaAction->execute($pacote, $material, $local, 400, $unidade, null, $this->user);
        $this->reservaAction->execute($pacote, $material, $local, 400, $unidade, null, $this->user);
        $this->reservaAction->execute($pacote, $material, $local, 190, $unidade, null, $this->user);

        $this->assertEqualsWithDelta(10.0, SaldoReserva::disponivelPorUnidade($unidade), 0.001);

        $this->expectException(SaldoFisicoInsuficienteException::class);
        $this->reservaAction->execute($pacote, $material, $local, 11, $unidade, null, $this->user);
    }

    public function test_ah_saldo_por_unidade_estoque(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 1000, 'B-001');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B-001')->firstOrFail();

        $this->reservaAction->execute($pacote, $material, $local, 250, $unidade, null, $this->user);

        $this->assertEqualsWithDelta(250.0, SaldoReserva::porUnidade($unidade), 0.001);
        $this->assertEqualsWithDelta(1000.0, SaldoEstoque::porUnidade($unidade), 0.001);
    }

    // =========================================================
    // AI-AN: Reserva/Destinação sem Frente
    // =========================================================

    public function test_ai_demanda_sem_rateio_nao_bloqueia_reserva(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->assertNull($reserva->destinacao_planejada_material_id);
        $this->assertEqualsWithDelta(1000.0, ConciliacaoDestinacao::saldoADestinar($pacote->id, $material->id), 0.001);
    }

    public function test_aj_reserva_sem_frente_conforme_decisao_implementada(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);

        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->assertDatabaseHas('reservas_estoque', ['id' => $reserva->id, 'destinacao_planejada_material_id' => null]);
    }

    public function test_ak_pendencia_de_destinacao_calculada(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();
        $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);

        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        // Reserva sem Destinação nunca altera o saldo a destinar (dimensões independentes)
        $this->assertEqualsWithDelta(700.0, ConciliacaoDestinacao::saldoADestinar($pacote->id, $material->id), 0.001);
    }

    public function test_al_nunca_cria_frente_fake(): void
    {
        $arquivos = array_merge(
            glob(base_path('app/Actions/Estoque/*.php')),
            glob(base_path('app/Support/Estoque/*.php')),
        );

        foreach ($arquivos as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            $this->assertStringNotContainsStringIgnoringCase('a definir', $conteudo, "Arquivo {$arquivo} não deve criar Frente fake.");
            $this->assertDoesNotMatchRegularExpression('/FrenteTrabalho::(create|firstOrCreate)/', $conteudo, "Arquivo {$arquivo} não deve criar FrenteTrabalho automaticamente.");
        }
    }

    public function test_am_depois_detalhar_parcialmente(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $reservaOrfa = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $frente = $this->criarFrente();
        $d = $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);
        $reservaComDestinacao = $this->reservaAction->execute($pacote, $material, $local, 100, null, $d, $this->user);

        $this->assertNull($reservaOrfa->fresh()->destinacao_planejada_material_id, 'Reserva órfã nunca é migrada automaticamente.');
        $this->assertSame($d->id, $reservaComDestinacao->destinacao_planejada_material_id);
    }

    public function test_an_depois_detalhar_completamente(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);

        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        // "Completamente" = as Destinações somam a demanda formal INTEIRA
        // (1000), não uma fração dela — diferente do test_am (parcial).
        $dA = $this->destinacaoAction->criar($pacote, $material, $frenteA, 600, $this->user);
        $dB = $this->destinacaoAction->criar($pacote, $material, $frenteB, 400, $this->user);

        $this->reservaAction->execute($pacote, $material, $local, 600, null, $dA, $this->user);
        $this->reservaAction->execute($pacote, $material, $local, 400, null, $dB, $this->user);

        $this->assertEqualsWithDelta(0.0, ConciliacaoDestinacao::saldoADestinar($pacote->id, $material->id), 0.001);
        $this->assertEqualsWithDelta(1000.0, SaldoReserva::porMaterialLocal($material, $local), 0.001);
    }

    // =========================================================
    // AO-AU: UI / Permissão
    // =========================================================

    public function test_ao_usuario_autorizado_cria_destinacao_via_ui(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'planejamento')
            ->call('abrirModalDestinacao', null, $pacote->id, $material->id)
            ->set('destinacaoFrenteId', $frente->id)
            ->set('destinacaoQuantidade', 300)
            ->call('confirmarDestinacao')
            ->assertSet('modalDestinacaoAberto', false);

        $this->assertDatabaseHas('destinacoes_planejadas_material', [
            'item_suprimento_id' => $pacote->id, 'material_id' => $material->id, 'frente_trabalho_id' => $frente->id,
        ]);
    }

    public function test_ap_usuario_autorizado_reserva_via_ui(): void
    {
        $material = $this->criarMaterial(['codigo' => 'UI-RES']);
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'planejamento')
            ->call('abrirModalReserva', null, $pacote->id, $material->id)
            ->set('reservaLocalId', $local->id)
            ->set('reservaQuantidade', 200)
            ->call('confirmarReserva')
            ->assertSet('modalReservaAberto', false);

        $this->assertDatabaseHas('reservas_estoque', ['item_suprimento_id' => $pacote->id, 'material_id' => $material->id, 'local_estoque_id' => $local->id, 'quantidade' => 200]);
    }

    public function test_aq_apenas_ver_nao_muta(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();

        $usuarioLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $usuarioLeitura, Papel::Engenheiro->value);
        $this->actingAs($usuarioLeitura);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'planejamento')
            ->call('abrirModalDestinacao', null, $pacote->id, $material->id)
            ->assertStatus(403);

        $this->assertSame(0, DestinacaoPlanejadaMaterial::count());
    }

    public function test_ar_sem_permissao_bloqueia_reserva(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);

        $usuarioLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $usuarioLeitura, Papel::ClienteLeitura->value);
        $this->actingAs($usuarioLeitura);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalReserva')
            ->assertStatus(403);

        $this->assertSame(0, ReservaEstoque::count());
    }

    public function test_as_cross_obra_com_acesso_as_duas(): void
    {
        [$pacoteDaObraB, $materialDaObraB] = $this->parFormal(1000);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);

        $frenteDaObraB = $this->criarFrente();

        $component = Livewire::test('pages::radar.estoque', ['obra' => $outraObra])
            ->set('abaAtiva', 'planejamento')
            ->call('abrirModalDestinacao', null, $pacoteDaObraB->id, $materialDaObraB->id)
            ->set('destinacaoFrenteId', $frenteDaObraB->id)
            ->set('destinacaoQuantidade', 100);

        $this->expectException(ModelNotFoundException::class);
        $component->call('confirmarDestinacao');
    }

    public function test_at_mensagens_didaticas(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();

        try {
            $this->destinacaoAction->criar($pacote, $material, $frente, 1100, $this->user);
            $this->fail('Deveria ter lançado SaldoDestinacaoInsuficienteException.');
        } catch (SaldoDestinacaoInsuficienteException $e) {
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_au_ui_mostra_fisico_reservado_disponivel(): void
    {
        $material = $this->criarMaterial(['codigo' => 'UI-SALDO']);
        $local = $this->criarLocal(['nome' => 'Local Saldo UI']);
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $this->reservaAction->execute($pacote, $material, $local, 150, null, null, $this->user);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'planejamento')
            ->assertSee('Reservas de Estoque')
            ->assertSee('UI-SALDO')
            ->assertSee('Local Saldo UI');
    }

    // =========================================================
    // AV-AW: Performance
    // =========================================================

    public function test_av_performance_100_pares_conciliacao(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->parFormal(100);
        }

        DB::enableQueryLog();
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'planejamento');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(30, $queries, 'Renderizar a aba de Planejamento com 100 pares não deveria escalar linearmente em queries.');
    }

    public function test_aw_performance_1000_reservas(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 100000);

        for ($i = 0; $i < 1000; $i++) {
            ReservaEstoque::create([
                'obra_id' => $this->obra->id, 'item_suprimento_id' => $pacote->id, 'material_id' => $material->id, 'local_estoque_id' => $local->id,
                'quantidade' => 1, 'status' => StatusReservaEstoque::Ativa->value,
            ]);
        }

        DB::enableQueryLog();
        $disponivel = SaldoReserva::disponivelPorMaterialLocal($material, $local);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEqualsWithDelta(99000.0, $disponivel, 0.001);
        $this->assertLessThanOrEqual(3, $queries);
    }

    // =========================================================
    // Zero Saída / Regressão
    // =========================================================

    public function test_zero_conceitos_de_20_4_criados(): void
    {
        // Ciclo 20, Etapa 20.3 — este guard existia desde a 20.2/
        // 20.2.CORREÇÃO como "zero Saída criada" (a Saída ainda não
        // tinha sido implementada). A 20.3 é exatamente a etapa que
        // implementa Saída de propósito — por isso o padrão
        // 'case Saida'/"'tipo' => 'saida'" SAIU da lista de proibidos, e
        // a asserção final passou a aceitar ['Entrada', 'Saida']. O
        // guard real (nunca antecipar Transferência/Ajuste/
        // Industrialização/Devolução/Estorno/Inventário — conceitos da
        // 20.4+) continua intacto e sem enfraquecimento. Checagem por
        // PADRÃO DE CÓDIGO real (case de enum / valor quotado usado como
        // argumento), nunca substring crua — os próprios docblocks desta
        // e de fases anteriores mencionam esses termos em PROSA,
        // explicando o que ainda não foi implementado (mesma classe de
        // falso positivo já documentada e corrigida em
        // EstoqueFundacaoCorrecaoTest — test_k/test_ac).
        $arquivos = array_merge(
            glob(base_path('app/Actions/Estoque/*.php')),
            glob(base_path('app/Support/Estoque/*.php')),
            glob(base_path('app/Enums/TipoMovimentacaoEstoque.php')),
        );

        $padroesProibidos = [
            '/case\s+Transferencia\b/i',
            '/case\s+Ajuste\b/i',
            '/case\s+Divergencia\b/i',
            '/case\s+Industrializacao\b/i',
            '/case\s+Devolucao\b/i',
            '/case\s+Estorno\b/i',
            '/case\s+Inventario\b/i',
        ];

        foreach ($arquivos as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            foreach ($padroesProibidos as $padrao) {
                $this->assertDoesNotMatchRegularExpression($padrao, $conteudo, "Arquivo {$arquivo} não deve declarar/usar tipo de movimentação além de Entrada/Saída nesta etapa.");
            }
        }

        $this->assertSame(['Entrada', 'Saida'], array_map(fn ($c) => $c->name, \App\Enums\TipoMovimentacaoEstoque::cases()));
    }

    public function test_zero_alteracao_semantica_em_prontidao_e_restricao(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();
        $d = $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);

        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, $d, $this->user);
        $this->liberarAction->execute($reserva, $this->user);

        $this->assertSame(0, DB::table('restricoes')->where('tenant_id', $this->tenant->id)->count());
    }
}
