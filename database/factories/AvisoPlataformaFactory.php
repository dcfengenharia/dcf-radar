<?php

namespace Database\Factories;

use App\Enums\TipoAvisoPlataforma;
use Illuminate\Database\Eloquent\Factories\Factory;

class AvisoPlataformaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'titulo' => ucfirst($this->faker->sentence(3)),
            'mensagem' => $this->faker->paragraph(),
            'tipo' => TipoAvisoPlataforma::Informativo->value,
            'ativo' => true,
        ];
    }

    public function inativo(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }

    public function urgente(): static
    {
        return $this->state(fn () => ['tipo' => TipoAvisoPlataforma::Urgente->value]);
    }
}
