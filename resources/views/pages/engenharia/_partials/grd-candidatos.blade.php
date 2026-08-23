{{--
    Ciclo 18, Etapa 18.5.2 — consumo direto de
    App\Support\Grd\CandidatosNovaEntregaGrd::porObra() (recomendação
    derivada, nunca uma obrigação). Destinatario soft-deletado NÃO
    aparece aqui (ver docblock do service).

    Etapa 18.5.4 — card + filtros aplicados EM MEMÓRIA dentro do próprio
    computed `candidatos()` do componente — o card abaixo lê a MESMA
    coleção já filtrada (`cardsCandidatos()`), nunca uma contagem
    paralela.
--}}
<div class="alert alert-info">
    Recebeu revisão anterior e ainda não recebeu a vigente — recomendação, não uma entrega obrigatória.
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <span class="text-muted small d-block">Destinatários candidatos</span>
                <span class="fs-4 fw-semibold">{{ $this->cardsCandidatos['destinatarios'] }}</span>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-7">
                <label class="form-label small">Buscar documento (código ou descrição)</label>
                <input type="text" class="form-control" wire:model.live.debounce.400ms="buscaCandidatoDocumento" placeholder="Ex.: PROJ-001 ou planta baixa">
            </div>
            <div class="col-md-5">
                <label class="form-label small">Destinatário</label>
                <select class="form-select" wire:model.live="destinatarioCandidatoFiltro">
                    <option value="">Todos</option>
                    @foreach ($this->opcoesDestinatarioCandidatos as $opcao)
                        <option value="{{ $opcao->id }}">{{ $opcao->nome }}</option>
                    @endforeach
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
                    <th>Destinatário</th>
                    <th>Empresa / Setor</th>
                    <th>Revisões anteriores recebidas</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->candidatos as $registro)
                    <tr wire:key="cand-{{ $registro->documento->id }}-{{ $registro->destinatario->id }}">
                        <td class="fw-semibold">{{ $registro->documento->codigo }} — {{ $registro->documento->descricao }}</td>
                        <td>{{ $registro->revisao_vigente->revisao }}</td>
                        <td>{{ $registro->destinatario->nome }}</td>
                        <td class="text-muted small">
                            {{ collect([$registro->destinatario->empresa, $registro->destinatario->setor])->filter()->implode(' / ') ?: '—' }}
                        </td>
                        <td>{{ $registro->revisoes_anteriores_recebidas->pluck('revisao')->implode(', ') }}</td>
                        <td class="text-end">
                            @if (Auth::user()->temPermissaoNaObra($obraId, 'engenharia.pacotes', 'editar'))
                                <button class="btn btn-sm btn-outline-primary" wire:click="criarGrdAPartirDeCandidato('{{ $registro->documento->id }}', '{{ $registro->destinatario->id }}')">
                                    Criar rascunho de GRD
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">Nenhuma recomendação de nova entrega no momento.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
