<?php

namespace App\Enums;

enum OrigemProgramacaoSemanalItem: string
{
    case Manual = 'manual';   // ⚡plano-semanal.blade.php::comprometerSelecionadas() — seleção manual
    case Lote = 'lote';       // ⚡lookahead.blade.php::gerarPlanoSemanal() — comprometimento em massa
    case Revisao = 'revisao'; // App\Actions\ProgramacaoSemanal\CriarRevisaoProgramacaoSemanal — recapturado ao criar nova versão

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Seleção manual',
            self::Lote => 'Gerar Plano Semanal (em massa)',
            self::Revisao => 'Revisão',
        };
    }
}
