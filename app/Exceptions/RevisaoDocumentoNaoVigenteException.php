<?php

namespace App\Exceptions;

use App\Models\DocumentoEngenhariaRevisao;

/**
 * Ciclo 18, Etapa 18.3.CORREÇÃO — lançada por AlterarLiberacaoRevisaoDocumento
 * quando a revisão recebida NÃO é a revisão vigente canônica do seu
 * Documento (`DocumentoEngenharia::revisaoVigente()`) no momento da
 * chamada. Os dois únicos pontos de entrada alcançáveis por UI/Livewire
 * (`liberarRevisaoVigente()`/`revogarLiberacaoRevisaoVigente()`) sempre
 * resolvem a revisão vigente fresca antes de chamar a Action, então esta
 * exceção nunca deveria disparar por esse caminho — é a defesa em
 * profundidade da Action em si, contra qualquer chamador direto (código
 * futuro, comando, job) que passe uma revisão histórica por engano.
 */
class RevisaoDocumentoNaoVigenteException extends \RuntimeException
{
    public function __construct(public readonly DocumentoEngenhariaRevisao $revisao)
    {
        parent::__construct('Esta revisão não é mais a revisão vigente do documento — a liberação para construção só pode ser alterada na revisão vigente atual.');
    }
}
