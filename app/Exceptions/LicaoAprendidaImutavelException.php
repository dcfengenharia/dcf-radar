<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 23, Etapa 23.1 — uma LicaoAprendida Publicada ou Arquivada é
 * imutável pra conteúdo (mesmo idioma já usado em GRD/Requisição de
 * Compra/Requisição de Planejamento): corrigir o texto exige arquivar +
 * registrar uma lição nova, nunca editar de volta a já publicada.
 */
class LicaoAprendidaImutavelException extends Exception
{
}
