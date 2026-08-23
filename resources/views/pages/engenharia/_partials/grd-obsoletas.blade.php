{{--
    Ciclo 18, Etapa 18.5.2 — consumo direto de
    App\Support\Grd\DetectorCopiasObsoletasGrd::porObra() (nunca
    persistido, sempre derivado). Destinatario soft-deletado CONTINUA
    aparecendo aqui — a linha depende do snapshot em GrdDestinatario,
    nunca da existência ativa do cadastro (ver docblock do service).

    Etapa 18.5.4 — cards + filtros (busca/destinatário/estado) aplicados
    EM MEMÓRIA dentro do próprio computed `obsoletas()` do componente —
    os cards abaixo leem a MESMA coleção já filtrada (`cardsObsoletas()`),
    nunca uma contagem paralela, então cards e tabela nunca podem
    divergir entre si.
--}}
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <span class="text-muted small d-block">Cópias pendentes</span>
                <span class="fs-4 fw-semibold">{{ $this->cardsObsoletas['copias_pendentes'] }}</span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <span class="text-muted small d-block">Destinatários afetados</span>
                <span class="fs-4 fw-semibold">{{ $this->cardsObsoletas['destinatarios'] }}</span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <span class="text-muted small d-block">Documentos afetados</span>
                <span class="fs-4 fw-semibold">{{ $this->cardsObsoletas['documentos'] }}</span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <span class="text-muted small d-block">Quantidade física pendente</span>
                <span class="fs-4 fw-semibold">{{ $this->cardsObsoletas['quantidade_total_pendente'] }}</span>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label small">Buscar documento (código ou descrição)</label>
                <input type="text" class="form-control" wire:model.live.debounce.400ms="buscaObsoletaDocumento" placeholder="Ex.: PROJ-001 ou planta baixa">
            </div>
            <div class="col-md-4">
                <label class="form-label small">Destinatário</label>
                <select class="form-select" wire:model.live="destinatarioObsoletaFiltro">
                    <option value="">Todos</option>
                    @foreach ($this->opcoesDestinatarioObsoletas as $opcao)
                        <option value="{{ $opcao->destinatario_id }}">{{ $opcao->nome_snapshot }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Estado</label>
                <select class="form-select" wire:model.live="estadoObsoletaFiltro">
                    <option value="">Todos</option>
                    <option value="pendente">Pendente</option>
                    <option value="nao_localizado">Não localizado</option>
                </select>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Revisão vigente</th>
                    <th>Revisão em campo</th>
                    <th>Destinatário</th>
                    <th>Empresa / Setor</th>
                    <th>GRD de origem</th>
                    <th class="text-center">Entregues</th>
                    <th class="text-center">Recolhidas</th>
                    <th class="text-center">Pendentes</th>
                    <th>Último estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->obsoletas as $registro)
                    <tr wire:key="obs-{{ $registro->distribuicao->id }}">
                        <td class="fw-semibold">{{ $registro->documento->codigo }}</td>
                        <td>{{ $registro->revisao_vigente?->revisao ?? '—' }}</td>
                        <td>{{ $registro->revisao_entregue->revisao }}</td>
                        <td>
                            {{ $registro->grd_destinatario->nome_snapshot }}
                            @if (! $registro->grd_destinatario->destinatario)
                                <span class="badge bg-label-secondary ms-1">Destinatário inativo</span>
                            @endif
                        </td>
                        <td class="text-muted small">
                            {{ collect([$registro->grd_destinatario->empresa_snapshot, $registro->grd_destinatario->setor_snapshot])->filter()->implode(' / ') ?: '—' }}
                        </td>
                        <td>
                            @if ($registro->grd->estaEmitida())
                                GRD-{{ str_pad($registro->grd->numero, 3, '0', STR_PAD_LEFT) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-center">{{ $registro->quantidade_entregue }}</td>
                        <td class="text-center">{{ $registro->quantidade_recolhida }}</td>
                        <td class="text-center">{{ $registro->quantidade_pendente }}</td>
                        <td>
                            @if ($registro->ultimo_resultado_recolhimento?->value === 'nao_localizado')
                                <span class="badge bg-label-warning">Não localizado</span>
                            @else
                                <span class="badge bg-label-secondary">Pendente</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @if (Auth::user()->temPermissaoNaObra($obraId, 'engenharia.pacotes', 'editar'))
                                <button class="btn btn-sm btn-outline-primary" wire:click="abrirModalRecolhimento('{{ $registro->distribuicao->id }}')">
                                    Registrar recolhimento
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">Nenhuma cópia obsoleta em campo nesta obra.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
