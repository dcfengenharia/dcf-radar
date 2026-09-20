<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A1, SEED-01 — DatabaseSeeder nunca pode criar
 * usuários demo (com a senha padrão "password" do UserFactory) fora de
 * local/testing.
 */
class DatabaseSeederProductionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_nunca_cria_usuarios_demo_em_producao(): void
    {
        $this->assertSame(0, User::count());

        app()->instance('env', 'production');

        (new DatabaseSeeder())->run();

        $this->assertSame(0, User::count());
    }

    public function test_nunca_cria_usuarios_demo_em_staging(): void
    {
        $this->assertSame(0, User::count());

        app()->instance('env', 'staging');

        (new DatabaseSeeder())->run();

        $this->assertSame(0, User::count());
    }

    public function test_cria_usuarios_demo_em_local(): void
    {
        app()->instance('env', 'local');

        (new DatabaseSeeder())->run();

        $this->assertSame(11, User::count());
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_cria_usuarios_demo_em_testing(): void
    {
        app()->instance('env', 'testing');

        (new DatabaseSeeder())->run();

        $this->assertSame(11, User::count());
    }
}
