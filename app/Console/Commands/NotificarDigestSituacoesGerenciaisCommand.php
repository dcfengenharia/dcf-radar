<?php

namespace App\Console\Commands;

use App\Enums\SeveridadeSituacao;
use App\Enums\StatusSituacaoOcorrencia;
use App\Models\SituacaoOcorrencia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\DigestSituacoesGerenciaisNotification;
use App\Support\Gestao\PoliticaEntregaSituacao;
use App\Support\Gestao\SituacoesGerenciaisQuery;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Ciclo 21, Etapa 21.4 — Digest Operacional DIÁRIO de Situações
 * Gerenciais (Seção 9/10 do pedido — "apenas um digest operacional
 * simples", nunca um segundo digest semanal/executivo criado sem
 * necessidade). Estrutura, cadência e idempotência são a MESMA cópia
 * deliberada já usada por `NotificarPendenciasGedCommand`/
 * `NotificarProntidaoSemanalCommand` (Cache::lock() + Cache::put() por
 * obra+dia, isolamento de falha por obra via try/catch).
 *
 * **Nunca recalcula risco** (Seção 2) — lê exclusivamente
 * `App\Models\SituacaoOcorrencia` (já mantida em dia por
 * `SincronizarSituacoesGerenciais`, rodando com muito mais frequência),
 * nunca `SituacoesGerenciaisQuery::porObra()` diretamente.
 *
 * **1 e-mail por usuário por obra, nunca por situação** (Seção 9) —
 * agrupado por domínio (`TipoSituacaoGerencial::dominio()`, 21.2),
 * priorizado por severidade → "é nova" (detectada dentro da janela) →
 * mais recente. Inclui uma seção de "resolvidas recentemente" (últimas
 * 24h) quando útil.
 */
class NotificarDigestSituacoesGerenciaisCommand extends Command
{
    protected $signature = 'gestao:digest-situacoes';

    protected $description = 'Envia o Digest Operacional diário de Situações Gerenciais (ativas elegíveis + recentemente resolvidas) para os usuários autorizados de cada obra.';

    private const TTL_LOCK_SEGUNDOS = 30;

    private const TTL_MARCADOR_ENVIADO_DIAS = 3;

    private const JANELA_HORAS = 24;

    public function handle(): int
    {
        Tenant::query()->each(function (Tenant $tenant) {
            TenantContext::actingAs($tenant, function () {
                Work::query()->each(function (Work $obra) {
                    try {
                        $this->processarObra($obra);
                    } catch (\Throwable $e) {
                        report($e);
                        $this->error("Obra {$obra->id}: erro ao processar o digest — {$e->getMessage()}");
                    }
                });
            });
        });

        return self::SUCCESS;
    }

