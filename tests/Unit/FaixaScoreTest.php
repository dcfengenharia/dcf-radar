<?php

namespace Tests\Unit;

use App\Support\HealthCheck\Score\FaixaScore;
use PHPUnit\Framework\TestCase;

class FaixaScoreTest extends TestCase
{
    /** @dataProvider limites */
    public function test_paraScore_resolve_a_faixa_correta_em_cada_limite(int $score, FaixaScore $esperado): void
    {
        $this->assertSame($esperado, FaixaScore::paraScore($score));
    }

    public static function limites(): array
    {
        return [
            '100 -> Excelente' => [100, FaixaScore::Excelente],
            '90 -> Excelente' => [90, FaixaScore::Excelente],
            '89 -> Bom' => [89, FaixaScore::Bom],
            '80 -> Bom' => [80, FaixaScore::Bom],
            '79 -> Atenção' => [79, FaixaScore::Atencao],
            '70 -> Atenção' => [70, FaixaScore::Atencao],
            '69 -> Necessita atenção' => [69, FaixaScore::NecessitaAtencao],
            '60 -> Necessita atenção' => [60, FaixaScore::NecessitaAtencao],
            '59 -> Crítico' => [59, FaixaScore::Critico],
            '0 -> Crítico' => [0, FaixaScore::Critico],
        ];
    }
}
