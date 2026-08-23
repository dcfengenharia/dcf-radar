{{--
    Ciclo 18, Etapa 18.5.2 — modal compartilhado de registro de
    recolhimento, acionável tanto do detalhe de uma GRD Emitida quanto da
    aba "Cópias obsoletas em campo". `$this->distribuicaoEmRecolhimento`
    já resolve escopado à obra atual (cross-obra bloqueado no computed).
--}}
@php($dist = $this->distribuicaoEmRecolhimento)
@if ($dist)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55)">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Registrar recolhimento</h5>
                <button type="button" class="btn-close" wire:click="fecharModalRecolhimento"></button>
            </div>
            <div class="modal-body">
                @if ($recolhimentoErro)
                    <div class="alert alert-danger">{{ $recolhimentoErro }}</div>
                @endif

                <p class="mb-1"><strong>{{ $dist->item->codigo_documento_snapshot }}</strong> — revisão {{ $dist->item->revisao_snapshot }}</p>
                <p class="text-muted">Destinatário: {{ $dist->grdDestinatario->nome_snapshot }}</p>
                <p class="text-muted">Cópias pendentes: <strong>{{ $dist->quantidadePendente() }}</strong></p>

                <div class="mb-3">
                    <label class="form-label">Resultado</label>
                    <select class="form-select" wire:model="recolhimentoResultado">
                        <option value="recolhido">Recolhido</option>
                        <option value="nao_localizado">Não localizado</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Quantidade</label>
                    <input type="number" min="1" class="form-control" wire:model="recolhimentoQuantidade">
                </div>

                <div class="mb-3">
                    <label class="form-label">Data/hora do fato</label>
                    <input type="datetime-local" class="form-control" wire:model="recolhimentoData">
                </div>

                <div class="mb-0">
                    <label class="form-label">Observação (opcional)</label>
                    <textarea class="form-control" rows="2" wire:model="recolhimentoObservacao"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" wire:click="fecharModalRecolhimento">Cancelar</button>
                <button class="btn btn-primary" wire:click="confirmarRecolhimento">Registrar</button>
            </div>
        </div>
    </div>
</div>
@endif
