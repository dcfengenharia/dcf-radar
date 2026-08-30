<?php

namespace App\Support\TakeOff;

use App\Enums\TipoItemTakeOff;
use App\Models\ItemTakeOff;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ciclo 19, Etapa 19.1.CORREÇÃO — visão consolidada de Take Off de uma
 * obra, atravessando `item -> lista -> revisão`.
 *
 * Só considera itens cuja LISTA pertence a uma revisão VIGENTE
 * (`DocumentoEngenhariaRevisao::scopeVigentes()`, a MESMA fonte única
 * de vigência usada em todo o projeto) — item de lista de revisão
 * superada nunca entra na consolidação, porque uma revisão nova nasce
 * independente e não herda nenhuma lista da anterior (D1): o
 * quantitativo "atual" do projeto é sempre o das listas vigentes de
 * cada documento, nunca uma soma histórica.
 *
 * Não calcula saldo requisitado/RP — isso é 19.2, agregado sobre
 * RPItem, nunca armazenado ou pré-calculado aqui.
 */
class TakeOffConsolidado
{
    public static function itensVigentes(string $obraId, ?TipoItemTakeOff $tipo = null, ?string $listaId = null): Collection
    {
        return ItemTakeOff::query()
            ->whereHas('lista', function ($query) use ($obraId, $tipo, $listaId) {
                $query->whereHas(
                    'revisao',
                    fn ($q) => $q->vigentes()->whereHas('documento', fn ($qq) => $qq->where('obra_id', $obraId))
                );

                if ($tipo) {
                    $query->where('tipo', $tipo->value);
                }

                if ($listaId) {
                    $query->where('id', $listaId);
                }
            })
            ->with(['lista.revisao.documento', 'lista.disciplina', 'unidadeMedida', 'familiaMaterial', 'disciplina'])
            ->get();
    }

    /**
     * Listas (com revisão vigente) elegíveis pro filtro por lista da
     * UI consolidada — não itens, só o cabeçalho de cada lista.
     */
    public static function listasVigentes(string $obraId): Collection
    {
        return \App\Models\ListaEngenharia::query()
            ->whereHas(
                'revisao',
                fn ($q) => $q->vigentes()->whereHas('documento', fn ($qq) => $qq->where('obra_id', $obraId))
            )
            ->with('revisao.documento')
            ->orderBy('codigo')
            ->get();
    }
}
