<?php

namespace App\Support\CentralProntidao;

use Carbon\Carbon;

/**
 * Leitura resumida de UMA Restricao pra Central de Prontidão (Ciclo 15,
 * Etapa B.1) — puramente estrutura de dados, sem nenhuma lógica de
 * consulta ao banco (isso vive só em CentralProntidaoQuery).
 */
final readonly class RestricaoResumo
{
    public function __construct(
        public string $id,
        public string $descricao,
        public ?Carbon $prazoLimite,
        public ?string $responsavel,
        public OrigemRestricaoProntidao $origem,
    ) {
    }

    /**
     * Auditoria Pré-Produção A2, Seção 4 (Timezone) — substitui
     * `$r->prazoLimite?->isPast()`, usado sem esse método em 3 pontos
     * (export Excel, PDF, partial de detalhe da Central de Prontidão).
     * Mesma correção/motivo de `App\Models\Restricao::estaVencida()`.
     */
    public function estaVencida(): bool
    {
        return \App\Support\Tempo\RelogioNegocio::dataEstaVencida($this->prazoLimite);
    }
}
