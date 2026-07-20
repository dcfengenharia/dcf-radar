<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->company(),
            'trading_name' => $this->faker->optional()->companySuffix(),
            'cnpj' => null,
            'logo_path' => null,
            'email' => $this->faker->optional()->companyEmail(),
            'phone' => null,
        ];
    }

    public function withLogo(string $path): static
    {
        return $this->state(['logo_path' => $path]);
    }
}
