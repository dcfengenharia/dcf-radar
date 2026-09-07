<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 23, Etapa 23.5.B.CORREÇÃO (Seção 1) — write-path seguro por
 * construção: `App\Actions\LicoesAprendidas\RegistrarReaplicacaoLicao`
 * SEMPRE revalida, internamente, que o ator explícito recebido
 * (`User $usuario`, nunca `Auth::user()` implícito — compatível com
 * Jobs/CLI) possui `gestao.licoes-aprendidas|criar` na obra de destino,
 * mesmo que o chamador (trait/componente Livewire) já tenha checado a
 * mesma coisa antes só para UX. Nunca confiar em botão oculto, Policy
 * chamada só pelo Blade, ou wrapper Livewire como única defesa.
 */
class ReaplicacaoLicaoNaoAutorizadaException extends Exception
{
}
