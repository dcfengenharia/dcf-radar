<?php

use App\Actions\ProgramacaoSemanal\CriarRevisaoProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\SalvarRealizadoProgramacaoSemanalItem;
use App\Models\ProgramacaoSemanal;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Work;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use ExecutaComTransacaoSegura;

    public Work $obra;

    // ---- Modal de itens / lançamento de HH Realizado ----
    public ?string $programacaoDetalheId = null;
    public array $hhRealizadoForm = [];
    public array $erroRealizado = [];

    public function mount(Work $obra): void
    {
        $this->obra = $obra;
    }

    /**
     * Uma linha por semana, sempre a versão VIGENTE (mais recente) —
     * ordena por semana_inicio desc, versao desc, e pega a primeira
     * ocorrência de cada semana_inicio (garantido pela ordenação: dentro
     * de cada grupo de semana as linhas já vêm com a maior versão
     * primeiro).
     */
    #[Computed]
    public function programacoes(): \Illuminate\Support\Collection
    {
        return ProgramacaoSemanal::where('obra_id', $this->obra->id)
            ->withCount('itens')
            ->with('fechadoPor:id,first_name,last_name')
            ->orderByDesc('semana_inicio')
            ->orderByDesc('versao')
            ->get()
            ->unique('semana_inicio')
            ->values();
    }

    public function aderencia(ProgramacaoSemanal $programacao): ?float
    {
        return $programacao->aderencia();
    }

    public function fecharProgramacao(string $id): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'restricoes.minhas_programacoes', 'editar'), 403);

        $programacao = ProgramacaoSemanal::findOrFail($id);

        $this->transacaoSegura(fn () => (new FecharProgramacaoSemanal)->execute($programacao));

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        unset($this->programacoes);
        $this->dispatch('show-toast', message: 'Programação fechada.');
    }

    public function criarRevisao(string $id): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'restricoes.minhas_programacoes', 'editar'), 403);

        $original = ProgramacaoSemanal::findOrFail($id);

        $this->transacaoSegura(fn () => (new CriarRevisaoProgramacaoSemanal)->execute($original));

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        unset($this->programacoes);
        $this->dispatch('show-toast', message: 'Revisão criada — abra o Plano Semanal desta semana pra ajustar.');
    }

    /** Header (semana/status) da programação aberta no modal — reaproveita $this->programacoes já carregada, sem query extra. */
    #[Computed]
    public function programacaoDetalhe(): ?ProgramacaoSemanal
    {
        if (! $this->programacaoDetalheId) {
            return null;
        }

        return $this->programacoes->firstWhere('id', $this->programacaoDetalheId);
    }

    /**
     * Itens da programação aberta no modal de detalhe, com a atividade
     * (disciplina/responsável/HH total) já carregada — evita N+1 ao
     * montar a tabela do modal.
     */
    #[Computed]
    public function itensDaProgramacaoDetalhe(): \Illuminate\Support\Collection
    {
        if (! $this->programacaoDetalheId) {
            return collect();
        }

        return ProgramacaoSemanalItem::with(['atividade.disciplina', 'atividade.responsavel', 'realizadoPor'])
            ->where('programacao_semanal_id', $this->programacaoDetalheId)
            ->get();
    }

    public function abrirDetalhe(string $id): void
    {
        $this->programacaoDetalheId = $id;
        $this->erroRealizado = [];
        $this->hhRealizadoForm = $this->itensDaProgramacaoDetalhe
            ->mapWithKeys(fn (ProgramacaoSemanalItem $item) => [
                $item->id => $item->hh_realizado !== null ? (string) $item->hh_realizado : '',
            ])
            ->all();

        // Modal só abre DEPOIS que os itens já foram carregados no
        // servidor (round-trip completo) — nunca via data-bs-toggle puro
        // no mesmo clique que dispara wire:click. Mesma correção de bug
        // real já aplicada no Plano Semanal (⚡plano-semanal.blade.php):
        // abrir o modal em paralelo com o morph do Livewire pode deixar
        // o conteúdo do modal desatualizado ou disparar o mesmo bug de
        // backdrop preso já documentado no layout base do projeto.
        $this->dispatch('abrir-modal-itens-programacao');
    }

    public function fecharDetalhe(): void
    {
        $this->programacaoDetalheId = null;
        $this->hhRealizadoForm = [];
        $this->erroRealizado = [];
    }

    /**
     * Lança o HH Realizado de UM item — salvamento por linha (não em
     * lote), pra o erro de validação (ex.: HH acima do total) ficar
     * isolado naquela linha específica, sem invalidar o que já foi
     * digitado nas outras.
     */
    public function salvarRealizado(string $itemId): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'restricoes.minhas_programacoes', 'editar'), 403);

        unset($this->erroRealizado[$itemId]);

        $item = ProgramacaoSemanalItem::with(['atividade', 'programacaoSemanal'])->findOrFail($itemId);

        $valorDigitado = trim($this->hhRealizadoForm[$itemId] ?? '');
        $hhRealizado = $valorDigitado === '' ? null : (float) str_replace(',', '.', $valorDigitado);

        try {
            (new SalvarRealizadoProgramacaoSemanalItem)->execute($item, $hhRealizado, Auth::id());
        } catch (\Throwable $e) {
            $this->erroRealizado[$itemId] = $e->getMessage();
            return;
        }

        unset($this->itensDaProgramacaoDetalhe);
        unset($this->programacoes);
        $this->dispatch('show-toast', message: 'HH realizado salvo.');
    }
};
?>

