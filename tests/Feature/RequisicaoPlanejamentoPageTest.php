<?php

namespace Tests\Feature;

use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Enums\Papel;
use App\Models\DocumentoEngenharia;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.2 — smoke test da UI (`pages::planejamento.
 * requisicoes-planejamento`). Não duplica a cobertura de domínio já
 * exaustiva em RequisicaoPlanejamentoTest/ConciliacaoTakeOffTest — só
 * garante que o componente monta, autoriza e o fluxo básico funciona
 * através da camada Livewire.
 */
class RequisicaoPlanejamentoPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private ItemTakeOff $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        ObraContext::set($this->obra);

        $documento = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'ISO-001', 'descricao' => 'Isometrico']);
        $revisao = $documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $this->item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'Item A', 'quantidade' => 100]);
    }

    private function componente()
    {
        return Livewire::test('pages::planejamento.requisicoes-planejamento', ['obra' => $this->obra]);
    }

    /**
     * `App\Exceptions\Handler::render()` intercepta qualquer 403 de
     * navegação de página cheia e redireciona pro popup interno de acesso
     * negado — mesmo comportamento já documentado/testado em
     * PlanoAcaoPainelTest::test_usuario_sem_permissao_nao_acessa_a_pagina_nem_o_painel.
     */
    public function test_usuario_sem_permissao_e_redirecionado_com_popup_de_acesso_negado(): void
    {
        $outroUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($outroUser);

        $this->componente()
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_pagina_renderiza_para_usuario_autorizado(): void
    {
        $this->componente()->assertStatus(200)->assertSee('Nova Requisição');
    }

    public function test_criar_rascunho_pelo_componente(): void
    {
        $c = $this->componente()->call('criarRascunho');

        $this->assertSame(1, \App\Models\RequisicaoPlanejamento::where('obra_id', $this->obra->id)->count());
        $this->assertNotNull($c->get('rpAbertaId'));
    }

    public function test_fluxo_completo_adicionar_item_e_emitir_pelo_componente(): void
    {
        $c = $this->componente()->call('criarRascunho');
        $rpId = $c->get('rpAbertaId');

        $c->call('adicionarItem', $this->item->id, '40');
        $c->call('emitirRp');

        $rp = \App\Models\RequisicaoPlanejamento::find($rpId);
        $this->assertTrue($rp->estaEmitida());
        $this->assertNotNull($rp->numero);
    }

    public function test_encarregado_ve_mas_nao_pode_editar(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);
        $this->actingAs($encarregado);

        $this->componente()->assertStatus(200)->assertDontSee('Nova Requisição');
    }

    /**
     * Ciclo 19, Etapa 19.3, seção 29 — leitura de alocado/saldo a alocar/
     * Pacotes relacionados na própria tela da RP (nunca cria/edita
     * alocação daqui).
     */
    public function test_rp_emitida_mostra_alocacao_somente_leitura(): void
    {
        $c = $this->componente()->call('criarRascunho');
        $rpId = $c->get('rpAbertaId');
        $c->call('adicionarItem', $this->item->id, '60');
        $c->call('emitirRp');

        $rp = \App\Models\RequisicaoPlanejamento::find($rpId);
        $rpItem = $rp->itens()->first();

        $pacote = \App\Models\ItemSuprimento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Pacote Tubulação']);
        (new \App\Actions\Suprimentos\AlocarRequisicaoAoPacote())->alocar($rpItem, $pacote, 25);

        $this->componente()
            ->call('abrirRp', $rpId)
            ->assertSee('Pacote Tubulação')
            ->assertSee('25,000');
    }
}
