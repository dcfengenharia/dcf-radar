<?php

namespace App\Support\CentralProntidao;

/**
 * Origem de uma Restricao, DERIVADA em leitura a partir dos campos já
 * existentes (`origem_suprimento_item_id`/`origem_plano_acao_id`) — não é
 * uma coluna nova nem uma relação nova, só uma classificação de
 * apresentação pra Central de Prontidão (Ciclo 15, Etapa B.1). Ver
 * CentralProntidaoQuery::origemDaRestricao().
 */
enum OrigemRestricaoProntidao: string
{
    case Manual = 'manual';
    case Suprimento = 'suprimento';
    case PlanoAcao = 'plano_acao';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Suprimento => 'Suprimento',
            self::PlanoAcao => 'Plano de Ação',
        };
    }
}
