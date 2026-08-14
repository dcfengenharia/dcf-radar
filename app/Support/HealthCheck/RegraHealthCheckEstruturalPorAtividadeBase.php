<?php

namespace App\Support\HealthCheck;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Support\TextoCustomizado;

/**
 * Base para as regras estruturais que avaliam cada nó do grafo
 * individualmente e agregam as que combinarem num único HealthCheckFinding
 * — cobre STRUCT-001 (sem predecessora), STRUCT-002 (sem sucessora) e
 * STRUCT-003 (isolada). STRUCT-004/005 (componente/ciclo) implementam
 * HealthCheckRegraEstruturalInterface diretamente, pois operam sobre
 * grupos de atividades, não atividade a atividade.
 *
 * Guarda de ruído (decisão tomada na Fase 2B.1, documentada em CLAUDE.md):
 * quando o grafo inteiro não tem NENHUMA relação de precedência capturada
 * (`$grafo->semNenhumaRelacaoCapturada()`), estas 3 regras não avaliam
 * nada — um cronograma inteiro sem nenhum <PredecessorLink> é sinal de que
 * o arquivo de origem provavelmente não popula esse campo do MSPDI, não
 * que cada atividade individualmente está isolada; sem essa guarda, TODA
 * atividade de um arquivo assim viraria um finding STRUCT-003, inundando
 * a análise de ruído. Quando o grafo tem QUALQUER relação capturada, a
 * guarda não se aplica e fontes/sumidouros genuínos são reportados
 * normalmente.
 */
abstract class RegraHealthCheckEstruturalPorAtividadeBase implements HealthCheckRegraEstruturalInterface
{
    abstract public function id(): string;

    abstract public function severidade(): HealthCheckSeveridade;

    abstract public function titulo(): string;

    abstract public function descricao(int $quantidade): string;

    abstract public function impacto(): string;

    abstract public function recomendacao(): string;

    /** Predicado — true quando este nó do grafo é uma ocorrência da regra. */
    abstract protected function combina(TarefaImportada $tarefa, HealthCheckGrafoCronograma $grafo): bool;

    public function avaliar(PlanoImportacao $plano, HealthCheckGrafoCronograma $grafo): array
    {
        if ($grafo->semNenhumaRelacaoCapturada()) {
            return [];
        }

        $afetadas = [];

        foreach ($grafo->tarefas() as $tarefa) {
            if ($this->combina($tarefa, $grafo)) {
                $afetadas[] = $this->serializarTarefa($tarefa);
            }
        }

        if (empty($afetadas)) {
            return [];
        }

        return [new HealthCheckFinding(
            regraId: $this->id(),
            categoria: HealthCheckCategoria::Estrutura,
            severidade: $this->severidade(),
            titulo: $this->titulo(),
            descricao: $this->descricao(count($afetadas)),
            impacto: $this->impacto(),
            recomendacao: $this->recomendacao(),
            atividades: $afetadas,
        )];
    }

    protected function serializarTarefa(TarefaImportada $tarefa): array
    {
        return [
            'uid' => $tarefa->uid,
            'codigo' => $tarefa->codigo,
            'nome' => $tarefa->nome,
            'tipo' => $tarefa->isMarco ? 'Marco' : 'Normal',
            'disciplina' => TextoCustomizado::valor($tarefa->textos, 21) ?: null,
        ];
    }
}
