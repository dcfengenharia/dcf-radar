<?php

namespace App\Observers;

use App\Models\RevisaoLiberacao;
use App\Support\Grd\AlertaDistribuicaoGrd;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 18, Etapa 18.5.5 — gatilho do Alerta B (candidato a nova
 * entrega). `revisao_liberacoes` é append-only e tem um ÚNICO escritor de
 * domínio (App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento::registrar()
 * — ver docblock da própria Action) — um Observer no MODELO evita tocar
 * essa Action já aprovada (18.3/18.3.CORREÇÃO) e cobre liberar() E
 * revogar() com um único ponto, filtrando aqui qual dos dois interessa.
 *
 * Revogação nunca gera alerta (`liberada_para_construcao === false`
 * retorna cedo). `registrar()` já é idempotente (não cria linha nova se
 * o estado pedido já é o atual) e já garante que só a revisão VIGENTE
 * pode ser liberada (`garantirRevisaoVigente()`, lança exceção antes de
 * qualquer escrita caso contrário) — este Observer nunca precisa
 * reimplementar nenhuma dessas duas garantias, só herda o efeito de nunca
 * ser chamado nos casos que elas já bloqueiam.
 */
class RevisaoLiberacaoObserver
{
    public function created(RevisaoLiberacao $evento): void
    {
        if (! $evento->liberada_para_construcao) {
            return;
        }

        DB::afterCommit(function () use ($evento) {
            try {
                $revisao = $evento->revisao()->firstOrFail();
                (new AlertaDistribuicaoGrd())->dispararCandidatosNovaEntrega($revisao);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
