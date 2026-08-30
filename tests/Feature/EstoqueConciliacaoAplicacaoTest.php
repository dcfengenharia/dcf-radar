<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarAplicacaoMaterialEstoque;
use App\Actions\Estoque\AtualizarDestinacaoPlanejada;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\RegistrarAplicacaoMaterialEstoque;
use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Actions\Estoque\RegistrarSaidaEstoque;
use App\Actions\Estoque\RemoverAplicacaoMaterialEstoque;
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
use App\Enums\TipoLocalEstoque;
use App\Exceptions\AplicacaoConciliacaoFechadaException;
use App\Exceptions\AplicacaoConciliacaoInvalidaException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\AplicacaoMaterialEstoque;
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
use App\Models\Tenant;
use App\Models\UnidadeEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\CoberturaReservas;
use App\Support\Estoque\ConciliacaoAplicacao;
use App\Support\Estoque\DesviosAplicacao;
use App\Support\Estoque\PoliticaConciliacaoAplicacao;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.4 — Conciliação da Aplicação Real + Desvio entre
 * Frentes + Déficit/Recomposição. Cobertura A-AG do pedido (Seções
 * 45-49) — UI (AH-AN) e performance (AO-AQ) ficam em
 * `EstoqueConciliacaoAplicacaoUiTest`.
 */
class EstoqueConciliacaoAplicacaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    private RegistrarEntradaEstoque $registrarEntrada;
    private RegistrarSaidaEstoque $registrarSaida;
    private RegistrarAplicacaoMaterialEstoque $registrarAplicacao;
    private AtualizarAplicacaoMaterialEstoque $atualizarAplicacao;
    private RemoverAplicacaoMaterialEstoque $removerAplicacao;
    private AtualizarDestinacaoPlanejada $destinacaoAction;
    private CriarReservaEstoque $reservaAction;
    private CriarRequisicaoPlanejamento $criarRp;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizarRp;
    private EmitirRequisicaoPlanejamento $emitirRp;
    private AlocarRequisicaoAoPacote $alocar;

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

        $this->registrarEntrada = new RegistrarEntradaEstoque();
        $this->registrarSaida = new RegistrarSaidaEstoque();
        $this->registrarAplicacao = new RegistrarAplicacaoMaterialEstoque();
        $this->atualizarAplicacao = new AtualizarAplicacaoMaterialEstoque();
        $this->removerAplicacao = new RemoverAplicacaoMaterialEstoque();
        $this->destinacaoAction = new AtualizarDestinacaoPlanejada();
        $this->reservaAction = new CriarReservaEstoque();
        $this->criarRp = new CriarRequisicaoPlanejamento();
        $this->atualizarRp = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitirRp = new EmitirRequisicaoPlanejamento();
        $this->alocar = new AlocarRequisicaoAoPacote();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (mesma toolkit de EstoqueSaidaTest/EstoqueSaidaCorrecaoTest) ----

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

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade, ?string $codigoLote = null, ?ItemSuprimento $pacoteExistente = null, ?Work $obra = null, ?string $serialUnico = null): ItemSuprimento
    {
        $obra ??= $this->obra;
        $item = $this->criarItemTakeOffOrfao($material, $obra);
        $rpItem = $this->criarRpItemEmitido($item, $quantidade, $obra);
        $alocacao = $this->alocarNoPacote($rpItem, $quantidade, $pacoteExistente, $obra);

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
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);

        $this->registrarEntrada->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user, $codigoLote, $serialUnico);

        return ItemSuprimento::find($alocacao->item_suprimento_id);
    }

    private function saidaSimples(Material $material, LocalEstoque $local, float $quantidade, ?ItemSuprimento $pacote = null, ?UnidadeEstoque $unidade = null, ?\App\Models\ReservaEstoque $reserva = null): MovimentacaoEstoque
    {
        return $this->registrarSaida->execute(
            $material, $local, $quantidade, Carbon::today(), $this->user,
            reserva: $reserva, pacote: $pacote, unidade: $unidade, retiradoPor: $this->user,
        );
    }

    // =========================================================
    // A-J: Aplicação básica (Seção 45)
    // =========================================================

    public function test_a_saida_sem_aplicacao_fica_pendente_total(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);

        $this->assertEquals(500, PoliticaConciliacaoAplicacao::pendente($saida));
        $this->assertFalse(PoliticaConciliacaoAplicacao::saidaEstaFechada($saida));
    }

    public function test_b_aplicacao_parcial_reduz_pendencia(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();

        $this->registrarAplicacao->execute($saida, $frente, 200, Carbon::today(), $this->user);

        $this->assertEquals(300, PoliticaConciliacaoAplicacao::pendente($saida->fresh()));
    }

    public function test_c_multiplas_frentes(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $a = $this->criarFrente();
        $b = $this->criarFrente();
        $c = $this->criarFrente();

        $this->registrarAplicacao->execute($saida, $a, 200, Carbon::today(), $this->user);
        $this->registrarAplicacao->execute($saida, $b, 150, Carbon::today(), $this->user);
        $this->registrarAplicacao->execute($saida, $c, 100, Carbon::today(), $this->user);

        $this->assertEquals(50, PoliticaConciliacaoAplicacao::pendente($saida->fresh()));
        $this->assertCount(3, AplicacaoMaterialEstoque::where('movimentacao_estoque_id', $saida->id)->get());
    }

    public function test_d_completar_100_por_cento_fecha_conciliacao(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();

        $this->registrarAplicacao->execute($saida, $frente, 500, Carbon::today(), $this->user);

        $this->assertEquals(0, PoliticaConciliacaoAplicacao::pendente($saida->fresh()));
        $this->assertTrue(PoliticaConciliacaoAplicacao::saidaEstaFechada($saida->fresh()));
    }

    public function test_e_over_aplicacao_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();
        $this->registrarAplicacao->execute($saida, $frente, 300, Carbon::today(), $this->user);

        $this->expectException(AplicacaoConciliacaoInvalidaException::class);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 201, Carbon::today(), $this->user);
    }

    public function test_f_lock_trava_saida_antes_do_sum_ordem_estrutural(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'for update')) {
                $queries[] = $query->sql;
            }
        });

        $this->registrarAplicacao->execute($saida, $frente, 100, Carbon::today(), $this->user);

        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('movimentacoes_estoque', $queries[0]);
    }

    public function test_g_serial_exige_frente_unica_quantidade_1(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1, null, null, null, 'SERIAL-001');
        $unidade = UnidadeEstoque::where('material_id', $material->id)->first();
        $saida = $this->saidaSimples($material, $local, 1, unidade: $unidade);
        $frente = $this->criarFrente();

        $aplicacao = $this->registrarAplicacao->execute($saida, $frente, 1, Carbon::today(), $this->user);

        $this->assertEquals(1, (float) $aplicacao->quantidade);
        $this->assertTrue(PoliticaConciliacaoAplicacao::saidaEstaFechada($saida->fresh()));
    }

    public function test_h_lote_bobina_fracionavel_em_multiplas_frentes(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500, 'BOBINA-001');
        $unidade = UnidadeEstoque::where('material_id', $material->id)->first();
        $saida = $this->saidaSimples($material, $local, 500, unidade: $unidade);

        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 200, Carbon::today(), $this->user);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 150, Carbon::today(), $this->user);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 100, Carbon::today(), $this->user);

        $this->assertEquals(50, PoliticaConciliacaoAplicacao::pendente($saida->fresh()));
        // Bobina física continua a mesma identidade histórica.
        $this->assertEquals(0, SaldoEstoque::porUnidade($unidade->fresh()));
    }

    public function test_i_quantitativo_aceita_aplicacao_normal(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);

        $aplicacao = $this->registrarAplicacao->execute($saida, $this->criarFrente(), 500, Carbon::today(), $this->user);

        $this->assertEquals(500, (float) $aplicacao->quantidade);
    }

    // ---- J: histórico/correção (decisão do usuário — Seção 12) ----

    public function test_j1_editar_enquanto_parcial_e_permitido(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();
        $aplicacao = $this->registrarAplicacao->execute($saida, $frente, 200, Carbon::today(), $this->user);

        $atualizada = $this->atualizarAplicacao->execute($aplicacao, $frente, 250, Carbon::today(), $this->user);

        $this->assertEquals(250, (float) $atualizada->quantidade);
        $this->assertSame($this->user->id, $atualizada->atualizado_por);
        $this->assertEquals(250, PoliticaConciliacaoAplicacao::pendente($saida->fresh()));
    }

    public function test_j2_excluir_enquanto_parcial_e_permitido(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $aplicacao = $this->registrarAplicacao->execute($saida, $this->criarFrente(), 200, Carbon::today(), $this->user);

        $this->removerAplicacao->execute($aplicacao);

        $this->assertDatabaseMissing('aplicacoes_material_estoque', ['id' => $aplicacao->id]);
        $this->assertEquals(500, PoliticaConciliacaoAplicacao::pendente($saida->fresh()));
    }

    public function test_j3_over_aplicacao_apos_edicao_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $a1 = $this->registrarAplicacao->execute($saida, $this->criarFrente(), 200, Carbon::today(), $this->user);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 250, Carbon::today(), $this->user);

        $this->expectException(AplicacaoConciliacaoInvalidaException::class);
        $this->atualizarAplicacao->execute($a1, $this->criarFrente(), 300, Carbon::today(), $this->user);
    }

    public function test_j4_fechamento_em_100_por_cento(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 500, Carbon::today(), $this->user);

        $this->assertTrue(PoliticaConciliacaoAplicacao::saidaEstaFechada($saida->fresh()));
    }

    public function test_j5_editar_apos_fechamento_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();
        $aplicacao = $this->registrarAplicacao->execute($saida, $frente, 500, Carbon::today(), $this->user);

        $this->expectException(AplicacaoConciliacaoFechadaException::class);
        $this->atualizarAplicacao->execute($aplicacao, $frente, 400, Carbon::today(), $this->user);
    }

    public function test_j6_excluir_apos_fechamento_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $aplicacao = $this->registrarAplicacao->execute($saida, $this->criarFrente(), 500, Carbon::today(), $this->user);

        $this->expectException(AplicacaoConciliacaoFechadaException::class);
        $this->removerAplicacao->execute($aplicacao);

        $this->assertDatabaseHas('aplicacoes_material_estoque', ['id' => $aplicacao->id]);
    }

    public function test_j7_nova_aplicacao_apos_fechamento_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 500, Carbon::today(), $this->user);

        $this->expectException(AplicacaoConciliacaoFechadaException::class);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 1, Carbon::today(), $this->user);
    }

    public function test_j8_observer_bloqueia_write_direto_bypassando_action(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 500, Carbon::today(), $this->user);

        $this->expectException(AplicacaoConciliacaoFechadaException::class);
        AplicacaoMaterialEstoque::create([
            'obra_id' => $this->obra->id,
            'movimentacao_estoque_id' => $saida->id,
            'frente_trabalho_id' => $this->criarFrente()->id,
            'quantidade' => 1,
            'aplicado_em' => Carbon::today(),
            'registrado_por' => $this->user->id,
        ]);
    }

    // ---- guardas adicionais: frente/data/só-Saída ----

    public function test_j9_frente_soft_deletada_bloqueia_nova_aplicacao(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();
        $frente->delete();

        $this->expectException(AplicacaoConciliacaoInvalidaException::class);
        $this->registrarAplicacao->execute($saida, $frente, 100, Carbon::today(), $this->user);
    }

    public function test_j10_historico_sobrevive_a_frente_arquivada(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();
        $aplicacao = $this->registrarAplicacao->execute($saida, $frente, 200, Carbon::today(), $this->user);
        $frente->delete();

        $this->assertNotNull($aplicacao->fresh()->frenteTrabalho);
        $this->assertSame($frente->id, $aplicacao->fresh()->frenteTrabalho->id);
    }

    public function test_j11_aplicado_em_anterior_a_saida_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);

        $this->expectException(AplicacaoConciliacaoInvalidaException::class);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 100, Carbon::yesterday(), $this->user);
    }

    public function test_j12_aplicado_em_no_mesmo_dia_da_saida_permitido(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);

        $aplicacao = $this->registrarAplicacao->execute($saida, $this->criarFrente(), 100, Carbon::today(), $this->user);

        $this->assertNotNull($aplicacao->id);
    }

    public function test_j13_aplicado_em_futuro_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);

        $this->expectException(AplicacaoConciliacaoInvalidaException::class);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 100, Carbon::tomorrow(), $this->user);
    }

    public function test_j14_conciliacao_tardia_dias_depois_permitida(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);

        Carbon::setTestNow(Carbon::parse('2026-12-27'));
        $aplicacao = $this->registrarAplicacao->execute($saida, $this->criarFrente(), 500, Carbon::parse('2026-12-25'), $this->user);

        $this->assertSame('2026-12-25', $aplicacao->aplicado_em->toDateString());
    }

    public function test_j15_aplicacao_so_permitida_contra_saida_nunca_entrada(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $entrada = MovimentacaoEstoque::where('material_id', $material->id)->where('local_estoque_id', $local->id)->first();

        $this->expectException(AplicacaoConciliacaoInvalidaException::class);
        $this->registrarAplicacao->execute($entrada, $this->criarFrente(), 10, Carbon::today(), $this->user);
    }

    // =========================================================
    // K-O: Planejado × Real (Seção 46)
    // =========================================================

    private function planejarDestinacao(ItemSuprimento $pacote, Material $material, FrenteTrabalho $frente, float $quantidade): void
    {
        $this->destinacaoAction->criar($pacote, $material, $frente, $quantidade, $this->user);
    }

    public function test_k_planejado_e_real_iguais_delta_zero(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $this->planejarDestinacao($pacote, $material, $frenteA, 300);

        $saida = $this->saidaSimples($material, $local, 300, $pacote);
        $this->registrarAplicacao->execute($saida, $frenteA, 300, Carbon::today(), $this->user, $pacote);

        $linhas = ConciliacaoAplicacao::porPacoteMaterialFrente($pacote->id, $material->id);
        $linhaA = $linhas->firstWhere('frente_trabalho_id', $frenteA->id);

        $this->assertEquals(300, $linhaA['planejado']);
        $this->assertEquals(300, $linhaA['aplicado']);
        $this->assertEquals(0, $linhaA['delta']);
    }

    public function test_l_planejado_maior_que_real_delta_negativo(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $this->planejarDestinacao($pacote, $material, $frenteA, 300);

        $saida = $this->saidaSimples($material, $local, 100, $pacote);
        $this->registrarAplicacao->execute($saida, $frenteA, 100, Carbon::today(), $this->user, $pacote);

        $linhas = ConciliacaoAplicacao::porPacoteMaterialFrente($pacote->id, $material->id);
        $linhaA = $linhas->firstWhere('frente_trabalho_id', $frenteA->id);

        $this->assertEquals(300, $linhaA['planejado']);
        $this->assertEquals(100, $linhaA['aplicado']);
        $this->assertEquals(-200, $linhaA['delta']);
    }

    public function test_m_planejado_a_real_b_nunca_afirma_causalidade(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $this->planejarDestinacao($pacote, $material, $frenteA, 300);

        $saida = $this->saidaSimples($material, $local, 200, $pacote);
        $this->registrarAplicacao->execute($saida, $frenteB, 200, Carbon::today(), $this->user, $pacote);

        $linhas = ConciliacaoAplicacao::porPacoteMaterialFrente($pacote->id, $material->id);
        $linhaA = $linhas->firstWhere('frente_trabalho_id', $frenteA->id);
        $linhaB = $linhas->firstWhere('frente_trabalho_id', $frenteB->id);

        $this->assertEquals(300, $linhaA['planejado']);
        $this->assertEquals(0, $linhaA['aplicado']);
        $this->assertEquals(-300, $linhaA['delta']);
        $this->assertEquals(0, $linhaB['planejado']);
        $this->assertEquals(200, $linhaB['aplicado']);
        $this->assertEquals(200, $linhaB['delta']);
    }

    public function test_n_planejado_ab_real_abc(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $frenteC = $this->criarFrente();
        $this->planejarDestinacao($pacote, $material, $frenteA, 300);
        $this->planejarDestinacao($pacote, $material, $frenteB, 200);

        $saida = $this->saidaSimples($material, $local, 450, $pacote);
        $this->registrarAplicacao->execute($saida, $frenteA, 100, Carbon::today(), $this->user, $pacote);
        $this->registrarAplicacao->execute($saida, $frenteB, 250, Carbon::today(), $this->user, $pacote);
        $this->registrarAplicacao->execute($saida, $frenteC, 100, Carbon::today(), $this->user, $pacote);

        $linhas = ConciliacaoAplicacao::porPacoteMaterialFrente($pacote->id, $material->id);
        $this->assertEquals(-200, $linhas->firstWhere('frente_trabalho_id', $frenteA->id)['delta']);
        $this->assertEquals(50, $linhas->firstWhere('frente_trabalho_id', $frenteB->id)['delta']);
        $this->assertEquals(100, $linhas->firstWhere('frente_trabalho_id', $frenteC->id)['delta']);
    }

    public function test_o_acumulado_de_varias_saidas(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $this->planejarDestinacao($pacote, $material, $frenteA, 300);

        $saida1 = $this->saidaSimples($material, $local, 100, $pacote);
        $this->registrarAplicacao->execute($saida1, $frenteA, 100, Carbon::today(), $this->user, $pacote);
        $saida2 = $this->saidaSimples($material, $local, 150, $pacote);
        $this->registrarAplicacao->execute($saida2, $frenteA, 150, Carbon::today(), $this->user, $pacote);

        $linhas = ConciliacaoAplicacao::porPacoteMaterialFrente($pacote->id, $material->id);
        $this->assertEquals(250, $linhas->firstWhere('frente_trabalho_id', $frenteA->id)['aplicado']);
    }

    // =========================================================
    // P-U: Reserva / Desvio direto (Seção 47)
    // =========================================================

    public function test_p_reserva_a_saida_aplicacao_a_e_aderente(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $destinacaoA = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, $destinacaoA, $this->user);

        $saida = $this->saidaSimples($material, $local, 300, null, null, $reserva);
        $this->registrarAplicacao->execute($saida, $frenteA, 300, Carbon::today(), $this->user);

        $desvio = DesviosAplicacao::porSaida($saida->fresh());
        $this->assertTrue($desvio['tem_frente_planejada']);
        $this->assertEquals(300, $desvio['aderente']);
        $this->assertEquals(0, $desvio['total_desviado']);
    }

    public function test_q_reserva_a_aplicacao_b_e_desvio_direto(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $destinacaoA = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, $destinacaoA, $this->user);

        $saida = $this->saidaSimples($material, $local, 300, null, null, $reserva);
        $this->registrarAplicacao->execute($saida, $frenteB, 300, Carbon::today(), $this->user);

        $desvio = DesviosAplicacao::porSaida($saida->fresh());
        $this->assertEquals(0, $desvio['aderente']);
        $this->assertEquals(300, $desvio['total_desviado']);
        $this->assertEquals(300, (float) $desvio['desviado_por_frente'][$frenteB->id]);
    }

    public function test_r_uma_saida_reserva_a_dividida_entre_a_e_b(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $destinacaoA = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, $destinacaoA, $this->user);

        $saida = $this->saidaSimples($material, $local, 300, null, null, $reserva);
        $this->registrarAplicacao->execute($saida, $frenteA, 100, Carbon::today(), $this->user);
        $this->registrarAplicacao->execute($saida, $frenteB, 200, Carbon::today(), $this->user);

        $desvio = DesviosAplicacao::porSaida($saida->fresh());
        $this->assertEquals(100, $desvio['aderente']);
        $this->assertEquals(200, $desvio['total_desviado']);
    }

    public function test_s_multiplas_saidas_mesma_reserva(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $destinacaoA = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, $destinacaoA, $this->user);

        $saida1 = $this->saidaSimples($material, $local, 100, null, null, $reserva);
        $this->registrarAplicacao->execute($saida1, $frenteA, 100, Carbon::today(), $this->user);
        $saida2 = $this->saidaSimples($material, $local, 200, null, null, $reserva);
        $this->registrarAplicacao->execute($saida2, $this->criarFrente(), 200, Carbon::today(), $this->user);

        $desvio1 = DesviosAplicacao::porSaida($saida1->fresh());
        $desvio2 = DesviosAplicacao::porSaida($saida2->fresh());
        $this->assertEquals(100, $desvio1['aderente']);
        $this->assertEquals(0, $desvio2['aderente']);
        $this->assertEquals(200, $desvio2['total_desviado']);
    }

    public function test_t_reserva_sem_frente_nao_calcula_desvio(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, null, $this->user);

        $saida = $this->saidaSimples($material, $local, 300, null, null, $reserva);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 300, Carbon::today(), $this->user);

        $desvio = DesviosAplicacao::porSaida($saida->fresh());
        $this->assertFalse($desvio['tem_frente_planejada']);
        $this->assertEquals(0, $desvio['total_desviado']);
    }

    public function test_u_saida_sem_reserva_nao_tem_desvio_calculavel(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 300);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 300, Carbon::today(), $this->user);

        $this->assertNull(DesviosAplicacao::porSaida($saida->fresh()));
    }

    // =========================================================
    // V-AA: Déficit (Seção 48)
    // =========================================================

    public function test_v_fisico_cobre_reservas_deficit_zero(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $this->reservaAction->execute($pacote, $material, $local, 400, null, null, $this->user);

        $cobertura = CoberturaReservas::porMaterialLocal($material, $local);
        $this->assertEquals(1000, $cobertura['fisico']);
        $this->assertEquals(400, $cobertura['reservado_ativo']);
        $this->assertEquals(0, $cobertura['deficit']);
    }

    public function test_w_saida_livre_invade_cobertura_gera_deficit_agregado(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $this->reservaAction->execute($pacote, $material, $local, 400, null, null, $this->user);
        $this->reservaAction->execute($pacote, $material, $local, 300, null, null, $this->user);

        // Saída livre (sem Reserva) de 500 — físico cai pra 500, reservas continuam 700.
        $this->saidaSimples($material, $local, 500);

        $cobertura = CoberturaReservas::porMaterialLocal($material, $local);
        $this->assertEquals(500, $cobertura['fisico']);
        $this->assertEquals(700, $cobertura['reservado_ativo']);
        $this->assertEquals(200, $cobertura['deficit']);
        $this->assertEquals(200, $cobertura['reposicao_necessaria']);
    }

    public function test_x_reserva_nunca_alterada_pelo_deficit(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 700, null, null, $this->user);
        $this->saidaSimples($material, $local, 500);

        $reservaFresh = $reserva->fresh();
        $this->assertEquals(700, (float) $reservaFresh->quantidade);
        $this->assertTrue($reservaFresh->estaAtiva());
    }

    public function test_y_entrada_posterior_recompoe_deficit_automaticamente(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $this->reservaAction->execute($pacote, $material, $local, 700, null, null, $this->user);
        $this->saidaSimples($material, $local, 500);

        $this->assertEquals(200, CoberturaReservas::porMaterialLocal($material, $local)['deficit']);

        $this->entradaPronta($material, $local, 200);

        $this->assertEquals(0, CoberturaReservas::porMaterialLocal($material, $local)['deficit']);
    }

    public function test_z_deficit_respeita_lote_local_nao_mistura(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $localA, 500, 'B1');
        $this->entradaPronta($material, $localB, 500, 'B2', $pacote);
        $unidadeA = UnidadeEstoque::where('local_estoque_id', $localA->id)->first();

        $this->reservaAction->execute($pacote, $material, $localA, 400, $unidadeA, null, $this->user);
        $this->saidaSimples($material, $localA, 500, unidade: $unidadeA);

        $coberturaA = CoberturaReservas::porMaterialLocal($material, $localA);
        $coberturaB = CoberturaReservas::porMaterialLocal($material, $localB);
        $this->assertEquals(400, $coberturaA['deficit']);
        $this->assertEquals(0, $coberturaB['deficit']);
    }

    public function test_aa_deficit_respeita_serial_especifico(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1, null, null, null, 'SN-001');
        $unidade = UnidadeEstoque::where('material_id', $material->id)->first();
        $this->reservaAction->execute($pacote, $material, $local, 1, $unidade, null, $this->user);

        $coberturaUnidade = CoberturaReservas::porUnidade($unidade->fresh());
        $this->assertEquals(1, $coberturaUnidade['fisico']);
        $this->assertEquals(1, $coberturaUnidade['reservado_ativo']);
        $this->assertEquals(0, $coberturaUnidade['deficit']);
    }

    // =========================================================
    // AB-AG: Pacote/Demanda + isolamento (Seção 49)
    // =========================================================

    public function test_ab_saida_com_pacote_aplicacao_herda_automaticamente(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 300, $pacote);

        $aplicacao = $this->registrarAplicacao->execute($saida, $this->criarFrente(), 300, Carbon::today(), $this->user);

        $this->assertSame($pacote->id, $aplicacao->item_suprimento_id);
    }

    public function test_ab2_saida_com_pacote_aplicacao_pacote_divergente_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $outroPacote = $this->criarPacoteSimples();
        $saida = $this->saidaSimples($material, $local, 300, $pacote);

        $this->expectException(AplicacaoConciliacaoInvalidaException::class);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 300, Carbon::today(), $this->user, $outroPacote);
    }

    public function test_ac_saida_sem_pacote_aplicacao_sem_pacote_permitida(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 300);

        $aplicacao = $this->registrarAplicacao->execute($saida, $this->criarFrente(), 300, Carbon::today(), $this->user);

        $this->assertNull($aplicacao->item_suprimento_id);
    }

    public function test_ad_saida_sem_pacote_conciliacao_posterior_define_pacote_por_linha(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $pacoteA = $this->criarPacoteSimples();
        $saida = $this->saidaSimples($material, $local, 500);

        $aplicacao = $this->registrarAplicacao->execute($saida, $this->criarFrente(), 300, Carbon::today(), $this->user, $pacoteA);

        $this->assertSame($pacoteA->id, $aplicacao->item_suprimento_id);
        $this->assertNull($saida->fresh()->item_suprimento_id, 'MovimentacaoEstoque nunca é reescrita.');
    }

    public function test_ae_uma_saida_atende_multiplos_pacotes_diferentes(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $pacoteEletrica = $this->criarPacoteSimples();
        $pacoteInstrumentacao = $this->criarPacoteSimples();
        $saida = $this->saidaSimples($material, $local, 500);

        $aplicacaoEletrica = $this->registrarAplicacao->execute($saida, $this->criarFrente(), 300, Carbon::today(), $this->user, $pacoteEletrica);
        $aplicacaoInstrumentacao = $this->registrarAplicacao->execute($saida, $this->criarFrente(), 200, Carbon::today(), $this->user, $pacoteInstrumentacao);

        $this->assertSame($pacoteEletrica->id, $aplicacaoEletrica->item_suprimento_id);
        $this->assertSame($pacoteInstrumentacao->id, $aplicacaoInstrumentacao->item_suprimento_id);
        $this->assertEquals(0, PoliticaConciliacaoAplicacao::pendente($saida->fresh()));
    }

    public function test_af_cross_obra_frente_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 300);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $frenteDeOutraObra = $this->criarFrente([], $outraObra);

        $this->expectException(AplicacaoConciliacaoInvalidaException::class);
        $this->registrarAplicacao->execute($saida, $frenteDeOutraObra, 100, Carbon::today(), $this->user);
    }

    public function test_af2_cross_obra_pacote_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 300);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $pacoteDeOutraObra = $this->criarPacoteSimples($outraObra);

        $this->expectException(AplicacaoConciliacaoInvalidaException::class);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 100, Carbon::today(), $this->user, $pacoteDeOutraObra);
    }

    public function test_ag_cross_tenant_isolamento_estrutural(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 300);
        $this->registrarAplicacao->execute($saida, $this->criarFrente(), 300, Carbon::today(), $this->user);

        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($outroUser);

        $this->assertSame(0, AplicacaoMaterialEstoque::count());
    }
}
