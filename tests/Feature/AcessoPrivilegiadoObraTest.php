<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ImpersonationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pré-produção, Etapa 2 (seção 10/11) — fecha os 3 bypasses reais
 * encontrados na investigação (`WorkPolicy::view()/update()`,
 * `gestao.obra.show`, `⚡dashboard.blade.php::trocarObra()`): antes,
 * `is_platform_admin` sozinho concedia acesso a qualquer obra de qualquer
 * tenant, sem nenhuma trilha de auditoria. Agora exige impersonation ATIVA
 * do tenant dono da obra — "Entrar como" já grava o evento auditado antes
 * de conceder o acesso.
 */
class AcessoPrivilegiadoObraTest extends TestCase
{
    use RefreshDatabase;

    private function criarAdminPlataforma(): User
    {
        $tenantDoAdmin = Tenant::factory()->create();

        return User::factory()->create(['tenant_id' => $tenantDoAdmin->id, 'is_platform_admin' => true]);
    }

    public function test_admin_sem_impersonation_nao_ve_obra_de_outro_tenant(): void
    {
        $admin = $this->criarAdminPlataforma();
        $tenantCliente = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenantCliente->id]);

        $this->assertFalse($admin->can('view', $obra));
    }

    public function test_admin_com_impersonation_do_tenant_certo_ve_a_obra(): void
    {
        $admin = $this->criarAdminPlataforma();
        $tenantCliente = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenantCliente->id]);

        $this->actingAs($admin);
        ImpersonationContext::start($tenantCliente, 'suporte ao cliente');

        $this->assertTrue($admin->can('view', $obra));
        $this->assertTrue($admin->can('update', $obra));
    }

    public function test_admin_impersonando_um_tenant_nao_ganha_acesso_a_obra_de_outro_tenant(): void
    {
        $admin = $this->criarAdminPlataforma();
        $tenantImpersonado = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $obraDeOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        $this->actingAs($admin);
        ImpersonationContext::start($tenantImpersonado);

        $this->assertFalse($admin->can('view', $obraDeOutroTenant));
    }

    public function test_usuario_comum_sem_vinculo_continua_bloqueado_mesmo_sem_ser_platform_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $usuarioComum = User::factory()->create(['tenant_id' => $tenant->id, 'is_platform_admin' => false]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertFalse($usuarioComum->can('view', $obra));
    }

    /**
     * Achado da verificação empírica desta correção: o route-model-binding
     * de `{obra}` já resolve `Work::find()` sob o escopo de tenant do
     * ADMIN (não impersonando ninguém, `TenantContext::currentId()` cai no
     * tenant "casa" dele) — a obra de outro tenant nem é encontrada, então
     * o binding falha ANTES do `abort_unless` interno rodar, e a resposta
     * é 404, não 403. O resultado de segurança é o mesmo (acesso negado),
     * só a camada que bloqueia é diferente do que a investigação original
     * presumia — corrigido aqui, não escondido.
     */
    public function test_rota_gestao_obra_show_bloqueia_admin_sem_impersonation(): void
    {
        $admin = $this->criarAdminPlataforma();
        $tenantCliente = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenantCliente->id]);

        $this->actingAs($admin)
            ->get(route('gestao.obra.show', $obra))
            ->assertNotFound();
    }

    public function test_rota_gestao_obra_show_libera_admin_impersonando_o_tenant_certo(): void
    {
        $admin = $this->criarAdminPlataforma();
        $tenantCliente = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenantCliente->id]);

        $this->actingAs($admin);
        ImpersonationContext::start($tenantCliente);

        $this->get(route('gestao.obra.show', $obra))->assertOk();
    }

    public function test_motivo_da_impersonation_e_registrado(): void
    {
        $admin = $this->criarAdminPlataforma();
        $tenantCliente = Tenant::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.impersonar', $tenantCliente), ['motivo' => 'Investigar chamado #123']);

        $this->assertDatabaseHas('impersonacoes', [
            'admin_user_id' => $admin->id,
            'tenant_id' => $tenantCliente->id,
            'motivo' => 'Investigar chamado #123',
        ]);
    }

    public function test_motivo_em_branco_nunca_bloqueia_a_impersonation(): void
    {
        $admin = $this->criarAdminPlataforma();
        $tenantCliente = Tenant::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.tenants.impersonar', $tenantCliente))
            ->assertRedirect(route('app.home'));

        $this->assertDatabaseHas('impersonacoes', [
            'admin_user_id' => $admin->id, 'tenant_id' => $tenantCliente->id, 'motivo' => null,
        ]);
    }

    /**
     * Achado real (Etapa 2): `impersonacoes.admin_user_id` tinha
     * `cascadeOnDelete()` — excluir de verdade um admin (User não usa
     * SoftDeletes, então `delete()` já é hard delete) apagava junto todo o
     * histórico de impersonation dele. Agora `restrictOnDelete()` bloqueia
     * a exclusão enquanto houver histórico.
     */
    public function test_excluir_admin_com_historico_de_impersonation_e_bloqueado(): void
    {
        $admin = $this->criarAdminPlataforma();
        $tenantCliente = Tenant::factory()->create();
        $this->actingAs($admin);
        ImpersonationContext::start($tenantCliente);

        $this->expectException(\Illuminate\Database\QueryException::class);
        User::find($admin->id)->delete();
    }
}
