<?php

namespace App\Policies;

use App\Models\LicaoAprendidaReaplicacao;
use App\Models\User;
use App\Models\Work;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 15/16) — reaproveita exatamente os
 * mesmos slugs/ações já existentes de `gestao.licoes-aprendidas`, nunca
 * uma ação nova no catálogo:
 * `registrar` → `criar` NA OBRA DE DESTINO (não basta poder VER a lição
 * corporativa — é preciso ter autorização de criar conteúdo do domínio
 * de lições aprendidas na obra onde se afirma que o conhecimento foi
 * reaplicado, mesma semântica de `LicaoAprendidaPolicy::create()`).
 * `avaliar` → `editar` NA OBRA DE DESTINO da própria reaplicação (nunca
 * a obra de origem da lição).
 *
 * Elegibilidade de DOMÍNIO (status Publicada, obra destino != obra de
 * origem, mesmo tenant) é responsabilidade de
 * `App\Actions\LicoesAprendidas\RegistrarReaplicacaoLicao`/
 * `AvaliarReaplicacaoLicao` — esta Policy é só permissão, nunca mistura
 * as duas responsabilidades.
 */
class LicaoAprendidaReaplicacaoPolicy
{
    /** Sem model ainda (reaplicação sendo criada) — recebe a obra de destino diretamente. */
    public function registrar(User $user, Work $obraDestino): bool
    {
        return $user->temPermissaoNaObra($obraDestino->id, 'gestao.licoes-aprendidas', 'criar');
    }

    public function avaliar(User $user, LicaoAprendidaReaplicacao $reaplicacao): bool
    {
        return $user->temPermissaoNaObra($reaplicacao->obra_id, 'gestao.licoes-aprendidas', 'editar');
    }
}
