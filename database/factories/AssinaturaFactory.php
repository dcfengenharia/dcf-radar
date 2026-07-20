<?php

namespace Database\Factories;

use App\Enums\StatusAssinatura;
use App\Models\Plano;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssinaturaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plano_id' => Plano::factory(),
            'status' => StatusAssinatura::Trial->value,
            'inicio' => now(),
            'fim_trial' => now()->addDays(14),
        ];
    }
}
