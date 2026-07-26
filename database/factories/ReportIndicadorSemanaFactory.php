<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReportIndicadorSemanaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'report_id' => Report::factory(),
            'categoria' => $this->faker->randomElement(['restricoes', 'engenharia', 'suprimentos']),
            'janela' => $this->faker->randomElement(['semana_anterior', 'semana_proxima']),
            'periodo_inicio' => now()->startOfWeek()->toDateString(),
            'periodo_fim' => now()->endOfWeek()->toDateString(),
            'total_previsto' => $this->faker->numberBetween(0, 10),
            'total_concluido' => $this->faker->numberBetween(0, 10),
            'detalhes' => [],
        ];
    }
}
