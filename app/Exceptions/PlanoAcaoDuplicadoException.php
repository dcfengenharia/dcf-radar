<?php

namespace App\Exceptions;

use App\Models\PlanoAcao;

/**
 * Lançada por PlanoAcao::criarDeFinding() quando já existe uma ação Aberta
 * da MESMA obra + regra_id com sobreposição de uid com o finding sendo
 * usado agora — mesmo critério de identidade já usado por
 * PlanoAcaoReconciliador (App\Support\HealthCheck\PlanoAcao\SobreposicaoUid),
 * nunca uma heurística nova (Fase 4.2, decisão do usuário: "não invente
 * heurística adicional pra decidir que dois problemas são iguais"). Carrega
 * a ação existente pra a UI poder linkar direto pra ela.
 */
class PlanoAcaoDuplicadoException extends \RuntimeException
{
    public function __construct(public readonly PlanoAcao $acaoExistente)
    {
        parent::__construct('Já existe uma ação aberta para este problema.');
    }
}
