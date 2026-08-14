<?php

namespace Tests\Unit;

use App\Enums\EstadoAplicabilidadeCategoria;
use App\Enums\HealthCheckCategoria;
use App\Enums\TipoCronogramaImportacao;
use App\Support\HealthCheck\AplicabilidadeCategoria;
use App\Support\HealthCheck\HealthCheckEngine;
use PHPUnit\Framework\TestCase;

/**
 * Ciclo 10 ("Transparência Baseline") — testa AplicabilidadeCategoria contra
 * o catálogo REAL de produção (new HealthCheckEngine(), as 36 regras), não
 * um catálogo sintético — porque o próprio objetivo do teste é confirmar a
 * matriz real de composição por categoria (algumas mistas, algumas puras)
 * descrita na revisão funcional/arquitetural e no plano do Ciclo 10.
 *
 * Nenhuma regra é avaliada aqui (nenhum finding é gerado) — AplicabilidadeCategoria
 * é 100% derivada do catálogo (categoria+natureza por regra_id) e do tipo,
 * nunca de findings reais.
 */
class AplicabilidadeCategoriaTest extends TestCase
{
    private function calcular(?TipoCronogramaImportacao $tipo): array
    {
        return AplicabilidadeCategoria::calcularTodas(new HealthCheckEngine(), $tipo);
    }

    // -------------------------------------------------------------------
    // Matriz real Baseline — confirmada por leitura de código na Etapa A
    // do Ciclo 10 (revisão funcional/arquitetural, "Caminho 3").
    // -------------------------------------------------------------------

    public function test_baseline_categoria_pura_planejamento_fica_avaliada(): void
    {
        $resultado = $this->calcular(TipoCronogramaImportacao::Baseline);

        // Duração: 2 regras, ambas Planejamento (DUR-001/002).
        $duracao = $resultado[HealthCheckCategoria::Duracao->value];
        $this->assertSame(EstadoAplicabilidadeCategoria::Avaliada, $duracao->estado);
        $this->assertSame(2, $duracao->totalRegras);
        $this->assertSame(2, $duracao->regrasAplicaveis);
        $this->assertSame(0, $duracao->regrasNaoAplicaveis());

        // Estrutura: 5 regras estruturais, todas Planejamento (STRUCT-001..005).
        $estrutura = $resultado[HealthCheckCategoria::Estrutura->value];
        $this->assertSame(EstadoAplicabilidadeCategoria::Avaliada, $estrutura->estado);
        $this->assertSame(5, $estrutura->totalRegras);
        $this->assertSame(5, $estrutura->regrasAplicaveis);

        // Folgas: 3 regras SLACK, todas Planejamento.
        $slack = $resultado[HealthCheckCategoria::Slack->value];
        $this->assertSame(EstadoAplicabilidadeCategoria::Avaliada, $slack->estado);
        $this->assertSame(3, $slack->totalRegras);
        $this->assertSame(3, $slack->regrasAplicaveis);

        // Baseline (categoria): 2 regras, ambas Planejamento (BASE-002/003).
        $baseline = $resultado[HealthCheckCategoria::Baseline->value];
        $this->assertSame(EstadoAplicabilidadeCategoria::Avaliada, $baseline->estado);
        $this->assertSame(2, $baseline->totalRegras);
        $this->assertSame(2, $baseline->regrasAplicaveis);

        // Caminho Crítico: 1 regra, Planejamento (CRIT-001).
        $critico = $resultado[HealthCheckCategoria::CaminhoCritico->value];
        $this->assertSame(EstadoAplicabilidadeCategoria::Avaliada, $critico->estado);
        $this->assertSame(1, $critico->totalRegras);
        $this->assertSame(1, $critico->regrasAplicaveis);
    }

    public function test_baseline_categoria_pura_execucao_fica_nao_avaliada(): void
    {
        // Avanço Físico: 4 regras (PROG-001..004), todas Execução.
        $avanco = $this->calcular(TipoCronogramaImportacao::Baseline)[HealthCheckCategoria::Avanco->value];

        $this->assertSame(EstadoAplicabilidadeCategoria::NaoAvaliada, $avanco->estado);
        $this->assertSame(4, $avanco->totalRegras);
        $this->assertSame(0, $avanco->regrasAplicaveis);
        $this->assertSame(4, $avanco->regrasNaoAplicaveis());
    }

