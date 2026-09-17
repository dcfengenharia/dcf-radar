<?php

namespace App\Support\Perfis;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Fase 2E — defesa em profundidade para Actions de domínio críticas
 * (operação humana com efeito formal: emissão, adjudicação, aprovação).
 *
 * Reaproveita EXCLUSIVAMENTE o resolver já aprovado na Fase 2B
 * (`App\Models\Concerns\HasObraPapel::temPermissaoNaObra()`) — nunca uma
 * segunda regra de autoridade nem um cache paralelo. Não substitui a
 * autorização já feita pelo caller (UI/Livewire/Controller, que continua
 * intocada) — é a SEGUNDA camada, dentro da própria Action, contra
 * chamada direta que bypassa o caller (teste sem ator autorizado, Job/
 * Command futuro mal-configurado, uso indevido via `app(Action::class)`).
 *
 * Ator SEMPRE explícito (`User $ator`, nunca `Auth::user()` resolvido
 * aqui dentro) — a própria Action já recebe o `User` que a chamou, então
 * este helper só consulta a autoridade DELE na obra do RECURSO (nunca da
 * obra ativa da sessão), derivada pelo próprio caller antes de invocar.
 */
final class GarantirAutoridadeNaObra
{
    public static function checar(User $ator, string $obraId, string $funcionalidade, string $acao, ?string $mensagem = null): void
    {
        if (! $ator->temPermissaoNaObra($obraId, $funcionalidade, $acao)) {
            throw new AuthorizationException(
                $mensagem ?? 'Você não tem autoridade para executar esta ação nesta obra.'
            );
        }
    }
}
