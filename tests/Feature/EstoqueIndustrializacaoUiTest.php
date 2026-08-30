<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao;
use App\Actions\Estoque\CriarOrdemIndustrializacao;
use App\Actions\Estoque\EmitirOrdemIndustrializacao;
use App\Actions\Estoque\RegistrarConsumoIndustrializacao;
use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Actions\Estoque\RegistrarProducaoIndustrializada;
use App\Actions\Estoque\RegistrarRemessaIndustrializacao;
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
use App\Enums\TipoLocalEstoque;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\OrdemIndustrializacao;
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
 * Ciclo 20, Etapa 20.5 — UI (Seção 56) e performance (Seção 57) da
 * Industrialização em Terceiros.
 */
class EstoqueIndustrializacaoUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    private CriarOrdemIndustrializacao $criarOrdem;
    private AtualizarRascunhoOrdemIndustrializacao $rascunhoOrdem;
    private EmitirOrdemIndustrializacao $emitirOrdem;
    private RegistrarRemessaIndustrializacao $registrarRemessa;
    private RegistrarConsumoIndustrializacao $registrarConsumo;
    private RegistrarProducaoIndustrializada $registrarProducao;
    private RegistrarEntradaEstoque $registrarEntrada;

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

        $this->criarOrdem = new CriarOrdemIndustrializacao();
        $this->rascunhoOrdem = new AtualizarRascunhoOrdemIndustrializacao();
        $this->emitirOrdem = new EmitirOrdemIndustrializacao();
        $this->registrarRemessa = new RegistrarRemessaIndustrializacao();
        $this->registrarConsumo = new RegistrarConsumoIndustrializacao();
        $this->registrarProducao = new RegistrarProducaoIndustrializada();
        $this->registrarEntrada = new RegistrarEntradaEstoque();

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

    private function criarFornecedor(): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
    }

    private function criarLocalTerceiro(Fornecedor $fornecedor): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => $this->obra->id, 'nome' => 'Terceiro ' . uniqid(),
            'tipo' => TipoLocalEstoque::Terceiro->value, 'fornecedor_id' => $fornecedor->id, 'ativo' => true,
        ]);
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

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade): void
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

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'FornecedorCompra ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);
        $this->registrarEntrada->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);
    }

    private function ordemEmitidaComProduto(Material $materiaPrima, Material $materialProduto, float $quantidadePrevista = 100): array
    {
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $this->entradaPronta($materiaPrima, $localProprio, 1000);

        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        $this->rascunhoOrdem->adicionarProduto($ordem, $materialProduto, $quantidadePrevista, $this->user);
        $ordem = $this->emitirOrdem->execute($ordem->fresh(), $this->user);

        return [$ordem, $fornecedor, $localProprio, $localTerceiro];
    }

    // =========================================================
    // AU-AZ: UI/Permissão (Seção 56)
    // =========================================================

    public function test_au_usuario_autorizado_cria_ordem_via_ui(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'industrializacao')
            ->call('abrirModalOrdemIndustr')
            ->set('ordemIndustrFornecedorId', $fornecedor->id)
            ->set('ordemIndustrLocalTerceiroId', $local->id)
            ->call('confirmarCriarOrdemIndustr')
            ->assertHasNoErrors();

        $this->assertSame(1, OrdemIndustrializacao::count());
    }

    public function test_av_ver_lista_e_detalhe(): void
    {
        $material = $this->criarMaterial();
        [$ordem] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'industrializacao')
            ->assertSee($ordem->fornecedor->nome)
            ->call('abrirDetalheOrdemIndustr', $ordem->id)
            ->assertSee($ordem->localTerceiro->nome);
    }

    public function test_aw_usuario_sem_permissao_bloqueado(): void
    {
        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $semPermissao, Papel::ClienteLeitura->value);
        $this->actingAs($semPermissao);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'industrializacao')
            ->call('abrirModalOrdemIndustr')
            ->assertStatus(403);

        $this->assertSame(0, OrdemIndustrializacao::count());
    }

    public function test_ax_cross_obra_bloqueado_na_ui(): void
    {
        // Usuário precisa ter vínculo/perfil também na 2ª obra (mesmo
        // padrão de todo teste cross-obra já existente no projeto) —
        // sem isso, temPermissaoNaObra() nem chega a resolver a
        // pergunta "cross-obra", só falharia por ausência de perfil.
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value);
        $material = $this->criarMaterial();
        [$ordemDaObraOriginal] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());

        $componente = Livewire::test('pages::radar.estoque', ['obra' => $outraObra])
            ->set('abaAtiva', 'industrializacao')
            ->call('abrirDetalheOrdemIndustr', $ordemDaObraOriginal->id);

        $this->assertNull($componente->instance()->ordemIndustrDetalhe);
    }

    public function test_ay_timeline_mostra_remessas_e_produtos(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());
        $this->registrarRemessa->execute($ordem, $material, 200, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'industrializacao')
            ->call('abrirDetalheOrdemIndustr', $ordem->id)
            ->assertSee($material->codigo)
            ->assertSee('200');
    }

    public function test_az_documento_fabricacao_visivel(): void
    {
        $material = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $this->entradaPronta($material, $localProprio, 500);
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-FAB', 'descricao' => 'Desenho']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);

        $ordem = $this->criarOrdem->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        $this->rascunhoOrdem->adicionarProduto($ordem, $produtoMaterial, 10, $this->user, $rev);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'industrializacao')
            ->call('abrirDetalheOrdemIndustr', $ordem->id)
            ->assertSee('DOC-FAB');
    }

    // =========================================================
    // Fluxo completo via UI
    // =========================================================

    public function test_fluxo_completo_via_ui(): void
    {
        $materiaPrima = $this->criarMaterial();
        $produtoMaterial = $this->criarMaterial();
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $this->entradaPronta($materiaPrima, $localProprio, 1000);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'industrializacao')
            ->call('abrirModalOrdemIndustr')
            ->set('ordemIndustrFornecedorId', $fornecedor->id)
            ->set('ordemIndustrLocalTerceiroId', $localTerceiro->id)
            ->call('confirmarCriarOrdemIndustr')
            ->assertHasNoErrors();

        $ordem = OrdemIndustrializacao::first();

        $component->call('abrirDetalheOrdemIndustr', $ordem->id)
            ->call('abrirModalProdutoIndustr')
            ->set('produtoIndustrMaterialId', $produtoMaterial->id)
            ->set('produtoIndustrQuantidadePrevista', 50)
            ->call('confirmarAdicionarProdutoIndustr')
            ->assertHasNoErrors()
            ->call('confirmarEmitirOrdemIndustr')
            ->assertHasNoErrors();

        $produto = $ordem->fresh()->produtos->first();

        $component->call('abrirModalRemessaIndustr', 'envio')
            ->set('remessaIndustrMaterialId', $materiaPrima->id)
            ->set('remessaIndustrLocalProprioId', $localProprio->id)
            ->set('remessaIndustrQuantidade', 300)
            ->set('remessaIndustrData', now()->toDateString())
            ->call('confirmarRemessaIndustr')
            ->assertHasNoErrors();

        $remessa = $ordem->fresh()->remessas->first();

        $component->call('abrirModalConsumoIndustr', $produto->id)
            ->set('consumoIndustrRemessaId', $remessa->id)
            ->set('consumoIndustrQuantidade', 200)
            ->set('consumoIndustrData', now()->toDateString())
            ->call('confirmarConsumoIndustr')
            ->assertHasNoErrors();

        $component->call('abrirModalProducaoIndustr', $produto->id)
            ->set('producaoIndustrQuantidade', 50)
            ->set('producaoIndustrData', now()->toDateString())
            ->call('confirmarProducaoIndustr')
            ->assertHasNoErrors();

        $component->call('abrirModalEntregaIndustr', $produto->id)
            ->set('entregaIndustrQuantidade', 50)
            ->set('entregaIndustrModalidade', 'retorno_estoque_obra')
            ->set('entregaIndustrLocalDestinoId', $localProprio->id)
            ->set('entregaIndustrData', now()->toDateString())
            ->call('confirmarEntregaIndustr')
            ->assertHasNoErrors();

        $this->assertEquals(50, \App\Support\Estoque\SaldoEstoque::porMaterialLocal($produtoMaterial, $localProprio));
    }

    // =========================================================
    // Performance (Seção 57) — DELTA, mesma metodologia estabelecida
    // =========================================================

    public function test_ba_100_ordens_sem_n_mais_1(): void
    {
        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);

        for ($i = 0; $i < 5; $i++) {
            $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);
        }
        $queriesPoucas = 0;
        DB::listen(function () use (&$queriesPoucas) { $queriesPoucas++; });
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])->set('abaAtiva', 'industrializacao');

        for ($i = 0; $i < 95; $i++) {
            $this->criarOrdem->execute($this->obra, $fornecedor, $local, $this->user);
        }
        $queriesMuitas = 0;
        DB::listen(function () use (&$queriesMuitas) { $queriesMuitas++; });
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])->set('abaAtiva', 'industrializacao');

        $this->assertLessThanOrEqual($queriesPoucas + 15, $queriesMuitas, 'Listagem de Ordens não deve escalar linearmente com N.');
    }

    public function test_bc_dashboard_por_fornecedor_performance(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaComProduto($material, $this->criarMaterial());

        $this->registrarRemessa->execute($ordem, $material, 10, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        $queriesPoucas = 0;
        DB::listen(function () use (&$queriesPoucas) { $queriesPoucas++; });
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'industrializacao')
            ->call('abrirDetalheOrdemIndustr', $ordem->id);

        for ($i = 0; $i < 19; $i++) {
            $this->registrarRemessa->execute($ordem, $material, 1, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user);
        }
        $queriesMuitas = 0;
        DB::listen(function () use (&$queriesMuitas) { $queriesMuitas++; });
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'industrializacao')
            ->call('abrirDetalheOrdemIndustr', $ordem->id);

        $this->assertLessThanOrEqual($queriesPoucas + 15, $queriesMuitas, 'Detalhe da Ordem não deve escalar linearmente com N de remessas.');
    }
}
