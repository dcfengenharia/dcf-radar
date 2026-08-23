<?php

namespace App\Support\CentralProntidao;

/**
 * Estado visual DERIVADO exibido pela Central de Prontidão (Ciclo 15,
 * Etapa B.1) — nunca persistido, nunca uma nova fonte de verdade de
 * prontidão (Ciclo 14, princípio 10). `AtividadeProntidaoView::$pronta`
 * continua vindo exclusivamente de `Atividade::scopeProntas()` — este
 * enum classifica a APRESENTAÇÃO daquele valor já calculado, cruzado com
 * Concluída (que nem passa pela decisão de prontidão) e com alertas
 * contextuais que NUNCA alteram nem `pronta` nem `statusOperacional`
 * (PlanoAcao/Suprimento/Engenharia via ItemSuprimento — Ciclo 14,
 * princípios 5 e 6; CentralProntidaoQuery é quem decide qual caso se
 * aplica, nunca este enum).
 *
 * Ciclo 18, Etapa 18.4.CORREÇÃO — a exceção introduzida pela 18.4 (GED
 * promovia só `statusOperacional`, nunca `pronta`) foi ELIMINADA depois
 * que a auditoria adversarial provou empiricamente que isso permitia
 * comprometer no Plano Semanal uma atividade que a Central classificava
 * como "Não pronta" (duas verdades operacionais divergentes sobre a
 * mesma atividade). Desde a 18.4.CORREÇÃO, `Atividade::scopeProntas()`
 * (a fonte canônica) JÁ considera Documento de Engenharia vinculado
 * DIRETAMENTE (Ciclo 18.1) e não liberado para construção — `NaoPronta`
 * aqui é consequência direta de `! pronta`, nunca mais uma regra própria
 * desta Central. `AtividadeProntidaoView::$documentosBloqueantes`
 * continua populado independentemente (inclusive quando `statusOperacional
 * === Concluida`) só pra EXPLICAR o motivo na UI/export — nunca mais pra
 * decidir `statusOperacional` por conta própria.
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