<div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Semana</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Versão</th>
                        <th class="text-center">Itens</th>
                        <th class="text-center">% Aderência</th>
                        <th>Fechada em / por</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->programacoes as $prog)
                        @php $aderencia = $this->aderencia($prog); @endphp
                        <tr wire:key="prog-{{ $prog->id }}">
                            <td>
                                <strong>{{ $prog->semana_inicio->format('d/m/Y') }}</strong>
                                até {{ $prog->semana_fim->format('d/m/Y') }}
                                <div class="text-muted small">Semana {{ $prog->semana_inicio->weekOfYear }}</div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-label-{{ $prog->status->corBadge() }}">{{ $prog->status->label() }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-label-dark" @if ($prog->versao > 1) title="Revisão de v{{ $prog->versao - 1 }}" @endif>
                                    v{{ $prog->versao }}
                                </span>
                            </td>
                            <td class="text-center">{{ $prog->itens_count }}</td>
                            <td class="text-center">
                                @if ($aderencia === null)
                                    <span class="text-muted">—</span>
                                @else
                                    @php $cor = $aderencia >= 80 ? 'success' : ($aderencia >= 60 ? 'warning' : 'danger'); @endphp
                                    <span class="badge bg-label-{{ $cor }}">{{ number_format($aderencia, 1, ',', '.') }}%</span>
                                @endif
                            </td>
                            <td>
                                @if ($prog->fechada_em)
                                    <small>{{ $prog->fechada_em->format('d/m/Y H:i') }}<br>{{ $prog->fechadoPor?->first_name }} {{ $prog->fechadoPor?->last_name }}</small>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="d-flex gap-1 justify-content-end">
                                    <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2"
                                            wire:click="abrirDetalhe('{{ $prog->id }}')" wire:loading.attr="disabled">
                                        <i class="bx bx-time-five"></i> Itens / Realizado
                                    </button>
                                    <a href="{{ route('radar.plano-semanal', ['semana' => $prog->semana_inicio->toDateString()]) }}"
                                       class="btn btn-xs btn-outline-secondary py-0 px-2">
                                        <i class="bx bx-show"></i> Ver detalhe
                                    </a>
                                    @if ($prog->estaFechada())
                                        <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2"
                                                wire:click="criarRevisao('{{ $prog->id }}')"
                                                wire:confirm="Criar uma revisão desta programação? A nova versão nasce com as mesmas atividades, com datas/HH atualizados.">
                                            <i class="bx bx-git-branch"></i> Criar Revisão
                                        </button>
                                    @elseif ($prog->itens_count > 0)
                                        <button type="button" class="btn btn-xs btn-outline-success py-0 px-2"
                                                wire:click="fecharProgramacao('{{ $prog->id }}')"
                                                wire:confirm="Fechar a programação desta semana?">
                                            <i class="bx bx-lock-alt"></i> Gerar Programação
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">Nenhuma programação semanal ainda. Comprometa atividades pelo Plano Semanal ou Lookahead.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal Itens / Realizado — mesma estrutura visual dos demais
         modais do sistema (Confirmar Programação, Não Concluído). --}}
    <div class="modal fade" id="modalItensProgramacao" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bx bx-time-five me-2"></i>Itens da Programação
                        @if ($this->programacaoDetalhe)
                            — Semana {{ $this->programacaoDetalhe->semana_inicio->format('d/m/Y') }} a {{ $this->programacaoDetalhe->semana_fim->format('d/m/Y') }}
                        @endif
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if ($this->programacaoDetalhe)
                        @php $fechada = $this->programacaoDetalhe->estaFechada(); @endphp
                        @if ($fechada)
                            <div class="alert alert-secondary py-2 small mb-3">
                                <i class="bx bx-lock-alt me-1"></i>Programação fechada — somente consulta, HH realizado não pode mais ser alterado.
                            </div>
                        @endif
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Atividade</th>
                                        <th>Disciplina</th>
                                        <th>Responsável</th>
                                        <th class="text-end">HH Previsto</th>
                                        <th class="text-end">% Previsto</th>
                                        <th class="text-end" style="min-width: 140px">HH Realizado</th>
                                        <th class="text-end">% Realizado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($this->itensDaProgramacaoDetalhe as $item)
                                        @php
                                            $hhTotal = (float) ($item->atividade?->work_horas ?? 0);
                                            $percentualPrevisto = ($item->horas_previstas_congeladas !== null && $hhTotal > 0)
                                                ? round(((float) $item->horas_previstas_congeladas / $hhTotal) * 100, 2)
                                                : null;
                                            $percentualRealizado = $item->percentualRealizado();
                                        @endphp
                                        <tr wire:key="item-detalhe-{{ $item->id }}">
                                            <td>{{ $item->atividade?->nome ?? '—' }}</td>
                                            <td>{{ $item->atividade?->disciplina?->nome ?? '—' }}</td>
                                            <td>{{ $item->atividade?->responsavel?->name ?? '—' }}</td>
                                            <td class="text-end">
                                                <small>{{ $item->horas_previstas_congeladas !== null ? number_format((float) $item->horas_previstas_congeladas, 2, ',', '.') : '—' }}</small>
                                            </td>
                                            <td class="text-end">
                                                <small class="{{ $percentualPrevisto === null ? 'text-muted' : '' }}">
                                                    {{ $percentualPrevisto !== null ? number_format($percentualPrevisto, 2, ',', '.') . '%' : '—' }}
                                                </small>
                                            </td>
                                            <td>
                                                @if ($fechada)
                                                    <div class="text-end">
                                                        <small>{{ $item->hh_realizado !== null ? number_format((float) $item->hh_realizado, 2, ',', '.') : '—' }}</small>
                                                    </div>
                                                @else
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" inputmode="decimal" class="form-control text-end"
                                                               wire:model="hhRealizadoForm.{{ $item->id }}"
                                                               placeholder="0,00">
                                                        <button type="button" class="btn btn-outline-success"
                                                                wire:click="salvarRealizado('{{ $item->id }}')"
                                                                wire:loading.attr="disabled" title="Salvar HH realizado">
                                                            <i class="bx bx-check"></i>
                                                        </button>
                                                    </div>
                                                    @if (isset($erroRealizado[$item->id]))
                                                        <small class="text-danger d-block mt-1">{{ $erroRealizado[$item->id] }}</small>
                                                    @endif
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ($percentualRealizado === null)
                                                    <small class="text-muted">—</small>
                                                @else
                                                    @php $corRealizado = $percentualRealizado >= 100 ? 'success' : 'warning'; @endphp
                                                    <span class="badge bg-label-{{ $corRealizado }}">{{ number_format($percentualRealizado, 2, ',', '.') }}%</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-3">Nenhum item nesta programação.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted d-block mt-2">
                            % Previsto e % Realizado usam o HH da Tendência (Work atual) da atividade como referência —
                            atividade sem HH cadastrado no cronograma mostra "—" em vez de calcular.
                        </small>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
