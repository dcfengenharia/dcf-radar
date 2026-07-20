<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\PerfilPermissao;
use App\Models\StatusDocumento;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatusDocumentosCadastroTest extends TestCase
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
        return Livewire::test('pages::cadastros.status-documentos');
    }

    public function test_admin_cria_status(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirCriar')
            ->set('nome', 'Em Elaboração')
            ->set('cor', '#ffc107')
            ->call('salvar');

        $this->assertDatabaseHas('status_documentos_engenharia', [
            'obra_id' => $this->obra->id,
            'tenant_id' => $this->tenant->id,
            'nome' => 'Em Elaboração',
            'cor' => '#ffc107',
            'conclusivo' => false,
        ]);
    }

    public function test_novo_status_recebe_a_proxima_ordem_disponivel(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Primeiro', 'ordem' => 5]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirCriar')
            ->set('nome', 'Segundo')
            ->call('salvar');

        $this->assertDatabaseHas('status_documentos_engenharia', ['nome' => 'Segundo', 'ordem' => 6]);
    }

    public function test_admin_edita_status(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $status = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Nome Antigo']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('editar', $status->id)
            ->set('nome', 'Nome Novo')
            ->set('conclusivo', true)
            ->call('salvar');

        $this->assertDatabaseHas('status_documentos_engenharia', ['id' => $status->id, 'nome' => 'Nome Novo', 'conclusivo' => true]);
    }

    public function test_admin_exclui_status(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $status = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Status X']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('excluir', $status->id);

        $this->assertSoftDeleted('status_documentos_engenharia', ['id' => $status->id]);
    }

    public function test_mover_status_troca_ordem_com_o_vizinho(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $primeiro = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'A', 'ordem' => 1]);
        $segundo = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'B', 'ordem' => 2]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('moverBaixo', $primeiro->id);

        $this->assertSame(2, $primeiro->fresh()->ordem);
        $this->assertSame(1, $segundo->fresh()->ordem);
    }

    public function test_perfil_sem_permissao_nao_consegue_criar_editar_excluir(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'cadastros.status_documentos')
            ->delete();
        $this->actingAs($this->user);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirCriar')
            ->assertForbidden();

        $status = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Status Y']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('editar', $status->id)
            ->assertForbidden();

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('excluir', $status->id)
            ->assertForbidden();

        $this->assertDatabaseHas('status_documentos_engenharia', ['id' => $status->id, 'deleted_at' => null]);
    }

    public function test_status_de_uma_obra_nao_aparecem_em_outra(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $outraObra->id, 'nome' => 'Status de outra obra']);
        StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Status desta obra']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertSee('Status desta obra')
            ->assertDontSee('Status de outra obra');
    }
}
