<?php

namespace App\Policies;

use App\Enums\StatusReport;
use App\Models\Report;
use App\Models\User;

/**
 * Primeira Policy do projeto que combina PERFIL e STATUS do registro ao
 * mesmo tempo — todas as outras Policies do sistema checam só permissão.
 * Um report em rascunho só é visível/editável por quem tem permissão de
 * "editar" em report.relatorios na obra; uma vez emitido, ele vira
 * somente-leitura e passa a ser visível (e comentável) por qualquer
 * perfil com permissão de "ver" naquela funcionalidade.
 */
class ReportPolicy
{
    public function viewAny(User $user, string $obraId): bool
    {
        return $user->temAcessoAObra($obraId);
    }

    public function view(User $user, Report $report): bool
    {
        return $report->estaEmitido()
            ? $user->temPermissaoNaObra($report->obra_id, 'report.relatorios', 'ver')
            : $user->temPermissaoNaObra($report->obra_id, 'report.relatorios', 'editar');
    }

    public function create(User $user, string $obraId): bool
    {
        return $user->temPermissaoNaObra($obraId, 'report.relatorios', 'criar');
    }

    /**
     * Editar (curvas, pontos de atenção, fotos) só é permitido enquanto
     * o report ainda está em rascunho — depois de emitido, os dados
     * viram histórico congelado (ver App\Services\ReportGerador).
     */
    public function update(User $user, Report $report): bool
    {
        return $report->estaRascunho()
            && $user->temPermissaoNaObra($report->obra_id, 'report.relatorios', 'editar');
    }

    public function emitir(User $user, Report $report): bool
    {
        return $report->estaRascunho()
            && $user->temPermissaoNaObra($report->obra_id, 'report.relatorios', 'editar');
    }

    public function delete(User $user, Report $report): bool
    {
        return $user->temPermissaoNaObra($report->obra_id, 'report.relatorios', 'excluir');
    }

    /**
     * Comentário só faz sentido depois que o report foi emitido — é
     * nesse momento que "os demais usuários" (fora do planejamento)
     * ganham acesso pra análise, conforme o fluxo pedido pelo usuário.
     * O próprio planejamento também pode comentar depois de emitir.
     */
    public function comentar(User $user, Report $report): bool
    {
        return $report->estaEmitido() && $user->temPermissaoNaObra($report->obra_id, 'report.relatorios', 'ver');
    }
}
