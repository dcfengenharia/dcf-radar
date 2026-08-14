<?php

namespace App\Support\CentralProntidao;

/**
 * Estado visual DERIVADO exibido pela Central de Prontidão (Ciclo 15,
 * Etapa B.1) — nunca persistido, nunca uma nova fonte de verdade de
 * prontidão (Ciclo 14, princípio 10). `AtividadeProntidaoView::$pronta`
 * continua vindo exclusivamente de `Atividade::scopeProntas()`; este enum
 * só classifica a APRESENTAÇÃO daquele valor já calculado, cruzado com
 * Concluída (que nem passa pela decisão de prontidão) e com alertas
 * contextuais que NUNCA alteram `pronta` (PlanoAcao/Suprimento/
 * Engenharia — Ciclo 14, princípios 5 e 6; CentralProntidaoQuery é quem
 * decide qual caso se aplica, nunca este enum).
 */
enum StatusOperacionalProntidao: string
{
    case Pronta = 'pronta';
    case NaoPronta = 'nao_pronta';
    case Atencao = 'atencao';
    case Concluida = 'concluida';

    public function label(): string
    {
        return match ($this) {
            self::Pronta => 'Pronta',
            self::NaoPronta => 'Não pronta',
            self::Atencao => 'Atenção',
            self::Concluida => 'Concluída',
        };
    }
}
