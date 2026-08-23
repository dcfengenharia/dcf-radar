<?php

namespace App\Policies;

use App\Models\InconsistenciaAvanco;
use App\Models\User;

/**
 * Ciclo 17, A.9.6 — reaproveita a permissão já existente de
 * `restricoes.lookahead` (nenhum slug novo no catálogo, por instrução
 * explícita do pedido: "NÃO crie nova funcionalidade/permissão de catálogo
 * nesta etapa sem necessidade concreta") — as inconsistências de avanço
 * são, na essência, mais uma lente sobre o mesmo domínio Lookahead/avanço
 * de atividades, não uma responsabilidade nova. `ver` pra listar/visualizar,
 * `editar` pra tratar (mesmo padrão de `CronogramaImportacaoPolicy`/
 * `PlanoAcaoPolicy`: isolamento de tenant vem de graça do global scope de
 * BelongsToTenant — route model binding de um ID de outro tenant já
 * resulta em 404 antes de chegar aqui; isolamento de obra é o que
 * `temPermissaoNaObra()` garante).
 */
class InconsistenciaAvancoPolicy
{
    public function viewAny(User $user, string $obraId): bool
    {
        return $user->temPermissaoNaObra($obraId, 'restricoes.lookahead', 'ver');
    }

    public function view(User $user, InconsistenciaAvanco $inconsistencia): bool
    {
        return $user->temPermissaoNaObra($inconsistencia->obra_id, 'restricoes.lookahead', 'ver');
    }

    public function tratar(User $user, InconsistenciaAvanco $inconsistencia): bool
    {
        return $user->temPermissaoNaObra($inconsistencia->obra_id, 'restricoes.lookahead', 'editar');
    }
}
