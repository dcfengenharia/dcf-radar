<?php

namespace App\Support\CentralProntidao;

use App\Enums\HealthCheckSeveridade;
use App\Enums\ResultadoReconciliacaoPlanoAcao;

/**
 * Leitura resumida de UM PlanoAcao ABERTO relacionado à atividade (via
 * `uids_referencia`, nunca FK — ver CentralProntidaoQuery::indexarPlanoAcoesPorUid())
 * pra Central de Prontidão (Ciclo 15, Etapa B.1). Puramente CONTEXTO —
 * nunca altera `AtividadeProntidaoView::$pronta` (Ciclo 14, princípio 5).
 *
 * `regraId` (Ciclo 15, Etapa B.2) existe só pra alimentar o deep-link
 * "Ver Plano de Ação" — reaproveita o MESMO mecanismo já usado pelo badge
 * do Mapa de Ações (`route('radar.plano-acao', ['regra' => ...])`,
 * Ciclo 11 Etapa E), nunca uma rota/parâmetro novo.
 */
final readonly class PlanoAcaoResumo
{
    public function __construct(
        public string $id,
        public string $titulo,
        public string $regraId,
        public ?HealthCheckSeveridade $severidade,
        public ?ResultadoReconciliacaoPlanoAcao $resultadoUltimaReconciliacao,
    ) {
    }
}
