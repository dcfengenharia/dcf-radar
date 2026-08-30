<?php

namespace App\Enums;

/**
 * Ciclo 20, Etapa 20.5 — destino de uma EntregaProdutoIndustrializado
 * (Seção 19/21, decisão do usuário confirmada): as duas modalidades
 * SEMPRE geram uma Entrada técnica no Local próprio da obra — a
 * diferença é só se uma Saida real pro campo acontece imediatamente
 * em seguida (EntregaDiretaCampo) ou não (RetornoEstoqueObra, produto
 * fica fisicamente no Almoxarifado até uma Saida futura e separada).
 */
enum ModalidadeEntregaProduto: string
{
    case RetornoEstoqueObra = 'retorno_estoque_obra';
    case EntregaDiretaCampo = 'entrega_direta_campo';

    public function label(): string
    {
        return match ($this) {
            self::RetornoEstoqueObra => 'Retorno ao estoque da obra',
            self::EntregaDiretaCampo => 'Entrega direta ao campo',
        };
    }
}
