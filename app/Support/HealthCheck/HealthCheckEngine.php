<?php

namespace App\Support\HealthCheck;

use App\DTOs\PlanoImportacao;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\TipoCronogramaImportacao;
use App\Support\HealthCheck\Rules\Avanco\AtividadeAtrasadaRule;
use App\Support\HealthCheck\Rules\Avanco\ConclusaoAposTerminoPlanejadoRule;
use App\Support\HealthCheck\Rules\Avanco\DeveriaTerIniciadoRule;
use App\Support\HealthCheck\Rules\Avanco\ExecucaoAntecipadaRule;
use App\Support\HealthCheck\Rules\Baseline\AtividadesArquivadasRule;
use App\Support\HealthCheck\Rules\Baseline\BaselineIncompletaRule;
use App\Support\HealthCheck\Rules\CaminhoCritico\NenhumaAtividadeCriticaRule;
use App\Support\HealthCheck\Rules\Datas\AvancoSemInicioRealRule;
use App\Support\HealthCheck\Rules\Datas\ConcluidaSemTerminoRealRule;
use App\Support\HealthCheck\Rules\Datas\InicioRealAposDataStatusRule;
use App\Support\HealthCheck\Rules\Datas\TerminoPlanejadoAntesDoInicioRule;
use App\Support\HealthCheck\Rules\Datas\TerminoRealAntesDoInicioRealRule;
use App\Support\HealthCheck\Rules\Datas\TerminoRealAposDataStatusRule;
use App\Support\HealthCheck\Rules\Datas\ZeroPorCentoComTerminoRealRule;
use App\Support\HealthCheck\Rules\Duracao\DuracaoExcessivaRule;
use App\Support\HealthCheck\Rules\Duracao\DuracaoZeroRule;
use App\Support\HealthCheck\Rules\Hh\ConcluidaComHhZeradoRule;
use App\Support\HealthCheck\Rules\Hh\DuracaoSemHhRule;
use App\Support\HealthCheck\Rules\Hh\HhRealizadoComZeroPorCentoRule;
use App\Support\HealthCheck\Rules\Hh\HhRealizadoSemInicioRealRule;
use App\Support\HealthCheck\Rules\Hh\RealizadoMaiorQueBaselineRule;
use App\Support\HealthCheck\Rules\Hh\TendenciaMenorQueRealizadoRule;
use App\Support\HealthCheck\Rules\Estrutura\AtividadeIsoladaRule;
use App\Support\HealthCheck\Rules\Estrutura\CicloLogicoRule;
use App\Support\HealthCheck\Rules\Estrutura\RedeDesconectadaRule;
use App\Support\HealthCheck\Rules\Estrutura\SemPredecessoraRule;
use App\Support\HealthCheck\Rules\Estrutura\SemSucessoraRule;
use App\Support\HealthCheck\Rules\Logic\IncoerenciaDatasReaisFSRule;
use App\Support\HealthCheck\Rules\Logic\MultiplosVinculosMesmoParRule;
use App\Support\HealthCheck\Rules\Logic\SucessoraAtivaComPredecessoraInativaRule;
use App\Support\HealthCheck\Rules\Logic\VinculoComTarefaResumoRule;
use App\Support\HealthCheck\Rules\Marcos\MarcoAtrasadoRule;
use App\Support\HealthCheck\Rules\Slack\FreeSlackMaiorTotalSlackRule;
use App\Support\HealthCheck\Rules\Slack\FreeSlackNegativoRule;
use App\Support\HealthCheck\Rules\Slack\TotalSlackNegativoRule;
use App\Support\HealthCheck\Rules\Marcos\MarcoComVariacaoRelevanteRule;

/**
 * Orquestra as regras de Health Check sobre um PlanoImportacao já produzido
 * por App\Imports\MsProjectImporter::analisar() — nunca lê arquivo, nunca
 * escreve no banco. Ver CLAUDE.md, seção "Importação Segura — Health Check
 * (Fase 1)", pra contexto completo da arquitetura e da decisão de manter o
 * importador existente 100% intocado.
 *
 * Adicionar uma regra "por atividade" (Fase 1) nova = criar a classe em
 * Rules/{Categoria}/ e acrescentá-la em regrasPadrao() abaixo. Adicionar
 * uma regra ESTRUTURAL (Fase 2B, consome HealthCheckGrafoCronograma) =
 * criar a classe em Rules/Estrutura/ e acrescentá-la em
 * regrasEstruturaisPadrao() — nunca precisa mexer no restante do engine.
 *
 * O grafo estrutural é construído UMA ÚNICA VEZ por avaliação (dentro de
 * avaliar()) e reaproveitado por todas as regras estruturais — nenhuma
 * delas reconstrói o grafo (ver CLAUDE.md, seção "Health Check — Fase
 * 2B.1").
 */
