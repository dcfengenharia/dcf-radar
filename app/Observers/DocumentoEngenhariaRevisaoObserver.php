<?php

namespace App\Observers;

use App\Models\DocumentoEngenhariaRevisao;
use App\Support\Grd\AlertaDistribuicaoGrd;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 18, Etapa 18.5.5 — gatilho do Alerta A (cópias obsoletas em
 * campo). Observer de MODELO em vez de hook espalhado nos 2 pontos que
 * criam DocumentoEngenhariaRevisao (App\Actions\Engenharia\
 * AnexarRevisaoDocumento::execute() e App\Imports\
 * DocumentoEngenhariaImporter::aplicar()) — cobre os dois caminhos (e
 * qualquer caminho futuro) sem duplicar a chamada, mesmo padrão já
 * usado por AtividadeObserver/RestricaoObserver/GrdObserver.
 *
 * `DB::afterCommit()` é essencial aqui: DocumentoEngenhariaImporter::aplicar()
 * roda dentro de transacaoSegura()/DB::transaction() (a importação de LD
 * inteira é atômica) — se o `created` disparasse a Notification de forma
 * síncrona e uma linha POSTERIOR do mesmo lote falhasse, a transação
 * inteira reverteria mas a Notification já teria sido enviada sobre uma
 * revisão que deixou de existir. Sem transação ativa (caminho manual via
 * AnexarRevisaoDocumento, que deliberadamente não abre transação),
 * `afterCommit()` executa imediatamente — mesmo comportamento de sempre.
 */
class DocumentoEngenhariaRevisaoObserver
{
    public function created(DocumentoEngenhariaRevisao $revisao): void
    {
        DB::afterCommit(function () use ($revisao) {
            try {
                (new AlertaDistribuicaoGrd())->dispararCopiasObsoletas($revisao);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
