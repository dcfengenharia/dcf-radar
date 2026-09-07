<?php

namespace App\DTOs\LicoesAprendidas;

use App\Enums\AreaFuncionalLicao;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Models\Work;

/**
 * Ciclo 23, Etapa 23.2 — resultado de `PrepararContextoNovaLicao`. Só
 * dado, `readonly`, nunca persistido — vive em memória entre "clique no
 * CTA" e "usuário confirma o salvamento" (Seção 20: abrir nunca cria
 * nada). Carrega só sugestões OBJETIVAS (Seção 8) — nada de causa/
 * aprendizado/recomendação, que continuam 100% em branco pro usuário
 * preencher.
 */
final readonly class ContextoNovaLicao
{
    /**
     * @param  array<int, array{tipo: TipoEntidadeVinculoLicao, id: string, titulo: string}>  $vinculosComplementares
     */
    public function __construct(
        public TipoEntidadeVinculoLicao $tipoOrigem,
        public string $entidadeOrigemId,
        public string $tituloOrigemSnapshot,
        public ?Work $obraSugerida,
        public bool $obraDeterministica,
        public string $tituloSugerido,
        public ?string $disciplinaIdSugerida,
        public AreaFuncionalLicao $areaSugerida,
        public array $vinculosComplementares,
    ) {
    }
}
