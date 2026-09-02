<?php

namespace App\Support\Estoque;

use App\Models\MovimentacaoEstoque;
use Illuminate\Support\Collection;

/**
 * Ciclo 21, Etapa 21.2 — "material parado" (Seção 4/16 do pedido):
 * `dias_sem_movimentacao` a partir da última `MovimentacaoEstoque.
 * ocorrido_em` (o fato físico mais recente — Entrada, Saída ou
 * Transferência, qualquer um conta como "movimentação", nunca só
 * Entrada) de cada par (Material, Local), obra-escopada, batch.
 *
 * **Nunca classifica excesso** (Seção 4/16 — decisão explícita, não um
 * gap de implementação): "parado" é só o FATO de tempo sem movimento;
 * "excesso" exigiria comparar o saldo físico contra necessidade FUTURA
 * confiável — o mesmo vínculo Atividade↔Material já confirmado
 * determinístico na 21.1, mas cruzá-lo aqui é uma extensão de escopo
 * maior que "última movimentação", fora do recorte desta entrega (ver
 * gap documentado no CLAUDE.md). Um Material parado há 90 dias pode ser
 * exatamente o que uma Atividade daqui a 2 semanas vai precisar — nunca
 * inferir "excesso" só por estar parado.
 */
class MaterialParadoQuery
{
    /**
     * @param  array<int, string>  $materialIds  só materiais com QUALQUER
     *   saldo físico > 0 fazem sentido aqui (material zerado não está
     *   "parado", só não tem estoque) — o chamador decide quais materiais
     *   passar (mesmo padrão de todo o domínio: nunca descobre sozinha).
     * @return Collection<string, array> chave = "{materialId}|{localId}"
     */
    public static function porMateriaisNaObra(array $materialIds, string $obraId): Collection
    {
        if (empty($materialIds)) {
            return collect();
        }

        return MovimentacaoEstoque::query()
            ->whereIn('material_id', $materialIds)
            ->where('obra_id', $obraId)
            ->groupBy('material_id', 'local_estoque_id')
            ->selectRaw('material_id, local_estoque_id, MAX(ocorrido_em) as ultima_movimentacao')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->material_id}|{$row->local_estoque_id}" => [
                'material_id' => $row->material_id,
                'local_estoque_id' => $row->local_estoque_id,
                'ultima_movimentacao' => $row->ultima_movimentacao,
                'dias_sem_movimentacao' => \Carbon\Carbon::parse($row->ultima_movimentacao)->diffInDays(\Carbon\Carbon::today()),
            ]]);
    }
}
