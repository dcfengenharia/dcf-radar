<?php

namespace App\Observers;

use App\Enums\StatusRestricao;
use App\Models\Restricao;
use App\Notifications\RestricaoCriadaNotification;
use App\Notifications\RestricaoResolvidaNotification;

class RestricaoObserver
{
    public function created(Restricao $restricao): void
    {
        if (! $restricao->responsavel_id) {
            return;
        }

        $responsavel = $restricao->responsavel;
        if (! $responsavel) {
            return;
        }

        $criador = $restricao->autor ?? auth()->user();
        if (! $criador || $responsavel->id === $criador->id) {
            return;
        }

        $responsavel->notify(new RestricaoCriadaNotification($restricao, $criador));
    }

    public function updated(Restricao $restricao): void
    {
        if (! $restricao->wasChanged('status')) {
            return;
        }

        if ($restricao->status !== StatusRestricao::Resolvida) {
            return;
        }

        $resolvedor = auth()->user();
        if (! $resolvedor) {
            return;
        }

        $restricao->loadMissing('autor', 'atividade.responsavel');

        $notificar = collect();

        if ($restricao->autor && $restricao->autor->id !== $resolvedor->id) {
            $notificar->push($restricao->autor);
        }

        $responsavelAtividade = $restricao->atividade?->responsavel;
        if ($responsavelAtividade && $responsavelAtividade->id !== $resolvedor->id && ! $notificar->contains('id', $responsavelAtividade->id)) {
            $notificar->push($responsavelAtividade);
        }

        foreach ($notificar as $usuario) {
            $usuario->notify(new RestricaoResolvidaNotification($restricao, $resolvedor));
        }
    }
}
