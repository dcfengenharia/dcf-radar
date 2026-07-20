<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PlanoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nome' => ucfirst($this->faker->unique()->word()),
            'descricao' => $this->faker->sentence(),
            'preco_mensal' => $this->faker->randomFloat(2, 99, 999),
            'max_obras' => $this->faker->randomElement([1, 5, 10, null]),
            'max_usuarios' => $this->faker->randomElement([3, 10, 25, null]),
            'limite_upload_mb' => 100,
            'ativo' => true,
        ];
    }

    public function inativo(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }
}
