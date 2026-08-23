<?php

namespace App\Exceptions;

/**
 * Ciclo 18, Etapa 18.5.1 — lançada por App\Actions\Engenharia\EmitirGrd
 * quando o conteúdo da GRD não satisfaz alguma das regras de emissão
 * (sem item/destinatário/distribuição, distribuição órfã, entidade
 * cross-obra, revisão não vigente, revisão não liberada para construção).
 * Distinta de GrdImutavelException (que é sobre TENTAR mutar uma GRD já
 * emitida) — esta é sobre o CONTEÚDO de uma GRD ainda em Rascunho não
 * estar pronto para virar Emitida.
 */
class GrdEmissaoInvalidaException extends \RuntimeException
{
}
