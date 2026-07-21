<?php

namespace Tests\Feature\Admin;

use App\Enums\StatusAssinatura;
use App\Models\Plano;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_conta_operadora_nunca_ganha_assinatura_automatica(): void
    {
        Plano::factory()->create(['ativo' => true, 'padrao_trial' => true]);

        $operadora = Tenant::create(['name' => 'DCF.eng', 'eh_conta_operadora' => true]);

        $this->assertDatabaseMissing('assinaturas', ['tenant_id' => $operadora->id]);
    }

    public function test_scope_clientes_exclui_conta_operadora(): void
    {
        $operadora = Tenant::create(['name' => 'DCF.eng', 'eh_conta_operadora' => true]);
        $cliente = Tenant::factory()->create(['eh_conta_operadora' => false]);

        $ids = Tenant::clientes()->pluck('id');

        $this->assertTrue($ids->contains($cliente->id));
        $this->assertFalse($ids->contains($operadora->id));
    }

    public function test_dashboard_exclui_conta_operadora_das_metricas(): void
    {
        $plano = Plano::factory()->create(['ativo' => true, 'padrao_trial' => true, 'preco_mensal' => 500]);

        $operadora = Tenant::create(['name' => 'DCF.eng', 'eh_conta_operadora' => true]);
        // Conta operadora não ganha assinatura automática — cria uma manualmente
        // pra provar que mesmo assim ela é excluída das métricas.
        $operadora->assinaturas()->create([
            'plano_id' => $plano->id,
            'status' => StatusAssinatura::Ativa->value,
            'origem' => 'manual',
            'inicio' => now()->toDateString(),
        ]);

        $clienteTenant = Tenant::factory()->create(['eh_conta_operadora' => false]);
        $clienteTenant->assinaturas()->create([
            'plano_id' => $plano->id,
            'status' => StatusAssinatura::Ativa->value,
            'origem' => 'manual',
            'inicio' => now()->toDateString(),
        ]);

        $admin = User::factory()->create(['tenant_id' => $operadora->id, 'is_platform_admin' => true]);
        $this->actingAs($admin);

        $instance = Livewire::test('pages::admin.dashboard')->instance();

        $this->assertSame(1, $instance->totalTenants);
        $this->assertSame(1, $instance->assinaturasAtivasCount);
        $this->assertSame(500.0, $instance->mrrAproximado);
    }
}
