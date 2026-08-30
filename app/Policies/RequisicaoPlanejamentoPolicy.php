<?php

namespace App\Policies;

use App\Models\RequisicaoPlanejamento;
use App\Models\User;

/**
 * Ciclo 19, Etapa 19.2 — slug próprio `planejamento.requisicoes`
 * (decisão do usuário: não reaproveitar `restricoes.plano_semanal` nem
 * criar em `suprimentos.*` — a formalização da demanda é responsabilidade
 * do Planejamento). Só `ver`/`editar` nesta fase — sem granularidade de
 * `criar`/`excluir`/`emitir` separada; `editar` cobre criar rascunho,
 * adicionar/remover/alterar item e emitir, sempre reforçado
 * server-side, nunca só pela UI escondendo o botão.
 */
class RequisicaoPlanejamentoPolicy
{
    public function view(User $user, RequisicaoPlanejamento $rp): bool
    {
        return $user->temPermissaoNaObra($rp->obra_id, 'planejamento.requisicoes', 'ver');
    }

    /** Sem model ainda (RP sendo criada) — recebe o obra_id de destino diretamente. */
    public function create(User $user, string $obraId): bool
    {
        return $user->temPermissaoNaObra($obraId, 'planejamento.requisicoes', 'editar');
    }

    public function update(User $user, RequisicaoPlanejamento $rp): bool
    {
        return $user->temPermissaoNaObra($rp->obra_id, 'planejamento.requisicoes', 'editar');
    }
}
