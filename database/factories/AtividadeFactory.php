<?php

namespace Database\Factories;

use App\Enums\StatusAtividade;
use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

class AtividadeFactory extends Factory
{
    public function definition(): array
    {
        $inicio = $this->faker->dateTimeBetween('now', '+3 months');

        return [
            'tenant_id'       => Tenant::factory(),
            'obra_id'         => Work::factory(),
            'pacote_trabalho_id' => null,
            'disciplina_id'   => null,
            'responsavel_id'  => null,
            'created_by_id'   => null,
            'nome'            => $this->faker->sentence(3),
            'duracao_dias'    => $this->faker->numberBetween(1, 30),
            'inicio_planejado' => $inicio,
            'data_termino'    => $this->faker->dateTimeBetween($inicio, '+6 months'),
            'status'          => StatusAtividade::Planejado->value,
            'caminho_critico' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($atividade) {
            if ($atividade->obra && $atividade->obra->tenant_id !== $atividade->tenant_id) {
                $atividade->obra->forceFill(['tenant_id' => $atividade->tenant_id])->saveQuietly();
            }
        });
    }
}
