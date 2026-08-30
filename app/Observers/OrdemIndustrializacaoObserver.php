<?php

namespace App\Observers;

use App\Exceptions\OrdemIndustrializacaoImutavelException;
use App\Models\OrdemIndustrializacao;

/**
 * Ciclo 20, Etapa 20.5 — barreira semântica: uma OrdemIndustrializacao
 * que já deixou de ser Rascunho (Emitida ou Concluida) tem sua
 * identidade (fornecedor/local terceiro/pacote) congelada e nunca pode
 * ser excluída — mesmo padrão de `RequisicaoPlanejamentoObserver`/
 * `RequisicaoCompraObserver`/`GrdObserver`. A atomicidade real vem do
 * lock adquirido pelas Actions antes de qualquer leitura/escrita
 * (mesma disciplina de todo o domínio de Estoque/Suprimentos).
 */
class OrdemIndustrializacaoObserver
{
    private const CAMPOS_CONGELADOS = ['fornecedor_id', 'local_terceiro_id', 'item_suprimento_id', 'obra_id', 'tenant_id'];

    public function updating(OrdemIndustrializacao $ordem): void
    {
        if ($ordem->getOriginal('status') !== 'rascunho' && $ordem->isDirty(self::CAMPOS_CONGELADOS)) {
            throw new OrdemIndustrializacaoImutavelException(
                'Esta Ordem de Industrialização já foi emitida — Fornecedor, Local de custódia e Pacote não podem mais ser alterados.'
            );
        }
    }

    public function deleting(OrdemIndustrializacao $ordem): void
    {
        if ($ordem->status?->value !== 'rascunho') {
            throw new OrdemIndustrializacaoImutavelException(
                'Esta Ordem de Industrialização já foi emitida e não pode ser excluída.'
            );
        }
    }
}
