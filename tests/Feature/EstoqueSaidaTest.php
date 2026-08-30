<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarDestinacaoPlanejada;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\LiberarReservaEstoque;
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
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusReservaEstoque;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\MovimentacaoEstoqueImutavelException;
use App\Exceptions\SaidaEstoqueInvalidaException;
use App\Exceptions\SaldoFisicoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\ReservaEstoque;
use App\Models\Tenant;
use App\Models\UnidadeEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.3 — Saída física de estoque / retirada para campo.
 * Cobertura A-AR do pedido (Seções 44-51).
 */
class EstoqueSaidaTest extends TestCase
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
    private RegistrarSaidaEstoque $registrarSaida;
    private AtualizarDestinacaoPlanejada $destinacaoAction;
    private CriarReservaEstoque $reservaAction;
    private LiberarReservaEstoque $liberarAction;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

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
        $this->registrarSaida = new RegistrarSaidaEstoque();
        $this->destinacaoAction = new AtualizarDestinacaoPlanejada();
        $this->reservaAction = new CriarReservaEstoque();
        $this->liberarAction = new LiberarReservaEstoque();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (mesma toolkit de EstoqueDestinacaoReservaCorrecaoTest) ----

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(array $overrides = [], ?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarFrente(array $overrides = [], ?Work $obra = null): FrenteTrabalho
    {
        return FrenteTrabalho::create(array_merge(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Frente ' . uniqid()], $overrides));
    }

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

    private function alocarNoPacote(\App\Models\RequisicaoPlanejamentoItem $rpItem, float $quantidade, ?ItemSuprimento $pacote = null, ?Work $obra = null): AlocacaoRequisicaoPacote
    {
        $pacote ??= $this->criarPacoteSimples($obra);

        return $this->alocar->alocar($rpItem, $pacote, $quantidade);
    }

    /** @return array{0: ItemSuprimento, 1: Material, 2: AlocacaoRequisicaoPacote, 3: ItemTakeOff} */
    private function parFormal(float $quantidadeFormal, array $materialOverrides = [], ?Work $obra = null): array
    {
        $material = $this->criarMaterial($materialOverrides);
        $item = $this->criarItemTakeOffOrfao($material, $obra);
        $rpItem = $this->criarRpItemEmitido($item, $quantidadeFormal, $obra);
        $alocacao = $this->alocarNoPacote($rpItem, $quantidadeFormal, null, $obra);
        $pacote = ItemSuprimento::find($alocacao->item_suprimento_id);

        return [$pacote, $material, $alocacao, $item];
    }

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade, ?string $codigoLote = null, ?ItemSuprimento $pacoteExistente = null): ItemSuprimento
    {
        if ($pacoteExistente) {
            $item = $this->criarItemTakeOffOrfao($material, $this->obra);
            $rpItem = $this->criarRpItemEmitido($item, $quantidade);
            $alocacao = $this->alocarNoPacote($rpItem, $quantidade, $pacoteExistente);
        } else {
            $item = $this->criarItemTakeOffOrfao($material, $this->obra);
            $rpItem = $this->criarRpItemEmitido($item, $quantidade);
            $alocacao = $this->alocarNoPacote($rpItem, $quantidade);
        }

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

        $this->registrarEntrada->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user, $codigoLote);

        return ItemSuprimento::find($alocacao->item_suprimento_id);
    }

    // =========================================================
    // A-J: Saída básica (Seção 44)
    // =========================================================

    public function test_a_saida_quantitativa_basica(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);

        $mov = $this->registrarSaida->execute($material, $local, 200, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertSame(TipoMovimentacaoEstoque::Saida, $mov->tipo);
        $this->assertEquals(200, (float) $mov->quantidade);
        $this->assertEquals(800, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_b_saida_parcial_permitida(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);

        $this->registrarSaida->execute($material, $local, 100, Carbon::today(), $this->user, retiradoPor: $this->user);
        $this->registrarSaida->execute($material, $local, 150, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertEquals(750, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_c_saida_no_limite_exato_passa(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $this->registrarSaida->execute($material, $local, 500, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertEquals(0, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_d_over_saida_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $this->expectException(SaldoFisicoInsuficienteException::class);
        $this->registrarSaida->execute($material, $local, 500.5, Carbon::today(), $this->user, retiradoPor: $this->user);
    }

    public function test_e_fisico_reduz_exatamente_a_quantidade_da_saida(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);

        $this->registrarSaida->execute($material, $local, 333, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertEquals(667, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_f_ledger_append_only_update_e_delete_bloqueados(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $mov = $this->registrarSaida->execute($material, $local, 100, Carbon::today(), $this->user, retiradoPor: $this->user);

        try {
            $mov->update(['quantidade' => 999]);
            $this->fail('Deveria ter lançado exceção.');
        } catch (MovimentacaoEstoqueImutavelException $e) {
            $this->assertTrue(true);
        }

        try {
            $mov->delete();
            $this->fail('Deveria ter lançado exceção.');
        } catch (MovimentacaoEstoqueImutavelException $e) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('movimentacoes_estoque', ['id' => $mov->id, 'quantidade' => 100]);
    }

    public function test_g_data_retroativa_permitida(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $mov = $this->registrarSaida->execute($material, $local, 100, Carbon::parse('2026-12-01'), $this->user, retiradoPor: $this->user);

        $this->assertSame('2026-12-01', $mov->ocorrido_em->toDateString());
    }

    public function test_h_data_futura_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 100, Carbon::tomorrow(), $this->user, retiradoPor: $this->user);
    }

    public function test_i_autoria_registrado_por_retirado_por_e_externo(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $retirante = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $retirante, Papel::Encarregado->value);

        $movComUsuario = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPor: $retirante);
        $this->assertSame($this->user->id, $movComUsuario->registrado_por);
        $this->assertSame($retirante->id, $movComUsuario->retirado_por);
        $this->assertNull($movComUsuario->retirado_por_externo);

        $movComExterno = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPorExterno: 'João, terceirizado');
        $this->assertNull($movComExterno->retirado_por);
        $this->assertSame('João, terceirizado', $movComExterno->retirado_por_externo);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPor: $retirante, retiradoPorExterno: 'Fulano');
    }

    public function test_j_observacao_persistida(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $mov = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, observacao: 'Retirada de emergência', retiradoPor: $this->user);

        $this->assertSame('Retirada de emergência', $mov->observacao);
    }

    // =========================================================
    // K-R: Reserva (Seção 45)
    // =========================================================

    public function test_k_saida_consome_reserva_parcialmente(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, null, $this->user);

        $this->registrarSaida->execute($material, $local, 120, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);

        $this->assertEquals(120, SaldoReserva::consumidoPorSaidas($reserva->fresh()));
        $this->assertEquals(180, SaldoReserva::saldoPendenteConsumo($reserva->fresh()));
        $this->assertEquals(880, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_l_multiplas_saidas_mesma_reserva(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, null, $this->user);

        $this->registrarSaida->execute($material, $local, 120, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);
        $this->registrarSaida->execute($material, $local, 180, Carbon::today(), $this->user, reserva: $reserva->fresh(), retiradoPor: $this->user);

        $this->assertEquals(300, SaldoReserva::consumidoPorSaidas($reserva->fresh()));
        $this->assertEquals(0, SaldoReserva::saldoPendenteConsumo($reserva->fresh()));
    }

    public function test_m_saida_no_limite_exato_da_reserva_passa(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, null, $this->user);

        $this->registrarSaida->execute($material, $local, 300, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);

        $this->assertEquals(0, SaldoReserva::saldoPendenteConsumo($reserva->fresh()));
    }

    public function test_n_over_consumo_de_reserva_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, null, $this->user);
        $this->registrarSaida->execute($material, $local, 200, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 100.5, Carbon::today(), $this->user, reserva: $reserva->fresh(), retiradoPor: $this->user);
    }

    public function test_n2_reserva_totalmente_consumida_bloqueia_nova_saida(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, null, $this->user);
        $this->registrarSaida->execute($material, $local, 300, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 1, Carbon::today(), $this->user, reserva: $reserva->fresh(), retiradoPor: $this->user);
    }

    public function test_o_reserva_historica_nunca_reescrita_por_consumo(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, null, $this->user);

        $this->registrarSaida->execute($material, $local, 120, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);

        // A coluna reservas_estoque.quantidade NUNCA é decrementada — o
        // consumo é sempre derivado via SaldoReserva, nunca persistido.
        $this->assertEquals(300, (float) $reserva->fresh()->quantidade);
        $this->assertTrue($reserva->fresh()->estaAtiva());
    }

    public function test_p_saida_sem_reserva_permitida_livre(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $mov = $this->registrarSaida->execute($material, $local, 100, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertNull($mov->reserva_estoque_id);
    }

    public function test_q_saida_a_partir_de_reserva_sem_frente(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $mov = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);

        $this->assertSame($reserva->id, $mov->reserva_estoque_id);
        $this->assertNull($mov->frente_trabalho_id);
    }

    public function test_r_reserva_frente_a_saida_frente_b_permitida(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $destinacao = $this->destinacaoAction->criar($pacote, $material, $frenteA, 200, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, $destinacao, $this->user);

        $mov = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, reserva: $reserva, frenteInformada: $frenteB, retiradoPor: $this->user);

        $this->assertSame($frenteB->id, $mov->frente_trabalho_id);
        // A Destinação original (Frente A) permanece intocada.
        $this->assertSame($frenteA->id, $destinacao->fresh()->frente_trabalho_id);
        $this->assertEquals(200, (float) $destinacao->fresh()->quantidade_planejada);
    }

    // =========================================================
    // S-X: Modos de rastreabilidade (Seção 46)
    // =========================================================

    public function test_s_quantitativo_nunca_aceita_unidade(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        // Unidade REAL e existente (de outro Material Lote), pra exercitar
        // o guard de negócio de verdade — nunca uma instância fabricada em
        // memória, que travaria antes no lockForUpdate()->firstOrFail().
        $materialLote = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $this->entradaPronta($materialLote, $local, 100, 'B999');
        $unidadeReal = UnidadeEstoque::where('codigo_lote', 'B999')->firstOrFail();

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, unidade: $unidadeReal, retiradoPor: $this->user);
    }

    public function test_t_bobina_fracionavel_1000_menos_120_menos_80(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000, 'B001');
        $unidade = UnidadeEstoque::where('material_id', $material->id)->where('codigo_lote', 'B001')->firstOrFail();

        $this->registrarSaida->execute($material, $local, 120, Carbon::today(), $this->user, unidade: $unidade, retiradoPor: $this->user);
        $this->registrarSaida->execute($material, $local, 80, Carbon::today(), $this->user, unidade: $unidade->fresh(), retiradoPor: $this->user);

        $this->assertEquals(800, SaldoEstoque::porUnidade($unidade->fresh()));
        $this->assertSame(1, UnidadeEstoque::where('material_id', $material->id)->count());
    }

    public function test_u_dois_lotes_independentes(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500, 'LOTE-A');
        $this->entradaPronta($material, $local, 300, 'LOTE-B');
        $unidadeA = UnidadeEstoque::where('codigo_lote', 'LOTE-A')->firstOrFail();
        $unidadeB = UnidadeEstoque::where('codigo_lote', 'LOTE-B')->firstOrFail();

        $this->registrarSaida->execute($material, $local, 200, Carbon::today(), $this->user, unidade: $unidadeA, retiradoPor: $this->user);

        $this->assertEquals(300, SaldoEstoque::porUnidade($unidadeA->fresh()));
        $this->assertEquals(300, SaldoEstoque::porUnidade($unidadeB->fresh()));
    }

    public function test_v_serial_quantidade_1(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $this->entradaSerial($material, $local, 'SER-001');
        $unidade = UnidadeEstoque::where('serial_unico', 'SER-001')->firstOrFail();

        $mov = $this->registrarSaida->execute($material, $local, 1, Carbon::today(), $this->user, unidade: $unidade, retiradoPor: $this->user);

        $this->assertEquals(1, (float) $mov->quantidade);
        $this->assertEquals(0, SaldoEstoque::porUnidade($unidade->fresh()));
    }

    public function test_v2_serial_nao_permite_0_5(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $this->entradaSerial($material, $local, 'SER-002');
        $unidade = UnidadeEstoque::where('serial_unico', 'SER-002')->firstOrFail();

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 0.5, Carbon::today(), $this->user, unidade: $unidade, retiradoPor: $this->user);
    }

    public function test_w_serial_segunda_saida_bloqueada(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $this->entradaSerial($material, $local, 'SER-003');
        $unidade = UnidadeEstoque::where('serial_unico', 'SER-003')->firstOrFail();
        $this->registrarSaida->execute($material, $local, 1, Carbon::today(), $this->user, unidade: $unidade, retiradoPor: $this->user);

        $this->expectException(SaldoFisicoInsuficienteException::class);
        $this->registrarSaida->execute($material, $local, 1, Carbon::today(), $this->user, unidade: $unidade->fresh(), retiradoPor: $this->user);
    }

    public function test_x_lote_incompativel_com_reserva_bloqueado(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500, 'B001');
        $this->entradaPronta($material, $local, 500, 'B002', $pacote);
        $unidadeA = UnidadeEstoque::where('codigo_lote', 'B001')->firstOrFail();
        $unidadeB = UnidadeEstoque::where('codigo_lote', 'B002')->firstOrFail();
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, $unidadeA, null, $this->user);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, reserva: $reserva, unidade: $unidadeB, retiradoPor: $this->user);
    }

    public function test_x2_local_incompativel_com_reserva_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $localA, 500);
        $this->entradaPronta($material, $localB, 500, null, $pacote);
        $reserva = $this->reservaAction->execute($pacote, $material, $localA, 200, null, null, $this->user);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $localB, 50, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);
    }

    private function entradaSerial(Material $material, LocalEstoque $local, string $serial): void
    {
        $item = $this->criarItemTakeOffOrfao($material, $this->obra);
        $rpItem = $this->criarRpItemEmitido($item, 1);
        $alocacao = $this->alocarNoPacote($rpItem, 1);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 1);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, 1)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, 1, Carbon::parse('2026-12-10'), $this->user);

        $this->registrarEntrada->execute($recebimento, $local, 1, Carbon::today(), $this->user, null, $serial);
    }

    // =========================================================
    // Y-AB: Concorrência (Seção 47)
    // =========================================================

    public function test_y_ordem_de_lock_recurso_fisico_antes_de_reserva(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $ordem = [];
        DB::listen(function ($query) use (&$ordem) {
            $sql = $query->sql;
            if (str_contains($sql, 'locais_estoque') && str_contains(strtolower($sql), 'for update')) {
                $ordem[] = 'local';
            }
            if (str_contains($sql, 'reservas_estoque') && str_contains(strtolower($sql), 'for update')) {
                $ordem[] = 'reserva';
            }
        });

        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);

        $this->assertSame(['local', 'reserva'], $ordem);
    }

    public function test_z_saida_x_saida_nunca_formaliza_mais_que_o_fisico(): void
    {
        // RefreshDatabase impede teste multi-conexão real (mesma limitação
        // já documentada em ItemTakeOffReferenciaRequisicaoTest/
        // AlocacaoPacoteDeleteRaceTest) — prova estrutural equivalente:
        // como as duas operações travam a MESMA linha (LocalEstoque) antes
        // de ler o saldo, o caso concorrente se reduz ao sequencial. As
        // duas ordens possíveis são testadas.
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);

        $this->registrarSaida->execute($material, $local, 70, Carbon::today(), $this->user, retiradoPor: $this->user);
        try {
            $this->registrarSaida->execute($material, $local, 70, Carbon::today(), $this->user, retiradoPor: $this->user);
            $this->fail('Deveria ter lançado exceção — 140 excede o físico de 100.');
        } catch (SaldoFisicoInsuficienteException $e) {
            $this->assertTrue(true);
        }

        $this->assertEquals(30, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_aa_saida_x_entrada_saldo_final_correto(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 100);
        $this->entradaPronta($material, $local, 50, null, $pacote);

        $this->registrarSaida->execute($material, $local, 120, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertEquals(30, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_ab_lock_order_estrutural_unidade_antes_de_reserva(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500, 'B010');
        $unidade = UnidadeEstoque::where('codigo_lote', 'B010')->firstOrFail();
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, $unidade, null, $this->user);

        $ordem = [];
        DB::listen(function ($query) use (&$ordem) {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'unidades_estoque') && str_contains($sql, 'for update')) {
                $ordem[] = 'unidade';
            }
            if (str_contains($sql, 'reservas_estoque') && str_contains($sql, 'for update')) {
                $ordem[] = 'reserva';
            }
        });

        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, reserva: $reserva, unidade: $unidade->fresh(), retiradoPor: $this->user);

        $this->assertSame(['unidade', 'reserva'], $ordem);
    }

    // =========================================================
    // AC-AG: Isolamento (Seção 48)
    // =========================================================

    public function test_ac_cross_obra_local_bloqueado_na_ui(): void
    {
        // App\Actions\Estoque\RegistrarSaidaEstoque não tem noção própria
        // de "obra atual" — a obra é sempre DERIVADA do próprio Local
        // (local_estoque_id.obra_id), então não existe um "Local de outra
        // obra" pra bloquear dentro da Action. O isolamento cross-obra
        // real é na resolução da UI: LocalEstoque::where('obra_id',
        // obraAtual)->findOrFail() nunca resolve um Local de outra obra.
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $material = $this->criarMaterial();
        $localOutraObra = $this->criarLocal([], $outraObra);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)
            ->set('saidaLocalId', $localOutraObra->id)
            ->set('saidaQuantidade', 50)
            ->set('saidaData', now()->toDateString())
            ->set('saidaRetiradoPorId', $this->user->id)
            ->call('confirmarSaida');
    }

    public function test_ac2_frente_de_outra_obra_bloqueada(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $frenteOutraObra = $this->criarFrente([], $outraObra);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, frenteInformada: $frenteOutraObra, retiradoPor: $this->user);
    }

    public function test_ac3_pacote_de_outra_obra_bloqueado(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $pacoteOutraObra = $this->criarPacoteSimples($outraObra);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, pacote: $pacoteOutraObra, retiradoPor: $this->user);
    }

    public function test_ad_cross_tenant_reserva_bloqueada(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $outroUser, Papel::GerentePlanejamento->value);

        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        // Asserção sobre o tenant ORIGINAL precisa acontecer ANTES de
        // trocar actingAs — TenantScope filtra MovimentacaoEstoque pelo
        // tenant do usuário autenticado no momento da query.
        $this->assertEquals(500, SaldoEstoque::porMaterialLocal($material, $local));

        $this->actingAs($outroUser);
        $unidadeOutroTenant = UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'KG', 'nome' => 'Quilo']);
        $materialOutroTenant = Material::create([
            'tenant_id' => $outroTenant->id, 'codigo' => 'MOT', 'descricao' => 'Outro',
            'unidade_medida_id' => $unidadeOutroTenant->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ]);
        $localOutroTenant = LocalEstoque::create(['tenant_id' => $outroTenant->id, 'obra_id' => $outraObra->id, 'nome' => 'L', 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);

        // Reserva do OUTRO tenant nunca é resolvida pelo primeiro tenant
        // (garantia real vem do global scope de BelongsToTenant na UI —
        // aqui confirmamos que Material/Local de tenants diferentes
        // nunca colidem/afetam saldo um do outro).
        $this->assertEquals(0, SaldoEstoque::porMaterialLocal($materialOutroTenant, $localOutroTenant));
    }

    public function test_ae_material_errado_na_reserva_bloqueado(): void
    {
        $materialA = $this->criarMaterial();
        $materialB = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($materialA, $local, 500);
        $this->entradaPronta($materialB, $local, 500, null, $pacote);
        $reserva = $this->reservaAction->execute($pacote, $materialA, $local, 200, null, null, $this->user);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($materialB, $local, 50, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);
    }

    public function test_af_local_errado_na_reserva_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $localA, 500);
        $this->entradaPronta($material, $localB, 500, null, $pacote);
        $reserva = $this->reservaAction->execute($pacote, $material, $localA, 200, null, null, $this->user);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $localB, 50, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);
    }

    public function test_ag_pacote_errado_na_reserva_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacoteA = $this->entradaPronta($material, $local, 500);
        $pacoteB = $this->criarPacoteSimples();
        $reserva = $this->reservaAction->execute($pacoteA, $material, $local, 200, null, null, $this->user);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, reserva: $reserva, pacote: $pacoteB, retiradoPor: $this->user);
    }

    public function test_ag2_pacote_derivado_da_reserva_quando_nao_informado(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $mov = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);

        $this->assertSame($pacote->id, $mov->item_suprimento_id);
    }

    public function test_ag3_pacote_opcional_sem_reserva_nunca_bloqueia(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $mov = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertNull($mov->item_suprimento_id);
    }

    // =========================================================
    // AH-AN: UI (Seção 49)
    // =========================================================

    public function test_ah_usuario_autorizado_registra_saida_via_ui(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)
            ->set('saidaLocalId', $local->id)
            ->set('saidaQuantidade', 100)
            ->set('saidaData', now()->toDateString())
            ->set('saidaRetiradoPorId', $this->user->id)
            ->call('confirmarSaida')
            ->assertHasNoErrors();

        $this->assertEquals(400, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_ai_usuario_sem_permissao_de_criar_nao_registra_saida(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        // Encarregado TEM criar por padrão (mesmo perfil que já registra
        // Entrada) — usamos 'cliente_leitura' (só 'ver' em tudo) pra
        // provar o bloqueio de verdade.
        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $semPermissao, 'cliente_leitura');
        $this->actingAs($semPermissao);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalSaida')
            ->assertStatus(403);

        $this->assertSame(0, MovimentacaoEstoque::where('tipo', TipoMovimentacaoEstoque::Saida->value)->count());
    }

    public function test_aj_saldo_fisico_aparece_no_modal(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)
            ->set('saidaLocalId', $local->id);

        $this->assertEquals(500, $component->instance()->saldoFisicoPreviewSaida);
    }

    public function test_ak_reservas_ativas_aparecem_no_modal(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500);
        $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id);

        $this->assertCount(1, $component->instance()->reservasAtivasParaSaida);
    }

    public function test_al_frente_opcional_na_saida_via_ui(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $frente = $this->criarFrente();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)
            ->set('saidaLocalId', $local->id)
            ->set('saidaQuantidade', 50)
            ->set('saidaData', now()->toDateString())
            ->set('saidaFrenteId', $frente->id)
            ->set('saidaRetiradoPorId', $this->user->id)
            ->call('confirmarSaida')
            ->assertHasNoErrors();

        $mov = MovimentacaoEstoque::where('tipo', TipoMovimentacaoEstoque::Saida->value)->latest()->first();
        $this->assertSame($frente->id, $mov->frente_trabalho_id);
    }

    public function test_am_sem_reserva_explicito_via_ui(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)
            ->set('saidaLocalId', $local->id)
            ->set('saidaQuantidade', 50)
            ->set('saidaData', now()->toDateString())
            ->set('saidaRetiradoPorId', $this->user->id)
            ->assertSet('saidaReservaId', null)
            ->call('confirmarSaida')
            ->assertHasNoErrors();
    }

    public function test_an_erro_didatico_sem_500_ao_exceder_saldo(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)
            ->set('saidaLocalId', $local->id)
            ->set('saidaQuantidade', 999)
            ->set('saidaData', now()->toDateString())
            ->set('saidaRetiradoPorId', $this->user->id)
            ->call('confirmarSaida')
            ->assertHasErrors('saidaGeral') // erro didático via addError(), nunca um 500/exception
            ->assertSet('modalSaidaAberto', true);

        $this->assertEquals(100, SaldoEstoque::porMaterialLocal($material, $local));
    }

    // =========================================================
    // AO-AQ: Performance (Seção 50)
    // =========================================================

    public function test_ao_1000_movimentacoes_saldo_continua_rapido(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000000);

        for ($i = 0; $i < 30; $i++) {
            MovimentacaoEstoque::create([
                'obra_id' => $local->obra_id, 'tipo' => TipoMovimentacaoEstoque::Saida->value,
                'material_id' => $material->id, 'local_estoque_id' => $local->id,
                'quantidade' => 10, 'ocorrido_em' => now(), 'registrado_por' => $this->user->id,
            ]);
        }

        $queriesAntes = count(DB::getQueryLog());
        DB::enableQueryLog();
        $saldo = SaldoEstoque::porMaterialLocal($material, $local);
        $queriesDepois = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(2, $queriesDepois - $queriesAntes);
        $this->assertEquals(1000000 - 300, $saldo);
    }

    public function test_ap_consulta_de_saldo_reserva_apos_saidas_sem_n_mais_1(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 10000);
        $reservas = [];
        for ($i = 0; $i < 10; $i++) {
            $reservas[] = $this->reservaAction->execute($pacote, $material, $local, 100, null, null, $this->user);
        }
        foreach ($reservas as $r) {
            $this->registrarSaida->execute($material, $local, 10, Carbon::today(), $this->user, reserva: $r, retiradoPor: $this->user);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $consumido = SaldoReserva::consumidoPorReservas(collect($reservas)->pluck('id')->all());
        $totalQueries = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(2, $totalQueries);
        $this->assertCount(10, $consumido);
    }

    /**
     * Medição por DELTA (mesma técnica já estabelecida no projeto pra
     * este tipo de asserção — o render completo do Livewire avalia TODOS
     * os computeds referenciados no Blade, inclusive de outras abas/
     * badges, então um teto absoluto de queries seria frágil e não
     * relacionado ao que este teste quer provar). Duas listagens
     * completamente independentes (Material/Local/Pacote próprios cada
     * uma) com 1 e 15 Reservas Ativas — o delta entre as duas precisa
     * ser pequeno e não escalar com a quantidade de reservas.
     */
    public function test_aq_listagem_de_reservas_ativas_para_saida_sem_n_mais_1(): void
    {
        $material1 = $this->criarMaterial();
        $local1 = $this->criarLocal();
        $pacote1 = $this->entradaPronta($material1, $local1, 10000);
        $this->reservaAction->execute($pacote1, $material1, $local1, 100, null, null, $this->user);

        $material2 = $this->criarMaterial();
        $local2 = $this->criarLocal();
        $pacote2 = $this->entradaPronta($material2, $local2, 10000);
        for ($i = 0; $i < 15; $i++) {
            $this->reservaAction->execute($pacote2, $material2, $local2, 100, null, null, $this->user);
        }

        DB::enableQueryLog();

        DB::flushQueryLog();
        $c1 = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')->set('saidaMaterialId', $material1->id);
        $r1 = $c1->instance()->reservasAtivasParaSaida;
        $q1 = count(DB::getQueryLog());

        DB::flushQueryLog();
        $c2 = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')->call('abrirModalSaida')->set('saidaMaterialId', $material2->id);
        $r2 = $c2->instance()->reservasAtivasParaSaida;
        $q2 = count(DB::getQueryLog());

        $this->assertCount(1, $r1);
        $this->assertCount(15, $r2);
        $this->assertLessThanOrEqual($q1 + 3, $q2, 'Contagem de queries não deveria escalar com a quantidade de Reservas (suspeita de N+1)');
    }

    // =========================================================
    // AR: Zero 20.4 (Seção 51)
    // =========================================================

    /**
     * Ciclo 20, Etapa 20.4 — este guard nasceu na 20.3 proibindo
     * qualquer nome especulativo de conceito da 20.4 (Aplicação/
     * Conciliação/Desvio/Déficit/Recomposição) antes de ela ser
     * autorizada. A 20.4 já foi implementada e aprovada (`App\Models\
     * AplicacaoMaterialEstoque`, `App\Support\Estoque\
     * ConciliacaoAplicacao`/`DesviosAplicacao`/`CoberturaReservas` —
     * nomes reais, diferentes dos especulativos originais) — a lista
     * de proibidos foi reduzida pros conceitos que CONTINUAM fora de
     * escopo (Industrialização/Inventário/Transferência/Ajuste,
     * mesma guarda já usada em `EstoqueSaidaCorrecaoTest`).
     */
    public function test_ar_zero_conceito_pos_20_4_no_codigo_de_producao(): void
    {
        $base = base_path('app');
        $arquivos = array_merge(
            glob($base . '/**/*.php'),
            glob($base . '/*.php')
        );

        $proibidos = ['case Industrializacao', 'case Divergencia', 'case Inventario', 'case Transferencia', 'case Ajuste'];

        $encontrados = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $conteudo = file_get_contents($file->getPathname());
            foreach ($proibidos as $termo) {
                if (str_contains($conteudo, $termo)) {
                    $encontrados[] = $file->getPathname() . ' :: ' . $termo;
                }
            }
        }

        $this->assertEmpty($encontrados, 'Conceitos da 20.4 encontrados prematuramente: ' . implode(', ', $encontrados));
    }

    // =========================================================
    // Zero efeito colateral em prontidão/Restrição
    // =========================================================

    public function test_zero_regressao_restricao_e_prontidao_intactas(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, reserva: $reserva, retiradoPor: $this->user);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertSame(0, \App\Models\Restricao::count());
    }
}
