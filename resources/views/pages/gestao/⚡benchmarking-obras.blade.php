<?php

use App\Enums\GranularidadePeriodo;
use App\Enums\PilarLean;
use App\Enums\SerieAvanco;
use App\Enums\StatusReport;
use App\Enums\StatusRestricao;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Report;
use App\Models\Restricao;
use App\Models\Work;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Compara indicadores já calculados em outras telas lado a lado, uma
 * coluna por obra do tenant — não recalcula nada novo, só reagrega:
 * PPC histórico (mesma query de ⚡relatorios-restricoes.blade.php::ppcQuery()),
 * % avanço atual (mesma fonte que ⚡dashboard.blade.php::resumoAderencia()
 * usa — a curva raiz do último Report EMITIDO, filosofia de fotografia,
 * nunca CurvaAvanco ao vivo), restrições abertas por pilar Lean e tempo
 * médio de resolução (mesmas fórmulas de ⚡relatorios-restricoes.blade.php).
 * Página tenant-level (ESCOPO_TENANT em CatalogoFuncionalidades), fora
 * do contexto de uma obra selecionada — mesmo padrão de Cadastros.
 */
new class extends Component {
    public function mount(): void
    {
        abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('gestao.benchmarking', 'ver'), 403);
    }

    #[Computed]
    public function obras()
    {
        return Work::orderBy('name')->get();
    }

    #[Computed]
    public function indicadoresPorObra(): array
    {
        return $this->obras->map(fn (Work $obra) => [
            'obra' => $obra,
            'ppcHistorico' => $this->ppcHistorico($obra->id),
            'avancoAtual' => $this->avancoAtualDoUltimoReportEmitido($obra->id),
            'restricoesAbertasPorPilar' => $this->restricoesAbertasPorPilar($obra->id),
            'tempoMedioResolucaoDias' => $this->tempoMedioResolucaoDias($obra->id),
        ])->all();
    }

    /** Mesma fórmula/ancoragem de ppcQuery()+ppcPorSemana() em ⚡relatorios-restricoes.blade.php, só que agregada num único número (não por semana). */
    private function ppcHistorico(string $obraId): ?float
    {
        $linha = ProgramacaoSemanalItem::query()
            ->join('programacoes_semanais as ps', 'ps.id', '=', 'programacao_semanal_itens.programacao_semanal_id')
            ->join('atividades as a', function ($join) {
                $join->on('a.id', '=', 'programacao_semanal_itens.atividade_id')->whereNull('a.deleted_at');
            })
            ->where('ps.obra_id', $obraId)
            ->where('ps.semana_fim', '<', now()->startOfWeek()->toDateString())
            ->selectRaw('COUNT(*) as comprometidas, SUM(a.concluido_em IS NOT NULL AND DATE(a.concluido_em) <= ps.semana_fim) as concluidas_no_prazo')
            ->first();

        $comprometidas = (int) ($linha->comprometidas ?? 0);
        if ($comprometidas === 0) {
            return null;
        }

        return round(((int) $linha->concluidas_no_prazo / $comprometidas) * 100, 1);
    }

    /**
     * Mesma fonte que ⚡dashboard.blade.php::resumoAderencia() usa —
     * curva raiz (pacote_trabalho_id null) do último Report EMITIDO,
     * última semana com Realizado gravado. Nunca recalcula ao vivo
     * (filosofia de "Report é fotografia").
     */
    private function avancoAtualDoUltimoReportEmitido(string $obraId): ?float
    {
        $report = Report::where('obra_id', $obraId)
            ->where('status', StatusReport::Emitido->value)
            ->with('curvas.datapoints')
            ->latest('periodo_referencia')
            ->first();

        $curva = $report?->curvas->firstWhere('pacote_trabalho_id', null);
        if (! $curva) {
            return null;
        }

        $ultimoRealizado = $curva->datapoints
            ->where('granularidade', GranularidadePeriodo::Semanal)
            ->where('serie', SerieAvanco::Realizado)
            ->sortByDesc(fn ($d) => $d->periodo_inicio)
            ->first();

        return $ultimoRealizado ? (float) $ultimoRealizado->percentual_acumulado : null;
    }

    /** Mesma fórmula de porPilar() em ⚡relatorios-restricoes.blade.php. */
    private function restricoesAbertasPorPilar(string $obraId): array
    {
        $linhas = Restricao::join('atividades as a', function ($join) use ($obraId) {
            $join->on('restricoes.atividade_id', '=', 'a.id')
                ->whereNull('a.deleted_at')
                ->where('a.obra_id', $obraId);
        })
            ->join('categorias_restricao as cat', 'cat.id', '=', 'restricoes.categoria_id')
            ->where('restricoes.status', '!=', StatusRestricao::Resolvida->value)
            ->selectRaw('cat.pilar_lean as pilar, COUNT(*) as total')
            ->groupBy('cat.pilar_lean')
            ->pluck('total', 'pilar');

        $dados = [];
        foreach (PilarLean::cases() as $pilar) {
            $dados[$pilar->value] = (int) ($linhas[$pilar->value] ?? 0);
        }

        return $dados;
    }

    /** Mesma fórmula de tempoMedioResolucao()['geral'] em ⚡relatorios-restricoes.blade.php. */
    private function tempoMedioResolucaoDias(string $obraId): ?float
    {
        $media = Restricao::join('atividades as a', function ($join) use ($obraId) {
            $join->on('restricoes.atividade_id', '=', 'a.id')
                ->whereNull('a.deleted_at')
                ->where('a.obra_id', $obraId);
        })
            ->where('restricoes.status', StatusRestricao::Resolvida->value)
            ->whereNotNull('restricoes.resolvida_em')
            ->whereNotNull('restricoes.aberta_em')
            ->selectRaw('AVG(DATEDIFF(restricoes.resolvida_em, restricoes.aberta_em)) as media_dias')
            ->value('media_dias');

        return $media !== null ? round((float) $media, 1) : null;
    }

    private function labelPilar(PilarLean $pilar): string
    {
        return match ($pilar) {
            PilarLean::Materiais => 'Materiais',
            PilarLean::MaoDeObra => 'Mão de Obra',
            PilarLean::Equipamentos => 'Equipamentos',
            PilarLean::Informacoes => 'Informações',
            PilarLean::CondicoesPrecedentes => 'Condições Precedentes',
        };
    }
}; ?>

