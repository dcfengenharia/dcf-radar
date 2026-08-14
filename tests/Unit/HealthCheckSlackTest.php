<?php

namespace Tests\Unit;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckEngine;
use App\Support\HealthCheck\HealthCheckResultado;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Testa as regras SLACK-001/002/005 da Fase 2B.3 isoladas — `regras: []`
 * desliga as 24 regras da Fase 1, deixando o restante das
 * `regrasEstruturaisPadrao()` (STRUCT-*, LOGIC-*, SLACK-*) ativo, mesmo
 * padrão já usado em HealthCheckEstruturalTest.php/HealthCheckLogicaTest.php.
 * As asserções buscam o finding por regraId específico, nunca a lista
 * completa — a presença de outras regras rodando junto nunca contamina
 * os testes daqui.
 */
class HealthCheckSlackTest extends TestCase
{
    private function tarefa(array $overrides = []): TarefaImportada
    {
        $defaults = [
            'uid' => '1',
            'nome' => 'Tarefa 1',
            'isSummary' => false,
            'isMarco' => false,
            'caminhoCritico' => false,
            'parentUid' => null,
            'codigo' => '1',
            'dataInicio' => Carbon::parse('2024-01-01'),
            'dataTermino' => Carbon::parse('2024-01-14'),
            'baselineInicio' => Carbon::parse('2024-01-01'),
            'baselineTermino' => Carbon::parse('2024-01-14'),
            'realInicio' => null,
            'realTermino' => null,
            'baselineHoras' => 40.0,
            'workHoras' => 40.0,
            'realHoras' => 0.0,
            'percentualConcluido' => 0.0,
            'textos' => [],
            'predecessoras' => [],
            'ativa' => true,
            'totalSlack' => null,
            'freeSlack' => null,
        ];

        return new TarefaImportada(...array_merge($defaults, $overrides));
    }

    private function plano(array $tarefas): PlanoImportacao
    {
        return new PlanoImportacao(
            criar: $tarefas,
            atualizar: [],
            pacotes: [],
            removerIds: [],
            removerNomes: [],
            ignoradasNomes: [],
            dataStatus: Carbon::parse('2024-03-01'),
            totalBaselineHh: 0,
            totalWorkHh: 0,
            totalRealHh: 0,
            horasPeriodos: [],
        );
    }

    private function avaliar(array $tarefas): HealthCheckResultado
    {
        return (new HealthCheckEngine(regras: []))->avaliar($this->plano($tarefas));
    }

    private function findingsDe(HealthCheckResultado $resultado, string $regraId): array
    {
        return array_values(array_filter($resultado->findings, fn ($f) => $f->regraId === $regraId));
    }

    // -------------------------------------------------------------------
    // SLACK-001 — TotalSlack negativo
    // -------------------------------------------------------------------

    public function test_slack001_total_slack_negativo_gera_finding(): void
    {
        $t = $this->tarefa(['totalSlack' => -100]);
        $resultado = $this->avaliar([$t]);

        $findings = $this->findingsDe($resultado, 'SLACK-001');
        $this->assertCount(1, $findings);
        $this->assertSame(HealthCheckSeveridade::Alto, $findings[0]->severidade);
        $this->assertSame(-100, $findings[0]->atividades[0]['total_slack']);
    }

