<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Impersonacao;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ImpersonationContext;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Tenant $tenantDoAdmin;
    private Tenant $tenantAlvo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDoAdmin = Tenant::factory()->create();
        $this->admin = User::factory()->create(['tenant_id' => $this->tenantDoAdmin->id, 'is_platform_admin' => true]);
        $this->tenantAlvo = Tenant::factory()->create();
    }

    public function test_dono_entra_como_tenant_e_contexto_passa_a_resolver_pro_tenant_alvo(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.tenants.impersonar', $this->tenantAlvo))
            ->assertRedirect(route('app.home'));

        $this->assertEquals($this->tenantAlvo->id, TenantContext::currentId());
    }

    public function test_impersonar_cria_registro_de_auditoria_aberto(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.tenants.impersonar', $this->tenantAlvo));

        $this->assertDatabaseHas('impersonacoes', [
            'admin_user_id' => $this->admin->id,
            'tenant_id' => $this->tenantAlvo->id,
            'finalizado_em' => null,
        ]);
    }

    public function test_dados_escopados_por_tenant_retornam_os_do_tenant_impersonado(): void
    {
        $clienteAlvo = Client::factory()->create(['tenant_id' => $this->tenantAlvo->id, 'name' => 'Cliente do Alvo']);
        Client::factory()->create(['tenant_id' => $this->tenantDoAdmin->id, 'name' => 'Cliente do Admin']);

        $this->actingAs($this->admin);
        ImpersonationContext::start($this->tenantAlvo);

        $clientesVisiveis = Client::all();

        $this->assertCount(1, $clientesVisiveis);
        $this->assertEquals($clienteAlvo->id, $clientesVisiveis->first()->id);
    }

    public function test_parar_impersonation_volta_pro_tenant_original_e_fecha_auditoria(): void
    {
        $this->actingAs($this->admin);
        ImpersonationContext::start($this->tenantAlvo);

        $this->assertEquals($this->tenantAlvo->id, TenantContext::currentId());

        $this->post(route('admin.impersonar.parar'))
            ->assertRedirect(route('admin.tenants.index'));

        $this->assertEquals($this->tenantDoAdmin->id, TenantContext::currentId());

        $log = Impersonacao::where('tenant_id', $this->tenantAlvo->id)->first();
        $this->assertNotNull($log->finalizado_em);
    }

    public function test_segundo_usuario_comum_nao_e_afetado_pela_impersonation_do_dono(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroUsuario = User::factory()->create(['tenant_id' => $outroTenant->id]);

        // Dono inicia impersonation numa "sessão" (contexto de auth) separada
        $this->actingAs($this->admin);
        ImpersonationContext::start($this->tenantAlvo);
        $this->assertEquals($this->tenantAlvo->id, TenantContext::currentId());

        // Troca pra sessão do outro usuário comum — não deve ver nenhum efeito da impersonation
        $this->actingAs($outroUsuario);
        $this->assertEquals($outroTenant->id, TenantContext::currentId());
    }

    public function test_usuario_sem_is_platform_admin_nao_consegue_impersonar(): void
    {
        $usuarioComum = User::factory()->create(['tenant_id' => $this->tenantDoAdmin->id, 'is_platform_admin' => false]);
        $this->actingAs($usuarioComum);

        // Navegação de página cheia sem acesso não mostra mais 403 cru —
        // App\Exceptions\Handler::render() redireciona com flash.popup.
        $this->post(route('admin.tenants.impersonar', $this->tenantAlvo))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }
}