</div>

@script
<script>
    $wire.on('show-toast', ({ message, type = 'success' }) => {
        toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
        (toastr[type] || toastr.success)(message);
    });

    // Modal só abre DEPOIS que os itens já foram carregados no servidor
    // (abrirDetalhe) — mesma correção de bug real já aplicada no Plano
    // Semanal (⚡plano-semanal.blade.php): nunca via data-bs-toggle puro
    // no mesmo clique que dispara wire:click.
    $wire.on('abrir-modal-itens-programacao', () => {
        const modalEl = document.getElementById('modalItensProgramacao');
        if (modalEl) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    });

    // Limpa o estado (programacaoDetalheId/hhRealizadoForm) só DEPOIS que
    // o Bootstrap confirma que o modal já terminou de fechar de verdade
    // (evento nativo hidden.bs.modal) — nunca via wire:click no mesmo
    // botão que dispara data-bs-dismiss, pela mesma razão já documentada
    // no Plano Semanal: essa combinação corre em paralelo com o morph do
    // Livewire e pode deixar o .modal-backdrop preso.
    const modalItensEl = document.getElementById('modalItensProgramacao');
    modalItensEl?.addEventListener('hidden.bs.modal', () => {
        $wire.call('fecharDetalhe');
    });
</script>
@endscript
