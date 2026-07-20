<?php

namespace Database\Factories;

use App\Enums\StatusFatura;
use App\Models\Assinatura;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssinaturaFaturaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'assinatura_id' => Assinatura::factory(),
            'metodo_pagamento' => 'pix',
            'valor' => $this->faker->randomFloat(2, 99, 999),
            'status' => StatusFatura::Pendente->value,
            'vencimento' => now()->addDays(5),
        ];
    }

    public function paga(): static
    {
        return $this->state(fn () => [
            'status' => StatusFatura::Pago->value,
            'pago_em' => now(),
        ]);
    }
}
