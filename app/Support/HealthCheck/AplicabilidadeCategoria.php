<?php

namespace App\Support\HealthCheck;

use App\Enums\EstadoAplicabilidadeCategoria;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\TipoCronogramaImportacao;

/**
 * Estado de aplicabilidade de UMA categoria de Health Check numa importação
 * específica — Ciclo 10 (Caminho 3 da revisão funcional/arquitetural,
 * "Transparência Baseline"). Camada de APRESENTAÇÃO, nunca de domínio de
 * Score: não altera ScoreCalculator/ScoreDimensao/ScoreResultado, e nunca é
 * persistida — sempre derivada em tempo de leitura a partir do tipo da
 * importação + HealthCheckEngine::catalogoRegras() (mesma fonte de verdade
 * já usada por PlanoAcaoReconciliador via naturezaDaRegra() — sem mapa
 * paralelo de natureza). Ver CLAUDE.md, "Health Check — Ciclo 10", pra a
 * decisão consciente de derivar isso ao vivo em vez de persistir.
 *
 * Categorias podem ser MISTAS — contêm regras de Planejamento E de Execução
 * ao mesmo tempo (Datas, HH, Marcos, Lógica) — por isso o estado não é
 * binário: Avaliada (todas as regras da categoria aplicáveis a este tipo),
 * Parcialmente avaliada (só algumas) ou Não avaliada (nenhuma).
 */
readonly class AplicabilidadeCategoria
{
    public function __construct(
        public HealthCheckCategoria $categoria,
        public EstadoAplicabilidadeCategoria $estado,
        public int $totalRegras,
        public int $regrasAplicaveis,
    ) {}

    public function regrasNaoAplicaveis(): int
    {
        return $this->totalRegras - $this->regrasAplicaveis;
    }

    /**
     * Calcula o estado de TODAS as categorias do enum pra um dado tipo de
     * importação — chamado só pela camada de apresentação
     * (⚡importacao-detalhe.blade.php), nunca pelo ScoreCalculator.
     *
     * O predicado "esta regra é aplicável a este tipo?" espelha
     * EXATAMENTE HealthCheckEngine::regrasFiltradas() (Ciclo 2) — mesma
     * regra de negócio, sem alterar o método original (que continua sendo
     * o único a decidir o que de fato é avaliado). A consistência entre os
     * dois é protegida por teste dedicado (ver
     * tests/Unit/AplicabilidadeCategoriaTest.php).
     *
     * @return array<string, self> chave = HealthCheckCategoria->value, mesmo padrão de indexação de ScoreResultado::$porDimensao
     */
    public static function calcularTodas(HealthCheckEngine $engine, ?TipoCronogramaImportacao $tipo): array
    {
        $catalogo = $engine->catalogoRegras();

        $totais = [];
        $aplicaveis = [];

        foreach ($catalogo as ['categoria' => $categoria, 'natureza' => $natureza]) {
            $totais[$categoria->value] = ($totais[$categoria->value] ?? 0) + 1;

            if (self::regraAplicavel($natureza, $tipo)) {
                $aplicaveis[$categoria->value] = ($aplicaveis[$categoria->value] ?? 0) + 1;
            }
        }

        $resultado = [];
        foreach (HealthCheckCategoria::cases() as $categoria) {
            $total = $totais[$categoria->value] ?? 0;
            $qtdAplicaveis = $aplicaveis[$categoria->value] ?? 0;

            $resultado[$categoria->value] = new self(
                categoria: $categoria,
                estado: match (true) {
                    $total === 0 => EstadoAplicabilidadeCategoria::NaoAvaliada,
                    $qtdAplicaveis === $total => EstadoAplicabilidadeCategoria::Avaliada,
                    $qtdAplicaveis === 0 => EstadoAplicabilidadeCategoria::NaoAvaliada,
                    default => EstadoAplicabilidadeCategoria::ParcialmenteAvaliada,
                },
                totalRegras: $total,
                regrasAplicaveis: $qtdAplicaveis,
            );
        }

        return $resultado;
    }

    /** Mesma regra de HealthCheckEngine::regrasFiltradas() — ver docblock de calcularTodas(). */
    private static function regraAplicavel(HealthCheckNaturezaRegra $natureza, ?TipoCronogramaImportacao $tipo): bool
    {
        return $tipo !== TipoCronogramaImportacao::Baseline || $natureza === HealthCheckNaturezaRegra::Planejamento;
    }
}
