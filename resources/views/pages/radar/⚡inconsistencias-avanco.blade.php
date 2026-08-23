<?php

use App\Actions\InconsistenciaAvanco\TratarInconsistenciaAvanco;
use App\Enums\EntidadeInconsistenciaAvanco;
use App\Enums\SeveridadeInconsistenciaAvanco;
use App\Enums\StatusInconsistenciaAvanco;
use App\Enums\TipoInconsistenciaAvanco;
use App\Exceptions\InconsistenciaJaTratadaException;
use App\Models\AtividadeSnapshot;
use App\Models\CronogramaImportacao;
use App\Models\InconsistenciaAvanco;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ciclo 17, A.9.6 — primeiro fluxo humano de tratamento das
 * InconsistenciaAvanco (A.9.4/A.9.5). MVP deliberadamente enxuto (ver
 * CLAUDE.md, seção A.9.6): só listagem + filtros + modal "Tratar" com
 * justificativa obrigatória. Nenhuma ação aqui altera o domínio
 * operacional original (Restricao/AtividadeItemProntidao/
 * ProgramacaoSemanal/Atividade/Fotografia F/O/P) — só o próprio registro
 * de tratamento, via App\Actions\InconsistenciaAvanco\
 * TratarInconsistenciaAvanco.
 */
