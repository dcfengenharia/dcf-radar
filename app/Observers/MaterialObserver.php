<?php

namespace App\Observers;

use App\Exceptions\MaterialReferenciadoException;
use App\Models\DestinacaoPlanejadaMaterial;
use App\Models\ItemTakeOff;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\ReservaEstoque;
use App\Models\UnidadeEstoque;

/**
 * Ciclo 20, Etapa 20.1.CORREÇÃO — fecha o Achado B1 da auditoria
 * adversarial: Material usa SoftDeletes, e a FK `restrictOnDelete()` de
 * toda tabela que o referencia só protege `forceDelete()` (DELETE
 * físico) — `Model::delete()` (soft) é só um `UPDATE deleted_at`, que
 * NUNCA aciona FK nenhuma. Sem este Observer, um soft-delete
 * "silencioso" faria `Material::find()` (escopo global de SoftDeletes)
 * parar de resolver um Material com histórico real, quebrando a
 * navegação de `MovimentacaoEstoque->material`/`UnidadeEstoque->material`
 * pra fatos já gravados — comprovado empiricamente na auditoria.
 *
 * Mesmo padrão exato de `App\Observers\ItemTakeOffObserver`
 * (19.2.CORREÇÃO)/`App\Observers\ItemSuprimentoObserver` (19.3.CORREÇÃO):
 * um único guard no evento `deleting` cobre AMBAS as chamadas (`delete()`
 * e `forceDelete()`, já que `forceDelete()` sempre delega pra `delete()`
 * internamente no Eloquent). `ativo=false` continua sendo o mecanismo
 * operacional real pra "não aceitar mais nada novo" — a exclusão só é
 * bloqueada quando há histórico genuíno; um Material nunca usado
 * continua livremente excluível (soft ou force).
 *
 * **Ciclo 20, Etapa 20.2** — `possuiReferenciaHistorica()` ganhou os 2
 * checks novos (`DestinacaoPlanejadaMaterial`/`ReservaEstoque`) pela
 * MESMA razão: as duas tabelas novas também usam `material_id`
 * `restrictOnDelete()`, que só protegeria `forceDelete()` — sem este
 * ajuste, um soft-delete silencioso quebraria a navegação
 * `DestinacaoPlanejadaMaterial->material`/`ReservaEstoque->material`
 * exatamente como já acontecia com `ItemTakeOff`/`UnidadeEstoque`/
 * `MovimentacaoEstoque` antes da 20.1.CORREÇÃO original.
 *
 * **Disciplina de lock, não neste Observer**: a checagem abaixo só é
 * livre de corrida quando o CHAMADOR já adquiriu
 * `Material::lockForUpdate()` na mesma linha, dentro da mesma transação,
 * ANTES de chamar `delete()` — mesma disciplina já documentada nos
 * Observers irmãos. Nenhuma UI desta etapa oferece exclusão de Material
 * (só "Inativar/Reativar" via `ativo`), então esse risco de corrida é
 * hoje só teórico — documentado por precaução, não por exploração real
 * conhecida.
 */
class MaterialObserver
{
    public function deleting(Material $material): void
    {
        if ($this->possuiReferenciaHistorica($material)) {
            throw new MaterialReferenciadoException(
                'Este Material possui histórico de Take Off e/ou movimentações de estoque vinculadas e não pode ser excluído. '
                . 'Se o objetivo é impedir novo uso, inative o Material em vez de excluí-lo.'
            );
        }
    }

    private function possuiReferenciaHistorica(Material $material): bool
    {
        return ItemTakeOff::where('material_id', $material->id)->exists()
            || UnidadeEstoque::where('material_id', $material->id)->exists()
            || MovimentacaoEstoque::where('material_id', $material->id)->exists()
            || DestinacaoPlanejadaMaterial::where('material_id', $material->id)->exists()
            || ReservaEstoque::where('material_id', $material->id)->exists();
    }
}
