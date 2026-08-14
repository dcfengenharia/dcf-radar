<?php

namespace App\DTOs;

use App\Enums\TipoRelacionamentoPredecessora;

/**
 * Uma relação de predecessora extraída de <PredecessorLink> no MSPDI.
 *
 * IMPORTANTE (Fase 2A do Health Check): `linkLag`/`lagFormat` são
 * preservados BRUTOS, sem nenhuma conversão de unidade/magnitude — decisão
 * deliberada, documentada em CLAUDE.md. Antes de exibir algo como "2 dias
 * de lag" numa fase futura, a conversão precisa ser validada contra o
 * comportamento real do MS Project. Por enquanto, só o SINAL de `linkLag`
 * é seguro de usar (positivo/negativo/zero — verdadeiro em qualquer
 * unidade).
 */
readonly class PredecessoraLink
{
    public function __construct(
        public string $predecessoraUid,
        public ?TipoRelacionamentoPredecessora $tipo,
        /** Código numérico original do MSPDI — preservado mesmo quando `tipo` é null (código desconhecido). */
        public int $tipoCodigoOriginal,
        /** Valor bruto de LinkLag, sem conversão de unidade. Null quando ausente no XML. */
        public ?int $linkLag,
        /** Código bruto de LagFormat (unidade do LinkLag), sem interpretação. Null quando ausente no XML. */
        public ?int $lagFormat,
    ) {}
}
