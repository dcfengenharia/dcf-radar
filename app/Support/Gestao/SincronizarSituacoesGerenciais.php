<?php

namespace App\Support\Gestao;

use App\DTOs\Gestao\SituacaoGerencial;
use App\Enums\StatusSituacaoOcorrencia;
use App\Enums\TipoSituacaoGerencial;
use App\Models\SituacaoOcorrencia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\Channels\SituacaoLedgerMailChannel;
use App\Notifications\SituacaoGerencialNotification;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Ciclo 21, Etapa 21.3 — ponte entre a derivação pura de
 * `SituacoesGerenciaisQuery::porObra()` (fonte da verdade, nunca
 * persistida) e o ESTADO DE COMUNICAÇÃO com usuários reais. Mesmo nome/
 * papel de `App\Support\SincronizarRestricaoCadeiaSuprimento` (Ciclo
 * 19.7) — decisão arquitetural B do relatório final desta etapa: uma
 * tabela pequena (`situacao_ocorrencias`) acompanha o CICLO DE VIDA do
 * fenômeno; a tabela nativa `notifications` do Laravel (schema
 * INTOCADO) guarda as mensagens por usuário.
 *
 * **Horizonte FIXO e documentado** (Seção 7 do pedido — "ausência não é
 * sempre resolução"): todo sync usa o MESMO horizonte
 * (`self::HORIZONTE_DIAS`) — nunca varia entre execuções. Variar o
 * horizonte entre sincronizações faria uma `MaterialCritico` "sumir" só
 * porque a janela encolheu, nunca porque o material deixou de ser
 * crítico — isso seria uma resolução FALSA. Qualquer consulta ad-hoc de
 * `SituacoesGerenciaisQuery::porObra()` com outro horizonte (ex.: UI que
 * queira mostrar 90 dias) é sempre um USO SEPARADO, nunca alimenta este
 * sincronizador.
 *
 * **Falha parcial nunca resolve nada** (Seção 7): se `porObra()` lançar
 * qualquer exceção, a obra inteira é pulada NESTA rodada — nenhuma
 * ocorrência daquela obra é marcada resolvida, porque não temos
 * confiança de que o catálogo completo foi avaliado. A próxima execução
 * bem-sucedida reconcilia normalmente.
 *
 * **Idempotência (Seção 22, testada com 50 execuções idênticas)**: a
 * identidade de cada COMUNICAÇÃO (não da ocorrência) é um UUIDv5
 * determinístico sobre `(ocorrencia_id, episodio, motivo, [peso,]
 * userId)`, atribuído a `$notification->id` (PRIMARY KEY de
 * `notifications`) ANTES de `$user->notify()` — mesmo mecanismo exato
 * de `AlertaCadeiaSuprimento::enviarComIdempotencia()` (19.7)/
 * `AlertaDistribuicaoGrd` (18.5.5.HARDENING). Rodar o sync 2x, 50x ou
 * 1000x sobre o MESMO estado produz exatamente 1 ocorrência e o MESMO
 * conjunto de comunicações — nunca duplicado.
 *
 * **Fenômeno ≠ ocorrência ≠ comunicação (Seção 4)**: `chave_logica`
 * identifica o FENÔMENO (estável enquanto ele persistir); `episodio`
 * identifica a OCORRÊNCIA (incrementado só em reabertura — material
 * crítico → recomposto → crítico de novo é 2 episódios da MESMA
 * `chave_logica`, nunca 2 linhas); a COMUNICAÇÃO (linha em
 * `notifications`) é reenviada a cada NOVO episódio E a cada escalada de
 * severidade dentro do mesmo episódio (Seção 15), mas NUNCA a cada tick
 * de sincronização sobre um estado inalterado (Seção 16/17).
 *
 * **"Usuário ganha permissão enquanto ativa" (Seção 9) é resolvido de
 * graça pelo mesmo mecanismo de idempotência**: a comunicação "base" do
 * episódio atual é tentada para TODOS os destinatários ATUAIS a CADA
 * tick (não só na transição) — usuários já notificados colidem com a
 * PRIMARY KEY e não recebem nada de novo (custo: 1 `exists()` barato por
 * usuário por tick); um usuário recém-elegível nunca teve essa chave
 * gravada, então recebe a comunicação da ocorrência ATIVA na primeira
 * vez que aparece como destinatário. Um usuário que PERDE acesso
 * simplesmente para de aparecer em `resolverDestinatarios()` — nunca
 * recebe comunicação nova, e seu histórico antigo nunca é tocado
 * (Seção 10).
 *
 * **Legado 19.7 (Seção 18)**: `TipoSituacaoGerencial::PedidoAtrasado`
 * cobre o MESMO fato que `AlertaCadeiaSuprimento::
 * dispararPedidoAtrasado()`/`SuprimentosPedidoAtrasadoNotification` já
 * notificam em produção (granularidade idêntica — Pedido inteiro).
 * Estratégia escolhida (menor mudança coerente, nenhuma das duas
 * classes legadas é tocada): a ocorrência continua sendo rastreada
 * normalmente (útil pra um futuro Cockpit unificado), mas a COMUNICAÇÃO
 * é deliberadamente SUPRIMIDA pra este tipo — ver `TIPOS_SEM_COMUNICACAO`
 * — pra nunca duplicar o sino do usuário pelo mesmo fato.
 */
class SincronizarSituacoesGerenciais
{
    public const HORIZONTE_DIAS = 28;

    /**
     * Tipos cuja ocorrência é rastreada normalmente, mas cuja
     * COMUNICAÇÃO é suprimida porque um mecanismo legado já entrega o
     * mesmo fato ao mesmo usuário (Seção 18).
     */
    private const TIPOS_SEM_COMUNICACAO = [
        TipoSituacaoGerencial::PedidoAtrasado,
    ];

    public static function sincronizarTenant(Tenant $tenant): void
    {
        TenantContext::actingAs($tenant, function () {
            Work::query()->each(function (Work $obra) {
                try {
                    self::sincronizarObra($obra);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        });
    }

    /**
     * Seam de teste (Seção 7/28-P: "execução parcial/falha nunca resolve
     * situações erroneamente") — `static::` (late static binding, nunca
     * `self::`) permite que um teste crie uma subclasse anônima
     * sobrescrevendo só este método pra forçar uma exceção real, sem
     * precisar mockar um método estático de OUTRA classe (Mockery não
     * suporta isso de forma confiável quando a classe já foi carregada
     * no processo). Produção nunca sobrescreve isto — é sempre esta
     * implementação, idêntica a chamar `SituacoesGerenciaisQuery::
     * porObra()` diretamente.
     */
    protected static function derivarSituacoes(Work $obra): Collection
    {
        return SituacoesGerenciaisQuery::porObra($obra, self::HORIZONTE_DIAS);
    }

    public static function sincronizarObra(Work $obra): void
    {
        try {
            $situacoesAtuais = static::derivarSituacoes($obra);
        } catch (\Throwable $e) {
            // Seção 7: execução parcial/falha nunca resolve nada desta obra.
            report($e);

            return;
        }

        foreach ($situacoesAtuais as $situacao) {
            self::processarSituacao($obra, $situacao);
        }

        self::resolverAusentes($obra, $situacoesAtuais);
    }

    /**
     * **Comunicação nunca dentro de `DB::afterCommit()`** (desvio
     * deliberado do padrão de `SincronizarRestricaoCadeiaSuprimento::
     * dispararAlertaAposCommit()`, documentado aqui porque contraria um
     * precedente do próprio projeto) — achado empírico confirmado lendo
     * `Illuminate\Database\DatabaseTransactionsManager::
     * afterCommitCallbacksShouldBeExecuted()`: esses callbacks só
     * disparam quando o nível da transação chega a ZERO (a transação
     * MAIS EXTERNA de fato commita). Sob `RefreshDatabase` (usado em
     * TODOS os testes deste projeto), o teste inteiro já roda dentro de
     * UMA transação externa que é sempre revertida no fim — o nível
     * nunca chega a zero DURANTE o teste, então `afterCommit()` registrado
     * aqui NUNCA dispararia dentro de nenhum teste, tornando toda a
     * comunicação impossível de testar (confirmado empiricamente: com
     * `afterCommit()`, 18 dos 20 testes desta suíte falhavam com ZERO
     * notificação, mesmo com a ocorrência sendo criada corretamente).
     * `processarSituacao()` nunca é chamado de dentro de OUTRA transação
     * externa em nenhum caminho de produção real (`Command →
     * sincronizarTenant → actingAs → sincronizarObra`, sem nenhuma
     * transação por fora) — por isso despachar a comunicação
     * IMEDIATAMENTE DEPOIS que `DB::transaction()` retorna (nunca
     * dentro dela) é seguro e EQUIVALENTE a um `afterCommit()` de
     * verdade neste grafo de chamada específico, e continua correto sob
     * teste: se a transação lança, a linha abaixo nunca executa.
     */
    private static function processarSituacao(Work $obra, SituacaoGerencial $situacao): void
    {
        $resultado = DB::transaction(function () use ($obra, $situacao) {
            $ocorrencia = SituacaoOcorrencia::where('tenant_id', $obra->tenant_id)
                ->where('chave_logica', $situacao->chaveLogica)
                ->lockForUpdate()
                ->first();

            if (! $ocorrencia) {
                return ['ocorrencia' => self::criarOcorrencia($obra, $situacao), 'escalou' => false];
            }

            $ocorrencia->ultima_deteccao_em = now();
            $ocorrencia->descricao_atual = $situacao->descricao;
            $ocorrencia->contexto_atual = $situacao->contexto;

            $reaberta = $ocorrencia->status === StatusSituacaoOcorrencia::Resolvida;

            if ($reaberta) {
                $ocorrencia->episodio += 1;
                $ocorrencia->status = StatusSituacaoOcorrencia::Ativa;
                $ocorrencia->resolvida_em = null;
                $ocorrencia->severidade_atual = $situacao->severidade;
                $ocorrencia->severidade_peso_comunicado = $situacao->severidade->peso();
                $ocorrencia->save();

                return ['ocorrencia' => $ocorrencia, 'escalou' => false];
            }

            // Já ativa — atualiza severidade sempre (Seção 15), mas só
            // dispara comunicação de escalada quando o peso REALMENTE
            // sobe além do maior já comunicado neste episódio (nunca em
            // desescalada, Seção 16/Teste G).
            $escalou = $situacao->severidade->peso() > $ocorrencia->severidade_peso_comunicado;
            $ocorrencia->severidade_atual = $situacao->severidade;
            if ($escalou) {
                $ocorrencia->severidade_peso_comunicado = $situacao->severidade->peso();
            }
            $ocorrencia->save();

            return ['ocorrencia' => $ocorrencia, 'escalou' => $escalou];
        });

        self::comunicarBase($obra, $situacao, $resultado['ocorrencia']);
        if ($resultado['escalou']) {
            self::comunicarEscalada($obra, $situacao, $resultado['ocorrencia']);
        }
    }

    private static function criarOcorrencia(Work $obra, SituacaoGerencial $situacao): SituacaoOcorrencia
    {
        try {
            return SituacaoOcorrencia::create([
                'obra_id' => $obra->id,
                'tipo' => $situacao->tipo,
                'chave_logica' => $situacao->chaveLogica,
                'status' => StatusSituacaoOcorrencia::Ativa,
                'episodio' => 1,
                'severidade_atual' => $situacao->severidade,
                'severidade_peso_comunicado' => $situacao->severidade->peso(),
                'entidade_tipo' => $situacao->entidadeTipo,
                'entidade_id' => $situacao->entidadeId,
                'descricao_atual' => $situacao->descricao,
                'contexto_atual' => $situacao->contexto,
                'primeira_deteccao_em' => now(),
                'ultima_deteccao_em' => now(),
            ]);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }

            // Corrida concorrente (Seção 21): outro processo já criou a
            // MESMA chave — a UNIQUE constraint protegeu de verdade,
            // isto é idempotência, não erro (mesmo critério de
            // AlertaCadeiaSuprimento/PlanoAcao::transformarEmRestricoes).
            return SituacaoOcorrencia::where('tenant_id', $obra->tenant_id)
                ->where('chave_logica', $situacao->chaveLogica)
                ->firstOrFail();
        }
    }

    private static function resolverAusentes(Work $obra, Collection $situacoesAtuais): void
    {
        $chaves = $situacoesAtuais->pluck('chaveLogica')->all();

        SituacaoOcorrencia::where('obra_id', $obra->id)
            ->where('status', StatusSituacaoOcorrencia::Ativa->value)
            ->whereNotIn('chave_logica', $chaves)
            ->get(['id'])
            ->each(function (SituacaoOcorrencia $stub) {
                DB::transaction(function () use ($stub) {
                    $ocorrencia = SituacaoOcorrencia::whereKey($stub->id)->lockForUpdate()->first();

                    if ($ocorrencia && $ocorrencia->status === StatusSituacaoOcorrencia::Ativa) {
                        $ocorrencia->update([
                            'status' => StatusSituacaoOcorrencia::Resolvida,
                            'resolvida_em' => now(),
                        ]);
                    }
                });
            });
    }

    // =========================================================
    // Dry-run (Seção 23/24 do pedido 21.4) — SOMENTE LEITURA
    // =========================================================

    /**
     * Pré-visualização SOMENTE LEITURA pro Command manual (`--dry-run`):
     * NUNCA escreve em `situacao_ocorrencias`/`notifications`, nunca
     * chama `processarSituacao()`/`enviarComIdempotencia()` — só relê o
     * que JÁ está persistido (de execuções reais anteriores) e projeta o
     * que uma execução real faria agora.
     *
     * @return array{situacoes: array<int, array<string, mixed>>, resolveriam: array<int, array<string, mixed>>}
     */
    public static function preview(Work $obra): array
    {
        $situacoesAtuais = static::derivarSituacoes($obra);

        $existentes = SituacaoOcorrencia::where('obra_id', $obra->id)
            ->whereIn('chave_logica', $situacoesAtuais->pluck('chaveLogica')->all())
            ->get()
            ->keyBy('chave_logica');

        $situacoesPreview = $situacoesAtuais->map(function (SituacaoGerencial $situacao) use ($obra, $existentes) {
            $ocorrencia = $existentes->get($situacao->chaveLogica);

            if (! $ocorrencia) {
                $motivo = 'primeira_deteccao';
                $bypassaCooldown = false;
                $ultimoEmailEm = null;
            } elseif ($ocorrencia->status === StatusSituacaoOcorrencia::Resolvida) {
                // Reabertura também ultrapassa o cooldown — mesmo achado
                // do Teste H, ver comunicarBase().
                $motivo = 'reabertura';
                $bypassaCooldown = true;
                $ultimoEmailEm = $ocorrencia->ultimo_email_em;
            } elseif ($situacao->severidade->peso() > $ocorrencia->severidade_peso_comunicado) {
                $motivo = 'escalada';
                $bypassaCooldown = true;
                $ultimoEmailEm = $ocorrencia->ultimo_email_em;
            } else {
                $motivo = 'nenhuma_comunicacao_nova';
                $bypassaCooldown = false;
                $ultimoEmailEm = $ocorrencia->ultimo_email_em;
            }

            $canais = ['database', 'broadcast'];
            if ($motivo !== 'nenhuma_comunicacao_nova') {
                $politica = PoliticaEntregaSituacao::para($situacao->tipo);
                if ($politica->elegivelParaEmailAgora($situacao->severidade, $bypassaCooldown, $ultimoEmailEm, Carbon::now())) {
                    $canais[] = 'mail';
                }
            }

            return [
                'tipo' => $situacao->tipo->value,
                'chave_logica' => $situacao->chaveLogica,
                'severidade' => $situacao->severidade->value,
                'motivo_previsto' => $motivo,
                'destinatarios' => SituacoesGerenciaisQuery::resolverDestinatarios($obra, $situacao)->pluck('email')->all(),
                'canais_elegiveis' => $canais,
            ];
        })->values()->all();

        $chaves = $situacoesAtuais->pluck('chaveLogica')->all();
        $resolveriam = SituacaoOcorrencia::where('obra_id', $obra->id)
            ->where('status', StatusSituacaoOcorrencia::Ativa->value)
            ->whereNotIn('chave_logica', $chaves)
            ->get(['id', 'tipo', 'chave_logica'])
            ->map(fn (SituacaoOcorrencia $o) => ['tipo' => $o->tipo->value, 'chave_logica' => $o->chave_logica])
            ->values()
            ->all();

        return ['situacoes' => $situacoesPreview, 'resolveriam' => $resolveriam];
    }

    // =========================================================
    // Comunicação (Seção 13 idempotência, Seção 18 supressão legada)
    // =========================================================

    private static function comunicarBase(Work $obra, SituacaoGerencial $situacao, SituacaoOcorrencia $ocorrencia): void
    {
        if (in_array($situacao->tipo, self::TIPOS_SEM_COMUNICACAO, true)) {
            return;
        }

        $reaberta = $ocorrencia->episodio > 1;
        $motivo = $reaberta ? 'reabertura' : 'primeira_deteccao';
        $chave = "{$ocorrencia->id}:{$ocorrencia->episodio}:base";
        $titulo = $situacao->tipo->label().($reaberta ? ' (reaberta)' : '');

        // Reabertura TAMBÉM ultrapassa o cooldown (achado da implementação,
        // Teste H): é um episódio genuinamente NOVO (o anterior foi
        // integralmente resolvido) — nunca deveria ficar preso ao cooldown
        // de um episódio que já terminou. Só a comunicação "steady, mesmo
        // episódio, nada mudou" (motivo=primeira_deteccao numa reexecução
        // já idempotente por chave) respeita o cooldown normalmente.
        self::enviarParaDestinatariosAtuais($obra, $situacao, $ocorrencia, $chave, $motivo, $titulo, bypassaCooldown: $reaberta);
    }

    private static function comunicarEscalada(Work $obra, SituacaoGerencial $situacao, SituacaoOcorrencia $ocorrencia): void
    {
        if (in_array($situacao->tipo, self::TIPOS_SEM_COMUNICACAO, true)) {
            return;
        }

        $peso = $situacao->severidade->peso();
        $chave = "{$ocorrencia->id}:{$ocorrencia->episodio}:escalada:{$peso}";
        $titulo = $situacao->tipo->label().' — agravada para '.$situacao->severidade->label();

        self::enviarParaDestinatariosAtuais($obra, $situacao, $ocorrencia, $chave, 'escalada', $titulo, bypassaCooldown: true);
    }

    private static function enviarParaDestinatariosAtuais(
        Work $obra,
        SituacaoGerencial $situacao,
        SituacaoOcorrencia $ocorrencia,
        string $chaveComunicacao,
        string $motivo,
        string $titulo,
        bool $bypassaCooldown,
    ): void {
        $payload = [
            'titulo' => $titulo,
            'mensagem' => $situacao->descricao,
            'icone' => $situacao->severidade->icone(),
            'cor' => $situacao->severidade->cor(),
            'tipo' => $situacao->tipo->value,
            'severidade' => $situacao->severidade->value,
            'obra_id' => $obra->id,
            'obra_nome' => $obra->name,
            'ocorrencia_id' => $ocorrencia->id,
            'motivo' => $motivo,
            'episodio' => $ocorrencia->episodio,
            'entidade_tipo' => $situacao->entidadeTipo,
            'entidade_id' => $situacao->entidadeId,
            'contexto' => $situacao->contexto,
            'deep_link' => $situacao->deepLink,
            'canais' => self::resolverCanais($situacao, $ocorrencia, $bypassaCooldown),
            'tenant_id' => $obra->tenant_id,
        ];

        foreach (SituacoesGerenciaisQuery::resolverDestinatarios($obra, $situacao) as $user) {
            self::enviarComIdempotencia($user, $chaveComunicacao, $payload);
        }
    }

    /**
     * Ciclo 21, Etapa 21.4 — Seção 6/7: `database`/`broadcast` SEMPRE
     * entregues (já existiam desde a 21.3, nunca gateados por política);
     * `mail` só entra quando `PoliticaEntregaSituacao` (por tipo) permite
     * PRA ESTA severidade/cooldown/escalada. Quando o e-mail é incluído,
     * `ultimo_email_em` é marcado imediatamente — 1 UPDATE pontual, fora
     * de qualquer transação de `processarSituacao()` (que já retornou
     * antes deste ponto), consistente mesmo se `comunicarBase()` E
     * `comunicarEscalada()` rodarem no mesmo tick (a escalada sempre
     * ultrapassa o cooldown por design, então a ordem entre as duas
     * chamadas nunca produz um resultado diferente).
     *
     * **Achado real da implementação — `broadcast` SEMPRE por último no
     * array**: `Illuminate\Notifications\NotificationSender::
     * queueNotification()` despacha 1 job POR CANAL, em ORDEM, dentro do
     * MESMO `foreach`. Com `QUEUE_CONNECTION=sync` (`phpunit.xml`, e
     * também um cenário real possível em produção se a fila cair pro
     * modo síncrono), `Illuminate\Queue\SyncQueue::push()` EXECUTA o job
     * imediatamente e — confirmado lendo `SyncQueue::handleException()`
     * — **relança (`throw $e`) qualquer exceção não tratada**, abortando
     * o `foreach` e nunca despachando os canais SEGUINTES do array. Como
     * o canal `broadcast` tenta alcançar o Reverb via `localhost:8080`
     * (só resolve a partir do navegador — mesmo achado já documentado em
     * `Report::emitir()`/21.3), colocá-lo ANTES do canal de e-mail fazia
     * o e-mail NUNCA ser despachado sob fila síncrona, mesmo com a
     * política dizendo que deveria (confirmado empiricamente: com
     * `['database','broadcast',SituacaoLedgerMailChannel::class]`, ZERO
     * e-mails eram processados em qualquer teste). Sob fila REAL (redis,
     * produção), cada canal é um job Redis independente — a ordem nunca
     * importaria; mas nada aqui depende de qual driver de fila está
     * ativo, então a ordem `[database, mail?, broadcast]` é sempre
     * segura nos dois casos e nunca reintroduz esse achado.
     *
     * @return array<int, string>
     */
    private static function resolverCanais(SituacaoGerencial $situacao, SituacaoOcorrencia $ocorrencia, bool $bypassaCooldown): array
    {
        $canais = ['database'];

        $politica = PoliticaEntregaSituacao::para($situacao->tipo);
        $agora = Carbon::now();

        $elegivel = $politica->elegivelParaEmailAgora(
            severidadeAtual: $situacao->severidade,
            ehEscalada: $bypassaCooldown,
            ultimoEmailEm: $ocorrencia->ultimo_email_em,
            agora: $agora,
        );

        if ($elegivel) {
            $canais[] = SituacaoLedgerMailChannel::class;
            $ocorrencia->forceFill(['ultimo_email_em' => $agora])->save();
        }

        $canais[] = 'broadcast';

        return $canais;
    }

    private static function enviarComIdempotencia(User $user, string $chaveComunicacao, array $payload): void
    {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'situacao-gerencial-comunicacao:'.$chaveComunicacao.':'.$user->id)->toString();

        if (DB::table('notifications')->where('id', $id)->exists()) {
            return;
        }

        $notification = new SituacaoGerencialNotification($payload);
        $notification->id = $id;

        try {
            $user->notify($notification);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            // Corrida concorrente — outro processo já gravou a mesma
            // comunicação; a PRIMARY KEY protegeu, isto é idempotência.
        } catch (\Throwable $e) {
            // Achado da implementação, mesma classe já documentada em
            // `Report::emitir()`: o canal `broadcast` tenta alcançar o
            // Reverb via `localhost:8080`, hostname que só resolve a
            // partir do NAVEGADOR — de dentro do container/worker/teste,
            // a tentativa lança `BroadcastException`. O canal `database`
            // (sempre processado ANTES na mesma chamada de
            // `NotificationSender`) já persistiu a comunicação nesse
            // ponto — uma falha de infraestrutura de broadcast nunca
            // pode derrubar o sincronizador nem apagar essa escrita já
            // concluída.
            report($e);
        }
    }
}
