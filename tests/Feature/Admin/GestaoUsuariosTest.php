<?php

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GestaoUsuariosTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_comum_recebe_403_em_admin_usuarios(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_platform_admin' => false]);

        $this->actingAs($user);

        $this->get('/admin/usuarios')->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_admin_ve_usuarios_de_tenants_diferentes_na_mesma_listagem(): void
    {
        $tenantA = Tenant::factory()->create(['name' => 'Construtora A']);
        $tenantB = Tenant::factory()->create(['name' => 'Construtora B']);

        $usuarioA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $usuarioB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $admin = User::factory()->create(['tenant_id' => $tenantA->id, 'is_platform_admin' => true]);
        $this->actingAs($admin);

        $instance = Livewire::test('pages::admin.usuarios.index')->instance();
        $ids = $instance->usuarios->pluck('id');

        $this->assertTrue($ids->contains($usuarioA->id));
        $this->assertTrue($ids->contains($usuarioB->id));
    }

    public function test_alternar_status_desativa_usuario_de_outro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $admin = User::factory()->create(['tenant_id' => $tenantA->id, 'is_platform_admin' => true]);
        $usuario = User::factory()->create(['tenant_id' => $tenantB->id, 'ativo' => true]);

        $this->actingAs($admin);

        Livewire::test('pages::admin.usuarios.index')->call('alternarStatus', $usuario->id);

        $this->assertFalse($usuario->fresh()->ativo);
    }

    public function test_usuario_desativado_e_deslogado_no_proximo_request(): void
    {
        $tenant = Tenant::factory()->create();
        $usuario = User::factory()->create(['tenant_id' => $tenant->id, 'ativo' => true]);

        $this->actingAs($usuario);
        $this->get('/app/home')->assertOk();

        $usuario->update(['ativo' => false]);

        $response = $this->get('/app/home');
        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_nao_consegue_desativar_a_propria_conta(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'is_platform_admin' => true, 'ativo' => true]);

        $this->actingAs($admin);

        Livewire::test('pages::admin.usuarios.index')->call('alternarStatus', $admin->id);

        $this->assertTrue($admin->fresh()->ativo);
    }

    public function test_reativar_usuario_restaura_acesso(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'is_platform_admin' => true]);
        $usuario = User::factory()->create(['tenant_id' => $tenant->id, 'ativo' => false]);

        $this->actingAs($admin);
        Livewire::test('pages::admin.usuarios.index')->call('alternarStatus', $usuario->id);
        $usuario->refresh();
        $this->assertTrue($usuario->ativo);

        $this->actingAs($usuario);
        $this->get('/app/home')->assertOk();
    }
}
