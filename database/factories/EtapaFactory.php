<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

class EtapaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'obra_id' => Work::factory(),
            'nome' => $this->faker->randomElement([
                'Mobilização', 'Terraplenagem', 'Fundação', 'Superestrutura', 'Acabamento',
            ]),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($etapa) {
            if ($etapa->obra && $etapa->obra->tenant_id !== $etapa->tenant_id) {
                $etapa->obra->forceFill(['tenant_id' => $etapa->tenant_id])->saveQuietly();
            }
        });
    }
}
