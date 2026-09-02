<?php

namespace App\Support\Estoque;

use Illuminate\Database\Eloquent\Model;

/**
 * Ciclo 20, Etapa 20.8 — resultado tipado de
 * App\Support\Estoque\ResolverCodigoEstoque::resolver(). `ativo` é `null`
 * quando o conceito não se aplica à entidade (UnidadeEstoque nunca teve
 * campo `ativo` — é identidade física, não cadastro com ciclo de vida
 * ativo/inativo).
 */
final class ResultadoResolucaoCodigoEstoque
{
    public function __construct(
        public readonly string $tipo,
        public readonly Model $entidade,
        public readonly ?bool $ativo,
    ) {
    }
}
