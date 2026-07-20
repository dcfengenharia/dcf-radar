<?php

namespace Database\Factories;

use App\Enums\PilarLean;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class CategoriaRestricaoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'nome' => $this->faker->words(2, true),
            'pilar_lean' => $this->faker->randomElement(PilarLean::cases())->value,
        ];
    }
}
