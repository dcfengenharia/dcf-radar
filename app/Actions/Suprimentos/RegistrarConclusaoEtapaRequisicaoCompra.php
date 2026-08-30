<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusRequisicaoCompra;
use App\Exceptions\EtapaRequisicaoCompraJaConcluidaException;
use App\Exceptions\RequisicaoCompraImutavelException;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraEtapa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ciclo 19, Etapa 19.4 — único ponto de escrita de `data_realizada`
 * numa etapa de RC. Só RC `Emitida` tem etapas acionáveis (Concluída já
 * terminou; Rascunho ainda não tem `RequisicaoCompraEtapa` nenhuma).
 *
 * **19.4.CORREÇÃO, item 12 — etapa concluída é IMUTÁVEL**: uma etapa
 * com `data_realizada` já preenchida nunca pode ser reconcluída —
 * lança `EtapaRequisicaoCompraJaConcluidaException` antes de qualquer
 * escrita, sem sobrescrever `data_realizada`/`realizada_por` em
 * silêncio. Reabertura é feature futura, não implementada aqui
 * (instrução explícita do pedido de correção).
 *
 * **Ordem das etapas NÃO é dependência obrigatória de execução
 * (decisão consciente, item 13 do pedido de correção)** — concluir a
 * etapa 2 antes da 1 continua permitido de propósito: `ordem` define só
 * planejamento/apresentação do fluxo, nunca uma trava de sequência.
 *
 * **Status da RC é DERIVADO da progressão real, nunca um botão
 * arbitrário**: assim que a ÚLTIMA etapa PENDENTE recebe
 * `data_realizada` (fazendo TODAS ficarem concluídas, em qualquer
 * ordem), esta mesma Action transiciona a RC pra `Concluida` +
 * `concluida_em` — dentro da MESMA transação, travando a RC pra evitar
 * duas conclusões concorrentes transicionando duas vezes.
 */
class RegistrarConclusaoEtapaRequisicaoCompra
{
    public function execute(RequisicaoCompraEtapa $etapa, User $usuario, ?string $dataRealizada = null): RequisicaoCompraEtapa
    {
        return DB::transaction(function () use ($etapa, $usuario, $dataRealizada) {
            $rc = RequisicaoCompra::whereKey($etapa->requisicao_compra_id)->lockForUpdate()->firstOrFail();

            if ($rc->status !== StatusRequisicaoCompra::Emitida) {
                throw new RequisicaoCompraImutavelException(
                    'Só é possível registrar conclusão de etapa numa Requisição de Compra Emitida.'
                );
            }

            $etapa = RequisicaoCompraEtapa::whereKey($etapa->id)->lockForUpdate()->firstOrFail();

            if ($etapa->data_realizada !== null) {
                throw new EtapaRequisicaoCompraJaConcluidaException(
                    'Esta etapa já foi registrada como concluída e não pode ser alterada.'
                );
            }

            $data = $dataRealizada ? \Carbon\Carbon::parse($dataRealizada) : now();
            if ($data->isFuture()) {
                throw new InvalidArgumentException('A data de conclusão não pode estar no futuro.');
            }

            $etapa->update([
                'data_realizada' => $data->toDateString(),
                'realizada_por' => $usuario->id,
            ]);

            $todasConcluidas = RequisicaoCompraEtapa::where('requisicao_compra_id', $rc->id)
                ->whereNull('data_realizada')
                ->doesntExist();

            if ($todasConcluidas) {
                $rc->forceFill([
                    'status' => StatusRequisicaoCompra::Concluida,
                    'concluida_em' => now(),
                ])->save();
            }

            return $etapa->fresh();
        });
    }
}
