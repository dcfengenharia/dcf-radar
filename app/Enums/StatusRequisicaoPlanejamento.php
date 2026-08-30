<?php

namespace App\Enums;

/**
 * Ciclo 19, Etapa 19.2 — mesma simplicidade já aprovada em StatusGrd:
 * só Rascunho|Emitida. Cancelamento não tem necessidade real confirmada
 * nesta fase (ver CLAUDE.md, Etapa 19.2) — decisão explícita de não
 * inventar um terceiro estado sem uso real ainda.
 */
enum StatusRequisicaoPlanejamento: string
{
    case Rascunho = 'rascunho';
    case Emitida = 'emitida';

    public function label(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Emitida => 'Emitida',
        };
    }
}
