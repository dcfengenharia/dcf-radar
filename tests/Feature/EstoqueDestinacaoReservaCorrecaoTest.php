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
use App\Exceptions\AlocacaoConsumidaPorDestinacaoPlanejadaException;
use App\Exceptions\DestinacaoPlanejadaImutavelException;
use App\Exceptions\DestinacaoPlanejadaInvalidaException;
use App\Exceptions\ItemTakeOffMaterialImutavelException;
use App\Exceptions\ReservaEstoqueInvalidaException;
use App\Exceptions\SaldoDestinacaoInsuficienteException;
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
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\ConciliacaoDestinacao;
use App\Support\Estoque\PoliticaAssociacaoMaterial;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.2.CORREÇÃO — fecha C1/C2/C3 (+ B1/B2) da auditoria
 * adversarial da 20.2. Cobertura A-Z do pedido de correção.
 */
class EstoqueDestinacaoReservaCorrecaoTest extends TestCase
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

    // ---- helpers (mesma toolkit de EstoqueDestinacaoReservaTest) ----

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

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade, ?string $codigoLote = null): void
    {
        $item = $this->criarItemTakeOffOrfao($material, $this->obra);
        $rpItem = $this->criarRpItemEmitido($item, $quantidade);
        $alocacao = $this->alocarNoPacote($rpItem, $quantidade);

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
    }

    // =========================================================
    // A-C: identidade da Destinação (fecha C1)
    // =========================================================

    public function test_a_troca_de_frente_sem_reserva_e_bloqueada_por_politica_de_identidade_imutavel(): void
    {
        // Decisão de domínio (Seção 5): identidade é imutável DESDE A
        // CRIAÇÃO, não só depois de existir Reserva — mesmo sem nenhuma
        // Reserva vinculada, trocar a Frente é bloqueado. Uma troca de
        // Frente é conceitualmente uma NOVA Destinação, nunca uma edição.
        [$pacote, $material] = $this->parFormal(1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $d = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);

        $this->expectException(DestinacaoPlanejadaImutavelException::class);
        $d->update(['frente_trabalho_id' => $frenteB->id]);
    }

    public function test_b_troca_de_frente_com_reserva_vinculada_bloqueia(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $d = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, $d, $this->user);

        try {
            $d->update(['frente_trabalho_id' => $frenteB->id]);
            $this->fail('Deveria ter lançado DestinacaoPlanejadaImutavelException.');
        } catch (DestinacaoPlanejadaImutavelException $e) {
            $this->assertSame($frenteA->id, $d->fresh()->frente_trabalho_id);
            $this->assertSame($d->id, $reserva->fresh()->destinacao_planejada_material_id);
            $this->assertEqualsWithDelta(500.0, \App\Support\Estoque\SaldoEstoque::porMaterialLocal($material, $local), 0.001);
        }
    }

    public function test_c_campos_de_identidade_da_destinacao_protegidos(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        [$outroPacote, $outroMaterial] = $this->parFormal(500);
        $frente = $this->criarFrente();
        $d = $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);
        $outroTenant = Tenant::factory()->create();

        $bloqueios = 0;
        foreach ([
            ['item_suprimento_id', $outroPacote->id],
            ['material_id', $outroMaterial->id],
            ['tenant_id', $outroTenant->id],
        ] as [$campo, $valor]) {
            try {
                $d->fresh()->update([$campo => $valor]);
            } catch (DestinacaoPlanejadaImutavelException $e) {
                $bloqueios++;
            }
        }

        $this->assertSame(3, $bloqueios, 'item_suprimento_id, material_id e tenant_id deveriam estar todos protegidos.');
        // quantidade_planejada continua livremente alterável pela Action oficial
        $this->destinacaoAction->alterar($d->fresh(), 250);
        $this->assertEqualsWithDelta(250.0, (float) $d->fresh()->quantidade_planejada, 0.001);
    }

    // =========================================================
    // D-I: imutabilidade de campo em Reserva (fecha C2)
    // =========================================================

    public function test_d_reserva_quantidade_update_bloqueia(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->expectException(ReservaEstoqueInvalidaException::class);
        $reserva->update(['quantidade' => 99999]);
    }

    public function test_e_reserva_material_update_bloqueia(): void
    {
        $material = $this->criarMaterial();
        $outroMaterial = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->expectException(ReservaEstoqueInvalidaException::class);
        $reserva->update(['material_id' => $outroMaterial->id]);
    }

    public function test_f_reserva_local_update_bloqueia(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $outroLocal = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->expectException(ReservaEstoqueInvalidaException::class);
        $reserva->update(['local_estoque_id' => $outroLocal->id]);
    }

    public function test_g_reserva_unidade_update_bloqueia(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500, 'B-G1');
        $this->entradaPronta($material, $local, 300, 'B-G2');
        $unidadeA = \App\Models\UnidadeEstoque::where('codigo_lote', 'B-G1')->firstOrFail();
        $unidadeB = \App\Models\UnidadeEstoque::where('codigo_lote', 'B-G2')->firstOrFail();
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, $unidadeA, null, $this->user);

        $this->expectException(ReservaEstoqueInvalidaException::class);
        $reserva->update(['unidade_estoque_id' => $unidadeB->id]);
    }

    public function test_h_reserva_destinacao_update_bloqueia(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();
        $d = $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->expectException(ReservaEstoqueInvalidaException::class);
        $reserva->update(['destinacao_planejada_material_id' => $d->id]);
    }

    public function test_i_reserva_status_direto_bloqueia(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->expectException(ReservaEstoqueInvalidaException::class);
        $reserva->update(['status' => StatusReservaEstoque::Liberada->value]);
    }

    // =========================================================
    // J-L: liberação oficial continua funcionando (não regride)
    // =========================================================

    public function test_j_liberacao_oficial_funciona(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->liberarAction->execute($reserva, $this->user, 'Liberação de teste');

        $fresh = $reserva->fresh();
        $this->assertSame(StatusReservaEstoque::Liberada, $fresh->status);
        $this->assertNotNull($fresh->liberado_em);
    }

    public function test_k_liberacao_dupla_segura(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);
        $this->liberarAction->execute($reserva, $this->user);
        $liberadoEmOriginal = $reserva->fresh()->liberado_em;

        try {
            $this->liberarAction->execute($reserva->fresh(), $this->user);
            $this->fail('Segunda liberação deveria lançar exceção.');
        } catch (ReservaEstoqueInvalidaException $e) {
            $this->assertTrue($liberadoEmOriginal->eq($reserva->fresh()->liberado_em));
        }
    }

    public function test_l_historico_reserva_preservado_apos_liberacao(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);
        $this->liberarAction->execute($reserva, $this->user);

        $this->assertDatabaseHas('reservas_estoque', ['id' => $reserva->id, 'quantidade' => 200, 'material_id' => $material->id]);
        $this->assertSame(1, ReservaEstoque::where('id', $reserva->id)->count());
    }

    // =========================================================
    // M-O: C3 — política de Material conhece Destinação
    // =========================================================

    public function test_m_item_take_off_troca_material_sem_destinacao_permitida(): void
    {
        $material = $this->criarMaterial();
        $outroMaterial = $this->criarMaterial();
        $item = $this->criarItemTakeOffOrfao($material);
        $rpItem = $this->criarRpItemEmitido($item, 100);
        $this->alocarNoPacote($rpItem, 100);

        $this->assertTrue(PoliticaAssociacaoMaterial::podeAlterarMaterial($item));
        $item->update(['material_id' => $outroMaterial->id]);
        $this->assertSame($outroMaterial->id, $item->fresh()->material_id);
    }

    public function test_n_item_take_off_troca_material_com_destinacao_bloqueada(): void
    {
        [$pacote, $material, , $item] = $this->parFormal(1000);
        $outroMaterial = $this->criarMaterial();
        $frente = $this->criarFrente();
        $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);

        $this->assertFalse(PoliticaAssociacaoMaterial::podeAlterarMaterial($item));

        $this->expectException(ItemTakeOffMaterialImutavelException::class);
        $item->update(['material_id' => $outroMaterial->id]);
    }

    public function test_n2_item_take_off_row_permanece_com_material_original_apos_bloqueio(): void
    {
        [$pacote, $material, , $item] = $this->parFormal(1000);
        $outroMaterial = $this->criarMaterial();
        $frente = $this->criarFrente();
        $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);

        try {
            $item->update(['material_id' => $outroMaterial->id]);
        } catch (ItemTakeOffMaterialImutavelException $e) {
            // esperado
        }

        $this->assertSame($material->id, $item->fresh()->material_id);
        $this->assertEqualsWithDelta(1000.0, ConciliacaoDestinacao::quantidadeFormal($pacote->id, $material->id), 0.001);
        $this->assertEqualsWithDelta(300.0, ConciliacaoDestinacao::quantidadeDestinada($pacote->id, $material->id), 0.001);
    }

    public function test_o_multiplos_item_take_off_mesmo_material_congelamento_conservador(): void
    {
        // Seção 17: Pacote P com I1=60 + I2=40 = formal 100; Destinação=50.
        // Trocar I2 (que ISOLADAMENTE ainda deixaria 60 >= 50) é BLOQUEADO
        // mesmo assim — Opção A, congelamento por Pacote, não por saldo
        // aritmético restante.
        $material = $this->criarMaterial();
        $pacote = $this->criarPacoteSimples();

        $item1 = $this->criarItemTakeOffOrfao($material);
        $rpItem1 = $this->criarRpItemEmitido($item1, 60);
        $this->alocarNoPacote($rpItem1, 60, $pacote);

        $item2 = $this->criarItemTakeOffOrfao($material);
        $rpItem2 = $this->criarRpItemEmitido($item2, 40);
        $this->alocarNoPacote($rpItem2, 40, $pacote);

        $frente = $this->criarFrente();
        $this->destinacaoAction->criar($pacote, $material, $frente, 50, $this->user);

        $outroMaterial = $this->criarMaterial();

        $this->assertFalse(PoliticaAssociacaoMaterial::podeAlterarMaterial($item2), 'I2 deveria estar congelado mesmo isoladamente "sobrando saldo".');

        $this->expectException(ItemTakeOffMaterialImutavelException::class);
        $item2->update(['material_id' => $outroMaterial->id]);
    }

    // =========================================================
    // P-R: guard de Alocação já implementado na 20.2, revalidado
    // =========================================================

    public function test_p_formal_nunca_fica_abaixo_do_planejado_reduzindo_alocacao(): void
    {
        [$pacote, $material, $alocacao] = $this->parFormal(1000);
        $frente = $this->criarFrente();
        $this->destinacaoAction->criar($pacote, $material, $frente, 800, $this->user);

        $this->expectException(AlocacaoConsumidaPorDestinacaoPlanejadaException::class);
        (new AlocarRequisicaoAoPacote())->alterarQuantidade($alocacao, 700);
    }

    public function test_q_reduzir_alocacao_ate_o_limite_do_planejado_permitido(): void
    {
        [$pacote, $material, $alocacao] = $this->parFormal(1000);
        $frente = $this->criarFrente();
        $this->destinacaoAction->criar($pacote, $material, $frente, 800, $this->user);

        (new AlocarRequisicaoAoPacote())->alterarQuantidade($alocacao, 800);

        $this->assertEqualsWithDelta(800.0, ConciliacaoDestinacao::quantidadeFormal($pacote->id, $material->id), 0.001);
    }

    public function test_r_remover_alocacao_com_destinacao_vinculada_bloqueia(): void
    {
        [$pacote, $material, $alocacao] = $this->parFormal(1000);
        $frente = $this->criarFrente();
        $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);

        $this->expectException(AlocacaoConsumidaPorDestinacaoPlanejadaException::class);
        (new AlocarRequisicaoAoPacote())->remover($alocacao);
    }

    // =========================================================
    // S-T: B2 — Frente histórica navegável
    // =========================================================

    public function test_s_frente_soft_deleted_continua_navegavel_via_relacao(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();
        $d = $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);
        $nomeOriginal = $frente->nome;

        $frente->delete();

        $dFresh = DestinacaoPlanejadaMaterial::find($d->id);
        $this->assertNotNull($dFresh->frenteTrabalho, 'Relação padrão (sem withTrashed explícito) deveria continuar resolvendo a Frente arquivada.');
        $this->assertSame($nomeOriginal, $dFresh->frenteTrabalho->nome);
    }

    public function test_t_nova_destinacao_em_frente_arquivada_bloqueia(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $frente = $this->criarFrente();
        $frente->delete();

        $this->expectException(DestinacaoPlanejadaInvalidaException::class);
        $this->destinacaoAction->criar($pacote, $material, $frente, 100, $this->user);
    }

    // =========================================================
    // U: mass-update — grep de produção
    // =========================================================

    public function test_u_grep_mass_update_reserva_e_destinacao_fora_do_esperado(): void
    {
        $arquivos = array_merge(
            glob(base_path('app/Actions/Estoque/*.php')),
            glob(base_path('app/Support/Estoque/*.php')),
            glob(base_path('app/Observers/*.php')),
        );

        $ofensores = [];
        foreach ($arquivos as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            if (preg_match('/ReservaEstoque::where\([^)]*\)\s*->\s*update\(/', $conteudo) && ! str_ends_with($arquivo, 'LiberarReservaEstoque.php')) {
                $ofensores[] = "ReservaEstoque mass-update fora de LiberarReservaEstoque: {$arquivo}";
            }
            if (preg_match('/DestinacaoPlanejadaMaterial::where\([^)]*\)\s*->\s*update\(/', $conteudo)) {
                $ofensores[] = "DestinacaoPlanejadaMaterial mass-update: {$arquivo}";
            }
            if (str_contains($conteudo, "DB::table('reservas_estoque')") || str_contains($conteudo, "DB::table('destinacoes_planejadas_material')")) {
                $ofensores[] = "DB::table direto: {$arquivo}";
            }
        }

        $this->assertEmpty($ofensores, 'Encontrado writer de mass-update fora do esperado: ' . implode('; ', $ofensores));

        // Confirma que o writer oficial (LiberarReservaEstoque) de fato
        // usa a forma de mass-update — documentado, não escondido.
        $conteudoLiberar = file_get_contents(base_path('app/Actions/Estoque/LiberarReservaEstoque.php'));
        $this->assertMatchesRegularExpression('/ReservaEstoque::where\([^)]*\)\s*->\s*where\([^)]*\)\s*->\s*update\(/s', $conteudoLiberar);
    }

    // =========================================================
    // V-W: readiness estrutural pra 20.3 (documentação, não implementação)
    // =========================================================

    public function test_v_readiness_consumo_parcial_futuro_nao_exige_mudanca_de_schema(): void
    {
        // Uma futura tabela de Saída referenciaria reserva_estoque_id
        // (nullable, restrictOnDelete) e teria sua PRÓPRIA quantidade —
        // "quanto já foi consumido de uma Reserva" seria SEMPRE derivado
        // (SUM de Saida.quantidade WHERE reserva_estoque_id = X), mesmo
        // padrão de SaldoEstoque/SaldoReserva/ConciliacaoDestinacao já
        // usado em todo o domínio. ReservaEstoque.quantidade permanece o
        // COMPROMISSO TOTAL, nunca decrementado — nenhuma coluna nova
        // necessária nesta tabela pra suportar consumo parcial futuro.
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->criarPacoteSimples();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, null, $this->user);

        $this->assertEqualsWithDelta(300.0, (float) $reserva->quantidade, 0.001, 'quantidade representa o compromisso TOTAL, nunca decrementado por consumo — consumo é dimensão futura, sempre derivada.');
    }

    public function test_w_readiness_saida_com_frente_real_divergente_nao_e_bloqueada_pelo_schema(): void
    {
        // Nada em 20.2/20.2.CORREÇÃO acopla "Frente planejada" (via
        // Destinação) a uma futura "Frente real de aplicação" — uma
        // Reserva vinculada à Destinação da Frente A permanece
        // INTOCADA mesmo que o material tenha sido fisicamente aplicado
        // em outro lugar (Seção 46 do pedido original de 20.2). Este
        // teste confirma que a Reserva/Destinação nunca mudam sozinhas
        // por qualquer evento externo — a garantia estrutural que torna
        // isso possível no futuro.
        [$pacote, $material] = $this->parFormal(1000);
        $frenteA = $this->criarFrente();
        $d = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, $d, $this->user);

        // Simula "tempo passando" sem nenhuma ação de Saída (não
        // implementada) — Destinação/Reserva permanecem exatamente como
        // criadas, prontas para uma futura Saída registrar divergência
        // sem precisar reescrever nada aqui.
        $this->assertSame($frenteA->id, $d->fresh()->frente_trabalho_id);
        $this->assertSame($d->id, $reserva->fresh()->destinacao_planejada_material_id);
        $this->assertTrue($reserva->fresh()->estaAtiva());
    }

    // =========================================================
    // X-Y: cross-obra / cross-tenant dos novos guards
    // =========================================================

    public function test_x_cross_obra_update_direto_ainda_bloqueado_pela_identidade(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);
        $frenteDaOutraObra = $this->criarFrente([], $outraObra);
        $frente = $this->criarFrente();
        $d = $this->destinacaoAction->criar($pacote, $material, $frente, 300, $this->user);

        $this->expectException(DestinacaoPlanejadaImutavelException::class);
        $d->update(['frente_trabalho_id' => $frenteDaOutraObra->id]);
    }

    public function test_y_cross_tenant_reserva_nao_e_encontrada_para_alterar(): void
    {
        [$pacote, $material] = $this->parFormal(1000);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $outroTenant = Tenant::factory()->create();
        $usuarioOutroTenant = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $usuarioOutroTenant, Papel::GerentePlanejamento->value);
        $this->actingAs($usuarioOutroTenant);

        $this->assertNull(ReservaEstoque::find($reserva->id));
    }

    // =========================================================
    // Z: zero Saída criada (reconfirmado pós-correção)
    // =========================================================

    public function test_z_zero_conceitos_de_20_4_criados_pos_correcao(): void
    {
        // Ciclo 20, Etapa 20.3 — este guard existia desde a 20.2.CORREÇÃO
        // como "zero Saída criada" (Saída ainda não tinha sido
        // implementada). A 20.3 é exatamente a etapa que implementa
        // Saída de propósito — por isso 'case Saida' saiu da lista de
        // proibidos, e a asserção final passou a aceitar
        // ['Entrada', 'Saida']. O guard real (nunca antecipar
        // Divergência/Industrialização/Inventário — conceitos da 20.4+)
        // continua intacto, sem enfraquecimento.
        $arquivos = array_merge(
            glob(base_path('app/Actions/Estoque/*.php')),
            glob(base_path('app/Support/Estoque/*.php')),
            glob(base_path('app/Observers/*.php')),
            glob(base_path('app/Enums/TipoMovimentacaoEstoque.php')),
        );

        $padroesProibidos = [
            '/case\s+Divergencia\b/i',
            '/case\s+Industrializacao\b/i',
            '/case\s+Inventario\b/i',
        ];

        foreach ($arquivos as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            foreach ($padroesProibidos as $padrao) {
                $this->assertDoesNotMatchRegularExpression($padrao, $conteudo, "Arquivo {$arquivo} não deve declarar tipo além de Entrada/Saída nesta etapa.");
            }
        }

        $this->assertSame(['Entrada', 'Saida'], array_map(fn ($c) => $c->name, \App\Enums\TipoMovimentacaoEstoque::cases()));
        $this->assertSame(0, DB::table('restricoes')->where('tenant_id', $this->tenant->id)->count());
    }
}
