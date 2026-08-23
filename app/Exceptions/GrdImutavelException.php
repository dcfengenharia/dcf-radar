<?php

namespace App\Exceptions;

/**
 * Ciclo 18, Etapa 18.5.1 — lançada quando qualquer mutação de conteúdo
 * (item, destinatário, distribuição, quantidade, observação) é tentada
 * sobre uma Grd que já não está em Rascunho. Guard server-side em TODOS
 * os métodos de App\Actions\Engenharia\AtualizarRascunhoGrd — nunca
 * confiado só à UI (instrução explícita do briefing 18.5.1, seção 5).
 */
class GrdImutavelException extends \RuntimeException
{
}
