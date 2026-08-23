<?php

namespace App\Enums;

/**
 * Ciclo 17, A.9.4 — qual entidade concreta a inconsistência referencia.
 * Sem contraparte "polimórfica" de verdade — string simples, discrimina
 * como interpretar `entidade_id`.
 *
 * Ciclo 17, A.9.5 — 2 casos novos, os únicos que a coluna NOT NULL
 * `entidade_id` de `inconsistencias_avanco` não conseguiria representar
 * sem eles: `ProgramacaoSemanal` (existia programação pra aquela semana,
 * `entidade_id` = `programacoes_semanais.id`, usado por
 * `*ForaProgramacaoSemanal`) e `Atividade` (não existe NENHUMA entidade
 * de programação pra referenciar — nenhuma programação existia pra
 * aquela semana — `entidade_id` reaproveita o próprio `atividade_id`,
 * usado por `*SemProgramacaoSemanal`). Decisão deliberada de NÃO tornar
 * `entidade_id` nullable: manter a coluna NOT NULL preserva a proteção
 * do unique constraint contra duplicação silenciosa (MySQL trata NULL
 * como distinto de si mesmo dentro de um índice único — ver
 * `feedback_...` sobre esse mesmo risco documentado alhures no projeto).
 */
enum EntidadeInconsistenciaAvanco: string
{
    case Restricao = 'restricao';
    case ItemProntidao = 'item_prontidao';
    case ProgramacaoSemanal = 'programacao_semanal';
    case Atividade = 'atividade';
}