    public function test_baseline_categoria_mista_fica_parcialmente_avaliada(): void
    {
        $resultado = $this->calcular(TipoCronogramaImportacao::Baseline);

        // Datas: 7 regras, só DATE-005 é Planejamento.
        $datas = $resultado[HealthCheckCategoria::Datas->value];
        $this->assertSame(EstadoAplicabilidadeCategoria::ParcialmenteAvaliada, $datas->estado);
        $this->assertSame(7, $datas->totalRegras);
        $this->assertSame(1, $datas->regrasAplicaveis);
        $this->assertSame(6, $datas->regrasNaoAplicaveis());

        // HH: 6 regras, só WORK-006 é Planejamento.
        $hh = $resultado[HealthCheckCategoria::Hh->value];
        $this->assertSame(EstadoAplicabilidadeCategoria::ParcialmenteAvaliada, $hh->estado);
        $this->assertSame(6, $hh->totalRegras);
        $this->assertSame(1, $hh->regrasAplicaveis);

        // Marcos: 2 regras, só MILE-002 é Planejamento (MILE-001 é Execução).
        $marcos = $resultado[HealthCheckCategoria::Marcos->value];
        $this->assertSame(EstadoAplicabilidadeCategoria::ParcialmenteAvaliada, $marcos->estado);
        $this->assertSame(2, $marcos->totalRegras);
        $this->assertSame(1, $marcos->regrasAplicaveis);

        // Lógica: 4 regras, LOGIC-005/009/010 são Planejamento, LOGIC-008 é Execução.
        $logica = $resultado[HealthCheckCategoria::Logica->value];
        $this->assertSame(EstadoAplicabilidadeCategoria::ParcialmenteAvaliada, $logica->estado);
        $this->assertSame(4, $logica->totalRegras);
        $this->assertSame(3, $logica->regrasAplicaveis);
    }

    /** Exemplo obrigatório do plano do Ciclo 10 — confere a matriz inteira de uma vez. */
    public function test_baseline_matriz_completa_bate_com_o_exemplo_do_plano(): void
    {
        $resultado = $this->calcular(TipoCronogramaImportacao::Baseline);

        $esperado = [
            HealthCheckCategoria::Datas->value => EstadoAplicabilidadeCategoria::ParcialmenteAvaliada,
            HealthCheckCategoria::Avanco->value => EstadoAplicabilidadeCategoria::NaoAvaliada,
            HealthCheckCategoria::Hh->value => EstadoAplicabilidadeCategoria::ParcialmenteAvaliada,
            HealthCheckCategoria::Duracao->value => EstadoAplicabilidadeCategoria::Avaliada,
            HealthCheckCategoria::CaminhoCritico->value => EstadoAplicabilidadeCategoria::Avaliada,
            HealthCheckCategoria::Marcos->value => EstadoAplicabilidadeCategoria::ParcialmenteAvaliada,
            HealthCheckCategoria::Baseline->value => EstadoAplicabilidadeCategoria::Avaliada,
            HealthCheckCategoria::Estrutura->value => EstadoAplicabilidadeCategoria::Avaliada,
            HealthCheckCategoria::Logica->value => EstadoAplicabilidadeCategoria::ParcialmenteAvaliada,
            HealthCheckCategoria::Slack->value => EstadoAplicabilidadeCategoria::Avaliada,
        ];

        foreach ($esperado as $categoriaValue => $estadoEsperado) {
            $this->assertSame(
                $estadoEsperado,
                $resultado[$categoriaValue]->estado,
                "Categoria '{$categoriaValue}' deveria estar '{$estadoEsperado->value}' sob Baseline."
            );
        }
    }

    // -------------------------------------------------------------------
    // Avanço / Ambos / legado (null) — todas as categorias Avaliada.
    // -------------------------------------------------------------------

    public function test_avanco_todas_as_categorias_ficam_avaliadas(): void
    {
        $resultado = $this->calcular(TipoCronogramaImportacao::Avanco);

        foreach (HealthCheckCategoria::cases() as $categoria) {
            $dimensao = $resultado[$categoria->value];
            $this->assertSame(EstadoAplicabilidadeCategoria::Avaliada, $dimensao->estado, $categoria->value);
            $this->assertSame($dimensao->totalRegras, $dimensao->regrasAplicaveis, $categoria->value);
        }
    }

    public function test_ambos_todas_as_categorias_ficam_avaliadas(): void
    {
        $resultado = $this->calcular(TipoCronogramaImportacao::Ambos);

        foreach (HealthCheckCategoria::cases() as $categoria) {
            $this->assertSame(EstadoAplicabilidadeCategoria::Avaliada, $resultado[$categoria->value]->estado, $categoria->value);
        }
    }

    public function test_tipo_null_legado_todas_as_categorias_ficam_avaliadas(): void
    {
        $resultado = $this->calcular(null);

        foreach (HealthCheckCategoria::cases() as $categoria) {
            $this->assertSame(EstadoAplicabilidadeCategoria::Avaliada, $resultado[$categoria->value]->estado, $categoria->value);
        }
    }

    // -------------------------------------------------------------------
    // Totais e consistência estrutural
    // -------------------------------------------------------------------

    public function test_soma_de_totalregras_de_todas_as_categorias_bate_com_as_36_regras(): void
    {
        $resultado = $this->calcular(TipoCronogramaImportacao::Baseline);

        $soma = array_sum(array_map(fn (AplicabilidadeCategoria $d) => $d->totalRegras, $resultado));

        $this->assertSame(36, $soma);
    }

    public function test_retorna_uma_entrada_para_cada_categoria_do_enum(): void
    {
        $resultado = $this->calcular(TipoCronogramaImportacao::Baseline);

        $this->assertEqualsCanonicalizing(
            array_map(fn (HealthCheckCategoria $c) => $c->value, HealthCheckCategoria::cases()),
            array_keys($resultado)
        );
    }

    public function test_catalogo_regras_do_engine_tem_as_36_entradas(): void
    {
        $catalogo = (new HealthCheckEngine())->catalogoRegras();

        $this->assertCount(36, $catalogo);
    }
}
