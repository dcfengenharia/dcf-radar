<?php

namespace App\Exceptions;

use Exception;

/**
 * Auditoria Pré-Produção A2.1, Seções 5-9 — lançada quando um
 * `operation_id` já usado (mesmo tenant) chega de novo, mas com um
 * payload de negócio que DIVERGE do fato já registrado — nunca quando o
 * payload é idêntico (esse caso é retry legítimo da MESMA intenção e
 * retorna o fato já existente, sem erro, Seção 9 "comportamento
 * idempotente amigável"). Mensagem sempre didática, nunca expõe SQL/ID
 * técnico — a UI trata isso como "esta ação já foi processada de forma
 * diferente, recarregue a tela antes de tentar de novo".
 */
class OperacaoEstoqueDuplicadaException extends Exception
{
    public function __construct(string $message = 'Esta operação já foi registrada com dados diferentes. Recarregue a tela antes de tentar novamente.')
    {
        parent::__construct($message);
    }
}
