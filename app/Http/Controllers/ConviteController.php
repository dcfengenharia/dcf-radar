<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\Convite;
use App\Models\Perfil;
use App\Models\User;
use App\Support\Perfis\RegistrarEventoAcesso;
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

            // FASE 2C, Seção 4-8 — Convite multiperfil: os perfis
            // concedidos são sempre REVALIDADOS aqui (nunca confiados
            // ao que foi gravado no envio), pra nunca atribuir um
            // Perfil que foi excluído/mudou de tenant entre o envio e
            // o aceite (Seção 7 — "falha segura, não atribuir
            // parcialmente").
            $perfisConvidados = \App\Support\Perfis\AtribuicaoPerfilConvite::perfisValidosParaAceite($convite);

            if (! $obra->users()->where('user_id', $usuario->id)->exists()) {
                $obra->users()->attach($usuario->id, ['perfil_id' => $perfisConvidados[0] ?? null]);
                \App\Support\AtribuicaoPerfilObra::substituirPerfis($obra, $usuario->id, $perfisConvidados);
            } else {
                // Seção 6 — decisão de produto explícita: se o usuário
                // JÁ é membro desta obra no instante do aceite (ex.:
                // foi adicionado direto por outro caminho enquanto o
                // convite ainda estava pendente), o aceite ADICIONA os
                // perfis convidados à coleção já existente — NUNCA
                // substitui. Substituir arriscaria revogar
                // silenciosamente um perfil que o usuário já tinha
                // (ex.: Admin) só porque um convite mais restrito foi
                // aceito depois; adicionar nunca reduz a capacidade de
                // ninguém, então nenhum guard de "último Admin" é
                // necessário aqui.
                foreach ($perfisConvidados as $perfilId) {
                    \App\Support\AtribuicaoPerfilObra::adicionarPerfil($obra, $usuario->id, $perfilId);
                }
            }

            $convite->update(['status' => 'aceito', 'aceito_em' => now()]);

            // FASE 2D, Seção 17 — evento "convite aceito", único e
            // SEPARADO do envio (Seção 7: nunca duplica o `H`/`I`/`J`
            // genérico que `AtribuicaoPerfilObra` produziria — por isso
            // as duas chamadas acima (substituirPerfis/adicionarPerfil)
            // deliberadamente NÃO recebem ator/origem). Ator = o
            // próprio usuário que está aceitando (Seção 25 — é quem de
            // fato executa a ação neste instante); dentro da MESMA
            // transação da concessão de perfis (Seção 31).
            $perfisConcedidosModels = $perfisConvidados === [] ? collect() : Perfil::whereIn('id', $perfisConvidados)->get();
            RegistrarEventoAcesso::conviteAceito($usuario, $obra, $perfisConcedidosModels, $convite->email, $convite->convidadoPor);

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
