<?php

namespace App\Observers;

use App\Exceptions\AlocacaoRequisicaoInvalidaException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\ItemSuprimento;
use App\Models\RequisicaoCompra;

/**
 * Ciclo 19, Etapa 19.3 — mesmo padrão de `ItemTakeOffObserver`/
 * `GrdObserver`: um Pacote de Compra (`ItemSuprimento`) que já recebeu
 * QUALQUER alocação de `RequisicaoPlanejamentoItem` nunca pode ser
 * excluído (soft ou force) — apagar o Pacote apagaria silenciosamente a
 * demanda formal do Planejamento nele alocada. `deleting()` roda antes
 * de `performDeleteOnModel()`, cobre `delete()` (soft) e `forceDelete()`
 * (que sempre delega pra `delete()`).
 *
 * Sem nenhuma alocação e nenhuma RC (nunca teve, ou todas já foram
 * removidas): exclusão volta a seguir a política legado, sem restrição
 * nova.
 *
 * **19.3.CORREÇÃO — disciplina de lock (não é este Observer que garante
 * isso)**: esta checagem `exists()` só é livre de corrida quando o
 * CHAMADOR já adquiriu `ItemSuprimento::lockForUpdate()` na MESMA linha,
 * dentro da MESMA transação, ANTES de chamar `delete()` — exatamente a
 * mesma disciplina que `App\Actions\Suprimentos\AlocarRequisicaoAoPacote`
 * já usa antes de criar/alterar/remover uma alocação. As duas operações
 * disputando o lock da MESMA linha `ItemSuprimento` é o que garante que
 * nenhuma das duas conclui com base num estado que a outra já invalidou.
 * Ver `⚡suprimentos.blade.php::excluirItem()`. O Observer sozinho, sem
 * essa disciplina no chamador, seria só uma checagem best-effort —
 * correta no instante em que roda, mas sem garantia contra uma inserção
 * concorrente de alocação entre o `exists()` e o `UPDATE deleted_at`
 * real (achado C confirmado empiricamente na auditoria adversarial desta
 * etapa, mesma classe de corrida já fechada pra `ItemTakeOff` na
 * 19.2.CORREÇÃO).
 *
 * **19.4, seção 43 — mesma disciplina, repetida PROATIVAMENTE (não
 * reativamente)**: `App\Actions\Suprimentos\CriarRequisicaoCompra`
 * também trava `ItemSuprimento::lockForUpdate()` na MESMA linha antes de
 * criar a RC — reaproveita o MESMO lock que já protege a checagem de
 * alocação acima, sem precisar de uma ordem nova. É esse lock
 * compartilhado (não este Observer sozinho) que garante que a corrida
 * "criar RC" vs "excluir Pacote" nunca deixa uma RC apontando pra um
 * Pacote soft-deletado.
 */
class ItemSuprimentoObserver
{
    public function deleting(ItemSuprimento $item): void
    {
        if (AlocacaoRequisicaoPacote::where('item_suprimento_id', $item->id)->exists()) {
            throw new AlocacaoRequisicaoInvalidaException(
                'Este Pacote possui demandas do Planejamento alocadas e não pode ser excluído. '
                . 'Remova primeiro as alocações vinculadas.'
            );
        }

        if (RequisicaoCompra::where('item_suprimento_id', $item->id)->exists()) {
            throw new AlocacaoRequisicaoInvalidaException(
                'Este Pacote possui Requisições de Compra e não pode ser excluído.'
            );
        }
    }
}
