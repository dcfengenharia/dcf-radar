<?php

namespace App\Enums;

/**
 * Ciclo 17, A.9.5 — qual data factual da Fotografia F alimentou uma linha
 * de `atividade_snapshot_programacoes`: `real_inicio` (Inicio) ou
 * `real_termino` (Conclusao). Nunca a mesma linha serve pros dois — início
 * e conclusão são avaliados de forma independente (mesmo princípio já
 * usado pelo Detector de Inconsistências de Avanço, A.9.4).
 */
enum EventoFotografiaProgramacao: string
{
    case Inicio = 'inicio';
    case Conclusao = 'conclusao';
}
