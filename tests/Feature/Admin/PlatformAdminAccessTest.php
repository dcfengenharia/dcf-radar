<?php

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_nao_logado_e_redirecionado_para_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_usuario_comum_recebe_403(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_platform_admin' => false]);

        $this->actingAs($user);

        // Navegação de página cheia sem acesso não mostra mais 403 cru —
        // App\Exceptions\Handler::render() redireciona com flash.popup
        // pro popup de acesso negado (ver AcessoNegadoPopupTest).
        $this->get('/admin')->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
        $this->get('/admin/tenants')->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
        $this->get('/admin/planos')->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_dono_da_plataforma_recebe_200(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_platform_admin' => true]);

        $this->actingAs($user);

        $this->get('/admin')->assertOk();
        $this->get('/admin/tenants')->assertOk();
        $this->get('/admin/planos')->assertOk();
    }
}
