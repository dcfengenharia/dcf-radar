<?php

namespace Tests\Unit;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckResultado;
use App\Support\HealthCheck\Score\FaixaScore;
use App\Support\HealthCheck\Score\ScoreCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Fase 3 (Score de Saúde) — testa SOMENTE o motor de cálculo
 * (ScoreCalculator), sem nenhuma persistência/UI/histórico. Findings são
 * construídos à mão (nunca via HealthCheckEngine real) para controlar
 * precisamente severidade/quantidade/proporção em cada cenário.
 */
class ScoreCalculatorTest extends TestCase
{
    private function tarefa(array $overrides = []): TarefaImportada
    {
        $defaults = [
            'uid' => '1',
            'nome' => 'Tarefa',
            'isSummary' => false,
            'isMarco' => false,
            'caminhoCritico' => false,
            'parentUid' => null,
            'codigo' => '1',
            'dataInicio' => null,
            'dataTermino' => null,
            'baselineInicio' => null,
            'baselineTermino' => null,
            'realInicio' => null,
            'realTermino' => null,
            'baselineHoras' => 0.0,
            'workHoras' => 0.0,
            'realHoras' => 0.0,
            'percentualConcluido' => null,
            'textos' => [],
        ];

        return new TarefaImportada(...array_merge($defaults, $overrides));
    }

    /** Monta um plano com $elegiveis atividades ativas + $inativas atividades inativas + $resumos tarefas-resumo (defensivamente ignoradas). */
    private function plano(int $elegiveis, int $inativas = 0, int $resumos = 0): PlanoImportacao
    {
        $criar = [];

        for ($i = 0; $i < $elegiveis; $i++) {
            $criar[] = $this->tarefa(['uid' => "e{$i}"]);
        }

        for ($i = 0; $i < $inativas; $i++) {
            $criar[] = $this->tarefa(['uid' => "i{$i}", 'ativa' => false]);
        }

        for ($i = 0; $i < $resumos; $i++) {
            $criar[] = $this->tarefa(['uid' => "r{$i}", 'isSummary' => true]);
        }

        return new PlanoImportacao(
            criar: $criar,
            atualizar: [],
            pacotes: [],
            removerIds: [],
            removerNomes: [],
            ignoradasNomes: [],
            dataStatus: null,
            totalBaselineHh: 0,
            totalWorkHh: 0,
            totalRealHh: 0,
            horasPeriodos: [],
        );
    }

    private function finding(
        string $regraId,
        HealthCheckCategoria $categoria,
        HealthCheckSeveridade $severidade,
        int $quantidade,
        string $recomendacao = 'Recomendação padrão.',
    ): HealthCheckFinding {
        $atividades = [];
        for ($i = 0; $i < $quantidade; $i++) {
            $atividades[] = ['uid' => "{$regraId}-{$i}"];
        }

        return new HealthCheckFinding(
            regraId: $regraId,
            categoria: $categoria,
            severidade: $severidade,
            titulo: "Título {$regraId}",
            descricao: "Descrição {$regraId}",
            impacto: "Texto de impacto {$regraId}",
            recomendacao: $recomendacao,
            atividades: $atividades,
        );
    }

    private function resultado(array $findings): HealthCheckResultado
    {
        return new HealthCheckResultado(findings: $findings);
    }

    // =====================================================================
    // SCORE BÁSICO
    // =====================================================================

    public function test_sem_findings_score_100(): void
    {
        $r = (new ScoreCalculator())->calcular($this->resultado([]), $this->plano(100));

        $this->assertSame(100, $r->score);
        $this->assertSame(FaixaScore::Excelente, $r->faixa);
        $this->assertSame(0, $r->potencialRecuperavel);
    }

