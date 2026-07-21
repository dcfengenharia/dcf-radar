<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_conta_operadora_quando_nenhum_tenant_existe(): void
    {
        $this->assertSame(0, Tenant::count());

        $this->artisan('app:seed-owner', [
            '--email' => 'owner@dcf.eng.br',
            '--password' => 'senha-forte',
            '--empresa' => 'DCF.eng',
        ])->assertSuccessful();

        $tenant = Tenant::sole();
        $this->assertSame('DCF.eng', $tenant->name);
        $this->assertTrue($tenant->eh_conta_operadora);
        $this->assertDatabaseMissing('assinaturas', ['tenant_id' => $tenant->id]);

        $user = User::where('email', 'owner@dcf.eng.br')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->is_platform_admin);
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_reaproveita_tenant_existente_quando_ja_ha_um(): void
    {
        $tenant = Tenant::factory()->create(['eh_conta_operadora' => false]);

        $this->artisan('app:seed-owner', [
            '--email' => 'owner@dcf.eng.br',
        ])->assertSuccessful();

        $this->assertSame(1, Tenant::count());
        $user = User::where('email', 'owner@dcf.eng.br')->first();
        $this->assertSame($tenant->id, $user->tenant_id);
    }
}
