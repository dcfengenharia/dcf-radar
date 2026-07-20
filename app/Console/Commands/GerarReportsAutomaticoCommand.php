<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\Tenant;
use App\Models\Work;
use App\Services\ReportGerador;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Roda diariamente: gera o RASCUNHO do Report semanal (nunca emite —
 * emissão continua manual, dupla trava rascunho/emitido preservada) pra
 * toda obra com `dia_semana_report` configurado igual ao dia de hoje.
 * Reaproveita a definição de curvas (pacote_trabalho_id/ordem) do último
 * report já existente da obra — obra sem nenhum report anterior, ou sem
 * importação de avanço disponível, é pulada sem quebrar as demais.
 */
class GerarReportsAutomaticoCommand extends Command
{
    protected $signature = 'reports:gerar-automatico';

    protected $description = 'Gera rascunho de Report semanal automaticamente para obras com dia_semana_report configurado igual ao dia de hoje.';

    public function __construct(private readonly ReportGerador $reportGerador)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $hoje = Carbon::today();

        Tenant::query()->each(function (Tenant $tenant) use ($hoje) {
            TenantContext::actingAs($tenant, function () use ($hoje) {
                $obras = Work::where('dia_semana_report', $hoje->dayOfWeek)->get();

                foreach ($obras as $obra) {
                    $this->gerarParaObra($obra, $hoje);
                }
            });
        });

        return self::SUCCESS;
    }

    private function gerarParaObra(Work $obra, Carbon $hoje): void
    {
        $periodoReferencia = $hoje->copy()->startOfWeek();

        $jaExiste = Report::where('obra_id', $obra->id)
            ->whereDate('periodo_referencia', $periodoReferencia->toDateString())
            ->exists();

        if ($jaExiste) {
            return;
        }

        $ultimoReport = Report::where('obra_id', $obra->id)
            ->with(['curvas', 'criador'])
            ->orderByDesc('periodo_referencia')
            ->first();

        if (! $ultimoReport || $ultimoReport->curvas->isEmpty()) {
            $this->warn("Obra {$obra->id}: sem report anterior pra copiar definição de curvas, pulando.");

            return;
        }

        if (! $ultimoReport->criador) {
            $this->warn("Obra {$obra->id}: usuário criador do último report não existe mais, pulando.");

            return;
        }

        $curvas = $ultimoReport->curvas->map(fn (ReportCurva $curva) => [
            'pacote_trabalho_id' => $curva->pacote_trabalho_id,
            'ordem' => $curva->ordem,
            'pontos_atencao' => [],
        ])->all();

        try {
            $this->reportGerador->gerarRascunho($obra, $ultimoReport->criador, [
                'periodo_referencia' => $periodoReferencia,
                'linha_base_id' => null,
                'avanco_importacao_id' => null,
                'titulo' => null,
                'curvas' => $curvas,
            ]);

            $this->info("Obra {$obra->id}: rascunho de report gerado automaticamente.");
        } catch (\RuntimeException $e) {
            $this->warn("Obra {$obra->id}: {$e->getMessage()}");
        }
    }
}
