<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ProntidaoSemanalNotification;
use App\Services\DigestProntidao;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Ciclo 16, Etapa A.4 — orquestrador do Digest Semanal de Prontidão.
 * Roda semanalmente (Kernel.php): pra cada obra de cada tenant, consulta
 * `DigestProntidao::consolidar()` (Etapa A.2 — única fonte de verdade de
 * prontidão, nunca reimplementada aqui), e se houver pendências, resolve
 * destinatários e envia `ProntidaoSemanalNotification` (Etapa A.3 — a
 * Notification é usada exatamente como criada, sem reconstrução de
 * mensagem/snapshot aqui).
 *
 * Mesmo padrão estrutural de `GerarReportsAutomaticoCommand`/
 * `RecalcularStatusSuprimentos`: `Tenant::query()->each()` →
 * `TenantContext::actingAs()` → obras do tenant, com isolamento de falha
 * por obra (try/catch em volta de cada obra, nunca deixando uma obra
 * quebrada derrubar as demais).
 */
class NotificarProntidaoSemanalCommand extends Command
{
    protected $signature = 'prontidao:notificar-semanal';

    protected $description = 'Envia o Digest Semanal de Prontidão (obras com atividades Não Prontas/Em Atenção nos próximos 30 dias) para os usuários autorizados de cada obra.';

    private const TTL_LOCK_SEGUNDOS = 30;

    private const TTL_MARCADOR_ENVIADO = 14; // dias — folga além da semana em si, evita crescimento indefinido de chaves

    public function __construct(
        private readonly DigestProntidao $digestProntidao,
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
                        $this->error("Obra {$obra->id}: erro ao processar o digest — {$e->getMessage()}");
                    }
                }
            });
        });

        return self::SUCCESS;
    }

    /**
     * Protegido por lock atômico (`Cache::lock()`, backend Redis em
     * produção — mesmo store já usado pra queue/cache no projeto,
     * primeira vez que esse mecanismo é usado no código, mas sem
     * infraestrutura nova) contra duas execuções concorrentes da MESMA
     * obra na MESMA semana. O marcador de "já enviado" só é gravado
     * DEPOIS de `Notification::send()` retornar sem lançar exceção —
     * uma falha no meio do processamento nunca marca a semana como
     * entregue (permite nova tentativa numa execução seguinte).
     */
    private function processarObra(Work $obra): void
    {
        $anoSemana = Carbon::now()->format('oW');
        $lock = Cache::lock($this->chaveLock($obra, $anoSemana), self::TTL_LOCK_SEGUNDOS);

        if (! $lock->get()) {
            $this->warn("Obra {$obra->id}: outra execução já está processando esta obra nesta semana, pulando.");

            return;
        }

        try {
            $chaveEnviado = $this->chaveEnviado($obra, $anoSemana);

            if (Cache::has($chaveEnviado)) {
                $this->info("Obra {$obra->id}: digest já enviado nesta semana ({$anoSemana}), pulando.");

                return;
            }

            $resumo = $this->digestProntidao->consolidar($obra);

            if (! $resumo->temPendencias) {
                $this->info("Obra {$obra->id}: sem pendências no horizonte, nada a notificar.");

                return;
            }

            $destinatarios = $this->resolverDestinatarios($obra);

            if ($destinatarios->isEmpty()) {
                $this->warn("Obra {$obra->id}: {$resumo->totalExigeAtencao} atividade(s) exigem atenção, mas nenhum destinatário elegível — pulando.");

                return;
            }

            Notification::send($destinatarios, new ProntidaoSemanalNotification($resumo));

            Cache::put($chaveEnviado, true, now()->addDays(self::TTL_MARCADOR_ENVIADO));

            $this->info("Obra {$obra->id}: digest enviado para {$destinatarios->count()} destinatário(s) ({$resumo->totalExigeAtencao} atividade(s) exigem atenção).");
        } finally {
            $lock->release();
        }
    }

    /**
     * Composição obrigatória (Ciclo 16, A.4, seção 4): vinculado à obra +
     * ativo + permissão `restricoes.central_prontidao|ver`. Resolvida
     * INTEIRAMENTE nesta execução — nunca cacheada entre execuções —
     * então um usuário que perdeu acesso/permissão desde a semana
     * anterior nunca é incluído. `$obra->users()` já escopa por
     * `work_id` (obra_user), então um usuário vinculado só a OUTRA obra
     * nunca entra no conjunto candidato desta obra.
     */
    private function resolverDestinatarios(Work $obra)
    {
        return $obra->users()
            ->where('users.ativo', true)
            ->get()
            ->filter(fn (User $user) => $user->temPermissaoNaObra($obra, 'restricoes.central_prontidao', 'ver'))
            ->values();
    }

    private function chaveLock(Work $obra, string $anoSemana): string
    {
        return "prontidao:digest:lock:{$obra->id}:{$anoSemana}";
    }

    private function chaveEnviado(Work $obra, string $anoSemana): string
    {
        return "prontidao:digest:enviado:{$obra->id}:{$anoSemana}";
    }
}
