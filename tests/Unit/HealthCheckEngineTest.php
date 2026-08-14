<?php

namespace Tests\Unit;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Enums\TipoCronogramaImportacao;
use App\Support\HealthCheck\HealthCheckEngine;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckGrafoCronograma;
use App\Support\HealthCheck\HealthCheckRegraEstruturalInterface;
use App\Support\HealthCheck\HealthCheckResultado;
use App\Support\HealthCheck\HealthCheckRuleInterface;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class HealthCheckEngineTest extends TestCase
{
    private function tarefa(array $overrides = []): TarefaImportada
    {
        $defaults = [
            'uid' => '1',
            'nome' => 'Tarefa 1',
            'isSummary' => false,
            'isMarco' => false,
            'caminhoCritico' => true,
            'parentUid' => null,
            'codigo' => '1',
            'dataInicio' => Carbon::parse('2024-01-01'),
            'dataTermino' => Carbon::parse('2024-01-14'),
            'baselineInicio' => Carbon::parse('2024-01-01'),
            'baselineTermino' => Carbon::parse('2024-01-14'),
            'realInicio' => Carbon::parse('2024-01-01'),
            'realTermino' => Carbon::parse('2024-01-14'),
            'baselineHoras' => 40.0,
            'workHoras' => 40.0,
            'realHoras' => 40.0,
            'percentualConcluido' => 100.0,
            'textos' => [],
        ];

        $dados = array_merge($defaults, $overrides);

        return new TarefaImportada(...$dados);
    }

    private function plano(array $atualizar = [], array $criar = [], array $removerNomes = [], ?Carbon $dataStatus = null): PlanoImportacao
    {
        return new PlanoImportacao(
            criar: $criar,
            atualizar: $atualizar,
            pacotes: [],
            removerIds: $removerNomes ? array_fill(0, count($removerNomes), 'id-fake') : [],
            removerNomes: $removerNomes,
            ignoradasNomes: [],
            dataStatus: $dataStatus ?? Carbon::parse('2024-03-01'),
            totalBaselineHh: 0,
            totalWorkHh: 0,
            totalRealHh: 0,
            horasPeriodos: [],
        );
    }

    private function findingIds(HealthCheckResultado $resultado): array
    {
        return array_map(fn ($f) => $f->regraId, $resultado->findings);
    }

    // -------------------------------------------------------------------
    // Cenário base: tarefa 100% limpa não deve disparar NENHUMA regra.
    // -------------------------------------------------------------------

    public function test_tarefa_limpa_nao_gera_nenhum_finding(): void
    {
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$this->tarefa()]));

        $this->assertFalse($resultado->temAlertas());
        $this->assertSame([], $resultado->findings);
    }

    public function test_plano_vazio_nao_gera_nenhum_finding(): void
    {
        $resultado = (new HealthCheckEngine())->avaliar($this->plano());

        $this->assertFalse($resultado->temAlertas());
    }

    // -------------------------------------------------------------------
    // Datas
    // -------------------------------------------------------------------

    public function test_date001_inicio_real_apos_data_status(): void
    {
        $t = $this->tarefa(['realInicio' => Carbon::parse('2024-03-15')]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('DATE-001', $this->findingIds($resultado));
    }

    public function test_date002_termino_real_apos_data_status(): void
    {
        $t = $this->tarefa(['realTermino' => Carbon::parse('2024-03-15')]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('DATE-002', $this->findingIds($resultado));
    }

    public function test_date003_concluida_sem_termino_real(): void
    {
        $t = $this->tarefa(['percentualConcluido' => 100.0, 'realTermino' => null]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('DATE-003', $this->findingIds($resultado));
    }

    public function test_date004_avanco_sem_inicio_real(): void
    {
        $t = $this->tarefa(['percentualConcluido' => 40.0, 'realInicio' => null]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('DATE-004', $this->findingIds($resultado));
    }

    public function test_date005_termino_planejado_antes_do_inicio(): void
    {
        $t = $this->tarefa(['dataInicio' => Carbon::parse('2024-01-14'), 'dataTermino' => Carbon::parse('2024-01-01')]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('DATE-005', $this->findingIds($resultado));
    }

    public function test_date006_termino_real_antes_do_inicio_real(): void
    {
        $t = $this->tarefa(['realInicio' => Carbon::parse('2024-01-14'), 'realTermino' => Carbon::parse('2024-01-01')]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('DATE-006', $this->findingIds($resultado));
    }

    public function test_date007_zero_por_cento_com_termino_real(): void
    {
        $t = $this->tarefa(['percentualConcluido' => 0.0, 'realTermino' => Carbon::parse('2024-01-14')]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('DATE-007', $this->findingIds($resultado));
    }

    public function test_date007_nao_dispara_com_percentual_ausente(): void
    {
        // null (ausente) nunca deve ser tratado como 0 explícito.
        $t = $this->tarefa(['percentualConcluido' => null, 'realTermino' => Carbon::parse('2024-01-14')]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertNotContains('DATE-007', $this->findingIds($resultado));
    }

    public function test_date007_nao_dispara_sem_termino_real(): void
    {
        $t = $this->tarefa(['percentualConcluido' => 0.0, 'realTermino' => null]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertNotContains('DATE-007', $this->findingIds($resultado));
    }

    // -------------------------------------------------------------------
    // Avanço Físico
    // -------------------------------------------------------------------

    public function test_prog001_atividade_atrasada(): void
    {
        $t = $this->tarefa([
            'dataTermino' => Carbon::parse('2024-01-14'),
            'percentualConcluido' => 40.0,
            'realTermino' => null,
        ]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('PROG-001', $this->findingIds($resultado));
    }

    public function test_prog002_deveria_ter_iniciado_e_esta_em_zero(): void
    {
        $t = $this->tarefa([
            'dataInicio' => Carbon::parse('2024-01-01'),
            'percentualConcluido' => 0.0,
            'realInicio' => null,
            'realTermino' => null,
        ]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('PROG-002', $this->findingIds($resultado));
    }

    public function test_prog003_conclusao_apos_termino_planejado(): void
    {
        $t = $this->tarefa(['dataTermino' => Carbon::parse('2024-01-14'), 'realTermino' => Carbon::parse('2024-01-20')]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('PROG-003', $this->findingIds($resultado));
    }

    public function test_prog004_execucao_antecipada(): void
    {
        $t = $this->tarefa(['baselineInicio' => Carbon::parse('2024-01-05'), 'realInicio' => Carbon::parse('2024-01-01')]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('PROG-004', $this->findingIds($resultado));
    }

    // -------------------------------------------------------------------
    // HH
    // -------------------------------------------------------------------

    public function test_work001_tendencia_menor_que_realizado(): void
    {
        $t = $this->tarefa(['workHoras' => 20.0, 'realHoras' => 40.0]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('WORK-001', $this->findingIds($resultado));
    }

    public function test_work002_realizado_maior_que_baseline(): void
    {
        $t = $this->tarefa(['baselineHoras' => 20.0, 'realHoras' => 40.0, 'workHoras' => 40.0]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('WORK-002', $this->findingIds($resultado));
    }

    public function test_work003_concluida_com_hh_zerado(): void
    {
        $t = $this->tarefa(['percentualConcluido' => 100.0, 'realHoras' => 0.0]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('WORK-003', $this->findingIds($resultado));
    }

    public function test_work004_hh_realizado_sem_inicio_real(): void
    {
        $t = $this->tarefa(['realHoras' => 8.0, 'realInicio' => null]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('WORK-004', $this->findingIds($resultado));
    }

    public function test_work005_hh_realizado_com_zero_por_cento(): void
    {
        $t = $this->tarefa(['realHoras' => 8.0, 'percentualConcluido' => 0.0]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('WORK-005', $this->findingIds($resultado));
    }

    public function test_work006_duracao_sem_hh(): void
    {
        $t = $this->tarefa(['workHoras' => 0.0, 'baselineHoras' => 0.0, 'realHoras' => 0.0, 'percentualConcluido' => null]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('WORK-006', $this->findingIds($resultado));
    }

    // -------------------------------------------------------------------
    // Duração
    // -------------------------------------------------------------------

    public function test_dur001_duracao_zero(): void
    {
        $t = $this->tarefa(['dataInicio' => Carbon::parse('2024-01-01'), 'dataTermino' => Carbon::parse('2024-01-01')]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('DUR-001', $this->findingIds($resultado));
    }

    public function test_dur002_duracao_excessiva(): void
    {
        $t = $this->tarefa(['dataInicio' => Carbon::parse('2024-01-01'), 'dataTermino' => Carbon::parse('2024-12-01')]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('DUR-002', $this->findingIds($resultado));
    }

    // -------------------------------------------------------------------
    // Caminho Crítico
    // -------------------------------------------------------------------

    public function test_crit001_nenhuma_atividade_critica(): void
    {
        $t = $this->tarefa(['caminhoCritico' => false]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('CRIT-001', $this->findingIds($resultado));
    }

    public function test_crit001_nao_dispara_com_pelo_menos_uma_critica(): void
    {
        $critica = $this->tarefa(['uid' => '1', 'caminhoCritico' => true]);
        $naoCritica = $this->tarefa(['uid' => '2', 'caminhoCritico' => false]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$critica, $naoCritica]));

        $this->assertNotContains('CRIT-001', $this->findingIds($resultado));
    }

    // -------------------------------------------------------------------
    // Marcos
    // -------------------------------------------------------------------

    public function test_mile001_marco_atrasado(): void
    {
        $t = $this->tarefa([
            'isMarco' => true,
            'dataTermino' => Carbon::parse('2024-01-14'),
            'realTermino' => null,
        ]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('MILE-001', $this->findingIds($resultado));
    }

    public function test_mile002_marco_com_variacao_relevante(): void
    {
        $t = $this->tarefa([
            'isMarco' => true,
            'baselineTermino' => Carbon::parse('2024-01-14'),
            'dataTermino' => Carbon::parse('2024-02-14'),
        ]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('MILE-002', $this->findingIds($resultado));
    }

    // -------------------------------------------------------------------
    // Baseline
    // -------------------------------------------------------------------

    public function test_base002_atividades_arquivadas(): void
    {
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$this->tarefa()], removerNomes: ['Atividade Antiga']));

        $this->assertContains('BASE-002', $this->findingIds($resultado));
    }

    public function test_base003_baseline_incompleta(): void
    {
        $t = $this->tarefa(['baselineInicio' => null]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertContains('BASE-003', $this->findingIds($resultado));
    }

    // -------------------------------------------------------------------
    // Agregação (severidade, categoria, serialização)
    // -------------------------------------------------------------------

    public function test_totais_por_severidade_e_ocorrencias(): void
    {
        // 2 atividades atrasadas (PROG-001, severidade Alto) => 2 ocorrências na mesma regra.
        $t1 = $this->tarefa(['uid' => '1', 'dataTermino' => Carbon::parse('2024-01-14'), 'percentualConcluido' => 40.0, 'realTermino' => null]);
        $t2 = $this->tarefa(['uid' => '2', 'dataTermino' => Carbon::parse('2024-01-20'), 'percentualConcluido' => 50.0, 'realTermino' => null]);

        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t1, $t2]));

        $porSeveridade = $resultado->totalPorSeveridade();
        $this->assertSame(2, $porSeveridade[HealthCheckSeveridade::Alto->value]);
        $this->assertSame(1, $resultado->totalRegras());
        $this->assertSame(2, $resultado->totalOcorrencias());
    }

    public function test_score_preliminar_nao_e_calculado_na_fase_1(): void
    {
        $t = $this->tarefa(['realInicio' => Carbon::parse('2024-03-15')]);
        $resultado = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $this->assertNull($resultado->scorePreliminar());
    }

    public function test_toarray_fromarray_preserva_findings(): void
    {
        $t = $this->tarefa(['realInicio' => Carbon::parse('2024-03-15')]);
        $original = (new HealthCheckEngine())->avaliar($this->plano([$t]));

        $reidratado = HealthCheckResultado::fromArray($original->toArray());

        $this->assertSame($this->findingIds($original), $this->findingIds($reidratado));
        $this->assertSame($original->totalPorSeveridade(), $reidratado->totalPorSeveridade());
    }

    // -------------------------------------------------------------------
    // Filtro por Tipo de Importação (Baseline x Avanço/Ambos) — Ciclo 2
    // -------------------------------------------------------------------

    /**
     * @return array{0: TarefaImportada, 1: TarefaImportada} [execucao, planejamento]
     */
    private function planoComUmaRegraDeCadaNatureza(): array
    {
        // PROG-001 (Execucao): término planejado já passou da data de status, sem conclusão.
        $execucao = $this->tarefa([
            'uid' => '1',
            'dataTermino' => Carbon::parse('2024-01-14'),
            'percentualConcluido' => 40.0,
            'realTermino' => null,
        ]);

        // DUR-001 (Planejamento): início == término, sem ser marco.
        $planejamento = $this->tarefa([
            'uid' => '2',
            'dataInicio' => Carbon::parse('2024-01-01'),
            'dataTermino' => Carbon::parse('2024-01-01'),
        ]);

        return [$execucao, $planejamento];
    }

    public function test_avaliar_sem_tipo_mantem_comportamento_legado_com_as_36_regras(): void
    {
        [$execucao, $planejamento] = $this->planoComUmaRegraDeCadaNatureza();
        $ids = $this->findingIds((new HealthCheckEngine())->avaliar($this->plano([$execucao, $planejamento])));

        $this->assertContains('PROG-001', $ids);
        $this->assertContains('DUR-001', $ids);
    }

    public function test_avaliar_com_tipo_baseline_avalia_somente_regras_de_planejamento(): void
    {
        [$execucao, $planejamento] = $this->planoComUmaRegraDeCadaNatureza();
        $ids = $this->findingIds(
            (new HealthCheckEngine())->avaliar($this->plano([$execucao, $planejamento]), TipoCronogramaImportacao::Baseline)
        );

        $this->assertContains('DUR-001', $ids);
        $this->assertNotContains('PROG-001', $ids);
    }

    public function test_avaliar_com_tipo_avanco_mantem_as_36_regras(): void
    {
        [$execucao, $planejamento] = $this->planoComUmaRegraDeCadaNatureza();
        $ids = $this->findingIds(
            (new HealthCheckEngine())->avaliar($this->plano([$execucao, $planejamento]), TipoCronogramaImportacao::Avanco)
        );

        $this->assertContains('PROG-001', $ids);
        $this->assertContains('DUR-001', $ids);
    }

    public function test_avaliar_com_tipo_ambos_mantem_as_36_regras(): void
    {
        [$execucao, $planejamento] = $this->planoComUmaRegraDeCadaNatureza();
        $ids = $this->findingIds(
            (new HealthCheckEngine())->avaliar($this->plano([$execucao, $planejamento]), TipoCronogramaImportacao::Ambos)
        );

        $this->assertContains('PROG-001', $ids);
        $this->assertContains('DUR-001', $ids);
    }

    public function test_natureza_da_regra_resolve_regras_conhecidas_e_null_para_desconhecida(): void
    {
        $engine = new HealthCheckEngine();

        $this->assertSame(HealthCheckNaturezaRegra::Planejamento, $engine->naturezaDaRegra('DUR-001'));
        $this->assertSame(HealthCheckNaturezaRegra::Execucao, $engine->naturezaDaRegra('PROG-001'));
        $this->assertSame(HealthCheckNaturezaRegra::Planejamento, $engine->naturezaDaRegra('STRUCT-001'));
        $this->assertNull($engine->naturezaDaRegra('REGRA-INEXISTENTE'));
    }

    public function test_filtro_baseline_impede_que_regra_de_execucao_seja_sequer_avaliada(): void
    {
        // Regra fake que EXPLODE se avaliar() for chamado — prova que o
        // filtro acontece ANTES do loop, não que o finding só é escondido
        // depois de gerado.
        $regraExecucaoQueExplode = new class implements HealthCheckRuleInterface {
            public function id(): string { return 'FAKE-EXEC'; }
            public function categoria(): HealthCheckCategoria { return HealthCheckCategoria::Avanco; }
            public function severidade(): HealthCheckSeveridade { return HealthCheckSeveridade::Alto; }
            public function bloqueante(): bool { return false; }
            public function natureza(): HealthCheckNaturezaRegra { return HealthCheckNaturezaRegra::Execucao; }
            public function avaliar(PlanoImportacao $plano): ?HealthCheckFinding
            {
                throw new \RuntimeException('Regra de Execução foi avaliada numa importação Baseline.');
            }
        };

        $chamadas = 0;
        $regraPlanejamentoQueConta = new class ($chamadas) implements HealthCheckRuleInterface {
            public function __construct(private int &$chamadas) {}
            public function id(): string { return 'FAKE-PLAN'; }
            public function categoria(): HealthCheckCategoria { return HealthCheckCategoria::Duracao; }
            public function severidade(): HealthCheckSeveridade { return HealthCheckSeveridade::Baixo; }
            public function bloqueante(): bool { return false; }
            public function natureza(): HealthCheckNaturezaRegra { return HealthCheckNaturezaRegra::Planejamento; }
            public function avaliar(PlanoImportacao $plano): ?HealthCheckFinding
            {
                $this->chamadas++;
                return null;
            }
        };

        $engine = new HealthCheckEngine(regras: [$regraExecucaoQueExplode, $regraPlanejamentoQueConta], regrasEstruturais: []);

        // Não deve lançar exceção — a regra Execucao nunca chega a ser chamada.
        $engine->avaliar($this->plano([$this->tarefa()]), TipoCronogramaImportacao::Baseline);

        $this->assertSame(1, $chamadas, 'A regra de Planejamento deveria continuar sendo avaliada normalmente.');
    }

    public function test_filtro_baseline_tambem_impede_regra_estrutural_de_execucao_de_ser_avaliada(): void
    {
        $regraEstruturalExecucaoQueExplode = new class implements HealthCheckRegraEstruturalInterface {
            public function id(): string { return 'FAKE-ESTRUT-EXEC'; }
            public function natureza(): HealthCheckNaturezaRegra { return HealthCheckNaturezaRegra::Execucao; }
            public function avaliar(PlanoImportacao $plano, HealthCheckGrafoCronograma $grafo): array
            {
                throw new \RuntimeException('Regra estrutural de Execução foi avaliada numa importação Baseline.');
            }
        };

        $engine = new HealthCheckEngine(regras: [], regrasEstruturais: [$regraEstruturalExecucaoQueExplode]);

        $resultado = $engine->avaliar($this->plano([$this->tarefa()]), TipoCronogramaImportacao::Baseline);

        $this->assertSame([], $resultado->findings);
    }
}