new class extends Component {
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public Work $obra;

    // ---- Filtros ----
    public string $filtroStatus = 'aberta';
    public string $filtroSeveridade = '';
    public string $filtroTipo = '';
    public string $filtroBusca = '';
    public string $filtroImportacaoId = '';

    public int $perPage = 15;

    // ---- Modal "Tratar" ----
    public ?string $tratandoId = null;
    public string $justificativaTratamento = '';
    public ?string $tratarErro = null;

    public function mount(Work $obra): void
    {
        $this->obra = $obra;

        abort_unless(
            Auth::user()->temPermissaoNaObra($this->obra->id, 'restricoes.lookahead', 'ver'),
            403
        );
    }

    // =========================================================================
    // FILTROS
    // =========================================================================

    public function updatedFiltroStatus(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroSeveridade(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroTipo(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroBusca(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroImportacaoId(): void
    {
        $this->resetPage();
    }

    public function temFiltrosAtivos(): bool
    {
        return $this->filtroStatus !== 'aberta'
            || $this->filtroSeveridade !== ''
            || $this->filtroTipo !== ''
            || $this->filtroBusca !== ''
            || $this->filtroImportacaoId !== '';
    }

    public function limparFiltros(): void
    {
        $this->filtroStatus = 'aberta';
        $this->filtroSeveridade = '';
        $this->filtroTipo = '';
        $this->filtroBusca = '';
        $this->filtroImportacaoId = '';
        $this->resetPage();
    }

    // =========================================================================
    // QUERY / LISTAGEM
    // =========================================================================

    private function queryFiltrada(): Builder
    {
        return InconsistenciaAvanco::where('obra_id', $this->obra->id)
            ->when($this->filtroStatus !== '', fn ($q) => $q->where('status', $this->filtroStatus))
            ->when($this->filtroSeveridade !== '', fn ($q) => $q->where('severidade', $this->filtroSeveridade))
            ->when($this->filtroTipo !== '', fn ($q) => $q->where('tipo', $this->filtroTipo))
            ->when($this->filtroImportacaoId !== '', fn ($q) => $q->where('cronograma_importacao_id', $this->filtroImportacaoId))
            ->when($this->filtroBusca !== '', fn ($q) => $q->whereHas(
                'atividade',
                fn ($a) => $a->where('nome', 'like', "%{$this->filtroBusca}%")
                    ->orWhere('codigo_cronograma', 'like', "%{$this->filtroBusca}%")
            ));
    }

    /** Severidade crítica primeiro, depois mais recente — default recomendado do pedido. */
    private function aplicarOrdenacao(Builder $q): void
    {
        $q->orderByRaw("CASE severidade
                WHEN 'critica' THEN 0
                WHEN 'atencao' THEN 1
                WHEN 'informativa' THEN 2
                ELSE 3
            END ASC")
            ->orderByDesc('detectada_em');
    }

    #[Computed]
    public function inconsistencias()
    {
        $q = $this->queryFiltrada()->with([
            'atividade:id,codigo_cronograma,nome',
            'cronogramaImportacao:id,arquivo,tipo,importado_em',
            'tratadoPor:id,first_name,last_name',
        ]);
        $this->aplicarOrdenacao($q);

        return $q->paginate($this->perPage);
    }

    /**
     * Fotografia F das ocorrências da página atual (1 query pra TODA a
     * página, nunca por linha) — usada só pra exibir o fato factual
     * (iniciou/concluiu/percentual/datas reais) já congelado, nunca
     * reconsultado ao vivo. Chave composta em memória (mesmo padrão já
     * usado alhures no projeto pra evitar N+1 com chave dupla).
     */
    #[Computed]
    public function fotografiaFPorOcorrencia()
    {
        $importacaoIds = $this->inconsistencias->pluck('cronograma_importacao_id')->unique();
        $atividadeIds = $this->inconsistencias->pluck('atividade_id')->unique();

        return AtividadeSnapshot::whereIn('cronograma_importacao_id', $importacaoIds)
            ->whereIn('atividade_id', $atividadeIds)
            ->get()
            ->keyBy(fn (AtividadeSnapshot $s) => $s->cronograma_importacao_id.'|'.$s->atividade_id);
    }

    public function fatoFactual(InconsistenciaAvanco $inc): ?AtividadeSnapshot
    {
        return $this->fotografiaFPorOcorrencia->get($inc->cronograma_importacao_id.'|'.$inc->atividade_id);
    }

    /**
     * Contadores do cabeçalho — 1 query agregada (GROUP BY), nunca 3
     * queries separadas nem uma por linha (Seção 13/25 do pedido).
     */
    #[Computed]
    public function contadores()
    {
        $linhas = InconsistenciaAvanco::where('obra_id', $this->obra->id)
            ->where('status', StatusInconsistenciaAvanco::Aberta->value)
            ->selectRaw('severidade, count(*) as total')
            ->groupBy('severidade')
            ->pluck('total', 'severidade');

        return [
            'total_abertas' => $linhas->sum(),
            'criticas_abertas' => (int) ($linhas[SeveridadeInconsistenciaAvanco::Critica->value] ?? 0),
            'atencao_abertas' => (int) ($linhas[SeveridadeInconsistenciaAvanco::Atencao->value] ?? 0),
        ];
    }

    #[Computed]
    public function importacoesDisponiveis()
    {
        return CronogramaImportacao::where('obra_id', $this->obra->id)
            ->whereIn('id', InconsistenciaAvanco::where('obra_id', $this->obra->id)->distinct()->pluck('cronograma_importacao_id'))
            ->orderByDesc('importado_em')
            ->get(['id', 'importado_em', 'arquivo']);
    }

    // =========================================================================
    // TEXTO DIDÁTICO — traduz enums pra linguagem operacional (nunca expor
    // nome interno do enum na UI).
    // =========================================================================

    public function textoTipo(TipoInconsistenciaAvanco $tipo): string
    {
        return match ($tipo) {
            TipoInconsistenciaAvanco::InicioComRestricaoPendente
                => 'A atividade foi informada como iniciada, mas possuía uma Restrição pendente.',
            TipoInconsistenciaAvanco::InicioComProntidaoPendente
                => 'A atividade foi informada como iniciada, mas possuía um item de Prontidão pendente.',
            TipoInconsistenciaAvanco::ConclusaoComRestricaoPendente
                => 'A atividade foi informada como concluída, mas possuía uma Restrição pendente.',
            TipoInconsistenciaAvanco::ConclusaoComProntidaoPendente
                => 'A atividade foi informada como concluída, mas possuía um item de Prontidão pendente.',
            TipoInconsistenciaAvanco::InicioForaProgramacaoSemanal
                => 'A atividade foi informada como iniciada, mas não estava comprometida na Programação Semanal vigente na data informada.',
            TipoInconsistenciaAvanco::ConclusaoForaProgramacaoSemanal
                => 'A atividade foi informada como concluída, mas não estava comprometida na Programação Semanal vigente na data informada.',
            TipoInconsistenciaAvanco::InicioSemProgramacaoSemanal
                => 'A atividade foi informada como iniciada numa semana que não possuía nenhuma Programação Semanal registrada.',
            TipoInconsistenciaAvanco::ConclusaoSemProgramacaoSemanal
                => 'A atividade foi informada como concluída numa semana que não possuía nenhuma Programação Semanal registrada.',
        };
    }

    public function textoEntidade(InconsistenciaAvanco $inc): string
    {
        return match ($inc->entidade_tipo) {
            EntidadeInconsistenciaAvanco::Restricao => 'Restrição pendente: '.($inc->detalhes['status'] ?? '—').(($inc->detalhes['bloqueante'] ?? false) ? ' (bloqueante)' : ''),
            EntidadeInconsistenciaAvanco::ItemProntidao => 'Item de Prontidão pendente',
            EntidadeInconsistenciaAvanco::ProgramacaoSemanal => 'Programação Semanal da semana de '.($inc->detalhes['semana_inicio_resolvida'] ?? '—').' (versão '.($inc->detalhes['programacao_semanal_versao'] ?? '—').')',
            EntidadeInconsistenciaAvanco::Atividade => 'Nenhuma Programação Semanal existia para a semana de '.($inc->detalhes['semana_inicio_resolvida'] ?? '—'),
        };
    }

    // =========================================================================
    // TRATAR — modal
    // =========================================================================

    public function abrirModalTratar(string $id): void
    {
        $inc = InconsistenciaAvanco::where('obra_id', $this->obra->id)->findOrFail($id);
        $this->authorize('tratar', $inc);

        $this->tratandoId = $inc->id;
        $this->justificativaTratamento = '';
        $this->tratarErro = null;
    }

    public function fecharModalTratar(): void
    {
        $this->tratandoId = null;
        $this->justificativaTratamento = '';
        $this->tratarErro = null;
    }

    public function confirmarTratamento(): void
    {
        $this->justificativaTratamento = trim($this->justificativaTratamento);
        $this->validate(
            ['justificativaTratamento' => 'required|string|min:5'],
            [],
            ['justificativaTratamento' => 'justificativa']
        );

        // Escopado por obra explicitamente (defesa em profundidade — o
        // isolamento de tenant já vem do global scope de BelongsToTenant,
        // mas cross-obra dentro do MESMO tenant precisa ser checado aqui).
        $inc = InconsistenciaAvanco::where('obra_id', $this->obra->id)->find($this->tratandoId);

        if (! $inc) {
            $this->tratarErro = 'Esta inconsistência não foi encontrada nesta obra.';

            return;
        }

        $this->authorize('tratar', $inc);

        try {
            (new TratarInconsistenciaAvanco())->execute($inc, Auth::user(), $this->justificativaTratamento);
        } catch (InconsistenciaJaTratadaException $e) {
            $this->tratarErro = $e->getMessage();

            return;
        } catch (\InvalidArgumentException $e) {
            $this->tratarErro = $e->getMessage();

            return;
        }

        $this->fecharModalTratar();
        unset($this->inconsistencias, $this->contadores);
        $this->dispatch('show-toast', message: 'Inconsistência tratada — o fato importado permanece intacto, só o registro de análise foi gravado.');
    }
}; ?>

<div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card">
                <div class="card-body py-3">
                    <div class="text-muted small">Abertas</div>
                    <div class="fs-4 fw-semibold">{{ $this->contadores['total_abertas'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card">
                <div class="card-body py-3">
                    <div class="text-muted small">Críticas abertas</div>
                    <div class="fs-4 fw-semibold text-danger">{{ $this->contadores['criticas_abertas'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card">
                <div class="card-body py-3">
                    <div class="text-muted small">Atenção abertas</div>
                    <div class="fs-4 fw-semibold text-warning">{{ $this->contadores['atencao_abertas'] }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small">Status</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroStatus">
                        <option value="">Todas</option>
                        <option value="aberta">Abertas</option>
                        <option value="tratada">Tratadas</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Severidade</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroSeveridade">
                        <option value="">Todas</option>
                        @foreach (\App\Enums\SeveridadeInconsistenciaAvanco::cases() as $sev)
                            <option value="{{ $sev->value }}">{{ $sev->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Tipo</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroTipo">
                        <option value="">Todos</option>
                        @foreach (\App\Enums\TipoInconsistenciaAvanco::cases() as $tipo)
                            <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Importação</label>
                    <select class="form-select form-select-sm" wire:model.live="filtroImportacaoId">
                        <option value="">Todas</option>
                        @foreach ($this->importacoesDisponiveis as $imp)
                            <option value="{{ $imp->id }}">{{ $imp->importado_em->format('d/m/y H:i') }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Atividade</label>
                    <input type="text" class="form-control form-control-sm" wire:model.live.debounce.400ms="filtroBusca" placeholder="Código ou nome">
                </div>
                <div class="col-md-1">
                    @if ($this->temFiltrosAtivos())
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100" wire:click="limparFiltros">Limpar</button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Atividade</th>
                        <th>Importação</th>
                        <th>Fato importado</th>
                        <th>O que foi encontrado</th>
                        <th>Causa</th>
                        <th>Severidade</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->inconsistencias as $inc)
                        @php($fato = $this->fatoFactual($inc))
                        <tr wire:key="inc-{{ $inc->id }}">
                            <td>
                                <div class="fw-semibold">{{ $inc->atividade->codigo_cronograma ?? '—' }}</div>
                                <div class="small text-muted">{{ $inc->atividade->nome ?? 'Atividade removida' }}</div>
                            </td>
                            <td class="small">
                                {{ $inc->cronogramaImportacao?->importado_em?->format('d/m/y H:i') ?? '—' }}
                                @if ($inc->cronogramaImportacao?->arquivo)
                                    <div class="text-muted">{{ $inc->cronogramaImportacao->arquivo }}</div>
                                @endif
                            </td>
                            <td class="small">
                                @if ($fato)
                                    @if ($fato->real_inicio)
                                        <div>Início real: {{ $fato->real_inicio->format('d/m/y') }}</div>
                                    @endif
                                    @if ($fato->real_termino)
                                        <div>Término real: {{ $fato->real_termino->format('d/m/y') }}</div>
                                    @endif
                                    @if (!is_null($fato->percentual_concluido))
                                        <div>{{ (float) $fato->percentual_concluido }}% concluído</div>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="small" style="max-width: 260px;">{{ $this->textoTipo($inc->tipo) }}</td>
                            <td class="small">{{ $this->textoEntidade($inc) }}</td>
                            <td>
                                @php($badgeSev = match($inc->severidade) {
                                    \App\Enums\SeveridadeInconsistenciaAvanco::Critica => 'bg-label-danger',
                                    \App\Enums\SeveridadeInconsistenciaAvanco::Atencao => 'bg-label-warning',
                                    \App\Enums\SeveridadeInconsistenciaAvanco::Informativa => 'bg-label-info',
                                })
                                <span class="badge {{ $badgeSev }}">{{ $inc->severidade->label() }}</span>
                            </td>
                            <td>
                                @if ($inc->estaAberta())
                                    <span class="badge bg-label-secondary">Aberta</span>
                                @else
                                    <span class="badge bg-label-success">Tratada</span>
                                    <div class="small text-muted mt-1">
                                        {{ $inc->tratadoPor ? $inc->tratadoPor->first_name . ' ' . $inc->tratadoPor->last_name : 'Usuário removido' }}<br>
                                        {{ $inc->tratado_em?->format('d/m/y H:i') }}
                                    </div>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($inc->estaAberta() && Auth::user()->temPermissaoNaObra($obra->id, 'restricoes.lookahead', 'editar'))
                                    {{-- wire:target com o id da ocorrência (não só o nome do método) evita
                                         que o botão de OUTRA linha entre em estado de loading — mesmo idioma
                                         já usado em _partials/plano-acao-detalhe.blade.php. --}}
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            wire:click="abrirModalTratar('{{ $inc->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="abrirModalTratar('{{ $inc->id }}')">
                                        Tratar
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Nenhuma inconsistência encontrada com os filtros atuais.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->inconsistencias->hasPages())
            <div class="card-body">
                {{ $this->inconsistencias->links() }}
            </div>
        @endif
    </div>

    @if ($tratandoId)
        @php($incModal = $this->inconsistencias->firstWhere('id', $tratandoId))
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Tratar inconsistência</h5>
                        <button type="button" class="btn-close" wire:click="fecharModalTratar"></button>
                    </div>
                    <div class="modal-body">
                        @if ($incModal)
                            <div class="mb-3 small">
                                <div><strong>Atividade:</strong> {{ $incModal->atividade->codigo_cronograma ?? '—' }} — {{ $incModal->atividade->nome ?? '—' }}</div>
                                <div><strong>Fato importado:</strong> {{ $this->textoTipo($incModal->tipo) }}</div>
                                <div><strong>Causa (histórica):</strong> {{ $this->textoEntidade($incModal) }}</div>
                                <div><strong>Importação:</strong> {{ $incModal->cronogramaImportacao?->importado_em?->format('d/m/y H:i') ?? '—' }}</div>
                                <div><strong>Severidade:</strong> {{ $incModal->severidade->label() }}</div>
                            </div>
                        @endif

                        <p class="text-muted small">
                            A importação do cronograma é considerada o fato verdadeiro — tratar esta
                            inconsistência não altera o avanço importado, não fecha a Restrição, não
                            marca o item de Prontidão como concluído e não modifica a Programação
                            Semanal. Este registro é só uma evidência histórica de que o caso foi
                            analisado por um responsável.
                        </p>

                        @if ($tratarErro)
                            <div class="alert alert-danger py-2 small">{{ $tratarErro }}</div>
                        @endif

                        <div class="mb-2">
                            <label class="form-label">Justificativa *</label>
                            <textarea class="form-control @error('justificativaTratamento') is-invalid @enderror" rows="4" wire:model="justificativaTratamento" placeholder="Descreva o que foi verificado e por que o fato importado é aceito apesar da pendência."></textarea>
                            @error('justificativaTratamento')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalTratar">Cancelar</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmarTratamento" wire:loading.attr="disabled" wire:target="confirmarTratamento">
                            Confirmar tratamento
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
