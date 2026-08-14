<?php

namespace App\Support\HealthCheck;

use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;

/**
 * Resultado agregado de uma rodada do HealthCheckEngine sobre um
 * PlanoImportacao. Serializável (toArray/fromArray) porque precisa
 * atravessar a fronteira Livewire → Job (ver CLAUDE.md, seção Health Check):
 * o resultado é calculado uma única vez em analisar() e o mesmo array
 * serializado é reaproveitado até a persistência, nunca recalculado.
 */
readonly class HealthCheckResultado
{
    /** @param HealthCheckFinding[] $findings */
    public function __construct(public array $findings) {}

    public function temAlertas(): bool
    {
        return count($this->findings) > 0;
    }

    /** Número de regras que encontraram problema (não confundir com nº de atividades afetadas). */
    public function totalRegras(): int
    {
        return count($this->findings);
    }

    /** Soma de atividades afetadas em todos os findings — uma mesma atividade pode ser contada por mais de uma regra. */
    public function totalOcorrencias(): int
    {
        return array_sum(array_map(fn (HealthCheckFinding $f) => $f->quantidade(), $this->findings));
    }

    /** @return array<string, int> chave = HealthCheckSeveridade->value */
    public function totalPorSeveridade(): array
    {
        $totais = array_fill_keys(array_map(fn ($s) => $s->value, HealthCheckSeveridade::cases()), 0);

        foreach ($this->findings as $finding) {
            $totais[$finding->severidade->value] += $finding->quantidade();
        }

        return $totais;
    }

    /** @return array<string, int> chave = HealthCheckCategoria->value */
    public function totalPorCategoria(): array
    {
        $totais = array_fill_keys(array_map(fn ($c) => $c->value, HealthCheckCategoria::cases()), 0);

        foreach ($this->findings as $finding) {
            $totais[$finding->categoria->value] += $finding->quantidade();
        }

        return array_filter($totais, fn ($qtd) => $qtd > 0);
    }

    /** @return HealthCheckFinding[] findings da categoria informada, mais severos primeiro */
    public function findingsPorCategoria(HealthCheckCategoria $categoria): array
    {
        $ordem = array_flip(array_map(fn ($s) => $s->value, HealthCheckSeveridade::cases()));

        $filtrados = array_values(array_filter(
            $this->findings,
            fn (HealthCheckFinding $f) => $f->categoria === $categoria
        ));

        usort($filtrados, fn ($a, $b) => $ordem[$a->severidade->value] <=> $ordem[$b->severidade->value]);

        return $filtrados;
    }

    /**
     * Score de saúde — DELIBERADAMENTE não calculado na Fase 1 (decisão do
     * usuário: evitar um número aparentemente preciso baseado só na soma de
     * pesos por severidade). A estrutura já existe (HealthCheckSeveridade::peso())
     * pra quando o algoritmo definitivo for aprovado; até lá, retorna null e a
     * UI mostra "disponível em uma etapa futura" em vez de um número.
     */
    public function scorePreliminar(): ?int
    {
        return null;
    }

    public function toArray(): array
    {
        return [
            'findings' => array_map(fn (HealthCheckFinding $f) => $f->toArray(), $this->findings),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            findings: array_map(fn (array $f) => HealthCheckFinding::fromArray($f), $data['findings'] ?? []),
        );
    }
}
