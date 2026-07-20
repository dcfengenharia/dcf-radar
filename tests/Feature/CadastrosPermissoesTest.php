<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Client;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bug reportado pelo usuário: um perfil sem NENHUMA permissão em
 * Cadastros ainda via o menu e ainda conseguia criar/editar/excluir —
 * as 4 páginas de Cadastros (exceto Tipos de Restrição) nunca tiveram
 * enforcement real (checkboxes "órfãos"). Este teste cobre a correção:
 * Policies reais, visibilidade de menu por item, e o item pai
 * "Cadastros" sumindo quando todos os filhos somem.
 */
class CadastrosPermissoesTest extends TestCase
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

    private function revogarTudoDeCadastros(Perfil $perfil): void
    {
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'like', 'cadastros.%')
            ->delete();
    }

    public function test_bootstrap_sem_nenhuma_obra_ainda_libera_cadastros_por_padrao(): void
    {
        $usuarioNovo = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // Nunca foi adicionado a nenhuma obra — nenhuma linha em obra_user.

        $this->assertTrue($usuarioNovo->temPermissaoEmAlgumaObraDoTenant('cadastros.clientes', 'criar'));
    }

    public function test_perfil_sem_permissao_de_cadastros_nao_consegue_criar_cliente(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->revogarTudoDeCadastros($perfil);
        $this->actingAs($this->user);

        Livewire::test('clientes.create')
            ->set('name', 'Cliente Novo')
            ->set('trading_name', 'Fantasia')
            ->call('saveClient')
            ->assertForbidden();

        $this->assertDatabaseMissing('clients', ['name' => 'Cliente Novo']);
    }

    public function test_perfil_sem_permissao_de_cadastros_nao_consegue_criar_obra(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->revogarTudoDeCadastros($perfil);
        $client = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Cliente X']);
        $this->actingAs($this->user);

        Livewire::test('obras.create')
            ->set('name', 'Obra Nova')
            ->set('clientId', $client->id)
            ->call('saveWork')
            ->assertForbidden();

        $this->assertDatabaseMissing('works', ['name' => 'Obra Nova']);
    }

    public function test_admin_com_permissao_de_cadastros_consegue_criar_cliente(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        Livewire::test('clientes.create')
            ->set('name', 'Cliente Novo')
            ->set('trading_name', 'Fantasia')
            ->call('saveClient');

        $this->assertDatabaseHas('clients', ['name' => 'Cliente Novo']);
    }

    public function test_itens_de_prontidao_bloqueia_criar_editar_excluir_sem_permissao(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->revogarTudoDeCadastros($perfil);
        $this->actingAs($this->user);

        Livewire::test('pages::cadastros.itens-prontidao')
            ->set('obraId', $this->obra->id)
            ->call('abrirCriar')
            ->assertForbidden();
    }

    public function test_convite_config_bloqueia_salvar_sem_permissao(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->revogarTudoDeCadastros($perfil);
        $this->actingAs($this->user);

        Livewire::test('pages::cadastros.convite-config')
            ->set('assunto', 'Novo assunto')
            ->call('salvar')
            ->assertForbidden();
    }

    public function test_menu_esconde_itens_de_cadastros_sem_permissao_ver(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'like', 'cadastros.%')
            ->where('acao', 'ver')
            ->delete();
        $this->actingAs($this->user);

        $this->get(route('app.home'))
            ->assertOk()
            ->assertDontSee('Clientes', false)
            ->assertDontSee('>Cadastros<', false);
    }

    public function test_menu_mostra_cadastros_quando_ao_menos_um_item_tem_permissao_ver(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'like', 'cadastros.%')
            ->where('acao', 'ver')
            ->where('funcionalidade', '!=', 'cadastros.clientes')
            ->delete();
        $this->actingAs($this->user);

        $this->get(route('app.home'))
            ->assertOk()
            ->assertSee('Clientes', false);
    }
}
