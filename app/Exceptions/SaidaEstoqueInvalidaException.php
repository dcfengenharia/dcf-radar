<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.3 — validação de negócio de
 * App\Actions\Estoque\RegistrarSaidaEstoque que não é sobre saldo físico
 * insuficiente (esse caso usa App\Exceptions\SaldoFisicoInsuficienteException,
 * já existente desde a 20.2): data futura, Material/LocalEstoque
 * inativo, modo de rastreabilidade exigindo Unidade ausente, Reserva
 * incompatível (Material/Local/Unidade/Pacote/obra errados, ou já sem
 * saldo pendente de consumo), retirado_por e retirado_por_externo
 * informados ao mesmo tempo. Mensagem sempre didática, nunca SQL cru.
 */
class SaidaEstoqueInvalidaException extends Exception
{
}