    public function test_apenas_informativos_score_100(): void
    {
        $findings = [
            $this->finding('LOGIC-009', HealthCheckCategoria::Logica, HealthCheckSeveridade::Informativo, 100),
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(100, $r->score);
        $this->assertCount(0, $r->mapaAcoes, 'informativo não entra no mapa de ações');
    }

    public function test_um_finding_baixo(): void
    {
        // total=100, quantidade=10 (10%), Baixo: peso_maximo=-10 => impacto=-1
        $findings = [$this->finding('DATE-001', HealthCheckCategoria::Datas, HealthCheckSeveridade::Baixo, 10)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(99, $r->score);
    }

    public function test_um_finding_medio(): void
    {
        // total=100, quantidade=10 (10%), Médio: peso_maximo=-20 => impacto=-2
        $findings = [$this->finding('WORK-001', HealthCheckCategoria::Hh, HealthCheckSeveridade::Medio, 10)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(98, $r->score);
    }

    public function test_um_finding_alto(): void
    {
        // total=100, quantidade=10 (10%), Alto: peso_maximo=-50 => impacto=-5
        $findings = [$this->finding('SLACK-001', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 10)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(95, $r->score);
    }

    public function test_um_finding_critico(): void
    {
        // total=100, quantidade=10 (10%), Crítico: peso_maximo=-100 => impacto=-10
        $findings = [$this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 10)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(90, $r->score);
    }

    // =====================================================================
    // PROPORCIONALIDADE
    // =====================================================================

    public function test_mesmo_finding_cronograma_pequeno(): void
    {
        // Exemplo A aprovado: 50 elegíveis, STRUCT-005 Crítico afetando 3.
        $findings = [$this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 3)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(50));

        // impacto = -100 * (3/50) = -6 => score = 94
        $this->assertSame(94, $r->score);
        $this->assertSame(94, $r->porDimensao[HealthCheckCategoria::Estrutura->value]->score);
    }

    public function test_mesmo_finding_cronograma_grande_sem_piso_minimo_para_ciclos(): void
    {
        // Exemplo B aprovado: MESMO defeito absoluto (STRUCT-005, 3 atividades),
        // mas em 2.000 atividades elegíveis — decisão deliberada de calibração
        // inicial: ciclos lógicos NÃO têm piso mínimo de impacto nesta versão,
        // então o mesmo problema estrutural grave fica quase invisível no
        // Score de um cronograma grande. Documentado explicitamente aqui.
        $findings = [$this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 3)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(2000));

        // impacto = -100 * (3/2000) = -0,15 => score = round(99,85) = 100
        $this->assertSame(100, $r->score);
    }

    public function test_confirma_proporcionalidade_matematica(): void
    {
        // Mesma PROPORÇÃO (10%) em totais bem diferentes deve produzir o
        // mesmo Score — é a normalização por tamanho funcionando.
        $findingPequeno = [$this->finding('SLACK-001', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 50)];
        $findingGrande = [$this->finding('SLACK-001', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 500)];

        $rPequeno = (new ScoreCalculator())->calcular($this->resultado($findingPequeno), $this->plano(500));
        $rGrande = (new ScoreCalculator())->calcular($this->resultado($findingGrande), $this->plano(5000));

        $this->assertSame($rPequeno->score, $rGrande->score);
    }

    // =====================================================================
    // QUANTIDADE DE ATIVIDADES
    // =====================================================================

    public function test_finding_afeta_1_atividade(): void
    {
        // total=100, quantidade=1 (1%), Crítico: impacto = -100*0,01 = -1
        $findings = [$this->finding('STRUCT-003', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 1)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(99, $r->score);
    }

    public function test_finding_afeta_varias_atividades(): void
    {
        // total=100, quantidade=30 (30%), Médio: impacto = -20*0,3 = -6
        $findings = [$this->finding('WORK-002', HealthCheckCategoria::Hh, HealthCheckSeveridade::Medio, 30)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(94, $r->score);
    }

    public function test_finding_afeta_todas_atividades_elegiveis(): void
    {
        // total=50, quantidade=50 (100%), Alto: impacto = -50*1,0 = -50
        $findings = [$this->finding('SLACK-002', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 50)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(50));

        $this->assertSame(50, $r->score);
    }

    // =====================================================================
    // CATEGORIAS / DIMENSÕES
    // =====================================================================

    public function test_score_por_dimensao_bate_com_findings_da_categoria(): void
    {
        $findings = [$this->finding('SLACK-001', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 10)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(95, $r->porDimensao[HealthCheckCategoria::Slack->value]->score);
        $this->assertSame(10, $r->porDimensao[HealthCheckCategoria::Slack->value]->quantidadeOcorrencias);
        $this->assertSame(HealthCheckSeveridade::Alto, $r->porDimensao[HealthCheckCategoria::Slack->value]->severidadeMaxima);
    }

    public function test_categoria_sem_findings_fica_em_100(): void
    {
        $findings = [$this->finding('SLACK-001', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 10)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(100, $r->porDimensao[HealthCheckCategoria::Datas->value]->score);
        $this->assertSame(0, $r->porDimensao[HealthCheckCategoria::Datas->value]->quantidadeOcorrencias);
        $this->assertNull($r->porDimensao[HealthCheckCategoria::Datas->value]->severidadeMaxima);
    }

    public function test_multiplas_categorias_com_impactos_diferentes(): void
    {
        // Exemplo C aprovado: 2.000 elegíveis, 4 findings de categorias diferentes.
        $findings = [
            $this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 3),
            $this->finding('STRUCT-003', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Alto, 40),
            $this->finding('SLACK-001', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 120),
            $this->finding('LOGIC-009', HealthCheckCategoria::Logica, HealthCheckSeveridade::Informativo, 80),
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(2000));

        // Estrutura: -100*(3/2000) + -50*(40/2000) = -0,15 - 1,0 = -1,15 => 99 (round(98,85))
        $this->assertSame(99, $r->porDimensao[HealthCheckCategoria::Estrutura->value]->score);
        // Slack: -50*(120/2000) = -3,0 => 97
        $this->assertSame(97, $r->porDimensao[HealthCheckCategoria::Slack->value]->score);
        // Lógica: Informativo nunca reduz => 100
        $this->assertSame(100, $r->porDimensao[HealthCheckCategoria::Logica->value]->score);
        // Score geral: soma = -0,15 -1,0 -3,0 + 0 = -4,15 => round(95,85) = 96
        $this->assertSame(96, $r->score);
    }

    public function test_dimensoes_derivadas_dinamicamente_do_enum_nao_hardcoded(): void
    {
        $r = (new ScoreCalculator())->calcular($this->resultado([]), $this->plano(100));

        $this->assertCount(count(HealthCheckCategoria::cases()), $r->porDimensao);

        foreach (HealthCheckCategoria::cases() as $categoria) {
            $this->assertArrayHasKey($categoria->value, $r->porDimensao);
        }
    }

    // =====================================================================
    // LIMITES
    // =====================================================================

    public function test_score_nunca_ultrapassa_100(): void
    {
        $findings = [$this->finding('LOGIC-009', HealthCheckCategoria::Logica, HealthCheckSeveridade::Informativo, 1000)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(1000));

        $this->assertLessThanOrEqual(100, $r->score);
        $this->assertSame(100, $r->score);
    }

    public function test_score_nunca_fica_abaixo_de_0(): void
    {
        // 2 findings Críticos afetando 100% cada um (soma bruta = -200) — deve travar em 0, nunca negativo.
        $findings = [
            $this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 10),
            $this->finding('STRUCT-003', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 10),
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(10));

        $this->assertGreaterThanOrEqual(0, $r->score);
        $this->assertSame(0, $r->score);
        $this->assertSame(0, $r->porDimensao[HealthCheckCategoria::Estrutura->value]->score);
    }

    // =====================================================================
    // COBERTURA
    // =====================================================================

    public function test_cobertura_100_quando_todas_ativas(): void
    {
        $r = (new ScoreCalculator())->calcular($this->resultado([]), $this->plano(elegiveis: 20));

        $this->assertSame(100, $r->cobertura);
    }

    public function test_cobertura_menor_quando_existem_inativas(): void
    {
        // 8 ativas + 2 inativas = 10 executáveis => cobertura = 8/10*100 = 80
        $r = (new ScoreCalculator())->calcular($this->resultado([]), $this->plano(elegiveis: 8, inativas: 2));

        $this->assertSame(80, $r->cobertura);
    }

    public function test_tarefas_resumo_nao_entram_no_denominador_da_cobertura(): void
    {
        // 10 ativas + 5 tarefas-resumo (defensivamente ignoradas) => cobertura continua 100
        $r = (new ScoreCalculator())->calcular($this->resultado([]), $this->plano(elegiveis: 10, resumos: 5));

        $this->assertSame(100, $r->cobertura);
    }

    public function test_zero_atividades_cobertura_null(): void
    {
        $r = (new ScoreCalculator())->calcular($this->resultado([]), $this->plano(0));

        $this->assertNull($r->cobertura);
    }

    public function test_zero_atividades_score_100(): void
    {
        $r = (new ScoreCalculator())->calcular($this->resultado([]), $this->plano(0));

        $this->assertSame(100, $r->score);
        $this->assertSame(FaixaScore::Excelente, $r->faixa);

        foreach (HealthCheckCategoria::cases() as $categoria) {
            $this->assertSame(100, $r->porDimensao[$categoria->value]->score);
        }
    }

    // =====================================================================
    // MAPA DE AÇÕES
    // =====================================================================

    public function test_mapa_de_acoes_ordena_por_severidade(): void
    {
        $findings = [
            $this->finding('WORK-001', HealthCheckCategoria::Hh, HealthCheckSeveridade::Baixo, 5),
            $this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 5),
            $this->finding('SLACK-001', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 5),
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(['STRUCT-005', 'SLACK-001', 'WORK-001'], array_map(fn ($a) => $a->regraId, $r->mapaAcoes));
    }

    public function test_mapa_de_acoes_desempata_por_impacto(): void
    {
        // Mesma severidade (Alto), quantidades diferentes => impacto diferente, o maior impacto vem primeiro.
        $findings = [
            $this->finding('SLACK-001', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 5),
            $this->finding('SLACK-002', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 40),
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(['SLACK-002', 'SLACK-001'], array_map(fn ($a) => $a->regraId, $r->mapaAcoes));
    }

    public function test_mapa_de_acoes_desempata_por_quantidade(): void
    {
        // Nota de arquitetura: com um único total_elegiveis compartilhado por
        // todos os findings de uma mesma chamada, "mesma severidade + mesmo
        // impacto" implica matematicamente "mesma quantidade" (impacto é
        // proporcional à quantidade para uma severidade/total fixos) — ou
        // seja, o critério de desempate por quantidade nunca decide de forma
        // diferente do critério por impacto dentro desta fórmula. O código
        // implementa os 4 critérios exatamente como especificado (defesa
        // barata caso a fórmula mude no futuro); este teste confirma o
        // efeito prático: mesma severidade, quantidade maior => impacto
        // maior => aparece primeiro.
        $findings = [
            $this->finding('SLACK-001', HealthCheckCategoria::Slack, HealthCheckSeveridade::Medio, 10),
            $this->finding('SLACK-002', HealthCheckCategoria::Slack, HealthCheckSeveridade::Medio, 25),
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(['SLACK-002', 'SLACK-001'], array_map(fn ($a) => $a->regraId, $r->mapaAcoes));
    }

    public function test_mapa_de_acoes_desempata_por_regra_id(): void
    {
        // Mesma severidade, mesma quantidade (logo mesmo impacto) => desempate final por regra_id ascendente.
        $findings = [
            $this->finding('STRUCT-003', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Alto, 10),
            $this->finding('STRUCT-001', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Alto, 10),
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(['STRUCT-001', 'STRUCT-003'], array_map(fn ($a) => $a->regraId, $r->mapaAcoes));
    }

    public function test_informativos_nao_entram_no_mapa_de_acoes(): void
    {
        $findings = [
            $this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 5),
            $this->finding('LOGIC-009', HealthCheckCategoria::Logica, HealthCheckSeveridade::Informativo, 50),
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertCount(1, $r->mapaAcoes);
        $this->assertSame('STRUCT-005', $r->mapaAcoes[0]->regraId);
    }

    public function test_ausencia_de_problemas_mapa_de_acoes_vazio(): void
    {
        $r = (new ScoreCalculator())->calcular($this->resultado([]), $this->plano(100));

        $this->assertSame([], $r->mapaAcoes);
    }

    public function test_potencial_recuperavel_calculado_como_100_menos_score(): void
    {
        $findings = [$this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 18)];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertSame(100 - $r->score, $r->potencialRecuperavel);
    }

    // =====================================================================
    // EXPLICABILIDADE
    // =====================================================================

    public function test_resultado_contem_dados_suficientes_para_explicar_o_score(): void
    {
        $findings = [
            $this->finding('SLACK-001', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 7, 'Revisar restrições e datas impostas.'),
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertIsInt($r->score);
        $this->assertInstanceOf(FaixaScore::class, $r->faixa);
        $this->assertIsInt($r->cobertura);
        $this->assertNotEmpty($r->porDimensao);
        $this->assertSame(ScoreCalculator::VERSAO_FORMULA, $r->versaoFormula);

        $acao = $r->mapaAcoes[0];
        $this->assertSame('SLACK-001', $acao->regraId);
        $this->assertSame(HealthCheckCategoria::Slack, $acao->categoria);
        $this->assertSame(HealthCheckSeveridade::Alto, $acao->severidade);
        $this->assertSame(7, $acao->quantidadeAtividades);
        $this->assertLessThan(0, $acao->impacto);
        $this->assertSame('Revisar restrições e datas impostas.', $acao->recomendacao);
    }

    // =====================================================================
    // FINDING INDEX (Fase 4.2) — aditivo, nunca muda nenhum número
    // =====================================================================

    public function test_finding_index_aponta_pro_indice_original_em_resultado_findings(): void
    {
        $findings = [
            $this->finding('WORK-001', HealthCheckCategoria::Hh, HealthCheckSeveridade::Baixo, 5),      // índice 0
            $this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 5), // índice 1
            $this->finding('SLACK-001', HealthCheckCategoria::Slack, HealthCheckSeveridade::Alto, 5),   // índice 2
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        // Ordenado por severidade: STRUCT-005(idx1) > SLACK-001(idx2) > WORK-001(idx0).
        $porRegra = collect($r->mapaAcoes)->keyBy('regraId');
        $this->assertSame(1, $porRegra['STRUCT-005']->findingIndex);
        $this->assertSame(2, $porRegra['SLACK-001']->findingIndex);
        $this->assertSame(0, $porRegra['WORK-001']->findingIndex);
    }

    public function test_finding_index_distingue_multiplas_ocorrencias_da_mesma_regra(): void
    {
        // O achado da Fase 4.2: 2 findings da MESMA regra com a MESMA
        // quantidade (comum em STRUCT-005 — cada ciclo conta 1 na estrutura
        // agrupada) ficam indistinguíveis por qualquer outro campo de
        // AcaoRecomendada — só findingIndex resolve isso.
        $findings = [
            $this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 1),
            $this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 1),
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        $this->assertCount(2, $r->mapaAcoes);
        $indices = array_map(fn ($a) => $a->findingIndex, $r->mapaAcoes);
        $this->assertEqualsCanonicalizing([0, 1], $indices, 'os dois itens precisam ter findingIndex diferentes, senão são indistinguíveis');
    }

    public function test_finding_index_nao_afeta_score_faixa_cobertura_potencial_ou_por_dimensao(): void
    {
        // Prova que a mudança é estritamente aditiva: rodando o MESMO
        // cenário duas vezes (uma conceitualmente "antes", outra "depois"
        // da mudança seria idêntica, já que não há toggle de comportamento)
        // — aqui a prova real é comparar contra os valores literais já
        // documentados/testados nos outros testes desta classe.
        $findings = [
            $this->finding('STRUCT-005', HealthCheckCategoria::Estrutura, HealthCheckSeveridade::Critico, 18),
        ];

        $r = (new ScoreCalculator())->calcular($this->resultado($findings), $this->plano(100));

        // Mesmos números do test_potencial_recuperavel_calculado_como_100_menos_score
        // e da fórmula documentada: pesoMaximo = -100 * 10 = -1000... na
        // verdade Critico peso bruto -10 * FATOR_ESCALA 10 = -100; impacto =
        // -100 * (18/100) = -18; score = 100-18 = 82.
        $this->assertSame(82, $r->score);
        $this->assertSame(FaixaScore::paraScore(82), $r->faixa);
        $this->assertSame(100, $r->cobertura);
        $this->assertSame(18, $r->potencialRecuperavel);
        $this->assertSame(82, $r->porDimensao[HealthCheckCategoria::Estrutura->value]->score);
        $this->assertSame(0, $r->mapaAcoes[0]->findingIndex);
    }

    public function test_finding_index_null_em_dtos_construidos_sem_o_campo(): void
    {
        // Compatibilidade retroativa: código que ainda constrói AcaoRecomendada
        // sem o 5º/8º argumento nomeado continua funcionando (default null).
        $acao = new \App\Support\HealthCheck\Score\AcaoRecomendada(
            regraId: 'STRUCT-005',
            categoria: HealthCheckCategoria::Estrutura,
            severidade: HealthCheckSeveridade::Critico,
            titulo: 'teste',
            quantidadeAtividades: 3,
            impacto: -10.0,
            recomendacao: 'teste',
        );

        $this->assertNull($acao->findingIndex);
    }

    public function test_acaorecomendada_fromarray_sem_finding_index_retorna_null_registro_antigo(): void
    {
        // Simula um registro de mapa_acoes persistido ANTES da Fase 4.2 —
        // sem a chave 'finding_index' no JSON — nunca deve quebrar nem
        // inventar um índice.
        $dadosAntigos = [
            'regra_id' => 'STRUCT-005',
            'categoria' => HealthCheckCategoria::Estrutura->value,
            'severidade' => HealthCheckSeveridade::Critico->value,
            'titulo' => 'Ciclo lógico identificado',
            'quantidade_atividades' => 3,
            'impacto' => -15.0,
            'recomendacao' => 'Revise as relações.',
        ];

        $acao = \App\Support\HealthCheck\Score\AcaoRecomendada::fromArray($dadosAntigos);

        $this->assertNull($acao->findingIndex);
    }

    public function test_acaorecomendada_toarray_fromarray_round_trip_preserva_finding_index(): void
    {
        $acao = new \App\Support\HealthCheck\Score\AcaoRecomendada(
            regraId: 'STRUCT-005',
            categoria: HealthCheckCategoria::Estrutura,
            severidade: HealthCheckSeveridade::Critico,
            titulo: 'teste',
            quantidadeAtividades: 3,
            impacto: -10.0,
            recomendacao: 'teste',
            findingIndex: 4,
        );

        $reidratada = \App\Support\HealthCheck\Score\AcaoRecomendada::fromArray($acao->toArray());

        $this->assertSame(4, $reidratada->findingIndex);
    }
}
