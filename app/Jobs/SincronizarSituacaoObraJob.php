<?php

namespace App\Jobs;

use App\Models\Work;
use App\Support\Gestao\SincronizarSituacoesGerenciais;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ciclo 21, Etapa 21.4 — unidade de execução do sincronizador POR OBRA
 * (Seção 4 do pedido: "Job por obra" em vez de uma única execução global
 * gigantesca — isolamento de falha real, cada obra é uma unidade de
 * trabalho independente na fila).
 *
 * **`ShouldBeUnique` é o lock (Seção 4/14)** — feature nativa do
 * Laravel, nunca usada antes neste projeto (os digests existentes,
 * `NotificarPendenciasGedCommand`/`NotificarProntidaoSemanalCommand`,
 * usam `Cache::lock()` manual porque rodam DENTRO de um `Command`
 * síncrono, nunca como Job de fila próprio) — aqui o Job É a unidade de
 * fila, então a primitiva idiomática certa é `ShouldBeUnique`:
 * `uniqueId()` = obra, `uniqueFor` = TTL de segurança (nunca mais que o
 * intervalo entre ticks do coordenador, senão um tick novo e legítimo
 * seria descartado achando que o anterior ainda está rodando). O lock é
 * liberado assim que o job termina de processar (sucesso ou falha
 * definitiva), `uniqueFor` só protege contra o caso do job travar sem
 * nunca terminar.
 *
 * **Retry seguro (Seção 4/13)**: `$tries = 3` (mesmo `--tries=3` já
 * configurado no worker `queue:work` do `docker-compose.yml`) — seguro
 * porque `SincronizarSituacoesGerenciais::sincronizarObra()` já é
 * IDEMPOTENTE por construção (21.3): reprocessar a MESMA obra do zero
 * nunca duplica ocorrência nem comunicação.
 *
 * **`TenantContext::actingAs()` dentro de `handle()`, nunca no
 * dispatch** — mesmo padrão de `ImportarCronogramaJob`: o worker que
 * processa este job não tem nenhum usuário autenticado (dispatch e
 * execução são processos DIFERENTES).
 */
class SincronizarSituacaoObraJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** TTL de segurança do lock de unicidade — nunca o mecanismo de retry em si. */
    public int $uniqueFor = 900;

    public function __construct(
        private readonly Work $obra,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->obra->id;
    }

    public function handle(): void
    {
        TenantContext::actingAs($this->obra->tenant, function () {
            SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        });
    }
}
