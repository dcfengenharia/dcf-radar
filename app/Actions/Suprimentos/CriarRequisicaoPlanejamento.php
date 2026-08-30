<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusRequisicaoPlanejamento;
use App\Models\RequisicaoPlanejamento;

/**
 * Ciclo 19, Etapa 19.2 — cria o rascunho vazio. Nasce sem `numero`
 * (só atribuído na emissão, ver EmitirRequisicaoPlanejamento) — não
 * consome sequência.
 */
class CriarRequisicaoPlanejamento
{
    public function execute(string $obraId, ?string $observacao, ?string $usuarioId): RequisicaoPlanejamento
    {
        // `status` é setado explicitamente (não só o DEFAULT de schema) —
        // Eloquent::create() não relê o valor DEFAULT do banco de volta
        // pro model em memória após o INSERT (MySQL não suporta
        // RETURNING); sem isso, $rp->status ficaria null até um refresh().
        return RequisicaoPlanejamento::create([
            'obra_id' => $obraId,
            'status' => StatusRequisicaoPlanejamento::Rascunho->value,
            'observacao' => $observacao,
            'created_by_id' => $usuarioId,
        ]);
    }
}
