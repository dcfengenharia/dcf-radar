<?php

namespace Database\Factories;

use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestricaoAcaoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'restricao_id' => Restricao::factory(),
            'autor_id' => User::factory(),
            'descricao' => $this->faker->paragraph(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($acao) {
            if ($acao->restricao && $acao->restricao->tenant_id !== $acao->tenant_id) {
                $acao->restricao->forceFill(['tenant_id' => $acao->tenant_id])->saveQuietly();
            }
        });
    }
}
