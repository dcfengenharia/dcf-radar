<?php

namespace App\Policies;

use App\Models\CronogramaImportacao;
use App\Models\User;

/**
 * Mesmo padrão de ReportPolicy — só o método `view` existe porque a tela de
 * detalhe da importação (Fase 3, Etapa 5) é somente-leitura. `ver` em
 * `obras.importar_cronograma` é liberado por padrão pra qualquer perfil com
 * acesso à obra (ver Perfil::REGRAS_ESCRITA — "ver" nunca bloqueia ninguém
 * hoje). Isolamento de tenant vem de graça do BelongsToTenant (route model
 * binding de um ID de outro tenant já resulta em 404 via global scope,
 * nunca chega a chamar esta Policy); isolamento de obra é o que
 * temPermissaoNaObra() garante aqui.
 */
class CronogramaImportacaoPolicy
{
    public function view(User $user, CronogramaImportacao $importacao): bool
    {
        return $user->temPermissaoNaObra($importacao->obra_id, 'obras.importar_cronograma', 'ver');
    }
}