<div>
    @if($this->obras->isEmpty())
    <div class="alert alert-info">Nenhuma obra cadastrada ainda.</div>
    @else
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 220px;">Indicador</th>
                    @foreach($this->indicadoresPorObra as $linha)
                    <th class="text-center">{{ $linha['obra']->name }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="fw-semibold">PPC histórico</td>
                    @foreach($this->indicadoresPorObra as $linha)
                    <td class="text-center">
                        {{ $linha['ppcHistorico'] !== null ? number_format($linha['ppcHistorico'], 1) . '%' : '—' }}
                    </td>
                    @endforeach
                </tr>
                <tr>
                    <td class="fw-semibold">% Avanço atual</td>
                    @foreach($this->indicadoresPorObra as $linha)
                    <td class="text-center">
                        {{ $linha['avancoAtual'] !== null ? number_format($linha['avancoAtual'], 1) . '%' : '—' }}
                    </td>
                    @endforeach
                </tr>
                <tr>
                    <td class="fw-semibold">Tempo médio de resolução</td>
                    @foreach($this->indicadoresPorObra as $linha)
                    <td class="text-center">
                        {{ $linha['tempoMedioResolucaoDias'] !== null ? number_format($linha['tempoMedioResolucaoDias'], 1) . ' dias' : '—' }}
                    </td>
                    @endforeach
                </tr>
                <tr>
                    <td colspan="{{ count($this->indicadoresPorObra) + 1 }}" class="table-light fw-semibold">
                        Restrições em aberto por Pilar Lean
                    </td>
                </tr>
                @foreach(\App\Enums\PilarLean::cases() as $pilar)
                <tr>
                    <td class="ps-4">{{ $this->labelPilar($pilar) }}</td>
                    @foreach($this->indicadoresPorObra as $linha)
                    <td class="text-center">{{ $linha['restricoesAbertasPorPilar'][$pilar->value] }}</td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
