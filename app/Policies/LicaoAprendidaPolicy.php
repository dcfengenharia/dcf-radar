<?php

namespace App\Policies;

use App\Models\LicaoAprendida;
use App\Models\User;
use App\Models\Work;

/**
 * Ciclo 23, Etapa 23.1 — mapa de permissão aprovado pelo usuário:
 * `criar`  → criar rascunho (limiar mais baixo, autor de campo)
 * `editar` → editar rascunho + enviar para validação (autoria)
 * `excluir`→ publicar/arquivar/devolver para rascunho (governança —
 *            as 3 transições mais sensíveis, mesmo limiar mais alto já
 *            usado em toda funcionalidade do catálogo) + exclusão de
 *            rascunho/em-validação (nunca de Publicada/Arquivada, já
 *            bloqueado por `App\Observers\LicaoAprendidaObserver`).
 *
 * Visibilidade (`view`): uma lição AINDA NÃO publicada só é visível
 * pra quem tem acesso à obra de origem (`temPermissaoNaObra`) — uma
 * Publicada passa a ser visível a qualquer usuário autorizado do
 * tenant, mesmo sem acesso à obra que a originou
 * (`temPermissaoEmAlgumaObraDoTenant`), exatamente o propósito da
 * biblioteca corporativa (Seção 26 do pedido). Isso nunca concede
 * acesso à Atividade/Restrição/Documento VINCULADOS — só ao conteúdo
 * da própria lição e aos snapshots já congelados
 * (`App\Support\LicoesAprendidas\VinculoLicaoResolver` decide,
 * separadamente, se a entidade vinculada em si pode ser exibida como
 * link vivo).
 */
class LicaoAprendidaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->temPermissaoEmAlgumaObraDoTenant('gestao.licoes-aprendidas', 'ver');
    }

    public function view(User $user, LicaoAprendida $licao): bool
    {
        if ($licao->estaPublicada()) {
            return $user->temPermissaoEmAlgumaObraDoTenant('gestao.licoes-aprendidas', 'ver');
        }

        return $user->temPermissaoNaObra($licao->obra_origem_id, 'gestao.licoes-aprendidas', 'ver');
    }

    /** Sem model ainda (lição sendo criada) — recebe a obra de destino diretamente. */
    public function create(User $user, Work $obra): bool
    {
        return $user->temPermissaoNaObra($obra->id, 'gestao.licoes-aprendidas', 'criar');
    }

    public function update(User $user, LicaoAprendida $licao): bool
    {
        return $user->temPermissaoNaObra($licao->obra_origem_id, 'gestao.licoes-aprendidas', 'editar');
    }

    public function enviarParaValidacao(User $user, LicaoAprendida $licao): bool
    {
        return $this->update($user, $licao);
    }

    public function devolverParaRascunho(User $user, LicaoAprendida $licao): bool
    {
        return $this->publicar($user, $licao);
    }

    public function publicar(User $user, LicaoAprendida $licao): bool
    {
        return $user->temPermissaoNaObra($licao->obra_origem_id, 'gestao.licoes-aprendidas', 'excluir');
    }

    public function arquivar(User $user, LicaoAprendida $licao): bool
    {
        return $this->publicar($user, $licao);
    }

    public function delete(User $user, LicaoAprendida $licao): bool
    {
        return $user->temPermissaoNaObra($licao->obra_origem_id, 'gestao.licoes-aprendidas', 'excluir');
    }

    public function vincular(User $user, LicaoAprendida $licao): bool
    {
        return $this->update($user, $licao);
    }

    /**
     * Ciclo 23, Etapa 23.3 (Seção 35) — reaproveita `gestao.licoes-aprendidas`,
     * nenhuma ação nova no catálogo. `ver`/`criar`/`excluir` (nunca
     * `editar` — um candidato não é editável, só convertido/descartado).
     */
    public function verRevisaoObra(User $user, Work $obra): bool
    {
        return $user->temPermissaoNaObra($obra->id, 'gestao.licoes-aprendidas', 'ver');
    }

    public function gerarCandidatos(User $user, Work $obra): bool
    {
        return $user->temPermissaoNaObra($obra->id, 'gestao.licoes-aprendidas', 'criar');
    }

    public function converterCandidato(User $user, \App\Models\CandidatoLicaoAprendida $candidato): bool
    {
        return $user->temPermissaoNaObra($candidato->obra_id, 'gestao.licoes-aprendidas', 'criar');
    }

    public function descartarCandidato(User $user, \App\Models\CandidatoLicaoAprendida $candidato): bool
    {
        return $user->temPermissaoNaObra($candidato->obra_id, 'gestao.licoes-aprendidas', 'excluir');
    }
}
