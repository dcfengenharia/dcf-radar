<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\Convite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Laravel\Jetstream\Jetstream;

class ConviteController extends Controller
{
    use PasswordValidationRules;

    public function show(string $token): View|RedirectResponse
    {
        $convite = Convite::where('token', $token)->first();

        if (! $this->conviteValido($convite)) {
            return $this->redirecionarConviteInvalido();
        }

        return view('auth.aceitar-convite', ['convite' => $convite]);
    }

    public function aceitar(string $token, Request $request): RedirectResponse
    {
        $convite = Convite::where('token', $token)->first();

        if (! $this->conviteValido($convite)) {
            return $this->redirecionarConviteInvalido();
        }

        $obra = $convite->obra;
        $tenant = $obra->tenant;

        $usuarioExistente = User::where('tenant_id', $tenant->id)->where('email', $convite->email)->first();

        // Pré-produção, Etapa 2.2 (achado B1) — invariável: `ativo=false`
        // impede autenticação e qualquer mutação decorrente dela, também no
        // aceite de convite pra uma conta JÁ EXISTENTE. Checado ANTES de
        // validar o formulário (nenhum dado do POST importa pra essa
        // decisão) e antes de qualquer escrita — nunca `Auth::login()`,
        // nunca `obra_user`, nunca reativação silenciosa. O convite
        // permanece `pendente` (nada foi de fato aceito) — quem convidou
        // pode reenviar depois que a conta for reativada pelo administrador
        // da plataforma; resposta neutra, nunca confirma/nega o motivo
        // exato (evita expor status de conta a quem só tem o link).
        if ($usuarioExistente && ! $usuarioExistente->ativo) {
            return redirect()->route('login')
                ->with('flash.banner', 'Não foi possível concluir o aceite deste convite. Fale com o administrador da plataforma.')
                ->with('flash.bannerStyle', 'danger');
        }

        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature() ? ['required', 'accepted'] : '',
        ], [
            'terms.required' => 'Você precisa aceitar a Política de Privacidade e os Termos de Uso para criar sua conta.',
            'terms.accepted' => 'Você precisa aceitar a Política de Privacidade e os Termos de Uso para criar sua conta.',
        ]);

        if (! $usuarioExistente) {
            $limite = $tenant->limiteUsuarios();
            if ($limite !== null && User::where('tenant_id', $tenant->id)->count() >= $limite) {
                return redirect()->route('login')
                    ->with('flash.banner', 'Este convite não pode ser aceito agora: a empresa atingiu o limite de usuários do plano contratado. Peça a quem te convidou pra falar com o administrador da conta.')
                    ->with('flash.bannerStyle', 'danger');
            }
        }

        $usuario = DB::transaction(function () use ($convite, $obra, $tenant, $request, $usuarioExistente) {
            $usuario = $usuarioExistente;

            if (! $usuario) {
                $usuario = User::create([
                    'tenant_id' => $tenant->id,
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'email' => $convite->email,
                    'password' => Hash::make($request->password),
                ]);

                // Clicar no link do convite já prova a posse do e-mail —
                // não precisa passar pela verificação de e-mail de novo.
                $usuario->forceFill(['email_verified_at' => now()])->save();
            }

            if (! $obra->users()->where('user_id', $usuario->id)->exists()) {
                $obra->users()->attach($usuario->id, ['perfil_id' => $convite->perfil_id]);
            }

            $convite->update(['status' => 'aceito', 'aceito_em' => now()]);

            return $usuario;
        });

        Auth::login($usuario);

        return redirect()->route('radar.entrar', $obra);
    }

    private function conviteValido(?Convite $convite): bool
    {
        return $convite !== null && $convite->status === 'pendente' && ! $convite->expirado();
    }

    private function redirecionarConviteInvalido(): RedirectResponse
    {
        return redirect()->route('login')
            ->with('flash.banner', 'Este convite é inválido, já foi usado ou expirou. Peça um novo convite a quem te chamou.')
            ->with('flash.bannerStyle', 'danger');
    }
}
