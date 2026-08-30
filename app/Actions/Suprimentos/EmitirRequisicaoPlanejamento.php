<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusRequisicaoPlanejamento;
use App\Exceptions\RequisicaoPlanejamentoEmissaoInvalidaException;
use App\Exceptions\RequisicaoPlanejamentoImutavelException;
use App\Exceptions\SaldoTakeOffInsuficienteException;
use App\Models\ItemTakeOff;
use App\Models\RequisicaoPlanejamento;
use App\Models\RequisicaoPlanejamentoItem;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 19, Etapa 19.2 — transição Rascunho -> Emitida. Mesmo padrão
 * exato de App\Actions\Engenharia\EmitirGrd: lock em linha estável da
 * obra (Work) pra numeração serializada, `UNIQUE(obra_id, numero)` como
 * defesa final; tudo dentro de UMA transação — se qualquer validação
 * falhar, nada é escrito (zero emissão parcial).
 *
 * **Revalidação total (seção 22 do pedido)**: nunca confia no saldo que
 * a UI viu quando o rascunho foi montado — RELÊ e trava (lockForUpdate)
 * cada `ItemTakeOff` desta RP e recalcula o saldo emitido por OUTRAS RPs
 * na hora, ANTES de escrever qualquer snapshot. Cenário coberto: RP1
 * rascunho reserva A=70 "visualmente"; RP2 emite A=50 no meio tempo; RP1
 * tenta emitir e falha por falta de saldo — sem ter escrito nada.
 */
class EmitirRequisicaoPlanejamento
{
    public function execute(RequisicaoPlanejamento $rp, User $usuario): RequisicaoPlanejamento
    {
        return DB::transaction(function () use ($rp, $usuario) {
            Work::whereKey($rp->obra_id)->lockForUpdate()->firstOrFail();

            $rp = RequisicaoPlanejamento::whereKey($rp->id)->lockForUpdate()->firstOrFail();

            if (! $rp->estaRascunho()) {
                throw new RequisicaoPlanejamentoImutavelException('Esta Requisição do Planejamento já foi emitida e não pode ser emitida novamente.');
            }

            $itens = $rp->itens()->get();
            if ($itens->isEmpty()) {
                throw new RequisicaoPlanejamentoEmissaoInvalidaException('A Requisição do Planejamento precisa ter ao menos 1 item antes de ser emitida.');
            }

            $itemTakeOffIds = $itens->pluck('item_take_off_id')->all();

            // Lock por linha (nunca a tabela inteira) — trava só os
            // ItemTakeOff efetivamente envolvidos nesta emissão, numa
            // ÚNICA query (nunca um foreach com lockForUpdate() por
            // item — é isso que evita o padrão clássico de deadlock
            // aplicacional "trava A depois B" vs "trava B depois A").
            // `orderBy('id')` (19.2.CORREÇÃO, achado B da auditoria
            // adversarial): determinismo EXPLÍCITO na ordem de acesso às
            // linhas — nunca depende implicitamente do plano de acesso
            // do InnoDB pra um `WHERE id IN (...)`. Não muda o conjunto
            // de linhas retornado nem o `keyBy('id')` seguinte.
            $itensTakeOffTravados = ItemTakeOff::whereIn('id', $itemTakeOffIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->with('lista.revisao.documento', 'unidadeMedida')
                ->get()
                ->keyBy('id');

            $requisitadoOutrasRps = RequisicaoPlanejamentoItem::query()
                ->whereIn('item_take_off_id', $itemTakeOffIds)
                ->where('requisicao_planejamento_id', '!=', $rp->id)
                ->whereHas('requisicao', fn ($q) => $q->where('status', StatusRequisicaoPlanejamento::Emitida->value))
                ->groupBy('item_take_off_id')
                ->selectRaw('item_take_off_id, SUM(quantidade_requisitada) as total')
                ->pluck('total', 'item_take_off_id');

            foreach ($itens as $rpItem) {
                $itemTakeOff = $itensTakeOffTravados->get($rpItem->item_take_off_id);

                if (! $itemTakeOff) {
                    throw new RequisicaoPlanejamentoEmissaoInvalidaException('Um item do Take Off desta requisição não foi encontrado.');
                }

                $emitidoOutras = (float) ($requisitadoOutrasRps[$rpItem->item_take_off_id] ?? 0);
                $saldoDisponivel = round((float) $itemTakeOff->quantidade - $emitidoOutras, 3);
                $quantidadeDesejada = (float) $rpItem->quantidade_requisitada;

                if ($quantidadeDesejada > $saldoDisponivel + 0.0005) {
                    throw new SaldoTakeOffInsuficienteException(
                        "O item \"{$itemTakeOff->descricao}\" não tem mais saldo suficiente ({$saldoDisponivel} disponível, {$quantidadeDesejada} requisitado nesta RP) — outra Requisição do Planejamento consumiu o saldo enquanto este rascunho estava aberto.",
                        $saldoDisponivel,
                        $quantidadeDesejada
                    );
                }
            }

            // Só depois de TODAS as validações passarem: congela os snapshots.
            foreach ($itens as $rpItem) {
                $itemTakeOff = $itensTakeOffTravados->get($rpItem->item_take_off_id);
                $lista = $itemTakeOff->lista;
                $documento = $lista?->revisao?->documento;

                $rpItem->forceFill([
                    'codigo_item_snapshot' => $itemTakeOff->codigo,
                    'descricao_snapshot' => $itemTakeOff->descricao,
                    'unidade_snapshot' => $itemTakeOff->unidadeMedida?->codigo,
                    'lista_codigo_snapshot' => $lista?->codigo,
                    'tipo_lista_snapshot' => $lista?->tipo?->label(),
                    'documento_codigo_snapshot' => $documento?->codigo,
                    'revisao_snapshot' => $lista?->revisao?->revisao,
                ])->save();
            }

            $proximoNumero = (int) RequisicaoPlanejamento::withTrashed()->where('obra_id', $rp->obra_id)->max('numero') + 1;

            $rp->forceFill([
                'numero' => $proximoNumero,
                'status' => StatusRequisicaoPlanejamento::Emitida,
                'emitida_em' => now(),
                'emitida_por' => $usuario->id,
            ])->save();

            return $rp->fresh('itens');
        });
    }
}
