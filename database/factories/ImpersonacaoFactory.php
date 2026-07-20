<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImpersonacaoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'admin_user_id' => User::factory(),
            'tenant_id' => Tenant::factory(),
            'iniciado_em' => now(),
        ];
    }
}
