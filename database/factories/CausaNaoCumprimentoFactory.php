<?php

namespace Database\Factories;

use App\Models\Atividade;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class CausaNaoCumprimentoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'atividade_id' => Atividade::factory(),
            'created_by_id' => null,
            'descricao' => $this->faker->sentence(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($causa) {
            if ($causa->atividade && $causa->atividade->tenant_id !== $causa->tenant_id) {
                $causa->atividade->forceFill(['tenant_id' => $causa->tenant_id])->saveQuietly();
            }
        });
    }
}
