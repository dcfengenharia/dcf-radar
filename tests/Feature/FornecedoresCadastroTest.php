<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Fornecedor;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FornecedoresCadastroTest extends TestCase
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
        return Livewire::test('pages::cadastros.fornecedores');
    }

    public function test_admin_cria_fornecedor(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirCriar')
            ->set('nome', 'FOA Engenharia')
            ->set('cnpj', '12.345.678/0001-90')
            ->set('contatoEmail', 'contato@foa.com.br')
            ->call('salvar');

        $this->assertDatabaseHas('fornecedores', [
            'obra_id' => $this->obra->id,
            'tenant_id' => $this->tenant->id,
            'nome' => 'FOA Engenharia',
            'contato_email' => 'contato@foa.com.br',
        ]);
    }

    public function test_admin_edita_fornecedor(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $fornecedor = Fornecedor::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Nome Antigo',
        ]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('editar', $fornecedor->id)
            ->set('nome', 'Nome Novo')
            ->call('salvar');

        $this->assertDatabaseHas('fornecedores', ['id' => $fornecedor->id, 'nome' => 'Nome Novo']);
    }

    public function test_admin_exclui_fornecedor(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $fornecedor = Fornecedor::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Fornecedor X',
        ]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('excluir', $fornecedor->id);

        $this->assertSoftDeleted('fornecedores', ['id' => $fornecedor->id]);
    }

    public function test_perfil_sem_permissao_nao_consegue_criar_editar_excluir(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'cadastros.fornecedores')
            ->delete();
        $this->actingAs($this->user);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirCriar')
            ->assertForbidden();

        $fornecedor = Fornecedor::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Fornecedor Y',
        ]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('editar', $fornecedor->id)
            ->assertForbidden();

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('excluir', $fornecedor->id)
            ->assertForbidden();

        $this->assertDatabaseHas('fornecedores', ['id' => $fornecedor->id, 'deleted_at' => null]);
    }

    public function test_fornecedores_de_uma_obra_nao_aparecem_em_outra(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        Fornecedor::create(['tenant_id' => $this->tenant->id, 'obra_id' => $outraObra->id, 'nome' => 'Fornecedor de outra obra']);
        Fornecedor::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Fornecedor desta obra']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertSee('Fornecedor desta obra')
            ->assertDontSee('Fornecedor de outra obra');
    }
}
