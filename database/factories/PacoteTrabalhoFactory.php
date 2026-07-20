<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

class PacoteTrabalhoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'obra_id' => Work::factory(),
            'parent_id' => null,
            'nome' => $this->faker->sentence(2),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($pacote) {
            if ($pacote->obra && $pacote->obra->tenant_id !== $pacote->tenant_id) {
                $pacote->obra->forceFill(['tenant_id' => $pacote->tenant_id])->saveQuietly();
            }
        });
    }
}
