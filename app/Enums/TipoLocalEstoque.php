<?php

namespace App\Enums;

/**
 * Ciclo 20, Etapa 20.1 — tipos físicos internos de LocalEstoque.
 *
 * Ciclo 20, Etapa 20.5 — `Terceiro` adicionado (decisão do usuário,
 * Opção A confirmada via investigação): custódia em fornecedor
 * (industrialização externa) É modelada como mais um `LocalEstoque`,
 * nunca uma entidade paralela de custódia — reaproveita 100% de
 * `MovimentacaoEstoque`/`SaldoEstoque` já construídos desde 20.1. Um
 * `LocalEstoque` com `tipo=Terceiro` SEMPRE tem `fornecedor_id`
 * preenchido (guard em `App\Observers\LocalEstoqueObserver`); os 4
 * tipos anteriores (Almoxarifado/Container/Pátio/Área Técnica)
 * continuam exclusivamente para posições físicas PRÓPRIAS da obra —
 * `fornecedor_id` neles é sempre `null`.
 */
enum TipoLocalEstoque: string
{
    case Almoxarifado = 'almoxarifado';
    case Container = 'container';
    case Patio = 'patio';
    case AreaTecnica = 'area_tecnica';
    case Terceiro = 'terceiro';

    public function label(): string
    {
        return match ($this) {
            self::Almoxarifado => 'Almoxarifado',
            self::Container => 'Container',
            self::Patio => 'Pátio',
            self::AreaTecnica => 'Área Técnica',
            self::Terceiro => 'Terceiro (custódia externa)',
        };
    }

    public function ehProprio(): bool
    {
        return $this !== self::Terceiro;
    }
}
