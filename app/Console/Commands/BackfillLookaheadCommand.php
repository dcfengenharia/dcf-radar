<?php

namespace App\Console\Commands;

use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\Disciplina;
use App\Models\Etapa;
use App\Models\FrenteTrabalho;
use App\Models\Work;
use App\Support\TenantContext;
use App\Support\TextoCustomizado;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillLookaheadCommand extends Command
{
    protected $signature = 'lookahead:backfill {obra? : ID de uma obra específica (opcional, roda em todas se omitido)}';

    protected $description = 'Classifica retroativamente disciplina/frente de trabalho/etapa (a partir do textos já salvo) '
        . 'e cria o snapshot da importação mais recente — tudo para atividades importadas antes dessas features existirem. '
        . 'Ciclo 17, A.9.1: NÃO resolve mais restrições/itens de prontidão automaticamente — pendências operacionais '
        . 'permanecem intocadas mesmo para atividades já 100% concluídas (ver App\Support\ConclusaoAutomaticaAtividades).';

    public function handle(): int
    {
        $obras = $this->argument('obra')
            ? Work::where('id', $this->argument('obra'))->get()
            : Work::all();

        foreach ($obras as $obra) {
            TenantContext::actingAs($obra->tenant, function () use ($obra) {
                $this->classificarAtividades($obra);
                $this->criarSnapshotDaUltimaImportacao($obra);
            });
        }

        $this->info('Backfill concluído.');

        return self::SUCCESS;
    }

    private function classificarAtividades(Work $obra): void
    {
        $mapaDisciplinas = [];
        $mapaFrentes     = [];
        $mapaEtapas      = [];
        $classificadas   = 0;

        Atividade::where('obra_id', $obra->id)
            ->whereNotNull('textos')
            ->where(function ($q) {
                $q->whereNull('disciplina_id')
                    ->orWhereNull('frente_trabalho_id')
                    ->orWhereNull('etapa_id');
            })
            ->chunkById(200, function ($atividades) use ($obra, &$mapaDisciplinas, &$mapaFrentes, &$mapaEtapas, &$classificadas) {
                foreach ($atividades as $atividade) {
                    $textos = $atividade->textos ?? [];
                    $dados  = [];

                    if (! $atividade->etapa_id) {
                        $nome = TextoCustomizado::valor($textos, 20);
                        if ($nome !== '') {
                            $dados['etapa_id'] = $mapaEtapas[$nome]
                                ??= Etapa::firstOrCreate(['obra_id' => $obra->id, 'nome' => $nome])->id;
                        }
                    }

                    if (! $atividade->disciplina_id) {
                        $nome = TextoCustomizado::valor($textos, 21);
                        if ($nome !== '') {
                            $dados['disciplina_id'] = $mapaDisciplinas[$nome]
                                ??= Disciplina::firstOrCreate(['nome' => $nome])->id;
                        }
                    }

                    if (! $atividade->frente_trabalho_id) {
                        $nome = TextoCustomizado::valor($textos, 22);
                        if ($nome !== '') {
                            $dados['frente_trabalho_id'] = $mapaFrentes[$nome]
                                ??= FrenteTrabalho::firstOrCreate(['obra_id' => $obra->id, 'nome' => $nome])->id;
                        }
                    }

                    if ($dados !== []) {
                        $atividade->update($dados);
                        $classificadas++;
                    }
                }
            });

        $this->line("Obra {$obra->name}: {$classificadas} atividade(s) classificada(s).");
    }

    private function criarSnapshotDaUltimaImportacao(Work $obra): void
    {
        $importacao = CronogramaImportacao::where('obra_id', $obra->id)
            ->orderByDesc('importado_em')
            ->orderByDesc('id')
            ->first();

        if (! $importacao) {
            return;
        }

        $atividadesSemSnapshot = Atividade::where('obra_id', $obra->id)
            ->where('origem', 'ms_project')
            ->whereDoesntHave('snapshots', fn ($q) => $q->where('cronograma_importacao_id', $importacao->id))
            ->get(['id', 'inicio_planejado', 'data_termino', 'baseline_inicio', 'baseline_termino']);

        if ($atividadesSemSnapshot->isEmpty()) {
            return;
        }

        $agora = now();
        $lote  = $atividadesSemSnapshot->map(fn ($at) => [
            'id'                       => (string) Str::ulid(),
            'tenant_id'                => $obra->tenant_id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id'             => $at->id,
            'inicio_planejado'         => $at->inicio_planejado?->toDateString(),
            'data_termino'             => $at->data_termino?->toDateString(),
            'baseline_inicio'          => $at->baseline_inicio?->toDateString(),
            'baseline_termino'         => $at->baseline_termino?->toDateString(),
            'created_at'               => $agora,
            'updated_at'               => $agora,
        ])->all();

        foreach (array_chunk($lote, 1000) as $chunk) {
            DB::table('atividade_snapshots')->insert($chunk);
        }

        $this->line("Obra {$obra->name}: {$atividadesSemSnapshot->count()} snapshot(s) criado(s) para a importação de " . $importacao->importado_em->format('d/m/Y H:i') . '.');
    }
}
