<?php

namespace Tests\Feature;

use App\Actions\Estoque\CriarOrdemIndustrializacao;
use App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao;
use App\Actions\Estoque\EmitirOrdemIndustrializacao;
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
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\TipoLocalEstoque;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Gestao\ResumoFornecedorQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResumoFornecedorQueryTest extends TestCase
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

    public function test_pedidos_abertos_e_atrasados_por_fornecedor(): void
    {
        $material = $this->criarMaterial();
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D1', 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM1']);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'IT1', 'descricao' => 'Item', 'quantidade' => 100, 'material_id' => $material->id]);

        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, 100);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote']);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 100);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 100);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor X']);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-05', null, null, null, $this->user);
        (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), 100);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        Carbon::setTestNow(Carbon::parse('2026-12-20'));
        $resumo = ResumoFornecedorQuery::porObra($this->obra->id)->get($fornecedor->id);

        $this->assertNotNull($resumo);
        $this->assertEquals(1, $resumo['pedidos_abertos']);
        $this->assertEquals(1, $resumo['pedidos_atrasados']);
        $this->assertEquals(100, $resumo['quantidade_pendente']);
        $this->assertNull($resumo['materiais_atividades_proximas']);
        $this->assertNull($resumo['documentos_pendentes']);
    }

    public function test_industrializacoes_em_aberto_por_fornecedor(): void
    {
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor Terceiro']);
        $localTerceiro = LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Terceiro', 'tipo' => TipoLocalEstoque::Terceiro->value, 'fornecedor_id' => $fornecedor->id, 'ativo' => true]);
        $ordem = (new CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        (new AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $this->criarMaterial(), 10, $this->user);
        (new EmitirOrdemIndustrializacao())->execute($ordem->fresh(), $this->user);

        $resumo = ResumoFornecedorQuery::porObra($this->obra->id)->get($fornecedor->id);

        $this->assertEquals(1, $resumo['industrializacoes_em_aberto']);
    }

    public function test_isolamento_por_obra(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $fornecedorB = Fornecedor::create(['obra_id' => $obraB->id, 'nome' => 'Fornecedor B']);

        $resumoA = ResumoFornecedorQuery::porObra($this->obra->id);

        $this->assertNull($resumoA->get($fornecedorB->id));
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material', 'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ]);
    }
}
