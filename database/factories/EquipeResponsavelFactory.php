<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

class EquipeResponsavelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'obra_id' => Work::factory(),
            'nome' => $this->faker->randomElement([
                'Equipe Alpha', 'Equipe Beta', 'Empreiteira Silva', 'Equipe Própria', 'Subcontratada Rocha',
            ]),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($equipeResponsavel) {
            if ($equipeResponsavel->obra && $equipeResponsavel->obra->tenant_id !== $equipeResponsavel->tenant_id) {
                $equipeResponsavel->obra->forceFill(['tenant_id' => $equipeResponsavel->tenant_id])->saveQuietly();
            }
        });
    }
}
