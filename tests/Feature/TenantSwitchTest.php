<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use App\Support\TenantContext;
use App\Support\TenantSwitchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantCasa;
    private User $usuario;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantCasa = Tenant::factory()->create(['name' => 'Tenant Casa']);
        $this->usuario = User::factory()->create(['tenant_id' => $this->tenantCasa->id]);
        $this->tenantCasa->update(['criado_por_id' => $this->usuario->id]);

        $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B', 'criado_por_id' => $this->usuario->id]);
        $this->tenantB->usuariosComAcesso()->attach($this->usuario->id);
    }

    public function test_usuario_troca_de_tenant_e_contexto_passa_a_resolver_pro_tenant_escolhido(): void
    {
        $this->actingAs($this->usuario);

        $this->post(route('app.empresa.trocar', $this->tenantB))
            ->assertRedirect(route('app.home'));

        $this->assertEquals($this->tenantB->id, TenantContext::currentId());
    }

    public function test_dados_escopados_por_tenant_retornam_os_do_tenant_escolhido(): void
    {
        $clienteB = Client::factory()->create(['tenant_id' => $this->tenantB->id, 'name' => 'Cliente B']);
        Client::factory()->create(['tenant_id' => $this->tenantCasa->id, 'name' => 'Cliente Casa']);

        $this->actingAs($this->usuario);
        TenantSwitchContext::set($this->tenantB);

        $clientesVisiveis = Client::all();

        $this->assertCount(1, $clientesVisiveis);
        $this->assertEquals($clienteB->id, $clientesVisiveis->first()->id);
    }

    public function test_usuario_nao_consegue_trocar_para_tenant_sem_pivo_de_acesso(): void
    {
        $tenantC = Tenant::factory()->create(['name' => 'Tenant C']);

        $this->actingAs($this->usuario);

        // Navegação de página cheia sem acesso não mostra mais 403 cru —
        // App\Exceptions\Handler::render() redireciona com flash.popup.
        $this->post(route('app.empresa.trocar', $tenantC))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');

        $this->assertEquals($this->tenantCasa->id, TenantContext::currentId());
    }

    public function test_apenas_criador_acessa_pagina_dados_da_empresa(): void
    {
        $this->actingAs($this->usuario);
        $this->get(route('app.empresa.show'))->assertOk();

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenantCasa->id]);
        $usuarioConvidado = User::factory()->create(['tenant_id' => $this->tenantCasa->id]);
        $this->vincularObra($outraObra, $usuarioConvidado, Papel::Encarregado->value);

        $this->actingAs($usuarioConvidado);
        // Navegação de página cheia sem acesso não mostra mais 403 cru —
        // App\Exceptions\Handler::render() redireciona com flash.popup.
        $this->get(route('app.empresa.show'))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_criar_nova_empresa_gera_criado_por_e_troca_automaticamente(): void
    {
        $this->actingAs($this->usuario);

        Livewire::test('empresas.create')
            ->set('name', 'Empresa Nova LTDA')
            ->call('criar');

        $novoTenant = Tenant::where('name', 'Empresa Nova LTDA')->first();

        $this->assertNotNull($novoTenant);
        $this->assertEquals($this->usuario->id, $novoTenant->criado_por_id);
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $novoTenant->id,
            'user_id' => $this->usuario->id,
        ]);
        $this->assertEquals($novoTenant->id, TenantContext::currentId());
    }

    public function test_trocar_tenant_limpa_obra_context_ativo(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenantCasa->id]);
        ObraContext::set($obra);

        $this->actingAs($this->usuario);
        $this->post(route('app.empresa.trocar', $this->tenantB));

        $this->assertNull(ObraContext::currentId());
    }

    public function test_registro_real_cria_pivo_automaticamente_via_hook(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertTrue($user->tenants()->where('tenants.id', $tenant->id)->exists());
    }
}
