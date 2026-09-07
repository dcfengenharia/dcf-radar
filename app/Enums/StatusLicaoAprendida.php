<?php

namespace App\Enums;

/**
 * Ciclo 23, Etapa 23.1 — workflow de governança:
 * experiência registrada (Rascunho) → análise (EmValidacao) →
 * conhecimento corporativo publicado (Publicada) → retirado da consulta
 * operacional padrão, mas preservado (Arquivada).
 *
 * Transições permitidas, aplicadas por Actions dedicadas (nunca um
 * `update(['status' => ...])` genérico de formulário):
 * Rascunho → EmValidacao (EnviarParaValidacao)
 * EmValidacao → Rascunho (DevolverParaRascunho — devolução pra revisão)
 * EmValidacao → Publicada (PublicarLicaoAprendida — exige completude)
 * Publicada → Arquivada (ArquivarLicaoAprendida)
 *
 * Publicada é imutável (decisão do usuário, mesmo idioma já usado 3x no
 * projeto — GRD/Requisição de Compra/Requisição de Planejamento: emitido
 * nunca edita de volta) — corrigir conteúdo publicado é arquivar +
 * registrar uma lição nova.
 */
enum StatusLicaoAprendida: string
{
    case Rascunho = 'rascunho';
    case EmValidacao = 'em_validacao';
    case Publicada = 'publicada';
    case Arquivada = 'arquivada';

    public function label(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::EmValidacao => 'Em Validação',
            self::Publicada => 'Publicada',
            self::Arquivada => 'Arquivada',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Rascunho => 'secondary',
            self::EmValidacao => 'warning',
            self::Publicada => 'success',
            self::Arquivada => 'dark',
        };
    }

    /** Editável via AtualizarLicaoAprendida — só Rascunho. */
    public function estaEditavel(): bool
    {
        return $this === self::Rascunho;
    }

    /** Imutável pra conteúdo (Publicada/Arquivada) — só transições de governança tocam a linha. */
    public function estaImutavel(): bool
    {
        return in_array($this, [self::Publicada, self::Arquivada], true);
    }
}
