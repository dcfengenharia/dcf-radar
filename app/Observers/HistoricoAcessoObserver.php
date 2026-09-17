<?php

namespace App\Observers;

use App\Exceptions\HistoricoAcessoImutavelException;
use App\Models\HistoricoAcesso;

/**
 * FASE 2D, Seção 33 — mesmo padrão exato de
 * `App\Observers\MovimentacaoEstoqueObserver`/`RecebimentoPedidoObserver`:
 * `updating()`/`deleting()` bloqueiam SEMPRE, incondicionalmente. Um
 * evento de histórico de acesso nunca é corrigido nem apagado — uma
 * correção é sempre um evento NOVO (Seção 3).
 *
 * Mesma limitação estrutural já documentada nos observers irmãos: mass
 * update/delete via Query Builder cru bypassam Observers por natureza
 * do framework — nenhum writer de produção usa qualquer uma das duas
 * formas contra esta tabela (só `App\Support\Perfis\RegistrarEventoAcesso`
 * escreve aqui, sempre via `HistoricoAcesso::create()`); ambas
 * permanecem API PROIBIDA por convenção arquitetural, não por trigger
 * de banco.
 */
class HistoricoAcessoObserver
{
    public function updating(HistoricoAcesso $evento): void
    {
        throw new HistoricoAcessoImutavelException(
            'Eventos de histórico de acesso são fatos de governança e não podem ser alterados. Registre um novo evento.'
        );
    }

    public function deleting(HistoricoAcesso $evento): void
    {
        throw new HistoricoAcessoImutavelException(
            'Eventos de histórico de acesso são fatos de governança e não podem ser excluídos.'
        );
    }
}
