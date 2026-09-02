<?php

namespace App\DTOs\Engenharia;

use App\Enums\SeveridadeSituacao;
use Carbon\Carbon;

/**
 * Ciclo 22, Etapa 22.1 — fato gerencial de Engenharia acionável (Seção
 * 19). Forma DELIBERADAMENTE compatível com `App\DTOs\Gestao\
 * SituacaoGerencial` (mesmos campos: tipo/severidade/entidade/obra/
 * impacto/data/atividade/perfis/deepLink/contexto) — decisão explícita
 * de arquitetura (Seção 20, Opção C): esta etapa é SÓ read-model, nunca
 * um segundo motor de alertas. `SituacoesGerenciaisQuery`/
 * `TipoSituacaoGerencial`/Notifications/scheduler/digest NÃO são
 * tocados aqui. `tipo` é string livre (não `TipoSituacaoGerencial` —
 * esses tipos novos de GRD/Industrialização/Suprimentos documental não
 * existem nesse enum, e não são adicionados nesta etapa) justamente pra
 * deixar uma eventual fase futura (22.2+) livre pra decidir SE e COMO
 * emendar isso na Central de Notificações — sem essa decisão já ter
 * sido tomada prematuramente aqui. Severidade reaproveita
 * `SeveridadeSituacao` (Seção 21 — nunca inventar score novo).
 */
final readonly class FatoEngenharia
{
    public function __construct(
        public string $tipo,
        public SeveridadeSituacao $severidade,
        public string $obraId,
        public string $entidadeTipo,
        public string $entidadeId,
        public string $descricao,
        public ?Carbon $dataRelevante,
        public ?int $diasParaRelevante,
        public ?string $atividadeId,
        public array $destinatariosPerfis,
        public array $deepLink,
        public array $contexto = [],
    ) {
    }
}
