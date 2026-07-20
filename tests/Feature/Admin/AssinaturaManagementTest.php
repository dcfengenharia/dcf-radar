<?php

namespace Tests\Feature\Admin;

use App\Enums\StatusAssinatura;
use App\Models\Assinatura;
use App\Models\Plano;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AssinaturaManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $adminTenant = Tenant::factory()->create();
        $this->admin = User::factory()->create(['tenant_id' => $adminTenant->id, 'is_platform_admin' => true]);
        $this->tenant = Tenant::factory()->create();
        $this->actingAs($this->admin);
    }

    private function componente()
    {
        return Livewire::test('pages::admin.tenants.show', ['tenant' => $this->tenant]);
    }

    public function test_atribuir_plano_cria_assinatura_nova(): void
    {
        $plano = Plano::factory()->create();

        $this->componente()
            ->call('abrirModalAssinatura')
            ->set('planoId', $plano->id)
            ->set('status', StatusAssinatura::Ativa->value)
            ->set('inicio', now()->toDateString())
            ->call('salvarAssinatura');

        $this->assertDatabaseHas('assinaturas', [
            'tenant_id' => $this->tenant->id,
            'plano_id' => $plano->id,
            'status' => StatusAssinatura::Ativa->value,
        ]);
    }

    public function test_mudar_status_para_cancelada_exige_motivo_e_grava_data(): void
    {
        $plano = Plano::factory()->create();

        $this->componente()
            ->call('abrirModalAssinatura')
            ->set('planoId', $plano->id)
            ->set('status', StatusAssinatura::Cancelada->value)
            ->set('inicio', now()->toDateString())
            ->call('salvarAssinatura')
            ->assertHasErrors(['motivoCancelamento' => 'required']);

        $this->componente()
            ->call('abrirModalAssinatura')
            ->set('planoId', $plano->id)
            ->set('status', StatusAssinatura::Cancelada->value)
            ->set('inicio', now()->toDateString())
            ->set('motivoCancelamento', 'Cliente não renovou.')
            ->call('salvarAssinatura');

        $assinatura = Assinatura::where('tenant_id', $this->tenant->id)->first();
        $this->assertEquals(StatusAssinatura::Cancelada, $assinatura->status);
        $this->assertNotNull($assinatura->cancelada_em);
        $this->assertEquals('Cliente não renovou.', $assinatura->motivo_cancelamento);
    }

    public function test_mostra_badge_de_origem_mercadopago_com_metodo_de_pagamento(): void
    {
        $plano = Plano::factory()->create();
        Assinatura::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plano_id' => $plano->id,
            'origem' => 'mercadopago',
            'metodo_pagamento' => 'pix',
        ]);

        $this->componente()
            ->assertSee('Mercado Pago')
            ->assertSee('pix');
    }

    public function test_mostra_badge_de_origem_manual_por_padrao(): void
    {
        $plano = Plano::factory()->create();
        Assinatura::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plano_id' => $plano->id,
        ]);

        $this->componente()->assertSee('Manual (admin)');
    }

    public function test_assinaturas_de_tenants_diferentes_sao_visiveis_sem_acting_as(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $plano = Plano::factory()->create();

        Assinatura::factory()->create(['tenant_id' => $tenantA->id, 'plano_id' => $plano->id]);
        Assinatura::factory()->create(['tenant_id' => $tenantB->id, 'plano_id' => $plano->id]);

        $tenantIds = Assinatura::all()->pluck('tenant_id')->unique();

        $this->assertTrue($tenantIds->contains($tenantA->id));
        $this->assertTrue($tenantIds->contains($tenantB->id));
    }
}
