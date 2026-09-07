<?php

namespace App\Exceptions;

use Exception;

/**
 * Ciclo 23, Etapa 23.1 — uma LicaoAprendida não pode ser publicada
 * (EmValidacao → Publicada) se faltar algum dos requisitos mínimos
 * (título/situação/recomendação futura/tipo/área/obra de origem —
 * causa/impacto/ação/resultado ficam sempre opcionais, uma Boa Prática
 * pode não ter "causa negativa"). `$camposFaltantes` carrega os nomes
 * pra a UI mostrar exatamente o que falta, nunca uma mensagem genérica.
 */
class LicaoAprendidaIncompletaException extends Exception
{
    /** @param array<int, string> $camposFaltantes */
    public function __construct(public readonly array $camposFaltantes)
    {
        parent::__construct('Lição incompleta para publicação: '.implode(', ', $camposFaltantes));
    }
}
