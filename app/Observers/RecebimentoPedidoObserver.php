<?php

namespace App\Observers;

use App\Exceptions\RecebimentoPedidoImutavelException;
use App\Models\RecebimentoPedido;

/**
 * Ciclo 19, Etapa 19.6.CORREÇÃO — achado C1 da auditoria adversarial:
 * a versão original só bloqueava `deleting()` — `$evento->
 * quantidade_recebida = 999; $evento->save();` (e `$evento->update([...])`)
 * passavam SEM exceção nenhuma, reescrevendo o fato físico sem trilha,
 * mesmo o docblock do model já afirmando (incorretamente, até esta
 * correção) que era "bloqueado incondicionalmente".
 *
 * `updating()` agora bloqueia SEMPRE, incondicionalmente — mesmo padrão
 * de `deleting()`/`ListaEngenhariaObserver`. Um recebimento físico é
 * fato/auditável: nenhum campo (`quantidade_recebida`/`recebido_em`/
 * `registrado_por`/`local_recebimento`/`observacao`) pode ser alterado
 * depois de criado — não existe "correção", só estorno/ajuste futuro
 * (etapa dedicada, não implementada aqui) como um EVENTO NOVO, nunca um
 * UPDATE sobre o antigo.
 *
 * **Limitação estrutural conhecida, documentada — não escondida**: um
 * Observer Eloquent NUNCA intercepta `DB::table('recebimentos_pedido')
 * ->update(...)` nem `RecebimentoPedido::where(...)->update(...)` (mass
 * update, que também bypassa eventos de model individuais) — isso é uma
 * limitação do próprio framework, não deste Observer. Grep exaustivo em
 * `app/` (19.6.CORREÇÃO) confirmou **zero writer de produção** usando
 * qualquer uma dessas duas formas contra esta tabela — o único writer
 * real é `App\Actions\Suprimentos\RegistrarRecebimentoPedido::execute()`,
 * via `RecebimentoPedido::create()`. A garantia de imutabilidade desta
 * etapa cobre 100% dos writers Eloquent por instância (`save()`/
 * `update()` num model já existente) — Query Builder cru e mass update
 * permanecem PROIBIDOS por convenção arquitetural (API proibida pra
 * esta entidade), não por trigger de banco (deliberadamente não criado
 * — nenhum motivo real apareceu que justificasse essa complexidade).
 * `creating()` nunca é bloqueado — é assim que `RegistrarRecebimentoPedido`
 * consegue criar a linha original.
 */
class RecebimentoPedidoObserver
{
    public function updating(RecebimentoPedido $recebimento): void
    {
        throw new RecebimentoPedidoImutavelException(
            'Recebimentos registrados são fatos históricos e não podem ser alterados ou excluídos.'
        );
    }

    public function deleting(RecebimentoPedido $recebimento): void
    {
        throw new RecebimentoPedidoImutavelException(
            'Recebimentos registrados são fatos históricos e não podem ser alterados ou excluídos.'
        );
    }
}
