<?php

namespace App\DTOs\Gestao\Cockpit;

use App\Enums\EstadoCoberturaMaterial;
use Carbon\CarbonInterface;

/**
 * Ciclo 21, Etapa 21.5 — 1 linha da Matriz de Prontidão Futura (Seção 9),
 * drill-down "por que essa atividade não está pronta?". Composta SEM
 * nenhuma regra nova: `statusOperacional`/`pacote`/`frente`/
 * `restricoesDocumentos` vêm de `App\Support\CentralProntidao\
 * CentralProntidaoQuery` (fonte canônica de prontidão OPERACIONAL, já
 * existente desde o Ciclo 15); `prontidaoMaterial`/`materiaisCriticos`
 * vêm de `App\Support\Gestao\CoberturaMaterialAtividadeQuery` (fonte
 * canônica de cobertura MATERIAL, Ciclo 21.1) — o Cockpit só faz o JOIN
 * em memória por `atividade_id`, nunca recalcula nenhuma das duas.
 */
final class CockpitAtividadeLinha
{
    /**
     * @param  array<int, array{material_id: string, codigo: string, estado: string, faltante: ?float, item_suprimento_id: string}>  $materiaisCriticos
     * @param  string[]  $restricoesDocumentos
     */
    public function __construct(
        public readonly string $atividadeId,
        public readonly string $codigo,
        public readonly string $descricao,
        public readonly ?string $frente,
        public readonly ?string $pacote,
        public readonly ?CarbonInterface $inicioPlanejado,
        public readonly ?int $diasParaInicio,
        public readonly ?string $statusOperacional,
        public readonly EstadoCoberturaMaterial $prontidaoMaterial,
        public readonly array $materiaisCriticos,
        public readonly array $restricoesDocumentos,
    ) {
    }

    public function toArray(): array
    {
        return [
            'atividade_id' => $this->atividadeId,
            'codigo' => $this->codigo,
            'descricao' => $this->descricao,
            'frente' => $this->frente,
            'pacote' => $this->pacote,
            'inicio_planejado' => $this->inicioPlanejado?->toDateString(),
            'dias_para_inicio' => $this->diasParaInicio,
            'status_operacional' => $this->statusOperacional,
            'prontidao_material' => $this->prontidaoMaterial->value,
            'prontidao_material_label' => $this->prontidaoMaterial->label(),
            'materiais_criticos' => $this->materiaisCriticos,
            'restricoes_documentos' => $this->restricoesDocumentos,
        ];
    }
}
