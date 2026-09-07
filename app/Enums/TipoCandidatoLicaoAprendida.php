<?php

namespace App\Enums;

/**
 * Ciclo 23, Etapa 23.3 — allowlist FECHADA dos tipos de candidato a
 * lição aprendida. Deliberadamente pequena (3 regras, aprovadas após
 * fresh-read + relatório intermediário — Restrição/Pedido/Aplicação);
 * as demais regras investigadas foram rejeitadas ou adiadas por falta
 * de evidência histórica suficiente (ver relatório final da etapa).
 */
enum TipoCandidatoLicaoAprendida: string
{
    case RestricaoRelevante = 'restricao_relevante';
    case PedidoAtrasoFinal = 'pedido_atraso_final';
    case AplicacaoDesvioDestinacao = 'aplicacao_desvio_destinacao';

    public function label(): string
    {
        return match ($this) {
            self::RestricaoRelevante => 'Restrição bloqueante resolvida com duração relevante',
            self::PedidoAtrasoFinal => 'Pedido de Compra concluído com atraso',
            self::AplicacaoDesvioDestinacao => 'Aplicação divergente da destinação planejada',
        };
    }
}
