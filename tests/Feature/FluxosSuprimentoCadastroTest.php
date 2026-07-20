<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\FluxoSuprimento;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FluxosSuprimentoCadastroTest extends TestCase
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
    }

    private function componente()
    {
        return Livewire::test('pages::cadastros.fluxos-suprimento');
    }

    public function test_admin_cria_fluxo_com_etapas_em_ordem(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $this->componente()
            ->call('abrirCriar')
            ->set('nome', 'Padrão - Compra de Materiais')
            ->set('etapas.0.nome', 'Cotação')
            ->set('etapas.0.prazo_dias_uteis', 5)
            ->call('adicionarEtapa')
            ->set('etapas.1.nome', 'Pedido')
            ->set('etapas.1.prazo_dias_uteis', 3)
            ->call('salvar');

        $fluxo = FluxoSuprimento::where('nome', 'Padrão - Compra de Materiais')->firstOrFail();
        $etapas = $fluxo->etapas()->orderBy('ordem')->get();

        $this->assertSame(2, $etapas->count());
        $this->assertSame('Cotação', $etapas[0]->nome);
        $this->assertSame(5, $etapas[0]->prazo_dias_uteis);
        $this->assertSame(1, $etapas[0]->ordem);
        $this->assertSame('Pedido', $etapas[1]->nome);
        $this->assertSame(2, $etapas[1]->ordem);
    }

    public function test_exige_ao_menos_uma_etapa(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $this->componente()
            ->set('nome', 'Fluxo Sem Etapas')
            ->set('etapas', [])
            ->call('salvar')
            ->assertHasErrors(['etapas']);

        $this->assertDatabaseMissing('fluxos_suprimento', ['nome' => 'Fluxo Sem Etapas']);
    }

    public function test_mover_etapa_cima_e_baixo_reordena_no_formulario(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $componente = $this->componente()
            ->call('abrirCriar')
            ->set('etapas.0.nome', 'A')
            ->call('adicionarEtapa')
            ->set('etapas.1.nome', 'B');

        $componente->call('moverEtapaBaixo', 0);

        $this->assertSame('B', $componente->get('etapas.0.nome'));
        $this->assertSame('A', $componente->get('etapas.1.nome'));
    }

    public function test_editar_fluxo_substitui_etapas_sem_afetar_itens_ja_congelados(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo X']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Etapa Antiga', 'prazo_dias_uteis' => 10]);

        $this->componente()
            ->call('editar', $fluxo->id)
            ->set('etapas.0.nome', 'Etapa Nova')
            ->set('etapas.0.prazo_dias_uteis', 20)
            ->call('salvar');

        $etapas = $fluxo->fresh()->etapas;
        $this->assertSame(1, $etapas->count());
        $this->assertSame('Etapa Nova', $etapas->first()->nome);
    }

    public function test_perfil_sem_permissao_nao_consegue_criar_editar_excluir(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'cadastros.fluxos_suprimento')
            ->delete();
        $this->actingAs($this->user);

        $this->componente()->call('abrirCriar')->assertForbidden();

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Y']);

        $this->componente()->call('editar', $fluxo->id)->assertForbidden();
        $this->componente()->call('excluir', $fluxo->id)->assertForbidden();

        $this->assertDatabaseHas('fluxos_suprimento', ['id' => $fluxo->id, 'deleted_at' => null]);
    }
}
