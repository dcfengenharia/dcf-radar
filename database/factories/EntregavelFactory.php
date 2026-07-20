<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

class EntregavelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'obra_id' => Work::factory(),
            'nome' => $this->faker->randomElement([
                'Projeto Executivo', 'Relatório Técnico', 'As-Built', 'Memorial de Cálculo', 'Plano de Ensaio',
            ]),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($entregavel) {
            if ($entregavel->obra && $entregavel->obra->tenant_id !== $entregavel->tenant_id) {
                $entregavel->obra->forceFill(['tenant_id' => $entregavel->tenant_id])->saveQuietly();
            }
        });
    }
}
