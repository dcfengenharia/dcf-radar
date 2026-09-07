<?php

namespace App\Enums;

/**
 * Ciclo 23, Etapa 23.4 — motivo ESTRUTURADO pelo qual uma lição
 * `Publicada` de outra obra apareceu no contexto operacional atual.
 * Nunca uma string solta (Seção 17 do pedido) — a UI traduz cada case
 * pra um texto amigável, mas o dado persistido/comparado é sempre este
 * enum.
 *
 * Precedência explícita (Seção 18/9 — nunca um score 0-100): quanto
 * maior `precedencia()`, mais específica a correspondência. Usada só
 * pra ORDENAR quando uma lição bate por mais de um motivo — nunca
 * significa maior risco/probabilidade de repetição (Seção 3).
 */
enum MotivoCorrespondenciaLicao: string
{
    case MesmoMaterial = 'mesmo_material';
    case MesmaDisciplina = 'mesma_disciplina';

    public function label(): string
    {
        return match ($this) {
            self::MesmoMaterial => 'Mesmo material',
            self::MesmaDisciplina => 'Mesma disciplina',
        };
    }

    /** Maior = correspondência mais específica (nunca "maior risco"). */
    public function precedencia(): int
    {
        return match ($this) {
            self::MesmoMaterial => 2,
            self::MesmaDisciplina => 1,
        };
    }
}
