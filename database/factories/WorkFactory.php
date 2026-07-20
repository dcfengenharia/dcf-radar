<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Work>
 */
class WorkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'client_id' => Client::factory(),
            'name' => $this->faker->sentence(3),
            'location' => $this->faker->city(),
            'budget_total' => $this->faker->randomFloat(2, 100000, 10000000),
            'start_date_baseline' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'end_date_baseline' => $this->faker->dateTimeBetween('now', '+2 years'),
            'status' => 'planejamento',
        ];
    }

    public function configure(): static
    {
        // Garante que o Client gerado automaticamente pertença ao mesmo tenant da Obra.
        // Quando o teste passa .for($tenant)->for($client) explicitamente, o afterCreating
        // ainda executa mas o client já terá o tenant_id correto.
        return $this->afterCreating(function (Work $work) {
            if ($work->client && $work->client->tenant_id !== $work->tenant_id) {
                $work->client->forceFill(['tenant_id' => $work->tenant_id])->saveQuietly();
            }
        });
    }
}
