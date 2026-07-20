<?php

namespace App\Support;

use App\Enums\StatusRestricao;
use App\Models\AtividadeItemProntidao;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use Illuminate\Support\Collection;

class ConclusaoAutomaticaAtividades
{
    /**
     * Atividade com "% trabalho concluído" = 100% não faz sentido continuar
     * com restrições abertas ou itens de prontidão pendentes — resolve as
     * restrições e marca os itens de prontidão automaticamente.
     *
     * @param  iterable<string>  $atividadeIds
     * @param  string  $obraId
     * @param  string|null  $userId  usuário a registrar como autor da ação/conclusão — null em execuções de sistema (backfill)
     */
    public static function aplicar(iterable $atividadeIds, string $obraId, ?string $userId): void
    {
        $ids = collect($atividadeIds)->values();

        if ($ids->isEmpty()) {
            return;
        }

        $agora = now();

        self::resolverRestricoesAbertas($ids, $userId, $agora);
        self::concluirItensDeProntidao($ids, $obraId, $userId, $agora);
    }

    private static function resolverRestricoesAbertas(Collection $atividadeIds, ?string $userId, $agora): void
    {
        $restricoesAbertas = Restricao::whereIn('atividade_id', $atividadeIds)
            ->whereIn('status', ['aberta', 'em_tratamento', 'aguardando_terceiros'])
            ->get();

        if ($restricoesAbertas->isEmpty()) {
            return;
        }

        if ($userId) {
            foreach ($restricoesAbertas as $r) {
                $r->acoes()->create([
                    'autor_id' => $userId,
                    'descricao' => 'Restrição concluída automaticamente: atividade atingiu 100% no cronograma.',
                ]);
            }
        }

        Restricao::whereIn('id', $restricoesAbertas->pluck('id'))->update([
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => $agora,
        ]);
    }

    private static function concluirItensDeProntidao(Collection $atividadeIds, string $obraId, ?string $userId, $agora): void
    {
        $itensObra = ItemProntidao::where('obra_id', $obraId)->pluck('id');

        if ($itensObra->isEmpty()) {
            return;
        }

        foreach ($atividadeIds as $atividadeId) {
            foreach ($itensObra as $itemId) {
                AtividadeItemProntidao::updateOrCreate(
                    ['atividade_id' => $atividadeId, 'item_prontidao_id' => $itemId],
                    ['concluido' => true, 'concluido_por' => $userId, 'concluido_em' => $agora]
                );
            }
        }
    }
}
