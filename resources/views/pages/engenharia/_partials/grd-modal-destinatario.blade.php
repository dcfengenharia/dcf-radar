@if ($modalDestinatarioAberto)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55)">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adicionar destinatário ao rascunho</h5>
                <button type="button" class="btn-close" wire:click="fecharModalDestinatario"></button>
            </div>
            <div class="modal-body">
                @if (! $formNovoDestinatarioAberto)
                    <input type="text" class="form-control mb-3" wire:model.live.debounce.300ms="buscaDestinatario" placeholder="Buscar destinatário já cadastrado...">

                    <div class="list-group mb-3">
                        @forelse ($this->destinatariosDisponiveisParaAdicionar as $destinatario)
                            <div class="list-group-item d-flex justify-content-between align-items-center" wire:key="dispd-{{ $destinatario->id }}">
                                <div>
                                    <strong>{{ $destinatario->nome }}</strong>
                                    @if ($destinatario->empresa) <span class="text-muted"> — {{ $destinatario->empresa }}</span> @endif
                                    @if ($destinatario->setor) <span class="text-muted small"> ({{ $destinatario->setor }})</span> @endif
                                </div>
                                <button class="btn btn-sm btn-primary" wire:click="adicionarDestinatarioExistente('{{ $destinatario->id }}')">
                                    Adicionar
                                </button>
                            </div>
                        @empty
                            <div class="text-center text-muted py-3">Nenhum destinatário encontrado.</div>
                        @endforelse
                    </div>

                    <button class="btn btn-outline-secondary btn-sm" wire:click="toggleFormNovoDestinatario">
                        <i class="bx bx-plus me-1"></i>Cadastrar novo destinatário
                    </button>
                @else
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome *</label>
                            <input type="text" class="form-control" wire:model="novoDestinatarioNome">
                            @error('novoDestinatarioNome') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Empresa</label>
                            <input type="text" class="form-control" wire:model="novoDestinatarioEmpresa">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Setor</label>
                            <input type="text" class="form-control" wire:model="novoDestinatarioSetor">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail</label>
                            <input type="email" class="form-control" wire:model="novoDestinatarioEmail">
                            @error('novoDestinatarioEmail') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefone</label>
                            <input type="text" class="form-control" wire:model="novoDestinatarioTelefone">
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-primary" wire:click="salvarNovoDestinatarioEAdicionar">Salvar e adicionar</button>
                        <button class="btn btn-outline-secondary" wire:click="toggleFormNovoDestinatario">Cancelar</button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endif
