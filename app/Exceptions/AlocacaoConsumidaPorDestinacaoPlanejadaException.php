<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 20, Etapa 20.2 — App\Actions\Suprimentos\AlocarRequisicaoAoPacote
 * ganhou um 3º guard (ao lado do já existente de RequisicaoCompraItem):
 * uma AlocacaoRequisicaoPacote não pode ser reduzida/removida se isso
 * deixaria a demanda formal do par Pacote+Material abaixo do que já foi
 * planejado em DestinacaoPlanejadaMaterial.
 */
class AlocacaoConsumidaPorDestinacaoPlanejadaException extends Exception
{
}
