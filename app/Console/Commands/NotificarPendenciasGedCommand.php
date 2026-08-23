<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\GrdPendenciasDigestNotification;
use App\Services\DigestPendenciasGed;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Ciclo 18, Etapa 18.5.7 — orquestrador do Digest Semanal de Pendências
 * GED. Roda semanalmente (Kernel.php): pra cada obra de cada tenant,
 * consulta `App\Services\DigestPendenciasGed::consolidar()` (única fonte
 * de verdade das contagens — nunca reimplementada aqui), e se houver
 * pendências, resolve destinatários e envia
 * `GrdPendenciasDigestNotification`.
 *
 * **Estrutura, cadência e idempotência são uma cópia deliberada de
 * `App\Console\Commands\NotificarProntidaoSemanalCommand`** (Ciclo 16,
 * A.4, o único precedente de digest periódico já em produção) — decisão
 * explícita do usuário na etapa 18.5.7: mesma cadência (segunda-feira
 * 08:00, sem `->timezone()` explícito — mesmo padrão UTC de TODOS os
 * comandos já agendados em `Kernel.php`, nenhuma exceção criada aqui) e
 * mesmo mecanismo de idempotência por ano-semana
 * (`Carbon::now()->format('oW')`, `Cache::lock()` + `Cache::put()`),
 * nunca uma segunda convenção de horário/cadência no projeto. Mesmo
 * padrão estrutural de `GerarReportsAutomaticoCommand`/
 * `RecalcularStatusSuprimentos`/`NotificarProntidaoSemanalCommand`:
 * `Tenant::query()->each()` → `TenantContext::actingAs()` (tenant-safe
 * sem depender de usuário autenticado — mesma lição do ACHADO C da
 * 18.5.6.HARDENING) → obras do tenant, com isolamento de falha por obra
 * (try/catch em volta de cada obra, nunca deixando uma obra quebrada
 * derrubar as demais).
 *
 * **Canais — decisão explícita do usuário**: só `database`+`broadcast`
 * (ver docblock de `GrdPendenciasDigestNotification`) — nenhum ledger
 * novo, nenhuma alteração em `grd_alerta_entregas`, nenhuma migration.
 * Os Alertas A/B imediatos continuam sendo os únicos responsáveis por
 * mail/WhatsApp na GRD.
 */
class NotificarPendenciasGedCommand extends Command
{
    protected $signature = 'engenharia:notificar-pendencias-grd';

    protected $description = 'Envia o Digest Semanal de Pendências GED (cópias obsoletas em campo / candidatos a nova entrega) para os usuários autorizados de cada obra.';

    private const TTL_LOCK_SEGUNDOS = 30;

    private const TTL_MARCADOR_ENVIADO = 14; // dias — folga além da semana em si, evita crescimento indefinido de chaves

    public function __construct(
        private readonly DigestPendenciasGed $digest,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Tenant::query()->each(function (Tenant $tenant) {
            TenantContext::actingAs($tenant, function () use ($tenant) {
                $obras = Work::where('tenant_id', $tenant->id)->get();

                foreach ($obras as $obra) {
                    try {
                        $this->processarObra($obra);
                    } catch (\Throwable $e) {
                        report($e);
                        $this->error("Obra {$obra->id}: erro ao processar o digest GED — {$e->getMessage()}");
                    }
                }
            });
        });

        return self::SUCCESS;
    }

    /**
     * Protegido por lock atômico (`Cache::lock()`) contra duas execuções
     * concorrentes da MESMA obra na MESMA semana — mesmo mecanismo de
     * `NotificarProntidaoSemanalCommand::processarObra()`. O marcador de
     * "já enviado" só é gravado DEPOIS de `Notification::send()` retornar
     * sem lançar exceção — uma falha no meio do processamento nunca marca
     * a semana como entregue (permite nova tentativa numa execução
     * seguinte, inclusive manual).
     */
    private function processarObra(Work $obra): void
    {
        $anoSemana = Carbon::now()->format('oW');
        $lock = Cache::lock($this->chaveLock($obra, $anoSemana), self::TTL_LOCK_SEGUNDOS);

        if (! $lock->get()) {
            $this->warn("Obra {$obra->id}: outra execução já está processando o digest GED desta obra nesta semana, pulando.");

            return;
        }

        try {
            $chaveEnviado = $this->chaveEnviado($obra, $anoSemana);

            if (Cache::has($chaveEnviado)) {
                $this->info("Obra {$obra->id}: digest GED já enviado nesta semana ({$anoSemana}), pulando.");

                return;
            }

            $resumo = $this->digest->consolidar($obra);

            if (! $resumo->temPendencias()) {
                $this->info("Obra {$obra->id}: sem pendências GED, nada a notificar.");

                return;
            }

            $destinatarios = $this->resolverDestinatarios($obra);

            if ($destinatarios->isEmpty()) {
                $this->warn("Obra {$obra->id}: há pendências GED, mas nenhum destinatário elegível — pulando.");

                return;
            }

            Notification::send($destinatarios, new GrdPendenciasDigestNotification(
                $resumo->obraId,
                $resumo->obraNome,
                $resumo->obsoletasDocumentos,
                $resumo->obsoletasDestinatarios,
                $resumo->obsoletasQuantidadeFisica,
                $resumo->candidatosDocumentos,
                $resumo->candidatosDestinatarios,
            ));

            Cache::put($chaveEnviado, true, now()->addDays(self::TTL_MARCADOR_ENVIADO));

            $this->info("Obra {$obra->id}: digest GED enviado para {$destinatarios->count()} destinatário(s) ({$resumo->obsoletasDocumentos} doc. obsoletos, {$resumo->candidatosDocumentos} doc. com candidatos).");
        } finally {
            $lock->release();
        }
    }

    /**
     * Composição obrigatória: vinculado à obra + ativo + permissão
     * `engenharia.pacotes|ver` — MESMA composição de
     * `App\Support\Grd\AlertaDistribuicaoGrd::usuariosComPermissaoNaObra()`
     * (Alertas A/B imediatos), nunca `temPermissaoEmAlgumaObraDoTenant()`.
     * Resolvida INTEIRAMENTE nesta execução — nunca cacheada entre
     * execuções — então um usuário que perdeu acesso/permissão desde a
     * semana anterior nunca é incluído.
     */
    private function resolverDestinatarios(Work $obra)
    {
        return $obra->users()
            ->where('users.ativo', true)
            ->get()
            ->filter(fn (User $user) => $user->temPermissaoNaObra($obra, 'engenharia.pacotes', 'ver'))
            ->values();
    }

    private function chaveLock(Work $obra, string $anoSemana): string
    {
        return "grd:digest:lock:{$obra->id}:{$anoSemana}";
    }

    private function chaveEnviado(Work $obra, string $anoSemana): string
    {
        return "grd:digest:enviado:{$obra->id}:{$anoSemana}";
    }
}