    /**
     * Lock atômico por obra+dia (Seção 14) + marcador "já enviado" só
     * gravado DEPOIS de todos os envios tentados — uma falha no meio
     * nunca marca o dia como entregue (permite nova tentativa numa
     * execução seguinte, inclusive manual).
     */
    private function processarObra(Work $obra): void
    {
        $hoje = Carbon::now()->format('Ymd');
        $lock = Cache::lock($this->chaveLock($obra, $hoje), self::TTL_LOCK_SEGUNDOS);

        if (! $lock->get()) {
            $this->warn("Obra {$obra->id}: outra execução já está processando o digest hoje, pulando.");

            return;
        }

        try {
            $chaveEnviado = $this->chaveEnviado($obra, $hoje);

            if (Cache::has($chaveEnviado)) {
                $this->info("Obra {$obra->id}: digest já enviado hoje, pulando.");

                return;
            }

            $coletado = $this->coletar($obra);

            if (! $coletado['temConteudo']) {
                $this->info("Obra {$obra->id}: sem situações elegíveis pro digest hoje.");
                Cache::put($chaveEnviado, true, now()->addDays(self::TTL_MARCADOR_ENVIADO_DIAS));

                return;
            }

            $enviados = 0;
            foreach ($coletado['porUsuario'] as $dados) {
                $this->enviarComIdempotencia($dados['user'], $obra, $hoje, $coletado, $dados);
                $enviados++;
            }

            Cache::put($chaveEnviado, true, now()->addDays(self::TTL_MARCADOR_ENVIADO_DIAS));
            $this->info("Obra {$obra->id}: digest enviado para {$enviados} usuário(s).");
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{temConteudo: bool, porUsuario: array<string, array{user: User, porDominio: array, criticas: int, altas: int, outras: int}>, resolvidasRecentemente: array}
     */
    private function coletar(Work $obra): array
    {
        $janela = Carbon::now()->subHours(self::JANELA_HORAS);

        $ativos = SituacaoOcorrencia::where('obra_id', $obra->id)
            ->where('status', StatusSituacaoOcorrencia::Ativa->value)
            ->get()
            ->filter(fn (SituacaoOcorrencia $o) => PoliticaEntregaSituacao::para($o->tipo)->elegivelDigest)
            ->sortBy(fn (SituacaoOcorrencia $o) => [
                -$o->severidade_atual->peso(),
                $o->primeira_deteccao_em->gte($janela) ? 0 : 1,
                -$o->ultima_deteccao_em->getTimestamp(),
            ])
            ->values();

        $resolvidas = SituacaoOcorrencia::where('obra_id', $obra->id)
            ->where('status', StatusSituacaoOcorrencia::Resolvida->value)
            ->where('resolvida_em', '>=', $janela)
            ->get()
            ->filter(fn (SituacaoOcorrencia $o) => PoliticaEntregaSituacao::para($o->tipo)->elegivelDigest)
            ->values();

        if ($ativos->isEmpty() && $resolvidas->isEmpty()) {
            return ['temConteudo' => false, 'porUsuario' => [], 'resolvidasRecentemente' => []];
        }

        // 1 query pros usuários da obra — reaproveitada pra TODAS as
        // ocorrências (Seção 26: nunca 1 query por situação/usuário).
        // temPermissaoNaObra() já cacheia por instância de User
        // (HasObraPapel), então reusar os MESMOS objetos across o loop
        // evita N+1 mesmo com múltiplos perfis/situações.
        $usuariosObra = $obra->users()->where('users.ativo', true)->get();

        $resolvidasFormatadas = $resolvidas->map(fn (SituacaoOcorrencia $o) => [
            'tipo' => $o->tipo->value,
            'descricao' => $o->descricao_atual,
        ])->values()->all();

        $porUsuario = [];

        foreach ($ativos as $ocorrencia) {
            $perfis = SituacoesGerenciaisQuery::perfisParaTipo($ocorrencia->tipo);

            $elegiveis = $usuariosObra->filter(function (User $user) use ($obra, $perfis) {
                foreach ($perfis as $perfil) {
                    if ($user->temPermissaoNaObra($obra, $perfil['slug'], $perfil['acao'])) {
                        return true;
                    }
                }

                return false;
            });

            foreach ($elegiveis as $user) {
                $porUsuario[$user->id] ??= ['user' => $user, 'porDominio' => [], 'criticas' => 0, 'altas' => 0, 'outras' => 0];

                $dominio = $ocorrencia->tipo->dominio();
                $porUsuario[$user->id]['porDominio'][$dominio][] = [
                    'tipo' => $ocorrencia->tipo->value,
                    'severidade' => $ocorrencia->severidade_atual->label(),
                    'descricao' => $ocorrencia->descricao_atual,
                ];

                match ($ocorrencia->severidade_atual) {
                    SeveridadeSituacao::Critica => $porUsuario[$user->id]['criticas']++,
                    SeveridadeSituacao::Alta => $porUsuario[$user->id]['altas']++,
                    default => $porUsuario[$user->id]['outras']++,
                };
            }
        }

        return [
            'temConteudo' => ! empty($porUsuario),
            'porUsuario' => $porUsuario,
            'resolvidasRecentemente' => $resolvidasFormatadas,
        ];
    }

    /**
     * Identidade determinística por obra+dia+usuário (mesmo mecanismo de
     * `SincronizarSituacoesGerenciais::enviarComIdempotencia()`) —
     * assignada ANTES do envio, protege tanto o canal `database`
     * (PRIMARY KEY de `notifications`) quanto, via
     * `App\Notifications\Channels\SituacaoLedgerMailChannel`, o canal
     * `mail` (Seção 13 — retry nunca duplica).
     */
    private function enviarComIdempotencia(User $user, Work $obra, string $anoMesDia, array $coletado, array $dadosUsuario): void
    {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "situacao-digest:{$obra->id}:{$anoMesDia}:{$user->id}")->toString();

        if (DB::table('notifications')->where('id', $id)->exists()) {
            return;
        }

        $notification = new DigestSituacoesGerenciaisNotification(
            tenantId: $obra->tenant_id,
            obraId: $obra->id,
            obraNome: $obra->name,
            porDominio: $dadosUsuario['porDominio'],
            resolvidasRecentemente: $coletado['resolvidasRecentemente'],
            totalCriticas: $dadosUsuario['criticas'],
            totalAltas: $dadosUsuario['altas'],
            totalOutras: $dadosUsuario['outras'],
        );
        $notification->id = $id;

        try {
            $user->notify($notification);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            // Corrida concorrente — idempotência, não erro.
        } catch (\Throwable $e) {
            // Mesmo achado de 21.3 — canal broadcast pode falhar em
            // ambiente sem Reverb alcançável; database/mail já
            // processados antes disso não podem ser derrubados por isso.
            report($e);
        }
    }

    private function chaveLock(Work $obra, string $anoMesDia): string
    {
        return "situacao:digest:lock:{$obra->id}:{$anoMesDia}";
    }

    private function chaveEnviado(Work $obra, string $anoMesDia): string
    {
        return "situacao:digest:enviado:{$obra->id}:{$anoMesDia}";
    }
}
