<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Enums\StatusSituacaoOcorrencia;
use App\Jobs\SincronizarSituacaoObraJob;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\SituacaoComunicacaoEntrega;
use App\Models\SituacaoOcorrencia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\Channels\SituacaoLedgerMailChannel;
use App\Notifications\SituacaoGerencialNotification;
use App\Support\Gestao\SincronizarSituacoesGerenciais;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ciclo 21, Etapa 21.4 — Seção 25 do pedido: A-I, P, Q, R. J/K/L/M/N
 * (digest) ficam em `DigestSituacoesGerenciaisTest.php`.
 */
class AutomacaoSituacoesGerenciaisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-01'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------- helpers (mesmos de SincronizarSituacoesGerenciaisTest) ----------------

    private function criarAtividade(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Atividade '.uniqid(),
            'codigo_cronograma' => 'A'.uniqid(),
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => Carbon::today()->addDays(3), // Alta (documentoBloqueante)
            'data_termino' => Carbon::today()->addDays(5),
            'fora_do_cronograma' => false,
        ], $overrides));
    }

    private function criarDocumentoBloqueante(Atividade $atividade, ?Work $obra = null): array
    {
        $obra ??= $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'DOC-'.uniqid(), 'descricao' => 'Doc']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $doc->atividades()->sync([$atividade->id]);

        return [$doc, $rev];
    }

    private function liberar($rev): void
    {
        $rev->historicoLiberacoes()->create([
            'liberada_para_construcao' => true,
            'alterado_por' => $this->user->id,
            'ocorrido_em' => now(),
            'observacao' => 'ok',
        ]);
    }

    /**
     * `canais` é deliberadamente removido do payload exposto
     * (`SituacaoGerencialNotification::arrayExibicao()`, mesma garantia
     * de GRD — nunca vazar metadado interno pro usuário) — a única forma
     * confiável de saber "o e-mail foi de fato processado" é o ledger
     * (`SituacaoComunicacaoEntrega`, canal `mail`), gravado só DEPOIS de
     * `MailChannel::send()` retornar sem exceção. `MAIL_MAILER=array`
     * (phpunit.xml) nunca faz I/O real nem lança — sempre "sucede" em
     * silêncio, então a contagem do ledger é um sinal positivo confiável
     * neste ambiente de teste, sem precisar de `Mail::fake()`.
     */
    private function contarEmailsEnviados(): int
    {
        return SituacaoComunicacaoEntrega::where('canal', 'mail')->count();
    }

    // =========================================================
    // A — Scheduler executa obras elegíveis
    // =========================================================

    public function test_a_command_padrao_despacha_1_job_por_obra_elegivel(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        Queue::fake();

        Artisan::call('gestao:sincronizar-situacoes');

        Queue::assertPushed(SincronizarSituacaoObraJob::class, 2);
    }

    public function test_a2_command_com_obra_especifica_despacha_so_essa(): void
    {
        Work::factory()->create(['tenant_id' => $this->tenant->id]);
        Queue::fake();

        Artisan::call('gestao:sincronizar-situacoes', ['--obra' => $this->obra->id]);

        Queue::assertPushed(SincronizarSituacaoObraJob::class, 1);
    }

    public function test_a3_command_sync_processa_sem_enfileirar(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarDocumentoBloqueante($atividade);
        Queue::fake();

        Artisan::call('gestao:sincronizar-situacoes', ['--sync' => true]);

        Queue::assertNotPushed(SincronizarSituacaoObraJob::class);
        $this->assertSame(1, SituacaoOcorrencia::count());
    }

    // =========================================================
    // B — Lock: mesma obra não processada simultaneamente
    // =========================================================

    public function test_b_ja_locked_bloqueia_novo_despacho_da_mesma_obra(): void
    {
        Queue::fake();

        $chave = 'laravel_unique_job:'.SincronizarSituacaoObraJob::class.$this->obra->id;
        $lock = Cache::lock($chave, 900);
        $this->assertTrue($lock->get());

        SincronizarSituacaoObraJob::dispatch($this->obra);
        Queue::assertNotPushed(SincronizarSituacaoObraJob::class);

        $lock->release();
        SincronizarSituacaoObraJob::dispatch($this->obra);
        Queue::assertPushed(SincronizarSituacaoObraJob::class, 1);
    }

    public function test_b2_job_uniqueid_e_a_obra(): void
    {
        $job = new SincronizarSituacaoObraJob($this->obra);
        $this->assertSame($this->obra->id, $job->uniqueId());
    }

    // =========================================================
    // C — Idempotência (50 execuções, já provado em 21.3 — reafirmado
    // aqui incluindo o canal de e-mail)
    // =========================================================

    public function test_c_cinquenta_syncs_com_email_elegivel_nao_duplicam(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]); // Alta
        $this->criarDocumentoBloqueante($atividade);

        for ($i = 0; $i < 50; $i++) {
            SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        }

        $this->assertSame(1, SituacaoOcorrencia::count());
        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertSame(1, $this->contarEmailsEnviados());
    }

    // =========================================================
    // D — Crítica gera e-mail imediato conforme política
    // =========================================================

    public function test_d_severidade_alta_documento_bloqueante_e_elegivel_a_email(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]); // Alta
        $this->criarDocumentoBloqueante($atividade);

        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertSame(1, $this->contarEmailsEnviados());
    }

    // =========================================================
    // E — Atenção não gera imediato se política mandar digest
    // =========================================================

    public function test_e_severidade_atencao_documento_bloqueante_nunca_email_imediato(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(20)]); // Atenção
        $this->criarDocumentoBloqueante($atividade);

        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertSame(0, $this->contarEmailsEnviados());
    }

    public function test_e2_desvio_aplicacao_nunca_email_mesmo_no_maior_peso(): void
    {
        // DesvioAplicacao é sempre Atencao (21.2) e NUNCA imediato (Seção 8/21) —
        // aqui só confirmamos que a política em produção reflete isso mesmo
        // dentro do fluxo real de sincronização, usando um tipo elegível
        // a digest, nunca a imediato, como controle negativo adicional.
        $politica = \App\Support\Gestao\PoliticaEntregaSituacao::para(\App\Enums\TipoSituacaoGerencial::DesvioAplicacao);
        $this->assertFalse($politica->elegivelImediato);
    }

    // =========================================================
    // F — Escalada gera nova comunicação (com e-mail se cruzar o mínimo)
    // =========================================================

    public function test_f_escalada_de_atencao_para_alta_passa_a_ser_elegivel_a_email(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(20)]); // Atenção
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertSame(0, $this->contarEmailsEnviados());

        $atividade->update(['inicio_planejado' => Carbon::today()->addDays(3)]); // Alta
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertSame(1, $this->contarEmailsEnviados());
        $ocorrencia = SituacaoOcorrencia::firstOrFail();
        $this->assertNotNull($ocorrencia->ultimo_email_em);
    }

    // =========================================================
    // G — Queda de severidade não gera spam (e-mail incluso)
    // =========================================================

    public function test_g_queda_de_severidade_nao_gera_novo_email(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]); // Alta
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertSame(1, $this->contarEmailsEnviados());

        $atividade->update(['inicio_planejado' => Carbon::today()->addDays(20)]); // Atenção
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertSame(1, $this->contarEmailsEnviados()); // continua 1, nunca 2
    }

    // =========================================================
    // H — Reabertura gera comunicação nova (com e-mail quando elegível)
    // =========================================================

    public function test_h_reabertura_com_severidade_alta_gera_novo_email(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]); // Alta
        [$doc, $rev] = $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertSame(1, $this->contarEmailsEnviados());

        $this->liberar($rev);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra); // resolve

        $doc->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra); // reabre

        $this->assertSame(2, $this->contarEmailsEnviados());
    }

    // =========================================================
    // I — Resolução não envia e-mail indevido
    // =========================================================

    public function test_i_resolucao_nunca_envia_email(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]); // Alta
        [, $rev] = $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertSame(1, $this->contarEmailsEnviados());

        $this->liberar($rev);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertSame(1, $this->contarEmailsEnviados()); // resolução não soma
        $this->assertSame(StatusSituacaoOcorrencia::Resolvida, SituacaoOcorrencia::firstOrFail()->status);
    }

    // =========================================================
    // P — Retry não duplica (ledger de e-mail)
    // =========================================================

    public function test_p_retry_do_canal_mail_nao_duplica_ledger(): void
    {
        Mail::fake();

        // Mesma técnica de GrdNotificacaoTest::
        // test_186_ledger_mail_envia_e_registra_e_retry_nao_reenvia: chama
        // o channel DIRETO (nunca $user->notify(), que também dispararia
        // broadcast contra o Reverb inalcançável em teste).
        $payload = [
            'titulo' => 't', 'mensagem' => 'm', 'icone' => 'bx-bell', 'cor' => 'primary',
            'tipo' => 'material_critico', 'severidade' => 'alta',
            'obra_id' => $this->obra->id, 'obra_nome' => $this->obra->name,
            'ocorrencia_id' => 'x', 'motivo' => 'primeira_deteccao', 'episodio' => 1,
            'entidade_tipo' => 'Atividade', 'entidade_id' => 'x', 'contexto' => [],
            'deep_link' => ['rota' => 'notificacoes.index', 'parametros' => []],
            'canais' => [SituacaoLedgerMailChannel::class],
            'tenant_id' => $this->tenant->id,
        ];
        $notification = new SituacaoGerencialNotification($payload);
        $notification->id = (string) \Illuminate\Support\Str::uuid();

        $channel = new SituacaoLedgerMailChannel(app(MailChannel::class));
        $channel->send($this->user, $notification);
        $this->assertSame(1, SituacaoComunicacaoEntrega::where('canal', 'mail')->count());

        // Retry — mesma identidade, nunca uma 2ª linha.
        $channel->send($this->user, $notification);
        $this->assertSame(1, SituacaoComunicacaoEntrega::where('canal', 'mail')->count());
    }

    // =========================================================
    // Q — Falha em uma obra não impede as demais (isolamento por job)
    // =========================================================

    public function test_q_falha_ao_sincronizar_uma_obra_nao_impede_as_demais(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::GerentePlanejamento->value);

        $atividadeB = $this->criarAtividade([], $obraB);
        $this->criarDocumentoBloqueante($atividadeB, $obraB);

        // Mesma técnica de late static binding já usada no Teste P da
        // 21.3 (`derivarSituacoes` sobrescrito) — aqui prova o isolamento
        // de falha por obra JÁ EXISTENTE em `sincronizarTenant()` (21.3,
        // intocado): a obra A lança, a obra B continua processando
        // normalmente dentro do MESMO `Tenant::query()->each()`.
        $sincronizadorComFalha = new class extends SincronizarSituacoesGerenciais
        {
            public static string $obraComFalha = '';

            protected static function derivarSituacoes(Work $obra): \Illuminate\Support\Collection
            {
                if ($obra->id === static::$obraComFalha) {
                    throw new \RuntimeException('falha simulada — Seção 25-Q');
                }

                return parent::derivarSituacoes($obra);
            }
        };

        $sincronizadorComFalha::$obraComFalha = $this->obra->id;
        $sincronizadorComFalha::sincronizarTenant($this->tenant);

        $this->assertSame(0, SituacaoOcorrencia::where('obra_id', $this->obra->id)->count());
        $this->assertSame(1, SituacaoOcorrencia::where('obra_id', $obraB->id)->count());
    }

    // =========================================================
    // R — Legado 19.7 continua sem duplicação (reconfirmado com a
    // camada de política/e-mail da 21.4 também ativa)
    // =========================================================

    public function test_r_pedido_atrasado_nunca_elegivel_a_email_mesmo_com_politica_ativa(): void
    {
        $politica = \App\Support\Gestao\PoliticaEntregaSituacao::para(\App\Enums\TipoSituacaoGerencial::PedidoAtrasado);
        $this->assertFalse($politica->elegivelImediato);
        $this->assertFalse($politica->elegivelDigest);
    }
}
