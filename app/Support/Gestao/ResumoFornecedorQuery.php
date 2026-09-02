<?php

namespace App\Support\Gestao;

use App\Enums\StatusOrdemIndustrializacao;
use App\Enums\StatusPedidoCompra;
use App\Models\Fornecedor;
use App\Models\OrdemIndustrializacao;
use App\Models\PedidoCompra;
use Illuminate\Support\Collection;

/**
 * Ciclo 21, Etapa 21.2 — visão agregada por Fornecedor (Seção 4 do
 * pedido). **Nenhum score arbitrário** (instrução explícita) — só
 * contagens/somas diretamente deriváveis do ledger comercial já
 * existente, sempre obra-escopadas e em lote.
 *
 * **Gaps documentados, NÃO implementados nesta entrega** (sem vínculo
 * determinístico confiável, nunca inferido): "materiais relacionados a
 * atividades próximas" exigiria cruzar `PedidoCompraItem` deste
 * Fornecedor com `CoberturaMaterialAtividadeQuery` — tecnicamente
 * viável (ambos já existem), mas fora do recorte desta entrega;
 * "documentos pendentes relacionados" NÃO tem vínculo direto — `Fornecedor`
 * nunca se relaciona com `DocumentoEngenharia` em nenhum ponto do
 * domínio (confirmado por fresh-read), então esse campo fica sempre
 * `null` (nunca inventado via join indireto e frágil).
 */
class ResumoFornecedorQuery
{
    /**
     * @return Collection<string, array> chave = fornecedor_id
     */
    public static function porObra(string $obraId): Collection
    {
        $fornecedores = Fornecedor::query()->where('obra_id', $obraId)->get();

        if ($fornecedores->isEmpty()) {
            return collect();
        }

        $fornecedorIds = $fornecedores->pluck('id')->all();

        $pedidos = PedidoCompra::query()
            ->whereIn('fornecedor_id', $fornecedorIds)
            ->where('status', StatusPedidoCompra::Emitido->value)
            ->with('itens.recebimentos')
            ->get();

        $ordensAbertas = OrdemIndustrializacao::query()
            ->whereIn('fornecedor_id', $fornecedorIds)
            ->where('status', StatusOrdemIndustrializacao::Emitida->value)
            ->get()
            ->groupBy('fornecedor_id');

        return $fornecedores->mapWithKeys(function (Fornecedor $fornecedor) use ($pedidos, $ordensAbertas) {
            $pedidosDoFornecedor = $pedidos->where('fornecedor_id', $fornecedor->id);
            $pedidosAbertos = $pedidosDoFornecedor->filter(fn (PedidoCompra $p) => $p->situacaoEntrega() !== \App\Enums\SituacaoEntregaPedido::Completa);
            $pedidosAtrasados = $pedidosDoFornecedor->filter(fn (PedidoCompra $p) => $p->diasAtrasoAtual() !== null);
            $quantidadePendente = round((float) $pedidosAbertos->sum(
                fn (PedidoCompra $p) => $p->itens->sum(fn ($item) => $item->saldoAReceber())
            ), 3);

            return [$fornecedor->id => [
                'fornecedor_id' => $fornecedor->id,
                'pedidos_abertos' => $pedidosAbertos->count(),
                'pedidos_atrasados' => $pedidosAtrasados->count(),
                'quantidade_pendente' => $quantidadePendente,
                'industrializacoes_em_aberto' => $ordensAbertas->get($fornecedor->id, collect())->count(),
                'materiais_atividades_proximas' => null, // gap documentado — não implementado nesta entrega
                'documentos_pendentes' => null, // sem vínculo determinístico (Fornecedor não se relaciona com DocumentoEngenharia)
            ]];
        });
    }
}