    public function test_slack001_total_slack_zero_nao_gera_finding(): void
    {
        $t = $this->tarefa(['totalSlack' => 0]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-001'));
    }

    public function test_slack001_total_slack_positivo_nao_gera_finding(): void
    {
        $t = $this->tarefa(['totalSlack' => 100]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-001'));
    }

    public function test_slack001_total_slack_nulo_nao_gera_finding(): void
    {
        $t = $this->tarefa(['totalSlack' => null]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-001'));
    }

    public function test_slack001_tarefa_resumo_com_slack_negativo_nao_gera_finding(): void
    {
        $t = $this->tarefa(['isSummary' => true, 'totalSlack' => -100]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-001'));
    }

    public function test_slack001_tarefa_inativa_com_slack_negativo_nao_gera_finding(): void
    {
        $t = $this->tarefa(['ativa' => false, 'totalSlack' => -100]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-001'));
    }

    public function test_slack001_marco_com_slack_negativo_gera_finding(): void
    {
        $t = $this->tarefa(['isMarco' => true, 'totalSlack' => -100]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(1, $this->findingsDe($resultado, 'SLACK-001'));
    }

    // -------------------------------------------------------------------
    // SLACK-002 — FreeSlack negativo
    // -------------------------------------------------------------------

    public function test_slack002_free_slack_negativo_gera_finding(): void
    {
        $t = $this->tarefa(['freeSlack' => -50]);
        $resultado = $this->avaliar([$t]);

        $findings = $this->findingsDe($resultado, 'SLACK-002');
        $this->assertCount(1, $findings);
        $this->assertSame(HealthCheckSeveridade::Medio, $findings[0]->severidade);
        $this->assertSame(-50, $findings[0]->atividades[0]['free_slack']);
    }

    public function test_slack002_free_slack_zero_nao_gera_finding(): void
    {
        $t = $this->tarefa(['freeSlack' => 0]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-002'));
    }

    public function test_slack002_free_slack_positivo_nao_gera_finding(): void
    {
        $t = $this->tarefa(['freeSlack' => 50]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-002'));
    }

    public function test_slack002_free_slack_nulo_nao_gera_finding(): void
    {
        $t = $this->tarefa(['freeSlack' => null]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-002'));
    }

    public function test_slack002_tarefa_resumo_ignorada(): void
    {
        $t = $this->tarefa(['isSummary' => true, 'freeSlack' => -50]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-002'));
    }

    public function test_slack002_tarefa_inativa_ignorada(): void
    {
        $t = $this->tarefa(['ativa' => false, 'freeSlack' => -50]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-002'));
    }

    public function test_slack002_marco_analisado(): void
    {
        $t = $this->tarefa(['isMarco' => true, 'freeSlack' => -50]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(1, $this->findingsDe($resultado, 'SLACK-002'));
    }

    // -------------------------------------------------------------------
    // SLACK-005 — FreeSlack > TotalSlack
    // -------------------------------------------------------------------

    public function test_slack005_free_slack_maior_que_total_slack_gera_finding(): void
    {
        $t = $this->tarefa(['totalSlack' => 100, 'freeSlack' => 200]);
        $resultado = $this->avaliar([$t]);

        $findings = $this->findingsDe($resultado, 'SLACK-005');
        $this->assertCount(1, $findings);
        $this->assertSame(HealthCheckSeveridade::Medio, $findings[0]->severidade);
    }

    public function test_slack005_free_slack_igual_total_slack_nao_gera_finding(): void
    {
        $t = $this->tarefa(['totalSlack' => 100, 'freeSlack' => 100]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-005'));
    }

    public function test_slack005_free_slack_menor_que_total_slack_nao_gera_finding(): void
    {
        $t = $this->tarefa(['totalSlack' => 200, 'freeSlack' => 100]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-005'));
    }

    public function test_slack005_total_slack_nulo_nao_gera_finding(): void
    {
        $t = $this->tarefa(['totalSlack' => null, 'freeSlack' => 100]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-005'));
    }

    public function test_slack005_free_slack_nulo_nao_gera_finding(): void
    {
        $t = $this->tarefa(['totalSlack' => 100, 'freeSlack' => null]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-005'));
    }

    public function test_slack005_tarefa_resumo_ignorada(): void
    {
        $t = $this->tarefa(['isSummary' => true, 'totalSlack' => 100, 'freeSlack' => 200]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-005'));
    }

    public function test_slack005_tarefa_inativa_ignorada(): void
    {
        $t = $this->tarefa(['ativa' => false, 'totalSlack' => 100, 'freeSlack' => 200]);
        $resultado = $this->avaliar([$t]);

        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-005'));
    }

    /** Comparação puramente matemática — valores negativos, FreeSlack ainda maior. */
    public function test_slack005_comparacao_matematica_pura_com_valores_negativos_dispara(): void
    {
        $t = $this->tarefa(['totalSlack' => -100, 'freeSlack' => -50]);
        $resultado = $this->avaliar([$t]);

        // -50 > -100 → dispara, mesmo os dois sendo negativos.
        $this->assertCount(1, $this->findingsDe($resultado, 'SLACK-005'));
    }

    /** Comparação puramente matemática — valores negativos, FreeSlack ainda menor. */
    public function test_slack005_comparacao_matematica_pura_com_valores_negativos_nao_dispara(): void
    {
        $t = $this->tarefa(['totalSlack' => -50, 'freeSlack' => -100]);
        $resultado = $this->avaliar([$t]);

        // -100 < -50 → não dispara.
        $this->assertCount(0, $this->findingsDe($resultado, 'SLACK-005'));
    }
}
