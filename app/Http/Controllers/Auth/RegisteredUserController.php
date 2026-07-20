<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Plano;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Jetstream\Jetstream;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register', [
            'planosAtivos' => Plano::where('ativo', true)->orderBy('preco_mensal')->get(),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'company_name' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'razao_social' => ['nullable', 'string', 'max:255'],
            'plano_id' => ['nullable', Rule::exists('planos', 'id')->where('ativo', true)],
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature() ? ['required', 'accepted'] : '',
        ], [
            'terms.required' => 'Você precisa aceitar a Política de Privacidade e os Termos de Uso para se cadastrar.',
            'terms.accepted' => 'Você precisa aceitar a Política de Privacidade e os Termos de Uso para se cadastrar.',
        ]);

        $user = DB::transaction(function () use ($request) {
            $tenant = Tenant::comPlanoTrialForcado($request->plano_id, function () use ($request) {
                return Tenant::create([
                    'name' => $request->company_name,
                    'cnpj' => $request->cnpj,
                    'razao_social' => $request->razao_social,
                ]);
            });

            $user = User::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'tenant_id' => $tenant->id,
            ]);

            $tenant->update(['criado_por_id' => $user->id]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('verification.notice');
    }
}
