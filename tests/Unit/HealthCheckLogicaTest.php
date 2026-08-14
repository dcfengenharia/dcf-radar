<?php

namespace Tests\Unit;

use App\DTOs\PlanoImportacao;
use App\DTOs\PredecessoraLink;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckSeveridade;
use App\Enums\TipoRelacionamentoPredecessora;
use App\Support\HealthCheck\HealthCheckEngine;
use App\Support\HealthCheck\HealthCheckResultado;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Testa as regras LOGIC-005/008/009/010 da Fase 2B.2B isoladas —
 * `regras: []` desliga as 24 regras da Fase 1, deixando o restante das
 * `regrasEstruturaisPadrao()` (STRUCT-* da Fase 2B.1 + LOGIC-* desta fase)
 * ativo, mesmo padrão já usado em HealthCheckEstruturalTest.php. As
 * asserções buscam o finding por regraId específico (nunca a lista
 * completa), então a presença de outras regras rodando junto nunca
 * contamina os testes daqui.
 */
class HealthCheckLogicaTest extends TestCase
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
        ];

        return new TarefaImportada(...array_merge($defaults, $overrides));
    }

    private function link(
        string $predecessoraUid,
        TipoRelacionamentoPredecessora $tipo = TipoRelacionamentoPredecessora::FinishToStart,
        ?int $linkLag = null,
        ?int $lagFormat = null
    ): PredecessoraLink {
        $tipoCodigo = match ($tipo) {
            TipoRelacionamentoPredecessora::FinishToFinish => 0,
            TipoRelacionamentoPredecessora::FinishToStart => 1,
            TipoRelacionamentoPredecessora::StartToFinish => 2,
            TipoRelacionamentoPredecessora::StartToStart => 3,
        };

        return new PredecessoraLink(
            predecessoraUid: $predecessoraUid,
            tipo: $tipo,
            tipoCodigoOriginal: $tipoCodigo,
            linkLag: $linkLag,
            lagFormat: $lagFormat,
        );
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

    /** @param TarefaImportada[] $pacotes */
    private function planoComPacotes(array $tarefas, array $pacotes): PlanoImportacao
    {
        return new PlanoImportacao(
            criar: $tarefas,
            atualizar: [],
            pacotes: $pacotes,
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

    private function avaliar(PlanoImportacao $plano): HealthCheckResultado
    {
        return (new HealthCheckEngine(regras: []))->avaliar($plano);
    }

    private function findingIds(HealthCheckResultado $resultado): array
    {
        return array_map(fn ($f) => $f->regraId, $resultado->findings);
    }

    /** @return array<int, \App\Support\HealthCheck\HealthCheckFinding> todos os findings com este regraId (pode ser mais de 1 — ex.: LOGIC-005/010) */
    private function findingsDe(HealthCheckResultado $resultado, string $regraId): array
    {
        return array_values(array_filter($resultado->findings, fn ($f) => $f->regraId === $regraId));
    }

    // -------------------------------------------------------------------
    // LOGIC-005 — múltiplos vínculos entre o mesmo par
    // -------------------------------------------------------------------

    public function test_logic005_duplicidade_exata(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [
            $this->link('A', TipoRelacionamentoPredecessora::FinishToStart, 0, 4),
            $this->link('A', TipoRelacionamentoPredecessora::FinishToStart, 0, 4),
        ]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $findings = $this->findingsDe($resultado, 'LOGIC-005');
        $this->assertCount(1, $findings);
        $this->assertSame(HealthCheckSeveridade::Medio, $findings[0]->severidade);
        $this->assertStringContainsString('duplicad', $findings[0]->titulo);
    }

    public function test_logic005_tipos_diferentes(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [
            $this->link('A', TipoRelacionamentoPredecessora::FinishToStart),
            $this->link('A', TipoRelacionamentoPredecessora::StartToStart),
        ]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $findings = $this->findingsDe($resultado, 'LOGIC-005');
        $this->assertCount(1, $findings);
        $this->assertSame(HealthCheckSeveridade::Informativo, $findings[0]->severidade);
    }

    public function test_logic005_mesmo_tipo_lag_diferente(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [
            $this->link('A', TipoRelacionamentoPredecessora::FinishToStart, 0, 4),
            $this->link('A', TipoRelacionamentoPredecessora::FinishToStart, 480, 4),
        ]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $findings = $this->findingsDe($resultado, 'LOGIC-005');
        $this->assertCount(1, $findings);
        $this->assertSame(HealthCheckSeveridade::Medio, $findings[0]->severidade);
        $this->assertStringContainsString('lag', mb_strtolower($findings[0]->titulo));
    }

    public function test_logic005_pares_diferentes_nao_geram_finding(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);
        $c = $this->tarefa(['uid' => 'C']);
        $d = $this->tarefa(['uid' => 'D', 'predecessoras' => [$this->link('C')]]);

        $resultado = $this->avaliar($this->plano([$a, $b, $c, $d]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-005'));
    }

    public function test_logic005_unico_vinculo_nao_gera_finding(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-005'));
    }

    // -------------------------------------------------------------------
    // LOGIC-008 — inconsistência entre datas reais em relação FS
    // -------------------------------------------------------------------

    public function test_logic008_fs_com_conflito_real(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'realTermino' => Carbon::parse('2024-08-20')]);
        $b = $this->tarefa(['uid' => 'B', 'realInicio' => Carbon::parse('2024-08-18'), 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $findings = $this->findingsDe($resultado, 'LOGIC-008');
        $this->assertCount(1, $findings);
        $this->assertSame(HealthCheckSeveridade::Alto, $findings[0]->severidade);
        $this->assertSame('A', $findings[0]->atividades[0]['predecessora']['uid']);
        $this->assertSame('B', $findings[0]->atividades[0]['sucessora']['uid']);
    }

    public function test_logic008_fs_sem_conflito(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'realTermino' => Carbon::parse('2024-08-18')]);
        $b = $this->tarefa(['uid' => 'B', 'realInicio' => Carbon::parse('2024-08-20'), 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-008'));
    }

    public function test_logic008_ausencia_de_actual_finish_nao_avalia(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'realTermino' => null]);
        $b = $this->tarefa(['uid' => 'B', 'realInicio' => Carbon::parse('2024-08-18'), 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-008'));
    }

    public function test_logic008_ausencia_de_actual_start_nao_avalia(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'realTermino' => Carbon::parse('2024-08-20')]);
        $b = $this->tarefa(['uid' => 'B', 'realInicio' => null, 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-008'));
    }

    public function test_logic008_relacao_ss_nao_dispara(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'realTermino' => Carbon::parse('2024-08-20')]);
        $b = $this->tarefa(['uid' => 'B', 'realInicio' => Carbon::parse('2024-08-18'), 'predecessoras' => [
            $this->link('A', TipoRelacionamentoPredecessora::StartToStart),
        ]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-008'));
    }

    public function test_logic008_relacao_ff_nao_dispara(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'realTermino' => Carbon::parse('2024-08-20')]);
        $b = $this->tarefa(['uid' => 'B', 'realInicio' => Carbon::parse('2024-08-18'), 'predecessoras' => [
            $this->link('A', TipoRelacionamentoPredecessora::FinishToFinish),
        ]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-008'));
    }

    public function test_logic008_relacao_sf_nao_dispara(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'realTermino' => Carbon::parse('2024-08-20')]);
        $b = $this->tarefa(['uid' => 'B', 'realInicio' => Carbon::parse('2024-08-18'), 'predecessoras' => [
            $this->link('A', TipoRelacionamentoPredecessora::StartToFinish),
        ]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-008'));
    }

    public function test_logic008_datas_iguais_nao_disparam(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'realTermino' => Carbon::parse('2024-08-20')]);
        $b = $this->tarefa(['uid' => 'B', 'realInicio' => Carbon::parse('2024-08-20'), 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-008'));
    }

    public function test_logic008_tarefa_resumo_nao_gera_falso_positivo(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'isSummary' => true, 'realTermino' => Carbon::parse('2024-08-20')]);
        $b = $this->tarefa(['uid' => 'B', 'realInicio' => Carbon::parse('2024-08-18'), 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar($this->planoComPacotes([$b], [$a]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-008'));
    }

    public function test_logic008_atividade_inativa_tratada_corretamente(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'ativa' => false, 'realTermino' => Carbon::parse('2024-08-20')]);
        $b = $this->tarefa(['uid' => 'B', 'realInicio' => Carbon::parse('2024-08-18'), 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-008'));
    }

    // -------------------------------------------------------------------
    // LOGIC-009 — vínculo envolvendo tarefa resumo
    // -------------------------------------------------------------------

    public function test_logic009_predecessora_resumo(): void
    {
        $resumo = $this->tarefa(['uid' => 'R', 'isSummary' => true, 'nome' => 'Pacote']);
        $b = $this->tarefa(['uid' => 'B', 'nome' => 'Atividade B', 'predecessoras' => [$this->link('R')]]);

        $resultado = $this->avaliar($this->planoComPacotes([$b], [$resumo]));

        $findings = $this->findingsDe($resultado, 'LOGIC-009');
        $this->assertCount(1, $findings);
        $this->assertSame('predecessora_e_resumo', $findings[0]->atividades[0]['direcao']);
        $this->assertSame('R', $findings[0]->atividades[0]['predecessora']['uid']);
        $this->assertSame('B', $findings[0]->atividades[0]['sucessora']['uid']);
    }

    public function test_logic009_sucessora_resumo(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'nome' => 'Atividade A']);
        $resumo = $this->tarefa(['uid' => 'R', 'isSummary' => true, 'nome' => 'Pacote', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar($this->planoComPacotes([$a], [$resumo]));

        $findings = $this->findingsDe($resultado, 'LOGIC-009');
        $this->assertCount(1, $findings);
        $this->assertSame('sucessora_e_resumo', $findings[0]->atividades[0]['direcao']);
    }

    public function test_logic009_relacao_normal_nao_gera_finding(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar($this->plano([$a, $b]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-009'));
    }

    public function test_logic009_multiplas_relacoes_sao_todas_contadas(): void
    {
        $resumo1 = $this->tarefa(['uid' => 'R1', 'isSummary' => true]);
        $resumo2 = $this->tarefa(['uid' => 'R2', 'isSummary' => true]);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('R1')]]);
        $c = $this->tarefa(['uid' => 'C', 'predecessoras' => [$this->link('R2')]]);

        $resultado = $this->avaliar($this->planoComPacotes([$b, $c], [$resumo1, $resumo2]));

        $findings = $this->findingsDe($resultado, 'LOGIC-009');
        $this->assertCount(1, $findings);
        $this->assertCount(2, $findings[0]->atividades);
    }

    public function test_logic009_nomes_e_uids_apresentados_corretamente(): void
    {
        $resumo = $this->tarefa(['uid' => 'R', 'codigo' => '1', 'isSummary' => true, 'nome' => 'Pacote Estrutural']);
        $b = $this->tarefa(['uid' => 'B', 'codigo' => '1.1', 'nome' => 'Atividade Executável', 'predecessoras' => [$this->link('R')]]);

        $resultado = $this->avaliar($this->planoComPacotes([$b], [$resumo]));

        $registro = $this->findingsDe($resultado, 'LOGIC-009')[0]->atividades[0];
        $this->assertSame('Pacote Estrutural', $registro['predecessora']['nome']);
        $this->assertSame('1', $registro['predecessora']['codigo']);
        $this->assertSame('Atividade Executável', $registro['sucessora']['nome']);
        $this->assertSame('1.1', $registro['sucessora']['codigo']);
    }

    // -------------------------------------------------------------------
    // LOGIC-010 — sucessora ativa com predecessora(s) inativa(s)
    // -------------------------------------------------------------------

    public function test_logic010_sucessora_ativa_com_predecessora_inativa(): void
    {
        $inativa = $this->tarefa(['uid' => 'I', 'ativa' => false]);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('I')]]);

        $resultado = $this->avaliar($this->plano([$inativa, $b]));

        $findings = $this->findingsDe($resultado, 'LOGIC-010');
        $this->assertCount(1, $findings);
        $this->assertSame(HealthCheckSeveridade::Baixo, $findings[0]->severidade);
        $this->assertSame('B', $findings[0]->atividades[0]['sucessora']['uid']);
    }

    public function test_logic010_mistura_de_predecessoras_ativas_e_inativas(): void
    {
        $ativa = $this->tarefa(['uid' => 'A']);
        $inativa = $this->tarefa(['uid' => 'I', 'ativa' => false]);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A'), $this->link('I')]]);

        $resultado = $this->avaliar($this->plano([$ativa, $inativa, $b]));

        $findingsMistura = array_values(array_filter(
            $this->findingsDe($resultado, 'LOGIC-010'),
            fn ($f) => $f->severidade === HealthCheckSeveridade::Informativo
        ));
        $this->assertCount(1, $findingsMistura);
        $this->assertCount(1, $findingsMistura[0]->atividades[0]['predecessoras_ativas']);
        $this->assertCount(1, $findingsMistura[0]->atividades[0]['predecessoras_inativas']);
    }

    public function test_logic010_todas_as_predecessoras_inativas(): void
    {
        $inativa1 = $this->tarefa(['uid' => 'I1', 'ativa' => false]);
        $inativa2 = $this->tarefa(['uid' => 'I2', 'ativa' => false]);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('I1'), $this->link('I2')]]);

        $resultado = $this->avaliar($this->plano([$inativa1, $inativa2, $b]));

        $findingsTodasInativas = array_values(array_filter(
            $this->findingsDe($resultado, 'LOGIC-010'),
            fn ($f) => $f->severidade === HealthCheckSeveridade::Baixo
        ));
        $this->assertCount(1, $findingsTodasInativas);
        $this->assertCount(2, $findingsTodasInativas[0]->atividades[0]['predecessoras_inativas']);
        $this->assertCount(0, $findingsTodasInativas[0]->atividades[0]['predecessoras_ativas']);
    }

    public function test_logic010_sucessora_inativa_nao_dispara(): void
    {
        $inativa = $this->tarefa(['uid' => 'I', 'ativa' => false]);
        $sucessoraInativa = $this->tarefa(['uid' => 'B', 'ativa' => false, 'predecessoras' => [$this->link('I')]]);

        $resultado = $this->avaliar($this->plano([$inativa, $sucessoraInativa]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-010'));
    }

    public function test_logic010_predecessora_ativa_nao_dispara(): void
    {
        $ativa = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar($this->plano([$ativa, $b]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-010'));
    }

    public function test_logic010_atividade_sem_relacao_nao_dispara(): void
    {
        $a = $this->tarefa(['uid' => 'A']);

        $resultado = $this->avaliar($this->plano([$a]));

        $this->assertCount(0, $this->findingsDe($resultado, 'LOGIC-010'));
    }
}
