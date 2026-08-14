<?php

namespace App\Support\HealthCheck;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;

/**
 * Índice uid => TarefaImportada com TODAS as tarefas do plano (criar +
 * atualizar + pacotes), incluindo tarefas-resumo e inativas — diferente de
 * `HealthCheckGrafoCronograma`, que deliberadamente EXCLUI resumo/inativa
 * dos seus nós (Fase 2B.1). As regras LOGIC (Fase 2B.2B) precisam resolver
 * predecessoras/sucessoras SEM esse filtro — ex.: LOGIC-009 precisa saber
 * quando a predecessora É uma tarefa-resumo, e LOGIC-010 precisa enxergar
 * a predecessora mesmo quando ela está inativa — por isso este índice é
 * uma abstração separada, não uma extensão do grafo estrutural.
 */
final class TarefasPorUid
{
    /** @return array<string, TarefaImportada> */
    public static function indexar(PlanoImportacao $plano): array
    {
        $indice = [];

        foreach ([...$plano->criar, ...$plano->atualizar, ...$plano->pacotes] as $tarefa) {
            $indice[$tarefa->uid] = $tarefa;
        }

        return $indice;
    }

    /** Serialização mínima e comum (uid/código/nome) usada pelos findings das regras LOGIC. */
    public static function resumo(TarefaImportada $tarefa): array
    {
        return [
            'uid' => $tarefa->uid,
            'codigo' => $tarefa->codigo,
            'nome' => $tarefa->nome,
        ];
    }
}
