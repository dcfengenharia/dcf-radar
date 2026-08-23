{{--
    Ciclo 18, Etapa 18.5.9 — modal de invalidação de um aceite ativo. Nunca
    apaga/edita o conteúdo original (App\Actions\Engenharia\
    InvalidarAceiteEntrega só grava invalidado_em/invalidado_por/motivo).
--}}
@php($aceite = $this->aceiteEmInvalidacao)
@if ($aceite)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55)">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Invalidar aceite — {{ $aceite->grdDestinatario->nome_snapshot }}</h5>
                <button type="button" class="btn-close" wire:click="fecharModalInvalidarAceite"></button>
            </div>
            <div class="modal-body">
                @if ($aceiteInvalidarErro)
                    <div class="alert alert-danger">{{ $aceiteInvalidarErro }}</div>
                @endif

                <p class="text-muted">O aceite continua no histórico — apenas deixa de ser o aceite ativo. Depois de invalidado, é possível registrar um novo aceite pra este destinatário.</p>

                <div class="mb-0">
                    <label class="form-label">Motivo da invalidação</label>
                    <textarea class="form-control" rows="2" wire:model="aceiteMotivoInvalidacao"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" wire:click="fecharModalInvalidarAceite">Cancelar</button>
                <button class="btn btn-danger" wire:click="confirmarInvalidarAceite">Invalidar</button>
            </div>
        </div>
    </div>
</div>
@endif
