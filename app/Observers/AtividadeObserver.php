<?php

namespace App\Observers;

use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Notifications\AtividadeAtribuidaNotification;
use Illuminate\Validation\ValidationException;

class AtividadeObserver
{
    public function updating(Atividade $atividade): void
    {
        if (! $atividade->isDirty('status')) {
            return;
        }

        $novoStatus = StatusAtividade::from($atividade->getAttributes()['status']);

        if ($novoStatus === StatusAtividade::Comprometido && ! $atividade->estaPronta()) {
            throw ValidationException::withMessages([
                'status' => 'Não é possível comprometer uma atividade com restrições bloqueantes em aberto.',
            ]);
        }

        // Fonte da verdade pro PPC histórico (⚡relatorios-restricoes.blade.php)
        // — nunca usar updated_at, que é tocado por reimportação de
        // cronograma, edição de linha de base, etc. Roda em qualquer
        // transição de status, então também limpa sozinho se uma atividade
        // concluída for reaberta.
        //
        // Ciclo 24 — quando o próprio chamador já definiu `concluido_em`
        // explicitamente na MESMA gravação (ex.: MsProjectImporter
        // reconciliando conclusão física preferindo ActualFinish/data de
        // status a `now()` — ver "concluido_em deve representar a melhor
        // data factual disponível"), esse valor é PRESERVADO — nunca
        // sobrescrito aqui. Fluxos manuais (ex.: `marcarConcluida()` do
        // Plano Semanal) nunca tocam `concluido_em` explicitamente, então
        // continuam caindo no `now()` de sempre, sem nenhuma mudança de
        // comportamento observável.
        if ($novoStatus === StatusAtividade::Concluido) {
            if (! $atividade->isDirty('concluido_em')) {
                $atividade->concluido_em = now();
            }
        } else {
            $atividade->concluido_em = null;
        }
    }

    public function updated(Atividade $atividade): void
    {
        if (! $atividade->wasChanged('responsavel_id')) {
            return;
        }

        $novoResponsavelId = $atividade->responsavel_id;
        if (! $novoResponsavelId) {
            return;
        }

        $atribuidor = auth()->user();
        if (! $atribuidor || $atribuidor->id === $novoResponsavelId) {
            return;
        }

        $atividade->responsavel?->notify(new AtividadeAtribuidaNotification($atividade, $atribuidor));
    }
}
