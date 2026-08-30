<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusRequisicaoCompra;
use App\Exceptions\RequisicaoCompraEmissaoInvalidaException;
use App\Exceptions\RequisicaoCompraImutavelException;
use App\Exceptions\SaldoAlocacaoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraEtapa;
use App\Models\User;
use App\Models\Work;
use App\Support\DiasUteisCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 19, Etapa 19.4 — transição Rascunho -> Emitida. Mesmo padrão
 * exato de `EmitirRequisicaoPlanejamento`/`EmitirGrd`: lock em linha
 * estável da obra (Work) pra numeração serializada,
 * `UNIQUE(obra_id, numero)` como defesa final; tudo dentro de UMA
 * transação — se qualquer validação falhar, nada é escrito.
 *
 * **Revalidação total (mesmo espírito da seção 22 de 19.2)**: nunca
 * confia no saldo que a UI viu quando o rascunho foi montado — RELÊ e
 * trava (`lockForUpdate`, `orderBy('id')` — determinismo explícito,
 * mesma correção já aplicada em 19.2.CORREÇÃO) cada
 * `AlocacaoRequisicaoPacote` envolvida e recalcula o saldo consumido por
 * OUTRAS RCs na hora, ANTES de escrever qualquer snapshot/etapa.
 *
 * **Instanciação do fluxo**: reaproveita SOMENTE o cadastro de
 * `FluxoSuprimento`/`EtapaFluxoSuprimento` como template — a instância
 * real das etapas (`RequisicaoCompraEtapa`) nasce aqui, congelada, via
 * `App\Support\DiasUteisCalculator` (dias ÚTEIS, convenção única do
 * projeto), encadeando `data_prevista` a partir de hoje somando
 * `prazo_dias_uteis` de cada etapa do template em sequência (mesmo
 * princípio de encadeamento já usado por
 * `App\Services\SuprimentoScheduler`). Nunca reaproveita
 * `ItemSuprimentoEtapa` (mecanismo legado do Pacote, intocado).
 */
class EmitirRequisicaoCompra
{
    public function execute(RequisicaoCompra $rc, User $usuario): RequisicaoCompra
    {
        return DB::transaction(function () use ($rc, $usuario) {
            Work::whereKey($rc->obra_id)->lockForUpdate()->firstOrFail();

            $rc = RequisicaoCompra::whereKey($rc->id)->lockForUpdate()->firstOrFail();

            if ($rc->status !== StatusRequisicaoCompra::Rascunho) {
                throw new RequisicaoCompraImutavelException('Esta Requisição de Compra já foi emitida e não pode ser emitida novamente.');
            }

            if (! $rc->fluxo_suprimento_id) {
                throw new RequisicaoCompraEmissaoInvalidaException('Selecione um Fluxo de Suprimento antes de emitir esta Requisição de Compra.');
            }

            $itens = $rc->itens()->get();
            if ($itens->isEmpty()) {
                throw new RequisicaoCompraEmissaoInvalidaException('A Requisição de Compra precisa ter ao menos 1 item antes de ser emitida.');
            }

            $fluxo = $rc->fluxo()->with('etapas')->firstOrFail();
            if ($fluxo->etapas->isEmpty()) {
                throw new RequisicaoCompraEmissaoInvalidaException('O Fluxo de Suprimento selecionado não tem etapas cadastradas.');
            }

            $alocacaoIds = $itens->pluck('alocacao_requisicao_pacote_id')->all();

            $alocacoesTravadas = AlocacaoRequisicaoPacote::whereIn('id', $alocacaoIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->with('requisicaoItem.itemTakeOff.lista.revisao.documento', 'requisicaoItem.itemTakeOff.unidadeMedida')
                ->get()
                ->keyBy('id');

            $consumidoOutrasRcs = DB::table('requisicao_compra_itens')
                ->whereIn('alocacao_requisicao_pacote_id', $alocacaoIds)
                ->where('requisicao_compra_id', '!=', $rc->id)
                ->whereIn('requisicao_compra_id', function ($query) {
                    $query->select('id')->from('requisicoes_compra')
                        ->whereIn('status', [StatusRequisicaoCompra::Emitida->value, StatusRequisicaoCompra::Concluida->value]);
                })
                ->groupBy('alocacao_requisicao_pacote_id')
                ->selectRaw('alocacao_requisicao_pacote_id, SUM(quantidade) as total')
                ->pluck('total', 'alocacao_requisicao_pacote_id');

            foreach ($itens as $item) {
                $alocacao = $alocacoesTravadas->get($item->alocacao_requisicao_pacote_id);

                if (! $alocacao) {
                    throw new RequisicaoCompraEmissaoInvalidaException('Uma alocação desta requisição não foi encontrada.');
                }

                $consumidoOutras = (float) ($consumidoOutrasRcs[$item->alocacao_requisicao_pacote_id] ?? 0);
                $saldoDisponivel = round((float) $alocacao->quantidade_alocada - $consumidoOutras, 3);
                $quantidadeDesejada = (float) $item->quantidade;

                if ($quantidadeDesejada > $saldoDisponivel + 0.0005) {
                    throw new SaldoAlocacaoInsuficienteException(
                        "Uma das alocações desta requisição não tem mais saldo suficiente ({$saldoDisponivel} disponível, {$quantidadeDesejada} requisitado) — outra Requisição de Compra consumiu o saldo enquanto este rascunho estava aberto.",
                        $saldoDisponivel,
                        $quantidadeDesejada
                    );
                }
            }

            // Só depois de TODAS as validações passarem: congela os snapshots.
            foreach ($itens as $item) {
                $alocacao = $alocacoesTravadas->get($item->alocacao_requisicao_pacote_id);
                $itemTakeOff = $alocacao->requisicaoItem?->itemTakeOff;
                $lista = $itemTakeOff?->lista;
                $documento = $lista?->revisao?->documento;

                $item->forceFill([
                    'codigo_item_snapshot' => $itemTakeOff?->codigo,
                    'descricao_snapshot' => $itemTakeOff?->descricao,
                    'unidade_snapshot' => $itemTakeOff?->unidadeMedida?->codigo,
                    'lista_codigo_snapshot' => $lista?->codigo,
                    'tipo_lista_snapshot' => $lista?->tipo?->label(),
                    'documento_codigo_snapshot' => $documento?->codigo,
                    'revisao_snapshot' => $lista?->revisao?->revisao,
                ])->save();
            }

            $calculadora = DiasUteisCalculator::paraObra($rc->obra);
            $dataBase = now()->startOfDay();

            foreach ($fluxo->etapas as $etapaTemplate) {
                $dataBase = $calculadora->somar($dataBase, (int) $etapaTemplate->prazo_dias_uteis);

                RequisicaoCompraEtapa::create([
                    'requisicao_compra_id' => $rc->id,
                    'etapa_fluxo_suprimento_id' => $etapaTemplate->id,
                    'ordem' => $etapaTemplate->ordem,
                    'nome_snapshot' => $etapaTemplate->nome,
                    'prazo_dias_snapshot' => $etapaTemplate->prazo_dias_uteis,
                    'data_prevista' => $dataBase->toDateString(),
                ]);
            }

            $proximoNumero = (int) RequisicaoCompra::withTrashed()->where('obra_id', $rc->obra_id)->max('numero') + 1;

            $rc->forceFill([
                'numero' => $proximoNumero,
                'status' => StatusRequisicaoCompra::Emitida,
                'fluxo_nome_snapshot' => $fluxo->nome,
                'emitida_em' => now(),
                'emitida_por' => $usuario->id,
            ])->save();

            return $rc->fresh(['itens', 'etapas']);
        });
    }
}
