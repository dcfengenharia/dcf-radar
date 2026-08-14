<?php

namespace App\Support\CentralProntidao;

use Carbon\Carbon;

/**
 * View consolidada de UMA atividade pra Central de Prontidão (Ciclo 15,
 * Etapa B.1) — puramente estrutura de dados, sem nenhuma lógica de
 * consulta ao banco (isso vive só em CentralProntidaoQuery). `$pronta` é
 * copiado tal e qual da fonte canônica (`Atividade::scopeProntas()`) —
 * este DTO nunca decide prontidão por conta própria (Ciclo 14, princípio 2).
 *
 * @param  RestricaoResumo[]  $restricoesBloqueantes
 * @param  RestricaoResumo[]  $restricoesNaoBloqueantes
 * @param  string[]  $checklistPendentes  nomes dos itens de prontidão ainda não concluídos
 * @param  PlanoAcaoResumo[]  $planoAcoesAbertas
 * @param  SuprimentoAlerta[]  $suprimentos  só itens EmRisco/Atrasado (MVP)
 * @param  EngenhariaAlerta[]  $engenharia  só documentos atrasados/não emitidos (MVP)
 * @param  string[]  $resumoMotivos  textos curtos prontos pra badge/UI futura
 */
final readonly class AtividadeProntidaoView
{
    public function __construct(
        public string $atividadeId,
        public ?string $externalUid,
        public ?string $codigoCronograma,
        public string $nome,
        public ?Carbon $inicioPlanejado,
        public ?string $pacoteNome,
        public ?string $disciplinaNome,
        public ?string $frenteNome,
        public ?string $responsavelNome,
        public StatusOperacionalProntidao $statusOperacional,
        public bool $pronta,
        public array $restricoesBloqueantes,
        public array $restricoesNaoBloqueantes,
        public int $checklistTotal,
        public int $checklistConcluido,
        public array $checklistPendentes,
        public array $planoAcoesAbertas,
        public array $suprimentos,
        public array $engenharia,
        public array $resumoMotivos,
    ) {
    }
}
