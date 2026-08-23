<?php

namespace App\Exceptions;

/**
 * Ciclo 18, Etapa 18.5.9 — já existe um aceite ATIVO (não invalidado)
 * pra este destinatário. Lançada tanto pela checagem de aplicação
 * (mensagem amigável no caminho comum) quanto ao capturar a violação da
 * UNIQUE estrutural do banco sob corrida genuína (2 registros
 * simultâneos) — nos dois casos, o usuário vê a mesma orientação:
 * invalidar o aceite atual antes de registrar um novo.
 */
class GrdAceiteJaAtivoException extends \RuntimeException
{
}
