<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Actions\Engenharia\AtualizarRascunhoGrd;
use App\Actions\Engenharia\CriarGrd;
use App\Actions\Engenharia\EmitirGrd;
use App\Actions\Engenharia\RegistrarRecolhimento;
use App\Enums\Papel;
use App\Enums\ResultadoRecolhimento;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Grd;
use App\Models\GrdDistribuicao;
use App\Models\InconsistenciaAvanco;
use App\Models\Perfil;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\GrdPendenciasDigestNotification;
use App\Services\DigestPendenciasGed;
use App\Support\Grd\ResumoDigestPendenciasGed;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.5.7 — Digest Semanal de Pendências GED
 * (`engenharia:notificar-pendencias-grd`). Helpers de fixture (doc/rev/
 * liberar/destinatario/emitirGrdComUmaEntrega) espelham exatamente
 * `tests/Feature/GrdNotificacaoTest.php` — convenção do projeto de não
 * compartilhar helper pequeno de teste via trait entre arquivos.
 *
 * Cadência aprovada pelo usuário: SEMANAL, igual ao Digest de Prontidão
 * (segunda-feira 08:00, idempotência por ano-semana) — o roteiro "Dia D/
 * D+1/D+2/D+3" do pedido original foi adaptado para "Semana 1/2/3/4",
 * preservando 100% das transições de estado pedidas, só remapeadas pra
 * granularidade semanal de verdade (nunca diária, que não é a cadência
 * aprovada).
 */
class GrdDigestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // =========================================================================================
    // Helpers (espelham GrdNotificacaoTest)
    // =========================================================================================

    private function doc(array $o = [], ?Work $obra = null): DocumentoEngenharia
    {
        return DocumentoEngenharia::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'codigo' => 'DOC-' . uniqid(),
            'descricao' => 'x',
        ], $o));
    }

    private function rev(DocumentoEngenharia $d, string $texto = 'R1', array $o = []): DocumentoEngenhariaRevisao
    {
        return $d->revisoes()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'revisao' => $texto,
            'descricao' => 'x',
        ], $o))->fresh();
    }

    private function liberar(DocumentoEngenhariaRevisao $r, ?User $usuario = null): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->liberar($r, $usuario ?? $this->user);
    }

    private function destinatario(array $o = [], ?Work $obra = null): Destinatario
    {
        return Destinatario::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Destinatario ' . uniqid(),
        ], $o));
    }

    private function emitirGrdComUmaEntrega(DocumentoEngenhariaRevisao $revisao, Destinatario $destinatario, int $quantidade = 1, ?User $emitente = null): array
    {
        $emitente ??= $this->user;
        $grd = (new CriarGrd())->execute($this->obra, $emitente);
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $revisao->fresh());
        $gd = $acoes->adicionarDestinatario($grd, $destinatario);
        $acoes->marcarDistribuicao($grd, $item, $gd, $quantidade);
        $grd = (new EmitirGrd())->execute($grd, $emitente);
        $dist = GrdDistribuicao::where('grd_item_id', $item->id)->where('grd_destinatario_id', $gd->id)->firstOrFail();

        return compact('grd', 'item', 'gd', 'dist');
    }

    /** Cenário base de "obsoleta": R1 entregue, R2 nasce (nunca precisa ser liberada). */
    private function cenarioObsoleta(int $quantidade = 2): array
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Obsoleta']);
        ['dist' => $dist] = $this->emitirGrdComUmaEntrega($r1, $joao, $quantidade);
        $r2 = $this->rev($doc, 'R2');

        return compact('doc', 'r1', 'joao', 'r2', 'dist');
    }

    /** Cenário base de "candidato": R1 entregue e TOTALMENTE recolhida, R2 nasce e é liberada. */
    private function cenarioCandidato(int $quantidade = 1): array
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Candidato']);
        ['dist' => $dist] = $this->emitirGrdComUmaEntrega($r1, $joao, $quantidade);
        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, $quantidade, $this->user);
        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());

        return compact('doc', 'r1', 'joao', 'r2', 'dist');
    }

    private function vincularSemPermissao(Work $obra, User $user): void
    {
        $perfil = Perfil::create([
            'tenant_id' => $obra->tenant_id,
            'nome' => 'Sem Permissão GED ' . uniqid(),
            'slug_padrao' => null,
        ]);

        $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);
    }

    // =========================================================================================
    // A-D: presença/ausência do digest
    // =========================================================================================

    public function test_a_zero_pendencia_zero_digest(): void
    {
        Notification::fake();

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_b_somente_obsoletas_gera_digest(): void
    {
        Notification::fake();
        $this->cenarioObsoleta(2);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class);
    }

    public function test_c_somente_candidatos_gera_digest(): void
    {
        Notification::fake();
        $this->cenarioCandidato(1);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, function ($n) {
            $d = $n->toArray($this->user);

            return $d['obsoletas_documentos'] === 0 && $d['candidatos_documentos'] === 1;
        });
    }

    public function test_d_ambos_gera_digest_agregado(): void
    {
        Notification::fake();
        $this->cenarioObsoleta(2);
        $this->cenarioCandidato(1);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, function ($n) {
            $d = $n->toArray($this->user);

            return $d['obsoletas_documentos'] === 1 && $d['candidatos_documentos'] === 1;
        });
        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 1);
    }

    // =========================================================================================
    // E-F: quantidades agregadas corretas
    // =========================================================================================

    public function test_e_quantidade_fisica_correta(): void
    {
        Notification::fake();
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao']);
        $maria = $this->destinatario(['nome' => 'Maria']);
        $this->emitirGrdComUmaEntrega($r1, $joao, 2);
        $this->emitirGrdComUmaEntrega($r1, $maria, 3);
        $this->rev($doc, 'R2');

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, function ($n) {
            $d = $n->toArray($this->user);

            return $d['obsoletas_documentos'] === 1
                && $d['obsoletas_destinatarios'] === 2
                && $d['obsoletas_quantidade_fisica'] === 5;
        });
    }

    public function test_f_candidatos_distintos_corretos(): void
    {
        Notification::fake();
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao']);
        $maria = $this->destinatario(['nome' => 'Maria']);
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->emitirGrdComUmaEntrega($r1, $maria, 1);
        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, function ($n) {
            $d = $n->toArray($this->user);

            return $d['candidatos_documentos'] === 1 && $d['candidatos_destinatarios'] === 2;
        });
    }

    // =========================================================================================
    // G-H: NaoLocalizado / recolhimento total
    // =========================================================================================

    public function test_g_naolocalizado_continua_no_digest(): void
    {
        Notification::fake();
        ['dist' => $dist] = $this->cenarioObsoleta(3);
        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::NaoLocalizado, 3, $this->user);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, function ($n) {
            return $n->toArray($this->user)['obsoletas_quantidade_fisica'] === 3;
        });
    }

    public function test_h_totalmente_recolhido_sai_do_digest(): void
    {
        Notification::fake();
        ['dist' => $dist] = $this->cenarioObsoleta(2);
        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 2, $this->user);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        // Nota: assertNothingSent() genérico seria falso negativo aqui — criar R2 dentro de
        // cenarioObsoleta() já dispara o Alerta A IMEDIATO (GrdCopiasObsoletasNotification,
        // 18.5.5) no instante em que R2 nasce, ANTES do recolhimento total acontecer — evento
        // legítimo e esperado, independente do digest. O que este teste precisa provar é
        // só que o DIGEST especificamente não considera mais a pendência já resolvida.
        Notification::assertNotSentTo($this->user, GrdPendenciasDigestNotification::class);
    }

    // =========================================================================================
    // I-J: liberação controla candidatos
    // =========================================================================================

    public function test_i_r2_nao_liberada_nao_gera_candidato(): void
    {
        Notification::fake();
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        ['dist' => $dist] = $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);
        $this->rev($doc, 'R2'); // NÃO liberada

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_j_liberar_r2_entra_no_proximo_digest(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00')); // segunda-feira, semana 1

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        ['dist' => $dist] = $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);
        $r2 = $this->rev($doc, 'R2'); // ainda não liberada

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        Notification::assertNothingSent();

        $this->liberar($r2->fresh());
        Carbon::setTestNow(Carbon::parse('2026-01-12 08:00:00')); // semana seguinte

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, function ($n) {
            return $n->toArray($this->user)['candidatos_documentos'] === 1;
        });
    }

    // =========================================================================================
    // K-L: destinatário inativo
    // =========================================================================================

    public function test_k_destinatario_inativo_continua_contando_em_obsoletas(): void
    {
        Notification::fake();
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 4);
        $joao->delete();
        $this->rev($doc, 'R2');

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, function ($n) {
            $d = $n->toArray($this->user);

            return $d['obsoletas_documentos'] === 1 && $d['obsoletas_quantidade_fisica'] === 4;
        });
    }

    public function test_l_destinatario_inativo_nao_conta_em_candidatos(): void
    {
        Notification::fake();
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        ['dist' => $dist] = $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);
        $joao->delete();
        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    // =========================================================================================
    // M-O: destinatários / permissão / ativo
    // =========================================================================================

    public function test_m_usuario_com_ver_recebe(): void
    {
        Notification::fake();
        $this->cenarioObsoleta();
        $outro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $outro, Papel::Engenheiro->value);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertSentTo($outro, GrdPendenciasDigestNotification::class);
    }

    public function test_n_usuario_sem_ver_nao_recebe(): void
    {
        Notification::fake();
        $this->cenarioObsoleta();
        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularSemPermissao($this->obra, $semPermissao);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertNotSentTo($semPermissao, GrdPendenciasDigestNotification::class);
    }

    public function test_o_usuario_inativo_nao_recebe(): void
    {
        Notification::fake();
        $this->cenarioObsoleta();
        $inativo = User::factory()->create(['tenant_id' => $this->tenant->id, 'ativo' => false]);
        $this->vincularObra($this->obra, $inativo, Papel::Engenheiro->value);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertNotSentTo($inativo, GrdPendenciasDigestNotification::class);
    }

    // =========================================================================================
    // P-Q: cross-obra / cross-tenant
    // =========================================================================================

    public function test_p_cross_obra_usuario_de_outra_obra_nao_recebe(): void
    {
        Notification::fake();
        $this->cenarioObsoleta();
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $userOutraObra = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $userOutraObra, Papel::Engenheiro->value);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertNotSentTo($userOutraObra, GrdPendenciasDigestNotification::class);
    }

    public function test_q_cross_tenant_zero_vazamento(): void
    {
        Notification::fake();
        $this->cenarioObsoleta();

        $outroTenant = Tenant::factory()->create();
        $userOutroTenant = User::factory()->create(['tenant_id' => $outroTenant->id]);
        // nunca vinculado a nenhuma obra deste tenant

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertNotSentTo($userOutroTenant, GrdPendenciasDigestNotification::class);
        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 1);
    }

    // =========================================================================================
    // R: usuário com duas obras
    // =========================================================================================

    public function test_r_usuario_com_duas_obras_recebe_dois_digests_distintos(): void
    {
        Notification::fake();
        $this->cenarioObsoleta();

        $obra2 = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obra2, $this->user, Papel::Admin->value);
        $doc2 = $this->doc([], $obra2);
        $r1b = $doc2->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R1', 'descricao' => 'x'])->fresh();
        $this->liberar($r1b);
        $joaoB = $this->destinatario([], $obra2);
        $emitente = $this->user;
        $grdB = (new CriarGrd())->execute($obra2, $emitente);
        $acoes = new AtualizarRascunhoGrd();
        $itemB = $acoes->adicionarItem($grdB, $r1b->fresh());
        $gdB = $acoes->adicionarDestinatario($grdB, $joaoB);
        $acoes->marcarDistribuicao($grdB, $itemB, $gdB, 1);
        (new EmitirGrd())->execute($grdB, $emitente);
        $doc2->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x']);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 2);
        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, fn ($n) => $n->toArray($this->user)['obra_id'] === $this->obra->id);
        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, fn ($n) => $n->toArray($this->user)['obra_id'] === $obra2->id);
    }

    // =========================================================================================
    // S-T: idempotência por período
    // =========================================================================================

    public function test_s_rodar_command_2x_gera_uma_vez_por_periodo(): void
    {
        Notification::fake();
        $this->cenarioObsoleta();

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 1);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 1);
    }

    public function test_t_rodar_10x_continua_uma(): void
    {
        Notification::fake();
        $this->cenarioObsoleta();

        for ($i = 0; $i < 10; $i++) {
            $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        }

        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 1);
    }

    public function test_u_semana_seguinte_permite_novo_digest_legitimo(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-05 08:00:00')); // segunda-feira, semana 1
        $this->cenarioObsoleta();

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 1);

        Carbon::setTestNow(Carbon::parse('2026-01-12 08:00:00')); // segunda-feira seguinte, semana 2
        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 2);
    }

    // =========================================================================================
    // V-W: histórico
    // =========================================================================================

    /**
     * Envia só via o canal `database`, isolado (nunca `Notification::send()`/
     * rodar o Command inteiro, que percorreriam `via()` incluindo `broadcast`
     * — tentaria alcançar o Reverb de verdade, indisponível no container de
     * teste). Mesma técnica já estabelecida em
     * `GrdNotificacaoTest::test_y_marcar_como_lida_preserva_notification_historica`.
     */
    public function test_v_notification_nasce_nao_lida(): void
    {
        $notification = new GrdPendenciasDigestNotification($this->obra->id, $this->obra->name, 1, 1, 2, 0, 0);
        $notification->id = (string) \Illuminate\Support\Str::uuid();
        (new \Illuminate\Notifications\Channels\DatabaseChannel())->send($this->user, $notification);

        $row = $this->user->notifications()->firstOrFail();
        $this->assertNull($row->read_at);

        $row->markAsRead();
        $this->assertNotNull($row->fresh()->read_at);
    }

    /**
     * Mesma técnica de `test_v_...` (canal `database` direto, sem depender
     * de `Notification::fake()` — que bloquearia a escrita real na tabela
     * `notifications` — nem de um dispatch de fila real). `registrar()` de
     * recolhimento não dispara nenhuma Notification (só Alertas A/B, ligados
     * a criação de revisão/liberação — CLAUDE.md), então resolver a
     * pendência aqui não precisa de nenhum fake adicional.
     */
    public function test_w_resolver_pendencia_depois_nao_apaga_digest_antigo(): void
    {
        ['dist' => $dist] = $this->cenarioObsoleta(2);

        $notification = new GrdPendenciasDigestNotification($this->obra->id, $this->obra->name, 1, 1, 2, 0, 0);
        $notification->id = (string) \Illuminate\Support\Str::uuid();
        (new \Illuminate\Notifications\Channels\DatabaseChannel())->send($this->user, $notification);

        $totalAntes = $this->user->notifications()->where('type', GrdPendenciasDigestNotification::class)->count();
        $this->assertSame(1, $totalAntes);
        $dadosAntes = $this->user->notifications()->where('type', GrdPendenciasDigestNotification::class)->first()->data;

        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 2, $this->user);

        $registro = $this->user->notifications()->where('type', GrdPendenciasDigestNotification::class)->first();
        $this->assertNotNull($registro, 'resolver a pendência depois não apaga o digest histórico já enviado');
        $this->assertSame($dadosAntes, $registro->data, 'o conteúdo do digest histórico é uma fotografia — nunca é reescrito depois que a pendência é resolvida');
    }

    // =========================================================================================
    // X: payload database
    // =========================================================================================

    public function test_x_payload_database_contem_campos_esperados(): void
    {
        $this->cenarioObsoleta(3);

        $notification = new GrdPendenciasDigestNotification($this->obra->id, $this->obra->name, 1, 1, 3, 0, 0);
        $dados = $notification->toArray($this->user);

        $this->assertSame("Pendências GED — {$this->obra->name}", $dados['titulo']);
        $this->assertStringContainsString('1 documento(s) com cópias obsoletas em campo', $dados['mensagem']);
        $this->assertSame('bx-list-check', $dados['icone']);
        $this->assertNotNull($dados['link']);
        $this->assertNotNull($dados['link_obsoletas']);
        $this->assertNull($dados['link_candidatos']);
        $this->assertSame($this->obra->id, $dados['obra_id']);
    }

    // =========================================================================================
    // Y-Z-AA: canais — SÓ database+broadcast, nunca mail/WhatsApp (decisão explícita do usuário)
    // =========================================================================================

    public function test_y_z_aa_digest_usa_apenas_database_e_broadcast_nunca_mail_ou_whatsapp(): void
    {
        Mail::fake();
        Http::fake();
        $this->cenarioObsoleta();

        $notification = new GrdPendenciasDigestNotification($this->obra->id, $this->obra->name, 1, 1, 2, 0, 0);
        $this->assertSame(['database', 'broadcast'], $notification->via($this->user));
        $this->assertFalse(method_exists($notification, 'toMail'), 'digest nunca deve implementar toMail — decisão explícita: só database+broadcast');
        $this->assertFalse(method_exists($notification, 'toWhatsApp'), 'digest nunca deve implementar toWhatsApp — decisão explícita: só database+broadcast');

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    // =========================================================================================
    // AB: falha isolada não impede outras obras / marcador só após sucesso
    // =========================================================================================

    public function test_ab_falha_isolada_em_uma_obra_nao_impede_processamento_das_demais(): void
    {
        Notification::fake();

        $obraComErro = $this->obra;
        $this->cenarioObsoleta();

        $obraOk = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $userOk = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraOk, $userOk, Papel::Admin->value);
        $docOk = $this->doc([], $obraOk);
        $r1ok = $this->rev($docOk, 'R1');
        $this->liberar($r1ok->fresh());
        $joaoOk = $this->destinatario([], $obraOk);
        $grdOk = (new CriarGrd())->execute($obraOk, $userOk);
        $acoes = new AtualizarRascunhoGrd();
        $itemOk = $acoes->adicionarItem($grdOk, $r1ok->fresh());
        $gdOk = $acoes->adicionarDestinatario($grdOk, $joaoOk);
        $acoes->marcarDistribuicao($grdOk, $itemOk, $gdOk, 1);
        (new EmitirGrd())->execute($grdOk, $userOk);
        $docOk->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x']);

        $real = app(DigestPendenciasGed::class);
        $obraComErroId = $obraComErro->id;

        $fake = new class($real, $obraComErroId) extends DigestPendenciasGed {
            public function __construct(private DigestPendenciasGed $real, private string $obraComErroId)
            {
            }

            public function consolidar(Work $obra): ResumoDigestPendenciasGed
            {
                if ($obra->id === $this->obraComErroId) {
                    throw new \RuntimeException('Falha simulada para teste de isolamento');
                }

                return $this->real->consolidar($obra);
            }
        };

        $this->app->instance(DigestPendenciasGed::class, $fake);

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        Notification::assertNotSentTo($this->user, GrdPendenciasDigestNotification::class);
        Notification::assertSentTo($userOk, GrdPendenciasDigestNotification::class);

        $anoSemana = Carbon::now()->format('oW');
        $this->assertFalse(Cache::has("grd:digest:enviado:{$obraComErro->id}:{$anoSemana}"), 'obra com falha não pode ter o marcador de enviado gravado');
        $this->assertTrue(Cache::has("grd:digest:enviado:{$obraOk->id}:{$anoSemana}"));
    }

    // =========================================================================================
    // AC: performance com múltiplas obras
    // =========================================================================================

    public function test_ac_performance_multiplas_obras(): void
    {
        Notification::fake();

        for ($i = 0; $i < 8; $i++) {
            $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
            $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
            $this->vincularObra($obra, $user, Papel::Admin->value);

            $doc = $this->doc([], $obra);
            $r1 = $this->rev($doc, 'R1');
            $this->liberar($r1->fresh());
            $dest = $this->destinatario([], $obra);
            $grd = (new CriarGrd())->execute($obra, $user);
            $acoes = new AtualizarRascunhoGrd();
            $item = $acoes->adicionarItem($grd, $r1->fresh());
            $gd = $acoes->adicionarDestinatario($grd, $dest);
            $acoes->marcarDistribuicao($grd, $item, $gd, 1);
            (new EmitirGrd())->execute($grd, $user);
            $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x']);
        }

        $inicio = microtime(true);
        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        $duracao = microtime(true) - $inicio;

        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 8);
        $this->assertLessThan(15.0, $duracao, 'processar 8 obras não deveria demorar de forma explosiva (indício de N+1 por obra)');
    }

    // =========================================================================================
    // AD: zero alteração de domínio
    // =========================================================================================

    public function test_ad_zero_alteracao_operacional(): void
    {
        Notification::fake();
        $this->cenarioObsoleta(2);
        $this->cenarioCandidato(1);

        $grdCountAntes = Grd::count();
        $distCountAntes = GrdDistribuicao::count();
        $restricaoCountAntes = Restricao::count();
        $inconsistenciaCountAntes = InconsistenciaAvanco::count();

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);

        $this->assertSame($grdCountAntes, Grd::count());
        $this->assertSame($distCountAntes, GrdDistribuicao::count());
        $this->assertSame($restricaoCountAntes, Restricao::count());
        $this->assertSame($inconsistenciaCountAntes, InconsistenciaAvanco::count());
    }

    // =========================================================================================
    // AE: fluxo crítico completo (roteiro do pedido, remapeado pra granularidade SEMANAL)
    // =========================================================================================

    public function test_ae_fluxo_critico_completo(): void
    {
        Notification::fake();

        // Semana 1: João recebeu R1 qtd2. R2 nasce não liberada.
        Carbon::setTestNow(Carbon::parse('2026-02-02 08:00:00')); // segunda-feira
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Critico']);
        ['dist' => $dist] = $this->emitirGrdComUmaEntrega($r1, $joao, 2);
        $r2 = $this->rev($doc, 'R2'); // não liberada

        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, function ($n) {
            $d = $n->toArray($this->user);

            return $d['obsoletas_documentos'] === 1 && $d['obsoletas_quantidade_fisica'] === 2 && $d['candidatos_documentos'] === 0;
        });

        // Libera R2 e roda DE NOVO na MESMA semana — não pode duplicar o digest do período.
        $this->liberar($r2->fresh());
        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 1);

        // Semana 2: novo digest legítimo, agora candidatos=1 (obsoletas ainda=1, R1 continua pendente).
        Carbon::setTestNow(Carbon::parse('2026-02-09 08:00:00'));
        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 2);
        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, function ($n) {
            $d = $n->toArray($this->user);

            return $d['obsoletas_documentos'] === 1 && $d['candidatos_documentos'] === 1;
        });

        // Recolher R1 qtd1 (parcial) e entregar R2 pro João — obsoletas continua (1 unidade ainda pendente),
        // candidatos zera (João já recebeu a vigente).
        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);
        $this->emitirGrdComUmaEntrega($r2, $joao, 1);

        Carbon::setTestNow(Carbon::parse('2026-02-16 08:00:00')); // semana 3
        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 3);
        Notification::assertSentTo($this->user, GrdPendenciasDigestNotification::class, function ($n) {
            $d = $n->toArray($this->user);

            return $d['obsoletas_quantidade_fisica'] === 1 && $d['candidatos_documentos'] === 0;
        });

        // Recolher a última unidade de R1 — zero pendência.
        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);

        Carbon::setTestNow(Carbon::parse('2026-02-23 08:00:00')); // semana 4
        $this->artisan('engenharia:notificar-pendencias-grd')->assertExitCode(0);
        // A contagem permanece em 3 (a mesma da semana 3) — nenhum 4º digest foi
        // despachado, E os 3 digests legítimos das semanas 1-3 nunca são
        // retroativamente removidos/substituídos (Notification::fake() só
        // acumula o que foi de fato despachado, nunca "esquece" um envio
        // anterior por causa de uma execução seguinte sem pendência).
        Notification::assertSentTimes(GrdPendenciasDigestNotification::class, 3, 'sem pendência nenhuma -> nenhum digest novo na semana 4, e os 3 digests anteriores continuam contabilizados');
    }

    // =========================================================================================
    // Scheduler
    // =========================================================================================

    public function test_scheduler_registra_o_comando_semanalmente_com_mutex(): void
    {
        $schedule = app(Schedule::class);

        $eventos = collect($schedule->events())
            ->filter(fn ($e) => str_contains($e->command, 'engenharia:notificar-pendencias-grd'))
            ->values();

        $this->assertCount(1, $eventos, 'O comando precisa estar registrado exatamente uma vez no Scheduler');

        $evento = $eventos->first();

        $this->assertSame('0 8 * * 1', $evento->expression, 'Esperado: toda segunda-feira às 08:00, mesmo horário do Digest de Prontidão');
        $this->assertTrue($evento->withoutOverlapping, 'withoutOverlapping() precisa estar ativo');
    }
}
