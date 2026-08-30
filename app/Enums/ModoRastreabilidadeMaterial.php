<?php

namespace App\Enums;

/**
 * Ciclo 20, Etapa 20.1 — modo de rastreabilidade física de um Material.
 * Uma única entidade de Material suporta os 3 modos (nunca 3 tabelas de
 * estoque separadas) — o modo só decide QUAIS campos de
 * App\Models\UnidadeEstoque são exigidos/permitidos numa entrada.
 */
enum ModoRastreabilidadeMaterial: string
{
    case Quantitativo = 'quantitativo';
    case Lote = 'lote';
    case Serializado = 'serializado';

    public function label(): string
    {
        return match ($this) {
            self::Quantitativo => 'Quantitativo',
            self::Lote => 'Lote / Unidade Logística',
            self::Serializado => 'Serializado',
        };
    }
}
