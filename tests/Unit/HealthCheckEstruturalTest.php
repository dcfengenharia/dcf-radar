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
 * Testa as regras estruturais da Fase 2B.1 (STRUCT-001..005) isoladas —
 * `regras: []` desliga as 24 regras da Fase 1 (não é o alvo destes
 * testes), deixando só `regrasEstruturaisPadrao()` ativa. O caminho
 * inverso (`HealthCheckEngineTest.php`, Fase 1) usa
 * `regrasEstruturais: []` pelo motivo espelhado: isolar cada dimensão de
 * análise evita que uma contamine as asserções da outra — ver CLAUDE.md,
 * seção "Health Check — Fase 2B.1".
 */
class HealthCheckEstruturalTest extends TestCase
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

    private function link(string $predecessoraUid): PredecessoraLink
    {
        return new PredecessoraLink(
            predecessoraUid: $predecessoraUid,
            tipo: TipoRelacionamentoPredecessora::FinishToStart,
            tipoCodigoOriginal: 1,
            linkLag: null,
            lagFormat: null,
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

    private function avaliar(array $tarefas): HealthCheckResultado
    {
        return (new HealthCheckEngine(regras: []))->avaliar($this->plano($tarefas));
    }

    private function findingIds(HealthCheckResultado $resultado): array
    {
        return array_map(fn ($f) => $f->regraId, $resultado->findings);
    }

    private function finding(HealthCheckResultado $resultado, string $regraId)
    {
        foreach ($resultado->findings as $f) {
            if ($f->regraId === $regraId) {
                return $f;
            }
        }
        return null;
    }

    /** @return array{0: TarefaImportada, 1: TarefaImportada} par conectado A→B, usado só pra garantir totalArestas > 0 e não acionar a guarda de "sem nenhuma relação capturada". */
    private function parConectado(string $uidA = 'ANCORA_A', string $uidB = 'ANCORA_B'): array
    {
        $a = $this->tarefa(['uid' => $uidA]);
        $b = $this->tarefa(['uid' => $uidB, 'predecessoras' => [$this->link($uidA)]]);
        return [$a, $b];
    }

    // -------------------------------------------------------------------
    // Guarda: grafo sem nenhuma relação capturada não dispara STRUCT-001/002/003
    // -------------------------------------------------------------------

    public function test_sem_nenhuma_relacao_capturada_no_plano_inteiro_nao_dispara_struct_001_002_003(): void
    {
        $resultado = $this->avaliar([
            $this->tarefa(['uid' => '1']),
            $this->tarefa(['uid' => '2']),
        ]);

        $ids = $this->findingIds($resultado);
        $this->assertNotContains('STRUCT-001', $ids);
        $this->assertNotContains('STRUCT-002', $ids);
        $this->assertNotContains('STRUCT-003', $ids);
    }

    // -------------------------------------------------------------------
    // STRUCT-001 — sem predecessora
    // -------------------------------------------------------------------

    public function test_struct001_atividade_sem_predecessora_mas_com_sucessora(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar([$a, $b]);

        $this->assertContains('STRUCT-001', $this->findingIds($resultado));
        $uids = array_column($this->finding($resultado, 'STRUCT-001')->atividades, 'uid');
        $this->assertContains('A', $uids);
        $this->assertNotContains('B', $uids);
    }

    public function test_struct001_nao_dispara_para_atividade_com_predecessora(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);
        $c = $this->tarefa(['uid' => 'C', 'predecessoras' => [$this->link('B')]]);

        $resultado = $this->avaliar([$a, $b, $c]);

        $uids = array_column($this->finding($resultado, 'STRUCT-001')->atividades, 'uid');
        $this->assertNotContains('B', $uids);
        $this->assertNotContains('C', $uids);
    }

    public function test_struct001_varias_atividades_sem_predecessora_aparecem_no_mesmo_finding(): void
    {
        $a1 = $this->tarefa(['uid' => 'A1']);
        $a2 = $this->tarefa(['uid' => 'A2']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A1'), $this->link('A2')]]);

        $resultado = $this->avaliar([$a1, $a2, $b]);

        $uids = array_column($this->finding($resultado, 'STRUCT-001')->atividades, 'uid');
        $this->assertEqualsCanonicalizing(['A1', 'A2'], $uids);
    }

    public function test_struct001_tarefa_resumo_sem_predecessora_nunca_gera_finding(): void
    {
        [$ancoraA, $ancoraB] = $this->parConectado();
        $resumo = $this->tarefa(['uid' => 'R', 'isSummary' => true]);

        $resultado = $this->avaliar([$ancoraA, $ancoraB, $resumo]);

        $uids = array_column($this->finding($resultado, 'STRUCT-001')->atividades, 'uid');
        $this->assertNotContains('R', $uids);
    }

    public function test_struct001_atividade_inativa_sem_predecessora_nunca_gera_finding(): void
    {
        [$ancoraA, $ancoraB] = $this->parConectado();
        $inativa = $this->tarefa(['uid' => 'I', 'ativa' => false]);

        $resultado = $this->avaliar([$ancoraA, $ancoraB, $inativa]);

        $uids = array_column($this->finding($resultado, 'STRUCT-001')->atividades, 'uid');
        $this->assertNotContains('I', $uids);
    }

    public function test_struct001_marco_sem_predecessora_dispara_normalmente(): void
    {
        $marco = $this->tarefa(['uid' => 'M', 'isMarco' => true]);
        $n = $this->tarefa(['uid' => 'N', 'predecessoras' => [$this->link('M')]]);

        $resultado = $this->avaliar([$marco, $n]);

        $struct001 = $this->finding($resultado, 'STRUCT-001');
        $uids = array_column($struct001->atividades, 'uid');
        $this->assertContains('M', $uids);
        $tipoDoMarco = collect($struct001->atividades)->firstWhere('uid', 'M')['tipo'];
        $this->assertSame('Marco', $tipoDoMarco);
    }

    // -------------------------------------------------------------------
    // STRUCT-002 — sem sucessora
    // -------------------------------------------------------------------

    public function test_struct002_atividade_sem_sucessora_mas_com_predecessora(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar([$a, $b]);

        $uids = array_column($this->finding($resultado, 'STRUCT-002')->atividades, 'uid');
        $this->assertContains('B', $uids);
        $this->assertNotContains('A', $uids);
    }

    public function test_struct002_nao_dispara_para_atividade_com_sucessora(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar([$a, $b]);

        $uids = array_column($this->finding($resultado, 'STRUCT-002')->atividades, 'uid');
        $this->assertNotContains('A', $uids);
    }

    public function test_struct002_varias_atividades_sem_sucessora(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b1 = $this->tarefa(['uid' => 'B1', 'predecessoras' => [$this->link('A')]]);
        $b2 = $this->tarefa(['uid' => 'B2', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar([$a, $b1, $b2]);

        $uids = array_column($this->finding($resultado, 'STRUCT-002')->atividades, 'uid');
        $this->assertEqualsCanonicalizing(['B1', 'B2'], $uids);
    }

    public function test_struct002_tarefa_resumo_sem_sucessora_nunca_gera_finding(): void
    {
        [$ancoraA, $ancoraB] = $this->parConectado();
        $resumo = $this->tarefa(['uid' => 'R', 'isSummary' => true]);

        $resultado = $this->avaliar([$ancoraA, $ancoraB, $resumo]);

        $uids = array_column($this->finding($resultado, 'STRUCT-002')->atividades, 'uid');
        $this->assertNotContains('R', $uids);
    }

    public function test_struct002_atividade_inativa_sem_sucessora_nunca_gera_finding(): void
    {
        [$ancoraA, $ancoraB] = $this->parConectado();
        $inativa = $this->tarefa(['uid' => 'I', 'ativa' => false]);

        $resultado = $this->avaliar([$ancoraA, $ancoraB, $inativa]);

        $uids = array_column($this->finding($resultado, 'STRUCT-002')->atividades, 'uid');
        $this->assertNotContains('I', $uids);
    }

    public function test_struct002_marco_sem_sucessora_dispara_normalmente(): void
    {
        $n = $this->tarefa(['uid' => 'N']);
        $marco = $this->tarefa(['uid' => 'M', 'isMarco' => true, 'predecessoras' => [$this->link('N')]]);

        $resultado = $this->avaliar([$n, $marco]);

        $uids = array_column($this->finding($resultado, 'STRUCT-002')->atividades, 'uid');
        $this->assertContains('M', $uids);
    }

    // -------------------------------------------------------------------
    // STRUCT-003 — isolada + dedup com STRUCT-001/002
    // -------------------------------------------------------------------

    public function test_struct003_atividade_isolada(): void
    {
        [$ancoraA, $ancoraB] = $this->parConectado();
        $isolada = $this->tarefa(['uid' => 'X']);

        $resultado = $this->avaliar([$ancoraA, $ancoraB, $isolada]);

        $uids = array_column($this->finding($resultado, 'STRUCT-003')->atividades, 'uid');
        $this->assertContains('X', $uids);
    }

    public function test_struct003_atividade_isolada_nao_dispara_struct001_nem_struct002_para_ela(): void
    {
        [$ancoraA, $ancoraB] = $this->parConectado();
        $isolada = $this->tarefa(['uid' => 'X']);

        $resultado = $this->avaliar([$ancoraA, $ancoraB, $isolada]);

        $uidsStruct001 = array_column($this->finding($resultado, 'STRUCT-001')?->atividades ?? [], 'uid');
        $uidsStruct002 = array_column($this->finding($resultado, 'STRUCT-002')?->atividades ?? [], 'uid');
        $this->assertNotContains('X', $uidsStruct001);
        $this->assertNotContains('X', $uidsStruct002);
    }

    public function test_struct003_atividade_com_apenas_predecessora_nao_e_isolada(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar([$a, $b]);

        $uidsStruct003 = array_column($this->finding($resultado, 'STRUCT-003')?->atividades ?? [], 'uid');
        $this->assertNotContains('B', $uidsStruct003);
    }

    public function test_struct003_atividade_com_apenas_sucessora_nao_e_isolada(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar([$a, $b]);

        $uidsStruct003 = array_column($this->finding($resultado, 'STRUCT-003')?->atividades ?? [], 'uid');
        $this->assertNotContains('A', $uidsStruct003);
    }

    public function test_struct003_atividade_com_predecessora_e_sucessora_nao_dispara_nada(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);
        $c = $this->tarefa(['uid' => 'C', 'predecessoras' => [$this->link('B')]]);

        $resultado = $this->avaliar([$a, $b, $c]);

        foreach (['STRUCT-001', 'STRUCT-002', 'STRUCT-003'] as $regraId) {
            $uids = array_column($this->finding($resultado, $regraId)?->atividades ?? [], 'uid');
            $this->assertNotContains('B', $uids);
        }
    }

    // -------------------------------------------------------------------
    // STRUCT-004 — redes desconectadas
    // -------------------------------------------------------------------

    public function test_struct004_rede_unica_nao_dispara(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);
        $c = $this->tarefa(['uid' => 'C', 'predecessoras' => [$this->link('B')]]);

        $resultado = $this->avaliar([$a, $b, $c]);

        $this->assertNotContains('STRUCT-004', $this->findingIds($resultado));
    }

    public function test_struct004_duas_redes_desconectadas_geram_dois_findings(): void
    {
        $a1 = $this->tarefa(['uid' => 'A1']);
        $a2 = $this->tarefa(['uid' => 'A2', 'predecessoras' => [$this->link('A1')]]);
        $a3 = $this->tarefa(['uid' => 'A3', 'predecessoras' => [$this->link('A2')]]);
        $b1 = $this->tarefa(['uid' => 'B1']);
        $b2 = $this->tarefa(['uid' => 'B2', 'predecessoras' => [$this->link('B1')]]);
        $b3 = $this->tarefa(['uid' => 'B3', 'predecessoras' => [$this->link('B2')]]);

        $resultado = $this->avaliar([$a1, $a2, $a3, $b1, $b2, $b3]);

        $findingsStruct004 = array_values(array_filter($resultado->findings, fn ($f) => $f->regraId === 'STRUCT-004'));
        $this->assertCount(2, $findingsStruct004);
    }

    public function test_struct004_componentes_de_tamanho_1_nao_contam_como_rede_desconectada(): void
    {
        // Uma rede real (A1-A2-A3) + duas atividades isoladas (B, C) — as
        // isoladas já são STRUCT-003, não devem virar "componentes" de
        // STRUCT-004 (decisão documentada em CLAUDE.md).
        $a1 = $this->tarefa(['uid' => 'A1']);
        $a2 = $this->tarefa(['uid' => 'A2', 'predecessoras' => [$this->link('A1')]]);
        $a3 = $this->tarefa(['uid' => 'A3', 'predecessoras' => [$this->link('A2')]]);
        $isoladaB = $this->tarefa(['uid' => 'B']);
        $isoladaC = $this->tarefa(['uid' => 'C']);

        $resultado = $this->avaliar([$a1, $a2, $a3, $isoladaB, $isoladaC]);

        $this->assertNotContains('STRUCT-004', $this->findingIds($resultado));
    }

    public function test_struct004_tres_componentes_de_tamanhos_diferentes_qualificados(): void
    {
        $a1 = $this->tarefa(['uid' => 'A1']);
        $a2 = $this->tarefa(['uid' => 'A2', 'predecessoras' => [$this->link('A1')]]);
        $b1 = $this->tarefa(['uid' => 'B1']);
        $b2 = $this->tarefa(['uid' => 'B2', 'predecessoras' => [$this->link('B1')]]);
        $c1 = $this->tarefa(['uid' => 'C1']);
        $c2 = $this->tarefa(['uid' => 'C2', 'predecessoras' => [$this->link('C1')]]);
        $c3 = $this->tarefa(['uid' => 'C3', 'predecessoras' => [$this->link('C2')]]);

        $resultado = $this->avaliar([$a1, $a2, $b1, $b2, $c1, $c2, $c3]);

        $findingsStruct004 = array_values(array_filter($resultado->findings, fn ($f) => $f->regraId === 'STRUCT-004'));
        $this->assertCount(3, $findingsStruct004);
        $tamanhos = array_map(fn ($f) => $f->atividades[0]['quantidade_atividades'], $findingsStruct004);
        sort($tamanhos);
        $this->assertSame([2, 2, 3], $tamanhos);
    }

    public function test_struct004_atividades_conectadas_em_direcoes_diferentes_continuam_uma_unica_rede(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $c = $this->tarefa(['uid' => 'C']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A'), $this->link('C')]]);
        $d = $this->tarefa(['uid' => 'D', 'predecessoras' => [$this->link('B')]]);

        $resultado = $this->avaliar([$a, $b, $c, $d]);

        $this->assertNotContains('STRUCT-004', $this->findingIds($resultado));
    }

    // -------------------------------------------------------------------
    // STRUCT-005 — ciclos
    // -------------------------------------------------------------------

    public function test_struct005_sem_ciclo_nao_dispara(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar([$a, $b]);

        $this->assertNotContains('STRUCT-005', $this->findingIds($resultado));
    }

    public function test_struct005_ciclo_a_b_a_dispara_com_severidade_critico(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'predecessoras' => [$this->link('B')]]);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $resultado = $this->avaliar([$a, $b]);

        $finding = $this->finding($resultado, 'STRUCT-005');
        $this->assertNotNull($finding);
        $this->assertSame(HealthCheckSeveridade::Critico, $finding->severidade);
        $uids = array_column($finding->atividades[0]['atividades'], 'uid');
        $this->assertEqualsCanonicalizing(['A', 'B'], $uids);
    }

    public function test_struct005_ciclo_a_b_c_a_dispara(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'predecessoras' => [$this->link('C')]]);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);
        $c = $this->tarefa(['uid' => 'C', 'predecessoras' => [$this->link('B')]]);

        $resultado = $this->avaliar([$a, $b, $c]);

        $finding = $this->finding($resultado, 'STRUCT-005');
        $uids = array_column($finding->atividades[0]['atividades'], 'uid');
        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], $uids);
    }

    public function test_struct005_multiplos_ciclos_geram_findings_separados(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'predecessoras' => [$this->link('B')]]);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);
        $x = $this->tarefa(['uid' => 'X', 'predecessoras' => [$this->link('Y')]]);
        $y = $this->tarefa(['uid' => 'Y', 'predecessoras' => [$this->link('X')]]);

        $resultado = $this->avaliar([$a, $b, $x, $y]);

        $findingsStruct005 = array_values(array_filter($resultado->findings, fn ($f) => $f->regraId === 'STRUCT-005'));
        $this->assertCount(2, $findingsStruct005);
    }

    public function test_struct005_grafo_complexo_com_ciclo_no_meio_de_um_dag(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A'), $this->link('D')]]);
        $c = $this->tarefa(['uid' => 'C', 'predecessoras' => [$this->link('B')]]);
        $d = $this->tarefa(['uid' => 'D', 'predecessoras' => [$this->link('C')]]);
        $e = $this->tarefa(['uid' => 'E', 'predecessoras' => [$this->link('D')]]);

        $resultado = $this->avaliar([$a, $b, $c, $d, $e]);

        $finding = $this->finding($resultado, 'STRUCT-005');
        $uids = array_column($finding->atividades[0]['atividades'], 'uid');
        $this->assertEqualsCanonicalizing(['B', 'C', 'D'], $uids);
    }

    public function test_struct005_grafo_grande_sem_ciclo_nao_acusa_falso_positivo(): void
    {
        $tarefas = [];
        $anterior = null;
        for ($i = 1; $i <= 150; $i++) {
            $uid = "T{$i}";
            $preds = $anterior ? [$this->link($anterior)] : [];
            $tarefas[] = $this->tarefa(['uid' => $uid, 'predecessoras' => $preds]);
            $anterior = $uid;
        }

        $resultado = $this->avaliar($tarefas);

        $this->assertNotContains('STRUCT-005', $this->findingIds($resultado));
    }

    // -------------------------------------------------------------------
    // Tipos de atividade — cobertura explícita (resumo/marco/inativa/normal)
    // -------------------------------------------------------------------

    public function test_tipo_tarefa_resumo_nunca_gera_finding_estrutural_proprio(): void
    {
        [$ancoraA, $ancoraB] = $this->parConectado();
        $resumo = $this->tarefa(['uid' => 'RESUMO', 'isSummary' => true]);

        $resultado = $this->avaliar([$ancoraA, $ancoraB, $resumo]);

        foreach ($resultado->findings as $finding) {
            if (in_array($finding->regraId, ['STRUCT-001', 'STRUCT-002', 'STRUCT-003'], true)) {
                $uids = array_column($finding->atividades, 'uid');
                $this->assertNotContains('RESUMO', $uids);
            }
        }
    }

    public function test_tipo_marco_participa_normalmente_das_regras_estruturais(): void
    {
        $marco = $this->tarefa(['uid' => 'MARCO', 'isMarco' => true]);
        [$ancoraA, $ancoraB] = $this->parConectado();

        $resultado = $this->avaliar([$marco, $ancoraA, $ancoraB]);

        // Marco isolado (sem pred/suc) vira STRUCT-003, mesmo tratamento de qualquer atividade.
        $uids = array_column($this->finding($resultado, 'STRUCT-003')->atividades, 'uid');
        $this->assertContains('MARCO', $uids);
    }

    public function test_tipo_atividade_inativa_nunca_gera_finding_estrutural_proprio(): void
    {
        [$ancoraA, $ancoraB] = $this->parConectado();
        $inativa = $this->tarefa(['uid' => 'INATIVA', 'ativa' => false]);

        $resultado = $this->avaliar([$ancoraA, $ancoraB, $inativa]);

        foreach ($resultado->findings as $finding) {
            if (in_array($finding->regraId, ['STRUCT-001', 'STRUCT-002', 'STRUCT-003'], true)) {
                $uids = array_column($finding->atividades, 'uid');
                $this->assertNotContains('INATIVA', $uids);
            }
        }
    }

    public function test_tipo_atividade_normal_conectada_nao_gera_nenhum_finding_estrutural(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);
        $c = $this->tarefa(['uid' => 'C', 'predecessoras' => [$this->link('B')]]);

        $resultado = $this->avaliar([$a, $b, $c]);

        $uids = array_column($this->finding($resultado, 'STRUCT-001')?->atividades ?? [], 'uid');
        $this->assertNotContains('B', $uids);
        $uids002 = array_column($this->finding($resultado, 'STRUCT-002')?->atividades ?? [], 'uid');
        $this->assertNotContains('B', $uids002);
    }
}
