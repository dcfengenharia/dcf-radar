@if ($modalEmitirAberto)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55)">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Emitir GRD</h5>
                <button type="button" class="btn-close" wire:click="fecharModalEmitir"></button>
            </div>
            <div class="modal-body">
                @if ($erroEmissao)
                    <div class="alert alert-danger">{{ $erroEmissao }}</div>
                @endif

                <p>Resumo desta GRD:</p>
                <ul>
                    <li>{{ $grd->itens->count() }} documento(s)</li>
                    <li>{{ $grd->destinatarios->count() }} destinatário(s)</li>
                    <li>{{ $this->distribuicoesDaGrdAberta->count() }} distribuição(ões)</li>
                </ul>

                <div class="alert alert-warning mb-0">
                    Após a emissão, esta GRD se torna um registro histórico e seus itens não poderão mais ser alterados.
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" wire:click="fecharModalEmitir">Cancelar</button>
                <button class="btn btn-success" wire:click="confirmarEmissao">
                    <i class="bx bx-send me-1"></i>Confirmar emissão
                </button>
            </div>
        </div>
    </div>
</div>
@endif
