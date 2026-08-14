<?php

namespace Tests\Unit;

use App\Enums\HealthCheckNaturezaRegra;
use App\Support\HealthCheck\HealthCheckEngine;
use PHPUnit\Framework\TestCase;

/**
 * Ciclo 5 — fonte única de verdade da classificação Planejamento/Execução
 * das 36 regras do Health Check (Ciclo 1). Consulta
 * HealthCheckEngine::naturezaDaRegra() — nunca reimplementa a
 * classificação aqui, só verifica o catálogo já existente contra a
 * tabela definitiva aprovada pelo usuário.
 */
class ClassificacaoRegrasHealthCheckTest extends TestCase
{
    /** @return array<string, HealthCheckNaturezaRegra> regra_id => natureza esperada */
    private function classificacaoDefinitiva(): array
    {
        return [
            // Planejamento (19)
            'DATE-005' => HealthCheckNaturezaRegra::Planejamento,
            'WORK-006' => HealthCheckNaturezaRegra::Planejamento,
            'DUR-001' => HealthCheckNaturezaRegra::Planejamento,
            'DUR-002' => HealthCheckNaturezaRegra::Planejamento,
            'CRIT-001' => HealthCheckNaturezaRegra::Planejamento,
            'MILE-002' => HealthCheckNaturezaRegra::Planejamento,
            'BASE-002' => HealthCheckNaturezaRegra::Planejamento,
            'BASE-003' => HealthCheckNaturezaRegra::Planejamento,
            'STRUCT-001' => HealthCheckNaturezaRegra::Planejamento,
            'STRUCT-002' => HealthCheckNaturezaRegra::Planejamento,
            'STRUCT-003' => HealthCheckNaturezaRegra::Planejamento,
            'STRUCT-004' => HealthCheckNaturezaRegra::Planejamento,
            'STRUCT-005' => HealthCheckNaturezaRegra::Planejamento,
            'LOGIC-005' => HealthCheckNaturezaRegra::Planejamento,
            'LOGIC-009' => HealthCheckNaturezaRegra::Planejamento,
            'LOGIC-010' => HealthCheckNaturezaRegra::Planejamento,
            'SLACK-001' => HealthCheckNaturezaRegra::Planejamento,
            'SLACK-002' => HealthCheckNaturezaRegra::Planejamento,
            'SLACK-005' => HealthCheckNaturezaRegra::Planejamento,

            // Execução (17)
            'DATE-001' => HealthCheckNaturezaRegra::Execucao,
            'DATE-002' => HealthCheckNaturezaRegra::Execucao,
            'DATE-003' => HealthCheckNaturezaRegra::Execucao,
            'DATE-004' => HealthCheckNaturezaRegra::Execucao,
            'DATE-006' => HealthCheckNaturezaRegra::Execucao,
            'DATE-007' => HealthCheckNaturezaRegra::Execucao,
            'PROG-001' => HealthCheckNaturezaRegra::Execucao,
            'PROG-002' => HealthCheckNaturezaRegra::Execucao,
            'PROG-003' => HealthCheckNaturezaRegra::Execucao,
            'PROG-004' => HealthCheckNaturezaRegra::Execucao,
            'WORK-001' => HealthCheckNaturezaRegra::Execucao,
            'WORK-002' => HealthCheckNaturezaRegra::Execucao,
            'WORK-003' => HealthCheckNaturezaRegra::Execucao,
            'WORK-004' => HealthCheckNaturezaRegra::Execucao,
            'WORK-005' => HealthCheckNaturezaRegra::Execucao,
            'MILE-001' => HealthCheckNaturezaRegra::Execucao,
            'LOGIC-008' => HealthCheckNaturezaRegra::Execucao,
        ];
    }

    public function test_as_36_regras_tem_exatamente_a_classificacao_definitiva(): void
    {
        $engine = new HealthCheckEngine();
        $esperado = $this->classificacaoDefinitiva();

        $this->assertCount(36, $esperado, 'a tabela definitiva em si precisa listar as 36 regras');

        foreach ($esperado as $regraId => $naturezaEsperada) {
            $this->assertSame(
                $naturezaEsperada,
                $engine->naturezaDaRegra($regraId),
                "regra {$regraId} deveria ser {$naturezaEsperada->value}"
            );
        }
    }

    public function test_total_de_19_planejamento_e_17_execucao(): void
    {
        $engine = new HealthCheckEngine();
        $esperado = $this->classificacaoDefinitiva();

        $planejamento = 0;
        $execucao = 0;

        foreach (array_keys($esperado) as $regraId) {
            match ($engine->naturezaDaRegra($regraId)) {
                HealthCheckNaturezaRegra::Planejamento => $planejamento++,
                HealthCheckNaturezaRegra::Execucao => $execucao++,
                null => null,
            };
        }

        $this->assertSame(19, $planejamento);
        $this->assertSame(17, $execucao);
    }

    public function test_nenhuma_regra_do_catalogo_fica_sem_classificacao_conhecida(): void
    {
        // Reflete sobre o catálogo real do Engine (regrasPadrao +
        // regrasEstruturaisPadrao) — garante que a tabela definitiva
        // acima não ficou desatualizada em relação ao código real (ex.:
        // uma regra nova adicionada sem entrar nesta lista).
        $engine = new HealthCheckEngine();
        $reflexao = new \ReflectionClass($engine);

        $regras = $reflexao->getMethod('regrasPadrao');
        $regras->setAccessible(true);
        $regrasEstruturais = $reflexao->getMethod('regrasEstruturaisPadrao');
        $regrasEstruturais->setAccessible(true);

        $todasAsRegras = [...$regras->invoke($engine), ...$regrasEstruturais->invoke($engine)];
        $idsReais = array_map(fn ($r) => $r->id(), $todasAsRegras);

        $this->assertCount(36, $idsReais, 'o catálogo real do Engine precisa ter exatamente 36 regras');
        $this->assertEqualsCanonicalizing(
            array_keys($this->classificacaoDefinitiva()),
            $idsReais,
            'a tabela definitiva deste teste precisa cobrir exatamente as mesmas 36 regras do catálogo real do Engine'
        );
    }
}
