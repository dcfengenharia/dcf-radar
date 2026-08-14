<?php

namespace Tests\Feature;

use App\Models\Atividade;
use App\Models\Perfil;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ProntidaoSemanalNotification;
use App\Services\DigestProntidao;
use App\Support\CentralProntidao\ResumoDigestProntidao;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Ciclo 16, Etapa A.4 — orquestrador (`prontidao:notificar-semanal`).
 * `Notification::fake()` em todos os testes (nunca `->notify()` real) —
 * consistente com o resto do projeto. `Carbon::setTestNow()` restaurado
 * em `tearDown()`, nunca deixando teste dependente do relógio real.
 */
class NotificarProntidaoSemanalCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function criarUsuario(array $overrides = [], ?Tenant $tenant = null): User
    {
        return User::factory()->create(array_merge(['tenant_id' => ($tenant ?? $this->tenant)->id], $overrides));
    }

    private function criarAtividadeNaoPronta(?Work $obra = null, ?Tenant $tenant = null): Atividade
    {
        $obra ??= $this->obra;
        $tenant ??= $obra->tenant_id === $this->tenant->id ? $this->tenant : Tenant::find($obra->tenant_id);

        $at = Atividade::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'fora_do_cronograma' => false,
            'inicio_planejado' => now()->addDays(5),
        ]);

        Restricao::factory()->create(['tenant_id' => $tenant->id, 'atividade_id' => $at->id, 'bloqueante' => true]);

        return $at;
    }

    /**
     * Qualquer perfil padrão seedado concede 'ver' em TODAS as
     * funcionalidades (Perfil::seedPadrao(), confirmado lendo o código
     * real) — inclusive `restricoes.central_prontidao`.
     */
    private function vincularComPermissao(Work $obra, User $user): void
    {
        $this->vincularObra($obra, $user, 'gerente_planejamento');
    }

    /**
     * Perfil customizado SEM nenhuma PerfilPermissao — usuário fica
     * vinculado à obra mas sem NENHUMA permissão, inclusive
     * `restricoes.central_prontidao|ver`.
     */
    private function vincularSemPermissao(Work $obra, User $user): void
    {
        $perfil = Perfil::create([
            'tenant_id' => $obra->tenant_id,
            'nome' => 'Sem Permissão de Prontidão',
            'slug_padrao' => null,
        ]);

        $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);
    }

    // =========================================================================
    // 1) Execução básica
    // =========================================================================

    public function test_obra_com_pendencia_e_destinatario_elegivel_envia_exatamente_uma_notification(): void
    {
        $this->criarAtividadeNaoPronta();
        $user = $this->criarUsuario();
        $this->vincularComPermissao($this->obra, $user);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertSentTo($user, ProntidaoSemanalNotification::class);
        Notification::assertSentTimes(ProntidaoSemanalNotification::class, 1);
    }

    // =========================================================================
    // 2) Sem pendência
    // =========================================================================

    public function test_obra_sem_pendencia_nao_envia(): void
    {
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'inicio_planejado' => now()->addDays(5),
        ]); // pronta, sem restrição

        $user = $this->criarUsuario();
        $this->vincularComPermissao($this->obra, $user);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    // =========================================================================
    // 3) Sem destinatário
    // =========================================================================

    public function test_obra_com_pendencia_sem_destinatario_elegivel_nao_envia_e_comando_continua(): void
    {
        $this->criarAtividadeNaoPronta();
        // nenhum usuário vinculado à obra

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    // =========================================================================
    // 4) Permissão (Usuário B)
    // =========================================================================

    public function test_usuario_sem_permissao_central_prontidao_nao_recebe(): void
    {
        $this->criarAtividadeNaoPronta();
        $user = $this->criarUsuario();
        $this->vincularSemPermissao($this->obra, $user);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertNotSentTo($user, ProntidaoSemanalNotification::class);
    }

    // =========================================================================
    // 5) Inativo (Usuário C)
    // =========================================================================

    public function test_usuario_inativo_nao_recebe(): void
    {
        $this->criarAtividadeNaoPronta();
        $user = $this->criarUsuario(['ativo' => false]);
        $this->vincularComPermissao($this->obra, $user);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertNotSentTo($user, ProntidaoSemanalNotification::class);
    }

    // =========================================================================
    // 6) Outra obra (Usuário D)
    // =========================================================================

    public function test_usuario_vinculado_apenas_a_outra_obra_nao_recebe(): void
    {
        $this->criarAtividadeNaoPronta();
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $user = $this->criarUsuario();
        $this->vincularComPermissao($outraObra, $user);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertNotSentTo($user, ProntidaoSemanalNotification::class);
    }

    // =========================================================================
    // 7) Outro tenant (Usuário E)
    // =========================================================================

    public function test_usuario_de_outro_tenant_nunca_recebe(): void
    {
        $this->criarAtividadeNaoPronta();

        $outroTenant = Tenant::factory()->create();
        $userOutroTenant = $this->criarUsuario([], $outroTenant);
        // nunca vinculado a nenhuma obra deste tenant

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertNotSentTo($userOutroTenant, ProntidaoSemanalNotification::class);
    }

    // =========================================================================
    // Usuário F — acesso removido antes da execução
    // =========================================================================

    public function test_usuario_com_acesso_removido_antes_da_execucao_nao_recebe(): void
    {
        $this->criarAtividadeNaoPronta();
        $user = $this->criarUsuario();
        $this->vincularComPermissao($this->obra, $user);

        // Simula: usuário tinha acesso na semana anterior, mas foi
        // removido da obra antes desta execução — resolução de
        // destinatários é sempre fresca, nunca cacheada entre execuções.
        $this->obra->users()->detach($user->id);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertNotSentTo($user, ProntidaoSemanalNotification::class);
    }

    // =========================================================================
    // 8) Múltiplos destinatários elegíveis
    // =========================================================================

    public function test_multiplos_destinatarios_elegiveis_todos_recebem_exatamente_uma_vez(): void
    {
        $this->criarAtividadeNaoPronta();

        $user1 = $this->criarUsuario();
        $user2 = $this->criarUsuario();
        $user3 = $this->criarUsuario();
        $this->vincularComPermissao($this->obra, $user1);
        $this->vincularComPermissao($this->obra, $user2);
        $this->vincularComPermissao($this->obra, $user3);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertSentTo($user1, ProntidaoSemanalNotification::class);
        Notification::assertSentTo($user2, ProntidaoSemanalNotification::class);
        Notification::assertSentTo($user3, ProntidaoSemanalNotification::class);
        Notification::assertSentTimes(ProntidaoSemanalNotification::class, 3);
    }

    // =========================================================================
    // 9) Múltiplas obras — digest independente
    // =========================================================================

    public function test_multiplas_obras_geram_digest_independente(): void
    {
        $obra2 = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->criarAtividadeNaoPronta($this->obra);
        $this->criarAtividadeNaoPronta($obra2);
        $this->criarAtividadeNaoPronta($obra2); // obra2 com 2 pendências

        $user1 = $this->criarUsuario();
        $this->vincularComPermissao($this->obra, $user1);
        $user2 = $this->criarUsuario();
        $this->vincularComPermissao($obra2, $user2);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertSentTo($user1, ProntidaoSemanalNotification::class, function (ProntidaoSemanalNotification $n) {
            return $n->snapshotParaTeste()->obraId === $this->obra->id && $n->snapshotParaTeste()->totalExigeAtencao === 1;
        });
        Notification::assertSentTo($user2, ProntidaoSemanalNotification::class, function (ProntidaoSemanalNotification $n) use ($obra2) {
            return $n->snapshotParaTeste()->obraId === $obra2->id && $n->snapshotParaTeste()->totalExigeAtencao === 2;
        });
        Notification::assertSentTimes(ProntidaoSemanalNotification::class, 2);
    }

    // =========================================================================
    // 10) Uma obra falha — as demais continuam
    // =========================================================================

    public function test_falha_isolada_em_uma_obra_nao_impede_processamento_das_demais(): void
    {
        $obraComErro = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $obraOk = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->criarAtividadeNaoPronta($obraComErro);
        $this->criarAtividadeNaoPronta($obraOk);

        $userErro = $this->criarUsuario();
        $this->vincularComPermissao($obraComErro, $userErro);
        $userOk = $this->criarUsuario();
        $this->vincularComPermissao($obraOk, $userOk);

        $real = app(DigestProntidao::class);
        $obraComErroId = $obraComErro->id;

        $fake = new class($real, $obraComErroId) extends DigestProntidao {
            public function __construct(private DigestProntidao $real, private string $obraComErroId)
            {
            }

            public function consolidar(Work $obra): ResumoDigestProntidao
            {
                if ($obra->id === $this->obraComErroId) {
                    throw new \RuntimeException('Falha simulada para teste de isolamento');
                }

                return $this->real->consolidar($obra);
            }
        };

        $this->app->instance(DigestProntidao::class, $fake);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertNotSentTo($userErro, ProntidaoSemanalNotification::class);
        Notification::assertSentTo($userOk, ProntidaoSemanalNotification::class);
    }

    // =========================================================================
    // 11) Duplicidade — mesma semana
    // =========================================================================

    public function test_segunda_execucao_na_mesma_semana_nao_reenvia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00')); // segunda-feira

        $this->criarAtividadeNaoPronta();
        $user = $this->criarUsuario();
        $this->vincularComPermissao($this->obra, $user);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);
        Notification::assertSentTimes(ProntidaoSemanalNotification::class, 1);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);
        Notification::assertSentTimes(ProntidaoSemanalNotification::class, 1); // ainda 1, não dobrou
    }

    // =========================================================================
    // 12) Nova semana — pode reenviar
    // =========================================================================

    public function test_nova_semana_permite_reenviar_se_ainda_houver_pendencia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00')); // segunda-feira, semana 1

        $this->criarAtividadeNaoPronta(); // restrição bloqueante nunca resolvida
        $user = $this->criarUsuario();
        $this->vincularComPermissao($this->obra, $user);

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);
        Notification::assertSentTimes(ProntidaoSemanalNotification::class, 1);

        Carbon::setTestNow(Carbon::parse('2026-01-12 08:00:00')); // segunda-feira seguinte, semana 2

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);
        Notification::assertSentTimes(ProntidaoSemanalNotification::class, 2);
    }

    // =========================================================================
    // Concorrência / mecanismo de lock
    // =========================================================================

    public function test_lock_ja_adquirido_por_outra_execucao_pula_a_obra_sem_erro(): void
    {
        $this->criarAtividadeNaoPronta();
        $user = $this->criarUsuario();
        $this->vincularComPermissao($this->obra, $user);

        $anoSemana = Carbon::now()->format('oW');
        $lock = Cache::lock("prontidao:digest:lock:{$this->obra->id}:{$anoSemana}", 30);
        $this->assertTrue($lock->get(), 'Pré-condição: o teste precisa conseguir adquirir o lock antes do comando rodar');

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        Notification::assertNotSentTo($user, ProntidaoSemanalNotification::class);

        $lock->release();
    }

    public function test_marcador_de_enviado_so_e_gravado_apos_send_bem_sucedido(): void
    {
        $this->criarAtividadeNaoPronta();
        $user = $this->criarUsuario();
        $this->vincularComPermissao($this->obra, $user);

        $anoSemana = Carbon::now()->format('oW');
        $chaveEnviado = "prontidao:digest:enviado:{$this->obra->id}:{$anoSemana}";

        $this->assertFalse(Cache::has($chaveEnviado));

        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        $this->assertTrue(Cache::has($chaveEnviado), 'Depois do envio bem-sucedido, o marcador precisa existir');
    }

    // =========================================================================
    // Isolamento de tenant
    // =========================================================================

    public function test_atividade_de_outro_tenant_nao_gera_notification_neste_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->criarAtividadeNaoPronta($outraObra, $outroTenant);

        $userOutroTenant = $this->criarUsuario([], $outroTenant);
        $this->vincularComPermissao($outraObra, $userOutroTenant);

        // Neste tenant, sem nenhuma pendência.
        $this->artisan('prontidao:notificar-semanal')->assertExitCode(0);

        // O usuário do outro tenant recebe o SEU próprio digest (obra dele
        // tem pendência) — confirma que o comando realmente iterou os dois
        // tenants via TenantContext::actingAs(), nunca vazando dado entre eles.
        Notification::assertSentTo($userOutroTenant, ProntidaoSemanalNotification::class, function (ProntidaoSemanalNotification $n) use ($outraObra) {
            return $n->snapshotParaTeste()->obraId === $outraObra->id;
        });
        Notification::assertSentTimes(ProntidaoSemanalNotification::class, 1);
    }

    // =========================================================================
    // Scheduler
    // =========================================================================

    public function test_scheduler_registra_o_comando_semanalmente_com_mutex(): void
    {
        $schedule = app(Schedule::class);

        $eventos = collect($schedule->events())
            ->filter(fn ($e) => str_contains($e->command, 'prontidao:notificar-semanal'))
            ->values();

        $this->assertCount(1, $eventos, 'O comando precisa estar registrado exatamente uma vez no Scheduler');

        $evento = $eventos->first();

        $this->assertSame('0 8 * * 1', $evento->expression, 'Esperado: toda segunda-feira às 08:00');
        $this->assertTrue($evento->withoutOverlapping, 'withoutOverlapping() precisa estar ativo');
    }
}
