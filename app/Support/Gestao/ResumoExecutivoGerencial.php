<?php

namespace App\Support\Gestao;

use Illuminate\Support\Collection;

/**
 * Ciclo 21, Etapa 21.2 — resumo executivo (Seção 16) SEMPRE calculado
 * sobre uma Collection<SituacaoGerencial> JÁ OBTIDA por
 * `SituacoesGerenciaisQuery::porObra()` — nunca executa a consulta de
 * novo. Puro agregador em memória (0 queries).
 */
class ResumoExecutivoGerencial
{
    /**
     * @param  Collection<int, \App\DTOs\Gestao\SituacaoGerencial>  $situacoes
     */
    public static function deSituacoes(Collection $situacoes): array
    {
        return [
            'total' => $situacoes->count(),
            'por_severidade' => $situacoes->countBy(fn ($s) => $s->severidade->value)->toArray(),
            'por_tipo' => $situacoes->countBy(fn ($s) => $s->tipo->value)->toArray(),
            'por_dominio' => $situacoes->countBy(fn ($s) => $s->tipo->dominio())->toArray(),
            'criticas' => $situacoes->filter(fn ($s) => $s->severidade === \App\Enums\SeveridadeSituacao::Critica)->count(),
            'altas' => $situacoes->filter(fn ($s) => $s->severidade === \App\Enums\SeveridadeSituacao::Alta)->count(),
            'atencao' => $situacoes->filter(fn ($s) => $s->severidade === \App\Enums\SeveridadeSituacao::Atencao)->count(),
            'informativas' => $situacoes->filter(fn ($s) => $s->severidade === \App\Enums\SeveridadeSituacao::Informativa)->count(),
        ];
    }
}
