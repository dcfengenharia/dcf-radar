<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

class Personalizado3Factory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'obra_id' => Work::factory(),
            'nome' => $this->faker->randomElement([
                'Categoria A', 'Categoria B', 'Categoria C', 'Categoria D', 'Categoria E',
            ]),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($personalizado) {
            if ($personalizado->obra && $personalizado->obra->tenant_id !== $personalizado->tenant_id) {
                $personalizado->obra->forceFill(['tenant_id' => $personalizado->tenant_id])->saveQuietly();
            }
        });
    }
}
