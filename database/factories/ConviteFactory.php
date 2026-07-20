<?php

namespace Database\Factories;

use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ConviteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'obra_id' => Work::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'token' => Str::random(64),
            'convidado_por_id' => User::factory(),
            'status' => 'pendente',
            'expira_em' => now()->addDays(7),
            'aceito_em' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($convite) {
            if ($convite->obra && $convite->obra->tenant_id !== $convite->tenant_id) {
                $convite->obra->forceFill(['tenant_id' => $convite->tenant_id])->saveQuietly();
            }
            if ($convite->convidadoPor && $convite->convidadoPor->tenant_id !== $convite->tenant_id) {
                $convite->convidadoPor->forceFill(['tenant_id' => $convite->tenant_id])->saveQuietly();
            }
            if (! $convite->perfil_id) {
                $perfil = Perfil::porSlugPadrao(Tenant::find($convite->tenant_id), 'encarregado');
                $convite->forceFill(['perfil_id' => $perfil?->id])->saveQuietly();
            }
        });
    }
}