class HealthCheckEngine
{
    /** @var HealthCheckRuleInterface[] */
    private array $regras;

    /** @var HealthCheckRegraEstruturalInterface[] */
    private array $regrasEstruturais;

    /**
     * @param HealthCheckRuleInterface[]|null $regras Override só para testes — produção sempre usa regrasPadrao().
     * @param HealthCheckRegraEstruturalInterface[]|null $regrasEstruturais Override só para testes — produção sempre usa regrasEstruturaisPadrao().
     */
    public function __construct(?array $regras = null, ?array $regrasEstruturais = null)
    {
        $this->regras = $regras ?? $this->regrasPadrao();
        $this->regrasEstruturais = $regrasEstruturais ?? $this->regrasEstruturaisPadrao();
    }

    /**
     * $tipo controla QUAIS regras são avaliadas — o filtro acontece ANTES do
     * loop, então uma regra Execucao numa importação Baseline nunca chega a
     * rodar (nunca gera finding, nunca entra no Score, nunca é persistida).
     * `null` preserva o comportamento legado (todas as 36 regras) — mesmo
     * comportamento de Avanco/Ambos. Ver App\Enums\HealthCheckNaturezaRegra.
     */
    public function avaliar(PlanoImportacao $plano, ?TipoCronogramaImportacao $tipo = null): HealthCheckResultado
    {
        $findings = [];

        foreach ($this->regrasFiltradas($this->regras, $tipo) as $regra) {
            $finding = $regra->avaliar($plano);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        $grafo = HealthCheckGrafoCronograma::construir($plano);

        foreach ($this->regrasFiltradas($this->regrasEstruturais, $tipo) as $regraEstrutural) {
            foreach ($regraEstrutural->avaliar($plano, $grafo) as $finding) {
                $findings[] = $finding;
            }
        }

        return new HealthCheckResultado($findings);
    }

    /**
     * @template T
     * @param T[] $regras
     * @return T[]
     */
    private function regrasFiltradas(array $regras, ?TipoCronogramaImportacao $tipo): array
    {
        if ($tipo !== TipoCronogramaImportacao::Baseline) {
            return $regras;
        }

        return array_values(array_filter(
            $regras,
            fn ($regra) => $regra->natureza() === HealthCheckNaturezaRegra::Planejamento
        ));
    }

    /**
     * Consulta o catálogo de regras já instanciado (nunca um mapa paralelo)
     * — usado por App\Support\HealthCheck\PlanoAcao\PlanoAcaoReconciliador
     * (Ciclo 3) para saber se a regra de origem de um Plano de Ação é
     * Planejamento ou Execução, sem duplicar a classificação em lugar
     * nenhum. `null` quando nenhuma regra registrada tem esse id.
     */
    public function naturezaDaRegra(string $regraId): ?HealthCheckNaturezaRegra
    {
        foreach ([...$this->regras, ...$this->regrasEstruturais] as $regra) {
            if ($regra->id() === $regraId) {
                return $regra->natureza();
            }
        }

        return null;
    }

    /**
     * Catálogo completo (categoria + natureza) de todas as regras
     * registradas, indexado por regra_id — usado pela camada de
     * apresentação (Ciclo 10, App\Support\HealthCheck\AplicabilidadeCategoria)
     * pra calcular, sem recalcular nenhuma regra, quantas regras de cada
     * categoria eram aplicáveis a um dado tipo de importação. Nunca usado
     * por avaliar()/regrasFiltradas() — método de introspecção pura, mesmo
     * espírito de naturezaDaRegra() (Ciclo 3).
     *
     * As regras "por atividade" (HealthCheckRuleInterface) já expõem
     * categoria() no próprio contrato — lida polimorficamente abaixo. As
     * regras estruturais (HealthCheckRegraEstruturalInterface, Fase 2B) NÃO
     * têm categoria() no contrato — cada uma constrói seu HealthCheckFinding
     * com a categoria hardcoded dentro do próprio avaliar() (ver
     * RegraHealthCheckEstruturalPorAtividadeBase/CicloLogicoRule/
     * RedeDesconectadaRule/MultiplosVinculosMesmoParRule/
     * IncoerenciaDatasReaisFSRule/VinculoComTarefaResumoRule/
     * SucessoraAtivaComPredecessoraInativaRule/RegraHealthCheckSlackPorAtividadeBase).
     * Adicionar categoria() a essas 12 classes está fora do escopo
     * autorizado do Ciclo 10 ("não alterar nenhuma das 36 regras"), então a
     * categoria delas é espelhada aqui, por regra_id, exatamente igual ao
     * valor hardcoded em cada avaliar() — coberto por
     * tests/Unit/AplicabilidadeCategoriaTest.php, que roda avaliar() de
     * verdade contra fixtures reais e confirma que este mapa nunca diverge.
     *
     * @return array<string, array{categoria: HealthCheckCategoria, natureza: HealthCheckNaturezaRegra}>
     */
    public function catalogoRegras(): array
    {
        $catalogo = [];

        foreach ($this->regras as $regra) {
            $catalogo[$regra->id()] = [
                'categoria' => $regra->categoria(),
                'natureza' => $regra->natureza(),
            ];
        }

        foreach ($this->regrasEstruturais as $regra) {
            $catalogo[$regra->id()] = [
                'categoria' => self::CATEGORIA_REGRAS_ESTRUTURAIS[$regra->id()],
                'natureza' => $regra->natureza(),
            ];
        }

        return $catalogo;
    }

    /** @var array<string, HealthCheckCategoria> ver docblock de catalogoRegras() */
    private const CATEGORIA_REGRAS_ESTRUTURAIS = [
        'STRUCT-001' => HealthCheckCategoria::Estrutura,
        'STRUCT-002' => HealthCheckCategoria::Estrutura,
        'STRUCT-003' => HealthCheckCategoria::Estrutura,
        'STRUCT-004' => HealthCheckCategoria::Estrutura,
        'STRUCT-005' => HealthCheckCategoria::Estrutura,
        'LOGIC-005' => HealthCheckCategoria::Logica,
        'LOGIC-008' => HealthCheckCategoria::Logica,
        'LOGIC-009' => HealthCheckCategoria::Logica,
        'LOGIC-010' => HealthCheckCategoria::Logica,
        'SLACK-001' => HealthCheckCategoria::Slack,
        'SLACK-002' => HealthCheckCategoria::Slack,
        'SLACK-005' => HealthCheckCategoria::Slack,
    ];

    /** @return HealthCheckRuleInterface[] */
    private function regrasPadrao(): array
    {
        return [
            // Datas
            new InicioRealAposDataStatusRule(),
            new TerminoRealAposDataStatusRule(),
            new ConcluidaSemTerminoRealRule(),
            new AvancoSemInicioRealRule(),
            new TerminoPlanejadoAntesDoInicioRule(),
            new TerminoRealAntesDoInicioRealRule(),
            new ZeroPorCentoComTerminoRealRule(),
            // Avanço Físico
            new AtividadeAtrasadaRule(),
            new DeveriaTerIniciadoRule(),
            new ConclusaoAposTerminoPlanejadoRule(),
            new ExecucaoAntecipadaRule(),
            // HH
            new TendenciaMenorQueRealizadoRule(),
            new RealizadoMaiorQueBaselineRule(),
            new ConcluidaComHhZeradoRule(),
            new HhRealizadoSemInicioRealRule(),
            new HhRealizadoComZeroPorCentoRule(),
            new DuracaoSemHhRule(),
            // Duração
            new DuracaoZeroRule(),
            new DuracaoExcessivaRule(),
            // Caminho Crítico
            new NenhumaAtividadeCriticaRule(),
            // Marcos
            new MarcoAtrasadoRule(),
            new MarcoComVariacaoRelevanteRule(),
            // Baseline
            new AtividadesArquivadasRule(),
            new BaselineIncompletaRule(),
        ];
    }

    /** @return HealthCheckRegraEstruturalInterface[] */
    private function regrasEstruturaisPadrao(): array
    {
        return [
            // Estrutura (Fase 2B.1)
            new SemPredecessoraRule(),
            new SemSucessoraRule(),
            new AtividadeIsoladaRule(),
            new RedeDesconectadaRule(),
            new CicloLogicoRule(),
            // Lógica (Fase 2B.2B)
            new MultiplosVinculosMesmoParRule(),
            new IncoerenciaDatasReaisFSRule(),
            new VinculoComTarefaResumoRule(),
            new SucessoraAtivaComPredecessoraInativaRule(),
            // Folgas (Fase 2B.3)
            new TotalSlackNegativoRule(),
            new FreeSlackNegativoRule(),
            new FreeSlackMaiorTotalSlackRule(),
        ];
    }
}
