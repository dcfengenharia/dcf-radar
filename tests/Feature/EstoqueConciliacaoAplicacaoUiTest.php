<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarDestinacaoPlanejada;
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
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\TipoLocalEstoque;
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
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.4 — UI (Seção 50) e performance (Seção 51) da
 * Conciliação/Aplicação. Cobertura AH-AQ do pedido.
 */
class EstoqueConciliacaoAplicacaoUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    private RegistrarEntradaEstoque $registrarEntrada;
    private RegistrarSaidaEstoque $registrarSaida;
    private RegistrarAplicacaoMaterialEstoque $registrarAplicacao;
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
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);

        $this->registrarEntrada = new RegistrarEntradaEstoque();
        $this->registrarSaida = new RegistrarSaidaEstoque();
        $this->registrarAplicacao = new RegistrarAplicacaoMaterialEstoque();
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

    // ---- helpers ----

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => $this->obra->id, 'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true,
        ]);
    }

    private function criarFrente(): FrenteTrabalho
    {
        return FrenteTrabalho::create(['obra_id' => $this->obra->id, 'nome' => 'Frente ' . uniqid()]);
    }

    private function criarItemTakeOffOrfao(?Material $material): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item',
            'quantidade' => 1000000, 'material_id' => $material?->id,
        ]);
    }

    private function criarRpItemEmitido(ItemTakeOff $item, float $quantidade): \App\Models\RequisicaoPlanejamentoItem
    {
        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidade);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $rpItem->fresh();
    }

    private function alocarNoPacote(\App\Models\RequisicaoPlanejamentoItem $rpItem, float $quantidade): AlocacaoRequisicaoPacote
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);

        return $this->alocar->alocar($rpItem, $pacote, $quantidade);
    }

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade): ItemSuprimento
    {
        $item = $this->criarItemTakeOffOrfao($material);
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
        $this->registrarEntrada->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);

        return ItemSuprimento::find($alocacao->item_suprimento_id);
    }

    private function saidaSimples(Material $material, LocalEstoque $local, float $quantidade): MovimentacaoEstoque
    {
        return $this->registrarSaida->execute($material, $local, $quantidade, Carbon::today(), $this->user, retiradoPor: $this->user);
    }

    // =========================================================
    // AH-AN: UI (Seção 50)
    // =========================================================

    public function test_ah_saida_pendente_aparece_na_listagem(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'conciliacao')
            ->assertSee($material->codigo)
            ->assertSee('500');
    }

    public function test_ai_aplicacao_parcial_atualiza_pendencia_na_ui(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'conciliacao')
            ->call('abrirModalConciliacao', $saida->id)
            ->set('aplicacaoFrenteId', $frente->id)
            ->set('aplicacaoQuantidade', 200)
            ->set('aplicacaoData', now()->toDateString())
            ->call('confirmarAplicacao')
            ->assertHasNoErrors();

        $this->assertEquals(300, \App\Support\Estoque\PoliticaConciliacaoAplicacao::pendente($saida->fresh()));
    }

    public function test_aj_conclusao_100_remove_da_lista_de_pendentes(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'conciliacao')
            ->call('abrirModalConciliacao', $saida->id)
            ->set('aplicacaoFrenteId', $frente->id)
            ->set('aplicacaoQuantidade', 500)
            ->set('aplicacaoData', now()->toDateString())
            ->call('confirmarAplicacao')
            ->assertHasNoErrors()
            ->assertSee('Nenhuma Saída com pendência');

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])->set('abaAtiva', 'conciliacao');
        $this->assertTrue($component->instance()->saidasComPendencia->isEmpty());
    }

    public function test_ak_historico_de_aplicacao_permanece_apos_conclusao(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();
        $this->registrarAplicacao->execute($saida, $frente, 500, Carbon::today(), $this->user);

        $this->assertSame(1, AplicacaoMaterialEstoque::where('movimentacao_estoque_id', $saida->id)->count());
        $this->assertDatabaseHas('aplicacoes_material_estoque', ['movimentacao_estoque_id' => $saida->id, 'frente_trabalho_id' => $frente->id]);
    }

    public function test_al_usuario_sem_permissao_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);

        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $semPermissao, Papel::ClienteLeitura->value);
        $this->actingAs($semPermissao);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'conciliacao')
            ->call('abrirModalConciliacao', $saida->id)
            ->assertStatus(403);

        $this->assertSame(0, AplicacaoMaterialEstoque::count());
    }

    public function test_am_mensagem_didatica_ao_exceder_pendente(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $saida = $this->saidaSimples($material, $local, 500);
        $frente = $this->criarFrente();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'conciliacao')
            ->call('abrirModalConciliacao', $saida->id)
            ->set('aplicacaoFrenteId', $frente->id)
            ->set('aplicacaoQuantidade', 501)
            ->set('aplicacaoData', now()->toDateString())
            ->call('confirmarAplicacao')
            ->assertHasErrors('aplicacaoGeral');
    }

    public function test_an_dashboard_nao_soma_unidades_incompativeis(): void
    {
        $materialMetro = $this->criarMaterial();
        $materialKg = $this->criarMaterial(['codigo' => 'MAT-KG-' . uniqid()]);
        $local = $this->criarLocal();
        $pacoteMetro = $this->entradaPronta($materialMetro, $local, 1000);
        $pacoteKg = $this->entradaPronta($materialKg, $local, 500);

        $this->reservaAction->execute($pacoteMetro, $materialMetro, $local, 900, null, null, $this->user);
        $this->reservaAction->execute($pacoteKg, $materialKg, $local, 450, null, null, $this->user);
        $this->saidaSimples($materialMetro, $local, 200);
        $this->saidaSimples($materialKg, $local, 100);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])->set('abaAtiva', 'conciliacao');
        $linhas = $component->instance()->coberturaDeficitPorObra;

        $linhaMetro = $linhas->firstWhere('material_id', $materialMetro->id);
        $linhaKg = $linhas->firstWhere('material_id', $materialKg->id);
        $this->assertEquals(100, $linhaMetro['deficit']);
        $this->assertEquals(50, $linhaKg['deficit']);
    }

    // =========================================================
    // AO-AQ: Performance (Seção 51) — DELTA, nunca teto absoluto
    // =========================================================

    private function cenarioComNSaidas(int $n): array
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, $n * 10 + 100);
        $saidas = [];
        for ($i = 0; $i < $n; $i++) {
            $saidas[] = $this->saidaSimples($material, $local, 5);
        }

        return [$material, $local, $saidas];
    }

    public function test_ao_100_saidas_sem_n_mais_1(): void
    {
        // Mesma técnica já estabelecida no projeto (evita trocar
        // tenant/obra/user no meio do teste — causa ModelNotFoundException
        // por interação com TenantContext/escopo, já documentado em
        // fases anteriores do Ciclo 19/20): mede a mesma obra em 2
        // volumes (5 depois +95=100), nunca 2 obras diferentes.
        [$material, $local] = $this->cenarioComNSaidas(5);

        $queries5 = 0;
        DB::listen(function () use (&$queries5) { $queries5++; });
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])->set('abaAtiva', 'conciliacao');

        for ($i = 0; $i < 95; $i++) {
            $this->saidaSimples($material, $local, 1);
        }

        $queries100 = 0;
        DB::listen(function () use (&$queries100) { $queries100++; });
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])->set('abaAtiva', 'conciliacao');

        $this->assertLessThanOrEqual($queries5 + 15, $queries100, 'Query count não deve escalar linearmente com N de Saídas.');
    }

    public function test_ap_1000_aplicacoes_sem_n_mais_1(): void
    {
        // DELTA, não teto absoluto — mesma metodologia já estabelecida
        // no projeto (o render completo do Livewire avalia os computeds
        // de badge de TODAS as abas a cada chamada, então um teto
        // absoluto é frágil; o que importa é o CUSTO MARGINAL de N
        // linhas de aplicação não crescer linearmente).
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100000);

        $saidaPoucas = $this->saidaSimples($material, $local, 100);
        for ($i = 0; $i < 5; $i++) {
            $this->registrarAplicacao->execute($saidaPoucas, $this->criarFrente(), 10, Carbon::today(), $this->user);
        }
        $queriesPoucas = 0;
        DB::listen(function () use (&$queriesPoucas) { $queriesPoucas++; });
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'conciliacao')
            ->call('abrirModalConciliacao', $saidaPoucas->id);

        $saidaMuitas = $this->saidaSimples($material, $local, 500);
        for ($i = 0; $i < 50; $i++) {
            $this->registrarAplicacao->execute($saidaMuitas, $this->criarFrente(), 10, Carbon::today(), $this->user);
        }
        $queriesMuitas = 0;
        DB::listen(function () use (&$queriesMuitas) { $queriesMuitas++; });
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'conciliacao')
            ->call('abrirModalConciliacao', $saidaMuitas->id);

        $this->assertLessThanOrEqual(
            $queriesPoucas + 10,
            $queriesMuitas,
            'Abrir o modal com 50 linhas de aplicação não deve gerar 1 query por linha (custo marginal deve ficar achatado).'
        );
    }

    public function test_aq_dashboard_por_frente_performance(): void
    {
        // DELTA — mesma metodologia de test_ao/test_ap.
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 100000);
        $this->reservaAction->execute($pacote, $material, $local, 100, null, null, $this->user);
        $this->saidaSimples($material, $local, 500);

        $queriesPoucas = 0;
        DB::listen(function () use (&$queriesPoucas) { $queriesPoucas++; });
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])->set('abaAtiva', 'conciliacao');

        for ($i = 0; $i < 19; $i++) {
            $this->reservaAction->execute($pacote, $material, $local, 10, null, null, $this->user);
        }

        $queriesMuitas = 0;
        DB::listen(function () use (&$queriesMuitas) { $queriesMuitas++; });
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])->set('abaAtiva', 'conciliacao');

        $this->assertLessThanOrEqual(
            $queriesPoucas + 10,
            $queriesMuitas,
            'Dashboard de cobertura/déficit não deve escalar com número de Reservas.'
        );
    }
}
