<?php

namespace Database\Factories;

use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestricaoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'atividade_id' => Atividade::factory(),
            'categoria_id' => null,
            'responsavel_id' => null,
            'responsavel_externo' => null,
            'created_by_id' => null,
            'descricao' => $this->faker->sentence(),
            'bloqueante' => true,
            'probabilidade' => $this->faker->numberBetween(0, 10),
            'impacto' => $this->faker->numberBetween(0, 10),
            'prazo_limite' => $this->faker->dateTimeBetween('now', '+2 months'),
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
            'resolvida_em' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($restricao) {
            if ($restricao->atividade && $restricao->atividade->tenant_id !== $restricao->tenant_id) {
                $restricao->atividade->forceFill(['tenant_id' => $restricao->tenant_id])->saveQuietly();
            }
        });
    }
}
