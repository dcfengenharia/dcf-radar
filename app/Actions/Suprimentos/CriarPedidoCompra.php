<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusPedidoCompra;
use App\Enums\StatusRequisicaoCompra;
use App\Exceptions\PedidoCompraEmissaoInvalidaException;
use App\Models\Fornecedor;
use App\Models\PedidoCompra;
use App\Models\RequisicaoCompra;
use App\Models\User;
use InvalidArgumentException;

/**
 * Ciclo 19, Etapa 19.5 — cria o Rascunho de um Pedido/OC pra UMA RC
 * (nunca cruza RCs). Só é possível criar Pedido sobre uma RC que já
 * deixou de ser Rascunho (`Emitida`/`Concluida` — mesmo padrão de "só
 * RP Emitida é alocável" da 19.3): a quantidade de `RequisicaoCompraItem`
 * só é real/congelada a partir da emissão da RC.
 *
 * **Sem lock proativo aqui (diferente de `CriarRequisicaoCompra`)**: uma
 * RC `Emitida`/`Concluida` já é PERMANENTEMENTE imutável desde 19.4 —
 * nunca volta a Rascunho, nunca é excluída (`RequisicaoCompraObserver`).
 * Não há corrida real a fechar (a seção 43 da 19.4 travava
 * `ItemSuprimento` porque um Pacote SEM RC nenhuma podia ser excluído
 * concorrentemente; aqui, qualquer RC — Rascunho ou não — já bloqueia a
 * exclusão do Pacote de qualquer forma, então criar um Pedido nunca
 * corre risco de "a RC/Pacote sumir debaixo dele").
 */
class CriarPedidoCompra
{
    public function execute(
        RequisicaoCompra $rc,
        Fornecedor $fornecedor,
        ?string $dataPrevistaEntrega,
        ?string $numeroContrato,
        ?string $dataContrato,
        ?string $observacao,
        User $usuario,
    ): PedidoCompra {
        if ($rc->status === StatusRequisicaoCompra::Rascunho) {
            throw new PedidoCompraEmissaoInvalidaException(
                'Só é possível criar Pedido/Ordem de Compra a partir de uma Requisição de Compra já Emitida.'
            );
        }

        if ($fornecedor->obra_id !== $rc->obra_id) {
            throw new InvalidArgumentException('Este Fornecedor não pertence à mesma obra da Requisição de Compra.');
        }

        return PedidoCompra::create([
            'obra_id' => $rc->obra_id,
            'requisicao_compra_id' => $rc->id,
            'fornecedor_id' => $fornecedor->id,
            'status' => StatusPedidoCompra::Rascunho,
            'data_prevista_entrega' => $dataPrevistaEntrega,
            'numero_contrato' => $numeroContrato,
            'data_contrato' => $dataContrato,
            'observacao' => $observacao,
            'created_by_id' => $usuario->id,
        ]);
    }
}
