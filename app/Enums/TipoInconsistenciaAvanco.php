<?php

namespace App\Enums;

/**
 * Ciclo 17, A.9.4 — os 4 tipos de inconsistência comprováveis só por
 * Fotografia F (o que o cronograma declarou nesta importação) × Fotografia O
 * (o que a plataforma sabia imediatamente antes dela).
 *
 * Ciclo 17, A.9.5 — mais 4 tipos, comprováveis por Fotografia F × Fotografia
 * P (`App\Models\AtividadeSnapshotProgramacao` — se a atividade fazia parte
 * da Programação Semanal historicamente aplicável ao instante do evento).
 * `*ForaProgramacaoSemanal` = existia Programação Semanal pra aquela
 * semana, mas a atividade não estava nela. `*SemProgramacaoSemanal` =
 * nenhuma Programação Semanal existia pra aquela semana (fato diferente,
 * nunca fundido no mesmo tipo — ver CLAUDE.md).
 */
enum TipoInconsistenciaAvanco: string
{
    case InicioComRestricaoPendente = 'inicio_com_restricao_pendente';
    case InicioComProntidaoPendente = 'inicio_com_prontidao_pendente';
    case ConclusaoComRestricaoPendente = 'conclusao_com_restricao_pendente';
    case ConclusaoComProntidaoPendente = 'conclusao_com_prontidao_pendente';
    case InicioForaProgramacaoSemanal = 'inicio_fora_programacao_semanal';
    case ConclusaoForaProgramacaoSemanal = 'conclusao_fora_programacao_semanal';
    case InicioSemProgramacaoSemanal = 'inicio_sem_programacao_semanal';
    case ConclusaoSemProgramacaoSemanal = 'conclusao_sem_programacao_semanal';

    public function label(): string
    {
        return match ($this) {
            self::InicioComRestricaoPendente => 'Início com Restrição pendente',
            self::InicioComProntidaoPendente => 'Início com Prontidão pendente',
            self::ConclusaoComRestricaoPendente => 'Conclusão com Restrição pendente',
            self::ConclusaoComProntidaoPendente => 'Conclusão com Prontidão pendente',
            self::InicioForaProgramacaoSemanal => 'Início fora da Programação Semanal',
            self::ConclusaoForaProgramacaoSemanal => 'Conclusão fora da Programação Semanal',
            self::InicioSemProgramacaoSemanal => 'Início sem Programação Semanal publicada',
            self::ConclusaoSemProgramacaoSemanal => 'Conclusão sem Programação Semanal publicada',
        };
    }
}
