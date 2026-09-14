<?php

namespace App\DTOs\Gestao\Home;

/**
 * Home Executiva (Ciclo 25) — contagem de prontidão OPERACIONAL (nunca só
 * cobertura material) num horizonte, sempre derivada de
 * `Atividade::scopeProntas()` via `App\Support\CentralProntidao\
 * CentralProntidaoQuery::paraObra()` — nunca uma segunda fonte de
 * verdade. `concluidas` usa `StatusOperacionalProntidao::Concluida`
 * (`Atividade::concluido_em`), sempre excluída do numerador/denominador
 * do percentual (uma atividade já concluída não é nem "pronta pra
 * executar" nem "bloqueada" — ela já aconteceu).
 *
 * `percentual` é sempre `prontas / (total - concluidas)`, `null` quando o
 * denominador é zero — nunca 0%/100% inventado (mesmo princípio já usado
 * em `CockpitProntidaoHorizonte::percentualCoberturaAvaliavel`).
 */
final class HomeHorizonte
{
    public function __construct(
        public readonly string $chave,
        public readonly string $label,
        public readonly int $horizonteDias,
        public readonly int $total,
        public readonly int $concluidas,
        public readonly int $prontas,
        public readonly int $bloqueadas,
        public readonly ?float $percentual,
    ) {
    }

    public function toArray(): array
    {
        return [
            'chave' => $this->chave,
            'label' => $this->label,
            'horizonte_dias' => $this->horizonteDias,
            'total' => $this->total,
            'concluidas' => $this->concluidas,
            'prontas' => $this->prontas,
            'bloqueadas' => $this->bloqueadas,
            'percentual' => $this->percentual,
        ];
    }
}
