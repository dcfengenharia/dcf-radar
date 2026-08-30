<?php

namespace App\Enums;

/**
 * Ciclo 20, Etapa 20.1 — tipo de MovimentacaoEstoque. Coluna string (não
 * MySQL ENUM físico), então o conjunto de valores cresce em fases
 * futuras sem migration.
 *
 * Ciclo 20, Etapa 20.1.CORREÇÃO — `fatorSaldo()` fecha o Achado B2 da
 * auditoria adversarial: `App\Support\Estoque\SaldoEstoque` nunca mais
 * faz um `SUM(quantidade)` cru assumindo implicitamente que toda
 * movimentação é positiva — toda agregação passa por este fator central.
 * `quantidade` em `MovimentacaoEstoque` permanece SEMPRE positiva (regra
 * de domínio validada em `RegistrarEntradaEstoque`/`RegistrarSaidaEstoque`,
 * nunca alterada por esta correção) — é o TIPO que decide o sinal
 * contábil do ledger, nunca o valor armazenado.
 *
 * Ciclo 20, Etapa 20.3 — `Saida` adicionado (`fatorSaldo() = -1`),
 * exatamente como o comentário acima já previa: nenhum método de
 * `App\Support\Estoque\SaldoEstoque` precisou ser tocado. Ainda NÃO
 * antecipados (instrução explícita do pedido): Transferencia/Ajuste/
 * Industrializacao/Devolucao/Estorno.
 */
enum TipoMovimentacaoEstoque: string
{
    case Entrada = 'entrada';
    case Saida = 'saida';

    public function label(): string
    {
        return match ($this) {
            self::Entrada => 'Entrada',
            self::Saida => 'Saída',
        };
    }

    public function fatorSaldo(): int
    {
        return match ($this) {
            self::Entrada => 1,
            self::Saida => -1,
        };
    }
}
