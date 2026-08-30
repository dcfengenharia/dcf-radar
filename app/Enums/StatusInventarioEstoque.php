<?php

namespace App\Enums;

/**
 * Ciclo 20, Etapa 20.7 — status de `App\Models\InventarioEstoque`.
 * Nomes do próprio pedido (Rascunho/EmContagem/EmAnalise/Concluido/
 * Cancelado) — investigado e confirmado que nenhuma convenção melhor já
 * existe no projeto pra um workflow de 5 estágios com contagem +
 * análise + aprovação de ajuste.
 */
enum StatusInventarioEstoque: string
{
    case Rascunho = 'rascunho';
    case EmContagem = 'em_contagem';
    case EmAnalise = 'em_analise';
    case Concluido = 'concluido';
    case Cancelado = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::EmContagem => 'Em Contagem',
            self::EmAnalise => 'Em Análise',
            self::Concluido => 'Concluído',
            self::Cancelado => 'Cancelado',
        };
    }

    public function estaAberto(): bool
    {
        return in_array($this, [self::Rascunho, self::EmContagem, self::EmAnalise], true);
    }

    public function estaFinalizado(): bool
    {
        return in_array($this, [self::Concluido, self::Cancelado], true);
    }
}
