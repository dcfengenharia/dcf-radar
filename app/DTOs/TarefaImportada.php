<?php

namespace App\DTOs;

use App\Enums\TipoRestricaoCronograma;
use Carbon\Carbon;

readonly class TarefaImportada
{
    public function __construct(
        public string $uid,
        public string $nome,
        public bool $isSummary,
        public bool $isMarco,
        public bool $caminhoCritico,
        public ?string $parentUid,
        public ?string $codigo,
        public ?Carbon $dataInicio,
        public ?Carbon $dataTermino,
        public ?Carbon $baselineInicio,
        public ?Carbon $baselineTermino,
        public ?Carbon $realInicio,
        public ?Carbon $realTermino,
        public float $baselineHoras,
        public float $workHoras,
        public float $realHoras,
        public ?float $percentualConcluido,
        public array $textos,
        // --- Dados estruturais (Fase 2A do Health Check) — aditivos, nunca
        // usados pelo fluxo de importação existente (HH/baseline/realizado/
        // tendência/persistência). Ver CLAUDE.md.
        /** @var PredecessoraLink[] */
        public array $predecessoras = [],
        /** Bruto, sem conversão de unidade — só o sinal é seguro de usar por enquanto. */
        public ?int $totalSlack = null,
        /** Bruto, sem conversão de unidade — só o sinal é seguro de usar por enquanto. */
        public ?int $freeSlack = null,
        public ?TipoRestricaoCronograma $tipoRestricao = null,
        /** Código numérico original do MSPDI — preservado mesmo quando `tipoRestricao` é null (código desconhecido). */
        public ?int $tipoRestricaoCodigoOriginal = null,
        public ?Carbon $dataRestricao = null,
        /** true quando <Active> está ausente do XML — "ausente" nunca é tratado como inativa. */
        public bool $ativa = true,
        // --- Modo de agendamento (Fase 2B.2A do Health Check) — aditivo,
        // nunca usado pelo fluxo de importação existente. Ver CLAUDE.md.
        /**
         * Normalizado a partir de <Manual> (MSPDI): true = Manualmente
         * Agendada, false = Automaticamente Agendada, null = desconhecido
         * (elemento ausente do XML — schemas anteriores ao MS Project 2010
         * não têm esse conceito, ou o exportador não o preenche). Ausência
         * NUNCA é tratada como "automática" nem como "manual" — permanece
         * desconhecida de propósito.
         */
        public ?bool $agendamentoManual = null,
        /** Valor bruto de <Manual> exatamente como veio do XML ('1'/'0'/'true'/'false'), null quando o elemento está ausente. */
        public ?string $agendamentoManualBruto = null,
    ) {}
}
