<?php

namespace App\Enums;

/**
 * Ciclo 20, Etapa 20.5 — status da OrdemIndustrializacao, mesmo padrão
 * enxuto de `StatusRequisicaoCompra`/`StatusGrd`/`StatusRequisicaoPlanejamento`.
 * `Concluida` é transição DERIVADA (todos os Produtos plenamente
 * entregues), nunca setada manualmente por nenhuma Action de UI.
 */
enum StatusOrdemIndustrializacao: string
{
    case Rascunho = 'rascunho';
    case Emitida = 'emitida';
    case Concluida = 'concluida';

    public function label(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Emitida => 'Emitida',
            self::Concluida => 'Concluída',
        };
    }

    public function estaEmitida(): bool
    {
        return $this === self::Emitida;
    }
}
