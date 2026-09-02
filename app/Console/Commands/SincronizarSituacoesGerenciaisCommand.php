<?php

namespace App\Console\Commands;

use App\Jobs\SincronizarSituacaoObraJob;
use App\Models\Tenant;
use App\Models\Work;
use App\Support\Gestao\SincronizarSituacoesGerenciais;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Ciclo 21, Etapa 21.3/21.4 — coordenador do sincronizador de Situações
 * Gerenciais. Desde a 21.4 (Seção 4 do pedido), NUNCA processa uma obra
 * diretamente por padrão — despacha 1 `App\Jobs\SincronizarSituacaoObraJob`
 * por obra elegível (isolamento de falha real + lock de unicidade nativo
 * do Laravel via `ShouldBeUnique`, ver docblock do Job). `--sync` e
 * `--dry-run` existem só pra operação/depuração manual (Seção 23).
 *
 * **Nunca carrega todas as obras de todos os tenants em memória de uma
 * vez** (Seção 26): `Tenant::query()->each()`/`Work::query()->each()`
 * (Eloquent, sob o capô, processa em chunks — nunca materializa a
 * coleção inteira) — mesmo padrão já usado em `NotificarPendenciasGedCommand`.
 */
class SincronizarSituacoesGerenciaisCommand extends Command
{
    protected $signature = 'gestao:sincronizar-situacoes
        {--obra= : Sincroniza só esta obra (ULID) em vez de todas as elegíveis}
        {--sync : Roda de forma síncrona no processo atual, sem enfileirar Job (nunca some com o resultado)}
        {--dry-run : NUNCA escreve nada — mostra o que uma execução real faria (situações, destinatários, canais)}';

    protected $description = 'Sincroniza o ciclo de vida das Situações Gerenciais e despacha as comunicações elegíveis, obra por obra.';

    public function handle(): int
    {
        $obraId = $this->option('obra');
        $dryRun = (bool) $this->option('dry-run');
        $sync = $dryRun || (bool) $this->option('sync');

        $totalObras = 0;
        $totalDespachadas = 0;

        $processarObra = function (Work $obra) use ($dryRun, $sync, &$totalObras, &$totalDespachadas) {
            $totalObras++;

            if ($dryRun) {
                $this->executarDryRun($obra);

                return;
            }

            if ($sync) {
                try {
                    SincronizarSituacoesGerenciais::sincronizarObra($obra);
                    $this->info("Obra {$obra->id}: sincronizada (síncrono).");
                } catch (\Throwable $e) {
                    report($e);
                    $this->error("Obra {$obra->id}: erro ao sincronizar — {$e->getMessage()}");
                }

                return;
            }

            SincronizarSituacaoObraJob::dispatch($obra);
            $totalDespachadas++;
        };

        if ($obraId) {
            $obra = Work::find($obraId);

            if (! $obra) {
                $this->error("Obra {$obraId} não encontrada.");

                return self::FAILURE;
            }

            TenantContext::actingAs($obra->tenant, fn () => $processarObra($obra));
        } else {
            Tenant::query()->each(function (Tenant $tenant) use ($processarObra) {
                TenantContext::actingAs($tenant, function () use ($processarObra) {
                    Work::query()->each($processarObra);
                });
            });
        }

        if (! $dryRun && ! $sync) {
            $this->info("{$totalObras} obra(s) elegível(is), {$totalDespachadas} job(s) despachado(s) pra fila.");
        } elseif ($sync && ! $dryRun) {
            $this->info("{$totalObras} obra(s) processada(s) sincronamente.");
        }

        return self::SUCCESS;
    }

    /**
     * Seção 24 — nunca chama `sincronizarObra()`/`processarSituacao()`;
     * `SincronizarSituacoesGerenciais::preview()` é 100% leitura.
     */
    private function executarDryRun(Work $obra): void
    {
        $preview = SincronizarSituacoesGerenciais::preview($obra);

        $this->line("=== Obra {$obra->id} ({$obra->name}) ===");

        $relevantes = array_filter($preview['situacoes'], fn (array $s) => $s['motivo_previsto'] !== 'nenhuma_comunicacao_nova');

        if (empty($relevantes) && empty($preview['resolveriam'])) {
            $this->line('  Nenhuma comunicação nova e nenhuma resolução prevista.');

            return;
        }

        foreach ($relevantes as $s) {
            $destinatarios = empty($s['destinatarios']) ? '(nenhum destinatário elegível)' : implode(', ', $s['destinatarios']);
            $this->line("  [{$s['motivo_previsto']}] {$s['tipo']} (severidade: {$s['severidade']}) — canais: ".implode(',', $s['canais_elegiveis'])." — destinatários: {$destinatarios}");
        }

        foreach ($preview['resolveriam'] as $r) {
            $this->line("  [resolveria] {$r['tipo']} ({$r['chave_logica']})");
        }
    }
}
