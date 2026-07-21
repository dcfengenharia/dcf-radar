<?php

use App\Actions\ProgramacaoSemanal\CriarRevisaoProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal;
use App\Models\ProgramacaoSemanal;
use App\Models\Work;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use ExecutaComTransacaoSegura;

    public Work $obra;

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
                                    <span class="badge bg-label-{{ $cor }}">{{ number_format($aderencia, 1) }}%</span>
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
</div>

@script
<script>
    $wire.on('show-toast', ({ message, type = 'success' }) => {
        toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
        (toastr[type] || toastr.success)(message);
    });
</script>
@endscript
