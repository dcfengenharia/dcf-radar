<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class DisciplinaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'nome' => $this->faker->randomElement([
                'Estrutura', 'Instalações Elétricas', 'Instalações Hidráulicas',
                'Alvenaria', 'Revestimento', 'Pintura', 'Impermeabilização', 'AVAC',
            ]),
        ];
    }
}
