<?php

namespace App\Enums;

enum StatusAtividade: string
{
    case Planejado = 'planejado';
    case Comprometido = 'comprometido';
    case EmExecucao = 'em_execucao';
    case Concluido = 'concluido';
    case NaoConcluido = 'nao_concluido';
}
