<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Feriado;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeriadosCadastroTest extends TestCase
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
        return Livewire::test('pages::cadastros.feriados');
    }

    public function test_admin_cria_feriado(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirCriar')
            ->set('data', '2026-12-25')
            ->set('descricao', 'Natal')
            ->call('salvar');

        $this->assertDatabaseHas('feriados', [
            'obra_id' => $this->obra->id,
            'tenant_id' => $this->tenant->id,
            'data' => '2026-12-25',
            'descricao' => 'Natal',
        ]);
    }

    public function test_admin_edita_feriado(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $feriado = Feriado::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'data' => '2026-01-01',
            'descricao' => 'Confraternização',
        ]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('editar', $feriado->id)
            ->set('descricao', 'Ano Novo')
            ->call('salvar');

        $this->assertDatabaseHas('feriados', ['id' => $feriado->id, 'descricao' => 'Ano Novo']);
    }

    public function test_admin_exclui_feriado(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $feriado = Feriado::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'data' => '2026-05-01',
            'descricao' => 'Trabalho',
        ]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('excluir', $feriado->id);

        $this->assertDatabaseMissing('feriados', ['id' => $feriado->id]);
    }

    public function test_nao_permite_feriado_duplicado_na_mesma_data_e_obra(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        Feriado::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'data' => '2026-05-01',
            'descricao' => 'Trabalho',
        ]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirCriar')
            ->set('data', '2026-05-01')
            ->set('descricao', 'Duplicado')
            ->call('salvar')
            ->assertDispatched('show-toast', function (string $name, array $params) {
                return ($params['type'] ?? null) === 'error';
            });

        $this->assertSame(1, Feriado::where('obra_id', $this->obra->id)->where('data', '2026-05-01')->count());
    }

    public function test_perfil_sem_permissao_nao_consegue_criar_editar_excluir(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'cadastros.feriados')
            ->delete();
        $this->actingAs($this->user);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirCriar')
            ->assertForbidden();

        $feriado = Feriado::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'data' => '2026-05-01',
        ]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('editar', $feriado->id)
            ->assertForbidden();

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('excluir', $feriado->id)
            ->assertForbidden();

        $this->assertDatabaseHas('feriados', ['id' => $feriado->id]);
    }
}
