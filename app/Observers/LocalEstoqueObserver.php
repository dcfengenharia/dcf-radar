<?php

namespace App\Observers;

use App\Enums\TipoLocalEstoque;
use App\Exceptions\LocalEstoqueInvalidoException;
use App\Exceptions\LocalEstoqueReferenciadoException;
use App\Models\LocalEstoque;
use App\Models\MovimentacaoEstoque;
use App\Models\ReservaEstoque;
use App\Models\UnidadeEstoque;

/**
 * Ciclo 20, Etapa 20.1.CORREÇÃO — fecha o Achado B1 da auditoria
 * adversarial pro lado do LocalEstoque, mesmo raciocínio exato de
 * App\Observers\MaterialObserver (SoftDeletes + FK restrictOnDelete só
 * protege forceDelete, nunca soft-delete). `ativo=false` continua sendo
 * o mecanismo operacional real; exclusão só é bloqueada com histórico
 * genuíno (UnidadeEstoque ou MovimentacaoEstoque vinculada).
 *
 * **Ciclo 20, Etapa 20.2** — `possuiReferenciaHistorica()` ganhou o
 * check de `ReservaEstoque` (mesma razão do análogo em
 * `App\Observers\MaterialObserver`): `reservas_estoque.local_estoque_id`
 * também é `restrictOnDelete()`, que só protege `forceDelete()`.
 *
 * **Ciclo 20, Etapa 20.5** — 2 guards novos, ambos fechando a Opção A
 * de custódia em terceiro confirmada pelo usuário: (1) `tipo=Terceiro`
 * SEMPRE exige `fornecedor_id`; qualquer outro tipo NUNCA aceita
 * `fornecedor_id` (evita um Local próprio "vinculado" a um fornecedor
 * por engano); (2) `tipo`/`fornecedor_id` ficam CONGELADOS assim que o
 * Local já tem qualquer `MovimentacaoEstoque` — reinterpretar um Local
 * já usado (própria virar terceiro ou vice-versa) reescreveria
 * silenciosamente a semântica de todo o histórico físico já gravado
 * nele.
 */
class LocalEstoqueObserver
{
    public function creating(LocalEstoque $local): void
    {
        $this->garantirFornecedorCoerenteComTipo($local);
    }

    public function updating(LocalEstoque $local): void
    {
        $this->garantirFornecedorCoerenteComTipo($local);

        if (($local->isDirty('tipo') || $local->isDirty('fornecedor_id')) && $this->possuiMovimentacao($local)) {
            throw new LocalEstoqueInvalidoException(
                'Este Local de Estoque já tem movimentações registradas — tipo e fornecedor não podem mais ser alterados.'
            );
        }
    }

    public function deleting(LocalEstoque $local): void
    {
        if ($this->possuiReferenciaHistorica($local)) {
            throw new LocalEstoqueReferenciadoException(
                'Este Local de Estoque possui movimentações e/ou reservas vinculadas e não pode ser excluído. '
                . 'Se o objetivo é impedir novo uso, inative o Local em vez de excluí-lo.'
            );
        }
    }

    private function garantirFornecedorCoerenteComTipo(LocalEstoque $local): void
    {
        $tipo = $local->tipo instanceof TipoLocalEstoque ? $local->tipo : TipoLocalEstoque::from($local->tipo);

        if ($tipo === TipoLocalEstoque::Terceiro && ! $local->fornecedor_id) {
            throw new LocalEstoqueInvalidoException('Um Local do tipo Terceiro precisa estar vinculado a um Fornecedor.');
        }

        if ($tipo !== TipoLocalEstoque::Terceiro && $local->fornecedor_id) {
            throw new LocalEstoqueInvalidoException('Só um Local do tipo Terceiro pode estar vinculado a um Fornecedor.');
        }
    }

    private function possuiMovimentacao(LocalEstoque $local): bool
    {
        return MovimentacaoEstoque::where('local_estoque_id', $local->id)->exists();
    }

    private function possuiReferenciaHistorica(LocalEstoque $local): bool
    {
        return UnidadeEstoque::where('local_estoque_id', $local->id)->exists()
            || MovimentacaoEstoque::where('local_estoque_id', $local->id)->exists()
            || ReservaEstoque::where('local_estoque_id', $local->id)->exists();
    }
}
