<?php

namespace App\DTOs;

use Carbon\Carbon;

readonly class PlanoImportacao
{
    public function __construct(
        /** @var TarefaImportada[] Folhas a criar no DB */
        public array $criar,
        /** @var TarefaImportada[] Folhas a atualizar no DB */
        public array $atualizar,
        /** @var TarefaImportada[] Tarefas-resumo (viram PacoteTrabalho) */
        public array $pacotes,
        /** @var string[] IDs de Atividade no DB a arquivar */
        public array $removerIds,
        /** @var string[] Nomes das atividades a arquivar (para exibição na prévia) */
        public array $removerNomes,
        /** @var string[] Nomes de tarefas do XML sem atividade correspondente (modo Avanço — nunca criadas) */
        public array $ignoradasNomes,
        public ?Carbon $dataStatus,
        public float $totalBaselineHh,
        public float $totalWorkHh,
        public float $totalRealHh,
        /** @var HorasPeriodo[] */
        public array $horasPeriodos,
    ) {}
}
