<?php

namespace App\Actions\Fortify;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Jetstream\Jetstream;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => $this->passwordRules(),
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature() ? ['accepted', 'required'] : '',
        ])->validate();

        return DB::transaction(function () use ($input) {
            $tenant = Tenant::create([
                'name' => $input['company_name'],
            ]);

            $user = User::create([
                'first_name'        => $input['first_name'],
                'last_name'         => $input['last_name'],
                'email'             => $input['email'],
                'password'          => Hash::make($input['password']),
                'tenant_id'         => $tenant->id,
                // Em local/testing, pula a verificação de e-mail para que o usuário
                // chegue direto ao app após o registro (evita tela blankLayout sem navbar).
                'email_verified_at' => app()->isLocal() || app()->runningUnitTests() ? now() : null,
            ]);

            $tenant->update(['criado_por_id' => $user->id]);

            return $user;
        });
    }
}
