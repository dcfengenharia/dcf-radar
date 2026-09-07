<?php

namespace App\DTOs\LicoesAprendidas;

/**
 * Ciclo 23, Etapa 23.5.A — 1 fatia de uma distribuição (Área Funcional/
 * Disciplina/Tipo/Criticidade) da memória PUBLICADA. `chave` é sempre o
 * valor bruto usado pra filtrar de volta na Biblioteca Corporativa
 * (drill-down, Decisão 8) — nunca o rótulo traduzido.
 */
final readonly class ItemDistribuicaoLicoes
{
    public function __construct(
        public string $chave,
        public string $rotulo,
        public int $quantidade,
    ) {
    }
}
