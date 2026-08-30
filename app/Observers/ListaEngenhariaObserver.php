<?php

namespace App\Observers;

use App\Exceptions\ListaEngenhariaImutavelException;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\ListaEngenharia;

/**
 * Ciclo 19, Etapa 19.1.HARDENING — mesmo padrão de `GrdObserver`/
 * `GrdAceiteEntregaObserver`: um Observer é o único ponto de verdade
 * que bloqueia mutação indevida, nunca confiando só num botão escondido
 * na UI.
 *
 * `deleting()` bloqueia SEMPRE, incondicionalmente — Lista de Engenharia
 * (LM/LI) é evidência documental e nunca é excluída, vigente ou não
 * (seção 10 do pedido: "delete sempre bloqueado", diferente de
 * `GrdObserver`, que só bloqueia quando `estaEmitida()`). Cobre
 * `delete()` E `forceDelete()` — `Model::forceDelete()` sempre delega
 * pra `$this->delete()`, que dispara `deleting` antes de
 * `performDeleteOnModel()` (mesma prova já usada em `GrdObserver`).
 *
 * `creating()` bloqueia criar uma lista NOVA numa revisão que já não é
 * mais a vigente do documento.
 *
 * `updating()` bloqueia editar metadados da própria lista (título/
 * disciplina/observação) fora da revisão vigente — hoje nenhuma UI faz
 * isso, mas o guard existe por simetria/defesa em profundidade.
 */
class ListaEngenhariaObserver
{
    public function creating(ListaEngenharia $lista): void
    {
        $revisao = DocumentoEngenhariaRevisao::with('documento')->find($lista->documento_engenharia_revisao_id);

        if ($revisao && $revisao->documento?->revisaoVigente()?->id !== $revisao->id) {
            throw new ListaEngenhariaImutavelException(
                'Esta revisão não é mais a vigente do documento — não é possível criar novas listas nela.'
            );
        }
    }

    public function updating(ListaEngenharia $lista): void
    {
        $lista->garantirEditavel();
    }

    public function deleting(ListaEngenharia $lista): void
    {
        throw new ListaEngenhariaImutavelException(
            'Listas de engenharia (LM/LI) são evidência documental e nunca podem ser excluídas.'
        );
    }
}
