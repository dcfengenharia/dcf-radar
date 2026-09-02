<?php

namespace App\Enums;

/**
 * Ciclo 21, Etapa 21.3 — status enxuto do ciclo de vida de uma
 * `App\Models\SituacaoOcorrencia`, mesmo espírito de `StatusPlanoAcao`
 * (Fase 4): só 2 estados, sem "Cancelada" — diferente de PlanoAcao (que
 * é uma ação MANUAL do usuário, cancelável), uma ocorrência é
 * inteiramente DERIVADA de `SituacoesGerenciaisQuery` — nunca existe um
 * cenário de "cancelar" uma ocorrência sem o fato subjacente deixar de
 * ser verdadeiro (que já é o próprio caminho de `Resolvida`).
 */
enum StatusSituacaoOcorrencia: string
{
    case Ativa = 'ativa';
    case Resolvida = 'resolvida';

    public function label(): string
    {
        return match ($this) {
            self::Ativa => 'Ativa',
            self::Resolvida => 'Resolvida',
        };
    }

    public function estaAtiva(): bool
    {
        return $this === self::Ativa;
    }
}
