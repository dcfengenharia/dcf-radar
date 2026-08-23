{{-- Ciclo 18, Etapa 18.5.2 — listagem de GRDs da obra atual. --}}
<div class="row g-3 mb-3 align-items-end">
    <div class="col-md-3">
        <label class="form-label mb-1">Buscar por nº da GRD</label>
        <input type="text" class="form-control" wire:model.live.debounce.400ms="buscaGrd" placeholder="Ex.: 12">
    </div>
    <div class="col-md-3">
        <label class="form-label mb-1">Status</label>
        <select class="form-select" wire:model.live="statusFiltro">
            <option value="">Todos</option>
            <option value="rascunho">Rascunho</option>
            <option value="emitida">Emitida</option>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label mb-1">Destinatário</label>
        <select class="form-select" wire:model.live="destinatarioFiltroId">
            <option value="">Todos</option>
            @foreach ($this->destinatariosDaObra as $d)
                <option value="{{ $d->id }}">{{ $d->nome }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label mb-1">Documento (código)</label>
        <input type="text" class="form-control" wire:model.live.debounce.400ms="documentoFiltro" placeholder="Ex.: DOC-001">
    </div>
</div>

<div class="mb-3">
    @if (Auth::user()->temPermissaoNaObra($obraId, 'engenharia.pacotes', 'editar'))
        <button class="btn btn-primary" wire:click="criarGrd">
            <i class="bx bx-plus me-1"></i>Nova GRD
        </button>
    @endif
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nº GRD</th>
                    <th>Status</th>
                    <th>Emitida em</th>
                    <th>Emitida por</th>
                    <th class="text-center">Documentos</th>
                    <th class="text-center">Destinatários</th>
                    <th class="text-center">Distribuições</th>
                    <th>Observação</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->grds as $grd)
                    <tr wire:key="grd-{{ $grd->id }}" style="cursor:pointer" wire:click="abrirGrd('{{ $grd->id }}')">
                        <td class="fw-semibold">
                            @if ($grd->estaEmitida())
                                GRD-{{ str_pad($grd->numero, 3, '0', STR_PAD_LEFT) }}
                            @else
                                <span class="text-muted">— (rascunho)</span>
                            @endif
                        </td>
                        <td>
                            @if ($grd->estaEmitida())
                                <span class="badge bg-label-success">Emitida</span>
                            @else
                                <span class="badge bg-label-secondary">Rascunho</span>
                            @endif
                        </td>
                        <td>{{ $grd->emitida_em?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td>{{ $grd->emitidoPor ? "{$grd->emitidoPor->first_name} {$grd->emitidoPor->last_name}" : '—' }}</td>
                        <td class="text-center">{{ $grd->itens_count }}</td>
                        <td class="text-center">{{ $grd->destinatarios_count }}</td>
                        <td class="text-center">{{ $grd->distribuicoes_count }}</td>
                        <td class="text-truncate" style="max-width:220px">{{ $grd->observacao ?: '—' }}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" wire:click.stop="abrirGrd('{{ $grd->id }}')">
                                Abrir
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">Nenhuma GRD encontrada para os filtros atuais.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($this->grds->hasPages())
        <div class="card-body">
            {{ $this->grds->links() }}
        </div>
    @endif
</div>
