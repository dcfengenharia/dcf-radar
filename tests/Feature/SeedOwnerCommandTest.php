<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
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

    /**
     * Auditoria Pré-Produção A1, SEED-02.
     */
    public function test_usuario_novo_sem_password_ganha_senha_aleatoria_forte_exibida_uma_vez(): void
    {
        Artisan::call('app:seed-owner', ['--email' => 'owner@dcf.eng.br']);
        $saida = Artisan::output();

        $user = User::where('email', 'owner@dcf.eng.br')->first();
        $this->assertNotNull($user);

        $this->assertStringContainsString('Senha gerada automaticamente', $saida);

        // A senha exibida de fato bate com a senha gravada.
        preg_match('/Senha gerada automaticamente[^\n]*\n(.+)/', $saida, $m);
        $this->assertNotEmpty($m[1] ?? null);
        $senhaExibida = trim($m[1]);
        $this->assertGreaterThanOrEqual(20, strlen($senhaExibida));
        $this->assertTrue(Hash::check($senhaExibida, $user->password));
    }

    public function test_usuario_novo_com_password_explicito_nunca_ecoa_a_senha_de_volta(): void
    {
        Artisan::call('app:seed-owner', [
            '--email' => 'owner@dcf.eng.br',
            '--password' => 'senha-bem-forte-123',
        ]);
        $saida = Artisan::output();

        $this->assertStringNotContainsString('senha-bem-forte-123', $saida);
        $this->assertStringNotContainsString('Senha gerada automaticamente', $saida);

        $user = User::where('email', 'owner@dcf.eng.br')->first();
        $this->assertTrue(Hash::check('senha-bem-forte-123', $user->password));
    }

    public function test_password_curto_demais_e_rejeitado(): void
    {
        $this->artisan('app:seed-owner', [
            '--email' => 'owner@dcf.eng.br',
            '--password' => 'curta12',
        ])->assertFailed();

        $this->assertNull(User::where('email', 'owner@dcf.eng.br')->first());
    }

    public function test_usuario_existente_sem_password_nunca_tem_a_senha_alterada(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@dcf.eng.br',
            'password' => Hash::make('senha-original-123'),
        ]);
        $hashOriginal = $user->password;

        $this->artisan('app:seed-owner', ['--email' => 'owner@dcf.eng.br'])->assertSuccessful();

        $this->assertSame($hashOriginal, $user->fresh()->password);
    }

    public function test_usuario_existente_com_password_sem_reset_password_e_rejeitado_e_preserva_senha(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@dcf.eng.br',
            'password' => Hash::make('senha-original-123'),
        ]);
        $hashOriginal = $user->password;

        $this->artisan('app:seed-owner', [
            '--email' => 'owner@dcf.eng.br',
            '--password' => 'senha-nova-tentativa-123',
        ])->assertFailed();

        $this->assertSame($hashOriginal, $user->fresh()->password);
    }

    public function test_usuario_existente_com_password_e_reset_password_altera_a_senha(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@dcf.eng.br',
            'password' => Hash::make('senha-original-123'),
        ]);

        $this->artisan('app:seed-owner', [
            '--email' => 'owner@dcf.eng.br',
            '--password' => 'senha-nova-confirmada-123',
            '--reset-password' => true,
        ])->assertSuccessful();

        $this->assertTrue(Hash::check('senha-nova-confirmada-123', $user->fresh()->password));
    }
}
