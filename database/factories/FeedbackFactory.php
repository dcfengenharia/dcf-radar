<?php

namespace Database\Factories;

use App\Enums\TipoFeedback;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeedbackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'tipo' => $this->faker->randomElement(TipoFeedback::cases())->value,
            'mensagem' => $this->faker->paragraph(),
            'url_origem' => $this->faker->url(),
        ];
    }
}
