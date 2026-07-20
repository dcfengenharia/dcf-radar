<?php

namespace Database\Factories;

use App\Models\Atividade;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AtividadeComentarioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'atividade_id' => Atividade::factory(),
            'autor_id' => User::factory(),
            'comentario' => $this->faker->paragraph(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($comentario) {
            if ($comentario->atividade && $comentario->atividade->tenant_id !== $comentario->tenant_id) {
                $comentario->atividade->forceFill(['tenant_id' => $comentario->tenant_id])->saveQuietly();
            }
        });
    }
}
