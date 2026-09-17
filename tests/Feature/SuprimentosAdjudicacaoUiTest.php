<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\DocumentoEngenharia;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Material;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraAnexo;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Etapa 2 — UI de Documentos/Adjudicação dentro de ⚡suprimentos.blade.php.
 * Cobertura da Seção 38 do pedido (AF-AL).
 */
class SuprimentosAdjudicacaoUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(RequisicaoCompraAnexo::DISCO);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Cabo',
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
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Cabo 3x25',
            'unidade_medida_id' => $this->unidade->id, 'quantidade' => $quantidade, 'material_id' => $material->id,
        ]);
    }

    private function alocacaoPronta(ItemTakeOff $ito, float $quantidade, ItemSuprimento $pacote): AlocacaoRequisicaoPacote
    {
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);
    }

    /** RC Emitida com 1 item detalhado por 1 Atividade — pronta pra adjudicar. */
    private function rcEmitidaComParcelaPronta(): array
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote X']);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Montar Painel']);
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = (new AtualizarNecessidadeMaterialAtividade())->criarTakeOff($atividade, $ito, 300, $this->user);
        $alocacao = $this->alocacaoPronta($ito, 300, $pacote);

        $fluxo = \App\Models\FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo RC']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        $rc = (new CriarRequisicaoCompra())->execute($pacote, $fluxo, null, $this->user);
        $item = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 300);
        $parcela = (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($item, $necessidade, 300, $this->user);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        return [$rc->fresh(), $item->fresh(), $parcela->fresh(), $pacote, $atividade];
    }

    public function test_af_secao_documentos_aparece_com_permissao(): void
    {
        [$rc, , , $pacote] = $this->rcEmitidaComParcelaPronta();

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->call('abrirRcDetalhe', $rc->id)
            ->assertSee('Documentos do Processo')
            ->assertSee('Adjudicação');
    }

    public function test_ag_usuario_sem_permissao_nao_anexa(): void
    {
        [$rc, , , $pacote] = $this->rcEmitidaComParcelaPronta();

        $clienteLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $clienteLeitura, Papel::ClienteLeitura->value);
        $this->actingAs($clienteLeitura);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->call('abrirRcDetalhe', $rc->id)
            ->assertDontSee('Anexar')
            ->assertDontSee('Registrar');
    }

    public function test_anexar_documento_pela_ui(): void
    {
        [$rc, , , $pacote] = $this->rcEmitidaComParcelaPronta();

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->call('abrirRcDetalhe', $rc->id)
            ->set('rcAnexoTipoNovo', 'proposta')
            ->set('rcAnexoNovo', UploadedFile::fake()->create('proposta.pdf', 100, 'application/pdf'))
            ->call('anexarDocumentoRc')
            ->assertSee('proposta.pdf');

        $this->assertSame(1, RequisicaoCompraAnexo::where('requisicao_compra_id', $rc->id)->count());
    }

    public function test_ah_secao_adjudicacao_mostra_saldo_correto(): void
    {
        [$rc, $item, $parcela, $pacote] = $this->rcEmitidaComParcelaPronta();
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor X']);

        $adjudicacao = (new CriarAdjudicacaoRequisicaoCompra())->execute($rc, $fornecedor, 'Menor preço', null, null, $this->user);

        $component = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->call('abrirRcDetalhe', $rc->id)
            ->call('abrirAdjudicacaoDetalhe', $adjudicacao->id)
            ->set('rcAdjudicacaoItemAlvoNovo', "parcela:{$parcela->id}")
            ->set('rcAdjudicacaoItemQuantidadeNovo', '300')
            ->call('adicionarItemAdjudicacao');

        $component->assertSee('300');
        $this->assertEquals(0.0, $parcela->fresh()->saldoAdjudicavel());
    }

    public function test_ai_atividade_correta_aparece_na_lista_de_alvos(): void
    {
        [$rc, , , $pacote, $atividade] = $this->rcEmitidaComParcelaPronta();
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor X']);
        $adjudicacao = (new CriarAdjudicacaoRequisicaoCompra())->execute($rc, $fornecedor, 'X', null, null, $this->user);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->call('abrirRcDetalhe', $rc->id)
            ->call('abrirAdjudicacaoDetalhe', $adjudicacao->id)
            ->assertSee($atividade->nome);
    }

    public function test_aj_nenhuma_parcela_incompativel_aparece_quando_totalmente_adjudicada(): void
    {
        [$rc, $item, $parcela, $pacote] = $this->rcEmitidaComParcelaPronta();
        $fornecedorX = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'X']);
        $adjX = (new CriarAdjudicacaoRequisicaoCompra())->execute($rc, $fornecedorX, 'X', null, null, $this->user);
        (new \App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra())->adicionarItem($adjX, $item, $parcela, 300, $this->user);

        $fornecedorY = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Y']);
        $adjY = (new CriarAdjudicacaoRequisicaoCompra())->execute($rc->fresh(), $fornecedorY, 'Y', null, null, $this->user);

        // Com saldo zerado, a parcela não deve mais aparecer como alvo disponível.
        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->call('abrirRcDetalhe', $rc->id)
            ->call('abrirAdjudicacaoDetalhe', $adjY->id)
            ->assertDontSee("parcela:{$parcela->id}", false);
    }

    public function test_ak_criar_pedido_a_partir_da_adjudicacao_pre_preenche_fornecedor(): void
    {
        [$rc, , , $pacote] = $this->rcEmitidaComParcelaPronta();
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor X']);
        $adjudicacao = (new CriarAdjudicacaoRequisicaoCompra())->execute($rc, $fornecedor, 'X', null, null, $this->user);

        $component = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->call('abrirRcDetalhe', $rc->id)
            ->call('prepararPedidoAPartirDaAdjudicacao', $adjudicacao->id);

        $component->assertSet('pedidoFornecedorIdNovo', $fornecedor->id);
        $component->assertSet('rcAdjudicacaoDetalheId', null);
    }

    public function test_al_livewire_refresh_mantem_estado_consistente(): void
    {
        [$rc, $item, $parcela, $pacote] = $this->rcEmitidaComParcelaPronta();
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor X']);
        $adjudicacao = (new CriarAdjudicacaoRequisicaoCompra())->execute($rc, $fornecedor, 'X', null, null, $this->user);

        $component = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->call('abrirRcDetalhe', $rc->id)
            ->call('abrirAdjudicacaoDetalhe', $adjudicacao->id)
            ->set('rcAdjudicacaoItemAlvoNovo', "parcela:{$parcela->id}")
            ->set('rcAdjudicacaoItemQuantidadeNovo', '100')
            ->call('adicionarItemAdjudicacao');

        // Um segundo round-trip (simulando o refresh do Livewire) continua
        // consistente com o estado já persistido, sem duplicar a linha.
        $component->call('$refresh');

        $this->assertSame(1, $adjudicacao->itens()->count());
        $this->assertEquals(200.0, $parcela->fresh()->saldoAdjudicavel());
    }
}
