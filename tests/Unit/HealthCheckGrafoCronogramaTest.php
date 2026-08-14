<?php

namespace Tests\Unit;

use App\DTOs\PlanoImportacao;
use App\DTOs\PredecessoraLink;
use App\DTOs\TarefaImportada;
use App\Support\HealthCheck\HealthCheckGrafoCronograma;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Testa só o grafo (HealthCheckGrafoCronograma) — construção, graus,
 * componentes e ciclos — sem passar pelas regras STRUCT-*. Ver
 * HealthCheckEstruturalTest.php pras regras que consomem este grafo.
 */
class HealthCheckGrafoCronogramaTest extends TestCase
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
            tipo: \App\Enums\TipoRelacionamentoPredecessora::FinishToStart,
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

    // -------------------------------------------------------------------
    // Construção / nós / arestas
    // -------------------------------------------------------------------

    public function test_grau_entrada_e_saida_com_predecessora_simples(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a, $b]));

        $this->assertSame(0, $grafo->grauEntrada('A'));
        $this->assertSame(1, $grafo->grauSaida('A'));
        $this->assertSame(1, $grafo->grauEntrada('B'));
        $this->assertSame(0, $grafo->grauSaida('B'));
        $this->assertSame(['A'], $grafo->predecessorasDe('B'));
        $this->assertSame(['B'], $grafo->sucessorasDe('A'));
    }

    public function test_tarefa_resumo_nunca_e_no_do_grafo(): void
    {
        $resumo = $this->tarefa(['uid' => 'R', 'isSummary' => true]);
        $filha = $this->tarefa(['uid' => 'F', 'predecessoras' => [$this->link('R')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$resumo, $filha]));

        $this->assertNull($grafo->tarefa('R'));
        $this->assertArrayNotHasKey('R', $grafo->tarefas());
        // Relação apontando pra resumo é ignorada — F fica com grau de entrada 0.
        $this->assertSame(0, $grafo->grauEntrada('F'));
    }

    public function test_tarefa_inativa_nunca_e_no_do_grafo(): void
    {
        $inativa = $this->tarefa(['uid' => 'I', 'ativa' => false]);
        $ativa = $this->tarefa(['uid' => 'A', 'predecessoras' => [$this->link('I')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$inativa, $ativa]));

        $this->assertNull($grafo->tarefa('I'));
        $this->assertSame(0, $grafo->grauEntrada('A'));
    }

    public function test_predecessora_apontando_pra_uid_inexistente_e_ignorada(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'predecessoras' => [$this->link('nao-existe')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a]));

        $this->assertSame(0, $grafo->grauEntrada('A'));
    }

    public function test_marco_e_no_normal_do_grafo(): void
    {
        $marco = $this->tarefa(['uid' => 'M', 'isMarco' => true]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$marco]));

        $this->assertNotNull($grafo->tarefa('M'));
        $this->assertSame(0, $grafo->grauEntrada('M'));
        $this->assertSame(0, $grafo->grauSaida('M'));
    }

    // -------------------------------------------------------------------
    // semNenhumaRelacaoCapturada()
    // -------------------------------------------------------------------

    public function test_sem_nenhuma_relacao_capturada_quando_nao_ha_predecessor_link_algum(): void
    {
        $grafo = HealthCheckGrafoCronograma::construir($this->plano([
            $this->tarefa(['uid' => '1']),
            $this->tarefa(['uid' => '2']),
        ]));

        $this->assertTrue($grafo->semNenhumaRelacaoCapturada());
        $this->assertSame(0, $grafo->totalArestas());
    }

    public function test_tem_relacao_capturada_quando_ha_pelo_menos_um_link_valido(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a, $b]));

        $this->assertFalse($grafo->semNenhumaRelacaoCapturada());
        $this->assertSame(1, $grafo->totalArestas());
    }

    // -------------------------------------------------------------------
    // Componentes (conectividade não direcionada)
    // -------------------------------------------------------------------

    public function test_rede_unica_gera_um_unico_componente(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);
        $c = $this->tarefa(['uid' => 'C', 'predecessoras' => [$this->link('B')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a, $b, $c]));
        $componentes = $grafo->componentes();

        $this->assertCount(1, $componentes);
        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], $componentes[0]);
    }

    public function test_duas_redes_desconectadas_geram_dois_componentes(): void
    {
        $a1 = $this->tarefa(['uid' => 'A1']);
        $a2 = $this->tarefa(['uid' => 'A2', 'predecessoras' => [$this->link('A1')]]);
        $a3 = $this->tarefa(['uid' => 'A3', 'predecessoras' => [$this->link('A2')]]);
        $b1 = $this->tarefa(['uid' => 'B1']);
        $b2 = $this->tarefa(['uid' => 'B2', 'predecessoras' => [$this->link('B1')]]);
        $b3 = $this->tarefa(['uid' => 'B3', 'predecessoras' => [$this->link('B2')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a1, $a2, $a3, $b1, $b2, $b3]));
        $componentes = $grafo->componentes();

        $this->assertCount(2, $componentes);
        $tamanhos = array_map('count', $componentes);
        sort($tamanhos);
        $this->assertSame([3, 3], $tamanhos);
    }

    public function test_tres_componentes_de_tamanhos_diferentes(): void
    {
        $a1 = $this->tarefa(['uid' => 'A1']);
        $a2 = $this->tarefa(['uid' => 'A2', 'predecessoras' => [$this->link('A1')]]);
        $b1 = $this->tarefa(['uid' => 'B1']);
        $c1 = $this->tarefa(['uid' => 'C1']);
        $c2 = $this->tarefa(['uid' => 'C2', 'predecessoras' => [$this->link('C1')]]);
        $c3 = $this->tarefa(['uid' => 'C3', 'predecessoras' => [$this->link('C2')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a1, $a2, $b1, $c1, $c2, $c3]));
        $componentes = $grafo->componentes();

        $tamanhos = array_map('count', $componentes);
        sort($tamanhos);
        $this->assertSame([1, 2, 3], $tamanhos);
    }

    public function test_componentes_conectados_por_relacoes_em_direcoes_diferentes_contam_como_um(): void
    {
        // A → B, C → B (duas predecessoras diferentes pra B) — mesma rede,
        // mesmo com "direções" de aresta diferentes entrando no mesmo nó.
        $a = $this->tarefa(['uid' => 'A']);
        $c = $this->tarefa(['uid' => 'C']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A'), $this->link('C')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a, $b, $c]));

        $this->assertCount(1, $grafo->componentes());
    }

    // -------------------------------------------------------------------
    // Ciclos (grafo direcionado)
    // -------------------------------------------------------------------

    public function test_sem_ciclo_em_grafo_acclico_normal(): void
    {
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);
        $c = $this->tarefa(['uid' => 'C', 'predecessoras' => [$this->link('B')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a, $b, $c]));

        $this->assertSame([], $grafo->ciclos());
    }

    public function test_ciclo_simples_a_b_a(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'predecessoras' => [$this->link('B')]]);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a, $b]));
        $ciclos = $grafo->ciclos();

        $this->assertCount(1, $ciclos);
        $this->assertEqualsCanonicalizing(['A', 'B'], $ciclos[0]);
    }

    public function test_ciclo_a_b_c_a(): void
    {
        $a = $this->tarefa(['uid' => 'A', 'predecessoras' => [$this->link('C')]]);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);
        $c = $this->tarefa(['uid' => 'C', 'predecessoras' => [$this->link('B')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a, $b, $c]));
        $ciclos = $grafo->ciclos();

        $this->assertCount(1, $ciclos);
        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], $ciclos[0]);
    }

    public function test_multiplos_ciclos_independentes(): void
    {
        // Ciclo 1: A→B→A. Ciclo 2: X→Y→X. Sem conexão entre os dois grupos.
        $a = $this->tarefa(['uid' => 'A', 'predecessoras' => [$this->link('B')]]);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A')]]);
        $x = $this->tarefa(['uid' => 'X', 'predecessoras' => [$this->link('Y')]]);
        $y = $this->tarefa(['uid' => 'Y', 'predecessoras' => [$this->link('X')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a, $b, $x, $y]));
        $ciclos = $grafo->ciclos();

        $this->assertCount(2, $ciclos);
    }

    public function test_grafo_complexo_com_ciclo_embutido_no_meio_de_um_dag(): void
    {
        // Entrada: A → B. Ciclo: B → C → D → B. Saída: D → E.
        $a = $this->tarefa(['uid' => 'A']);
        $b = $this->tarefa(['uid' => 'B', 'predecessoras' => [$this->link('A'), $this->link('D')]]);
        $c = $this->tarefa(['uid' => 'C', 'predecessoras' => [$this->link('B')]]);
        $d = $this->tarefa(['uid' => 'D', 'predecessoras' => [$this->link('C')]]);
        $e = $this->tarefa(['uid' => 'E', 'predecessoras' => [$this->link('D')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$a, $b, $c, $d, $e]));
        $ciclos = $grafo->ciclos();

        $this->assertCount(1, $ciclos);
        $this->assertEqualsCanonicalizing(['B', 'C', 'D'], $ciclos[0]);
    }

    public function test_grafo_grande_sem_ciclo_nao_acusa_falso_positivo(): void
    {
        $tarefas = [];
        $anterior = null;
        for ($i = 1; $i <= 200; $i++) {
            $uid = "T{$i}";
            $preds = $anterior ? [$this->link($anterior)] : [];
            $tarefas[] = $this->tarefa(['uid' => $uid, 'predecessoras' => $preds]);
            $anterior = $uid;
        }

        $grafo = HealthCheckGrafoCronograma::construir($this->plano($tarefas));

        $this->assertSame([], $grafo->ciclos());
        $this->assertCount(1, $grafo->componentes());
    }

    public function test_auto_laco_e_detectado_como_ciclo_de_um_no(): void
    {
        // A lista a si mesma como predecessora — caso degenerado.
        $aComAutoLaco = $this->tarefa(['uid' => 'A', 'predecessoras' => [$this->link('A')]]);

        $grafo = HealthCheckGrafoCronograma::construir($this->plano([$aComAutoLaco]));
        $ciclos = $grafo->ciclos();

        $this->assertCount(1, $ciclos);
        $this->assertSame(['A'], $ciclos[0]);
    }
}
