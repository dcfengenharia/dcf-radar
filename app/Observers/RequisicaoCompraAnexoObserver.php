<?php

namespace App\Observers;

use App\Exceptions\RequisicaoCompraAnexoInvalidoException;
use App\Models\RequisicaoCompraAnexo;

/**
 * Etapa 2 — bloqueia `delete()`/`forceDelete()` de um anexo SOMENTE
 * quando ele é evidência já referenciada por outro registro (Seção 16 —
 * "não apagar evidência anterior silenciosamente"): (1) é a versão
 * ANTERIOR de um anexo mais novo (`substitui_anexo_id` de outra linha
 * aponta pra este), ou (2) suporta uma `RequisicaoCompraAdjudicacao`
 * (Seção 19). Sem nenhuma dessas referências, exclusão continua livre
 * (mesmo padrão condicional de `App\Observers\ItemSuprimentoObserver`,
 * nunca incondicional como `RequisicaoCompraAdjudicacaoObserver`) — a
 * FK `restrictOnDelete()` das duas tabelas é a defesa final, esta
 * mensagem só é a versão amigável antes de chegar lá.
 */
class RequisicaoCompraAnexoObserver
{
    public function deleting(RequisicaoCompraAnexo $anexo): void
    {
        if ($anexo->versoesPosteriores()->exists()) {
            throw new RequisicaoCompraAnexoInvalidoException(
                'Este anexo foi substituído por uma versão mais nova e não pode ser excluído — o histórico documental é sempre preservado.'
            );
        }

        if ($anexo->adjudicacoesSuportadas()->exists()) {
            throw new RequisicaoCompraAnexoInvalidoException(
                'Este anexo suporta uma decisão de adjudicação registrada e não pode ser excluído.'
            );
        }
    }
}
