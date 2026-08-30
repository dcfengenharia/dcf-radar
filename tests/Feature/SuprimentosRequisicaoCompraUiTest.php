<?php

namespace Tests\Feature;

use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\Papel;
use App\Enums\StatusRequisicaoCompra;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\RequisicaoCompra;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.4 — UI de Requisições de Compra dentro de
 * ⚡suprimentos.blade.php (seção nova, "Requisições de Compra", separada
 * do "Processo de Compra" legado — nunca some do histórico legado).
 */
class SuprimentosRequisicaoCompraUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    private function alocacaoPronta(ItemSuprimento $pacote, float $quantidade = 100): AlocacaoRequisicaoPacote
    {
        $documento = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'ISO-001', 'descricao' => 'Isometrico']);
        $revisao = $documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'Item A', 'quantidade' => $quantidade]);
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);
    }

    public function test_modal_de_rc_abre_e_lista_requisicoes_do_pacote(): void
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote X']);
        $this->alocacaoPronta($pacote);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->assertSee('Requisições de Compra')
            ->assertSee('Nenhuma Requisição de Compra ainda.');
    }

    public function test_fluxo_completo_criar_adicionar_item_emitir_e_concluir_etapa_pela_ui(): void
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote X']);
        $alocacao = $this->alocacaoPronta($pacote, 100);
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo RC']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        $component = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->set('rcFluxoIdNovo', $fluxo->id)
            ->call('criarRcRascunho');

        $rc = RequisicaoCompra::where('item_suprimento_id', $pacote->id)->firstOrFail();
        $this->assertSame(StatusRequisicaoCompra::Rascunho, $rc->status);

        $component->set('rcItemAlocacaoIdNovo', $alocacao->id)
            ->set('rcItemQuantidadeNovo', '30')
            ->call('adicionarItemRc');

        $this->assertSame(1, $rc->itens()->count());

        $component->call('emitirRc');

        $rc->refresh();
        $this->assertSame(StatusRequisicaoCompra::Emitida, $rc->status);
        $this->assertNotNull($rc->numero);
        $this->assertCount(1, $rc->etapas);

        $etapa = $rc->etapas->first();
        $component->call('concluirEtapaRc', $etapa->id);

        $this->assertSame(StatusRequisicaoCompra::Concluida, $rc->fresh()->status);
    }

    public function test_secao_de_rc_convive_com_processo_legado_sem_esconder_historico(): void
    {
        $fluxoLegado = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Legado']);
        $fluxoLegado->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 3]);
        $pacote = ItemSuprimento::create([
            'obra_id' => $this->obra->id, 'nome' => 'Pacote Legado', 'fluxo_suprimento_id' => $fluxoLegado->id,
        ]);
        $pacote->etapas()->create([
            'tenant_id' => $this->tenant->id, 'etapa_fluxo_suprimento_id' => $fluxoLegado->etapas->first()->id,
            'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 3,
        ]);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirDetalhe', $pacote->id)
            ->assertSee('Processo de Compra')
            ->assertSee('Requisições de Compra');
    }

    public function test_usuario_sem_permissao_editar_nao_ve_botoes_de_mutacao_de_rc(): void
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote X']);
        // suprimentos.mapa|criar já é liberado a partir de Encarregado
        // (Perfil::REGRAS_ESCRITA) — cliente_leitura é o único perfil
        // padrão abaixo desse limiar, sem nenhuma ação de escrita.
        $clienteLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $clienteLeitura, Papel::ClienteLeitura->value);
        $this->actingAs($clienteLeitura);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('abrirModalRc', $pacote->id)
            ->assertDontSee('Criar Rascunho');
    }
}
