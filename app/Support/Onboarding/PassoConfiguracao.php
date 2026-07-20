<?php

namespace App\Support\Onboarding;

use Closure;

class PassoConfiguracao
{
    public function __construct(
        public readonly string $chave,
        public readonly string $titulo,
        public readonly string $descricao,
        public readonly bool $obrigatorio,
        public readonly string $rotaAcao,
        public readonly string $rotuloAcao,
        private readonly Closure $verificar,
    ) {
    }

    public function estaConcluido(): bool
    {
        return ($this->verificar)();
    }
}
