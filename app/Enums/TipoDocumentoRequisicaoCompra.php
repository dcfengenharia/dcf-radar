<?php

namespace App\Enums;

/**
 * Etapa 2 (Dossiê Documental da RC) — categorias V1 do pedido (Seção 14),
 * literais, sem inventar categorias adicionais.
 */
enum TipoDocumentoRequisicaoCompra: string
{
    case Proposta = 'proposta';
    case Contrato = 'contrato';
    case Parecer = 'parecer';
    case MapaComparativo = 'mapa_comparativo';
    case Correspondencia = 'correspondencia';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Proposta => 'Proposta',
            self::Contrato => 'Contrato',
            self::Parecer => 'Parecer',
            self::MapaComparativo => 'Mapa Comparativo',
            self::Correspondencia => 'Correspondência',
            self::Outro => 'Outro',
        };
    }
}
