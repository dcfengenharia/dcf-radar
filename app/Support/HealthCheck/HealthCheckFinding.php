<?php

namespace App\Support\HealthCheck;

use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;

/**
 * Uma ocorrência de regra de Health Check — 1 regra que combinou com N
 * atividades do PlanoImportacao (nunca 1 finding por atividade; a lista de
 * atividades afetadas fica dentro do próprio finding, pra bater com a UI
 * "[VER N ATIVIDADES]" do pedido original).
 */
readonly class HealthCheckFinding
{
    public function __construct(
        public string $regraId,
        public HealthCheckCategoria $categoria,
        public HealthCheckSeveridade $severidade,
        public string $titulo,
        public string $descricao,
        public string $impacto,
        public string $recomendacao,
        /** @var array<int, array<string, mixed>> */
        public array $atividades,
    ) {}

    public function quantidade(): int
    {
        return count($this->atividades);
    }

    public function toArray(): array
    {
        return [
            'regra_id' => $this->regraId,
            'categoria' => $this->categoria->value,
            'severidade' => $this->severidade->value,
            'titulo' => $this->titulo,
            'descricao' => $this->descricao,
            'impacto' => $this->impacto,
            'recomendacao' => $this->recomendacao,
            'atividades' => $this->atividades,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            regraId: $data['regra_id'],
            categoria: HealthCheckCategoria::from($data['categoria']),
            severidade: HealthCheckSeveridade::from($data['severidade']),
            titulo: $data['titulo'],
            descricao: $data['descricao'],
            impacto: $data['impacto'],
            recomendacao: $data['recomendacao'],
            atividades: $data['atividades'],
        );
    }
}
