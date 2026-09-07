<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 3/19) — a unidade corporativa Lição×Obra
 * já tem uma reaplicação registrada. Lançada quando o `INSERT` colide
 * com `licao_reaplicacoes_tenant_licao_obra_unique` (SQLSTATE 23000/
 * MySQL 1062) — nunca uma checagem em PHP como única defesa; é a
 * garantia estrutural do banco que protege double-click/concorrência
 * real (mesmo idioma já usado em `VincularEntidadeALicao`/
 * `PlanoAcao::transformarEmRestricoes()`).
 */
class ReaplicacaoLicaoJaRegistradaException extends Exception
{
}
