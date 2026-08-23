@if ($modalAdicionarDocumentoAberto)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55)">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adicionar documento ao rascunho</h5>
                <button type="button" class="btn-close" wire:click="fecharModalAdicionarDocumento"></button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control mb-3" wire:model.live.debounce.300ms="buscaDocumento" placeholder="Buscar por código ou descrição...">

                <div class="list-group">
                    @forelse ($this->revisoesDisponiveisParaAdicionar as $documento)
                        <div class="list-group-item d-flex justify-content-between align-items-center" wire:key="disp-{{ $documento->id }}">
                            <div>
                                <strong>{{ $documento->codigo }}</strong> — {{ $documento->descricao }}
                                <br>
                                <span class="text-muted small">Revisão vigente: {{ $documento->latestRevisao->revisao }}</span>
                                @if ($documento->latestRevisao->estaLiberadaParaConstrucao())
                                    <span class="badge bg-label-success ms-1">Liberada para construção</span>
                                @else
                                    <span class="badge bg-label-warning ms-1">Ainda não liberada</span>
                                @endif
                            </div>
                            <button class="btn btn-sm btn-primary" wire:click="adicionarDocumento('{{ $documento->latestRevisao->id }}')">
                                Adicionar
                            </button>
                        </div>
                    @empty
                        <div class="text-center text-muted py-4">Nenhum documento disponível para adicionar.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endif
