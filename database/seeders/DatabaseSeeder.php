<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Auditoria Pré-Produção A1, SEED-01 — dado demo/factory (inclusive
        // senha padrão "password" via UserFactory) nunca pode nascer em
        // produção. Antes, este seeder criava 11 usuários reais com senha
        // conhecida incondicionalmente, em qualquer ambiente onde
        // `php artisan db:seed` fosse rodado.
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        User::factory(10)->create();

        // Achado incidental desta correção (não é o achado SEED-01 em si):
        // 'name' não é coluna de `users` neste projeto (schema usa
        // first_name/last_name) — este create() sempre falhava com
        // QueryException quando de fato executado, nunca detectado antes
        // porque o guard acima nunca tinha sido testado com o seeder
        // rodando de verdade.
        User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
        ]);
    }
}
