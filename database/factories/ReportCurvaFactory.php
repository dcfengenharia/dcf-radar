<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReportCurvaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'report_id' => Report::factory(),
            'pacote_trabalho_id' => null,
            'ordem' => 0,
            'titulo_exibicao' => $this->faker->words(2, true),
            'total_hh_previsto' => 0,
        ];
    }
}
