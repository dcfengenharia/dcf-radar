<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

class FrenteTrabalhoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'obra_id' => Work::factory(),
            'nome' => $this->faker->randomElement([
                'Berço 1', 'Berço 2', 'Berço 3', 'Pátio de Contêineres', 'Retroárea',
            ]),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($frente) {
            if ($frente->obra && $frente->obra->tenant_id !== $frente->tenant_id) {
                $frente->obra->forceFill(['tenant_id' => $frente->tenant_id])->saveQuietly();
            }
        });
    }
}
