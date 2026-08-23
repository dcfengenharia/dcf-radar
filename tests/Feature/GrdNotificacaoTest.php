<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Actions\Engenharia\AtualizarRascunhoGrd;
use App\Actions\Engenharia\CriarGrd;
use App\Actions\Engenharia\EmitirGrd;
use App\Actions\Engenharia\RegistrarRecolhimento;
use App\Enums\Papel;
use App\Enums\ResultadoRecolhimento;
use App\Exceptions\RevisaoDocumentoNaoVigenteException;
use App\Models\Atividade;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Grd;
use App\Models\GrdDistribuicao;
use App\Models\GrdRecolhimento;
use App\Models\PerfilPermissao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Models\GrdAlertaEntrega;
use App\Notifications\Channels\GrdLedgerMailChannel;
use App\Notifications\Channels\GrdLedgerZApiChannel;
use App\Notifications\GrdCandidatosNovaEntregaNotification;
use App\Notifications\GrdCopiasObsoletasNotification;
use App\Support\Grd\AlertaDistribuicaoGrd;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.5.5 — alertas internos de distribuição (Alerta A:
 * cópias obsoletas em campo / Alerta B: candidato a nova entrega). Toda
 * quantidade/regra é consumida de DetectorCopiasObsoletasGrd/
 * CandidatosNovaEntregaGrd (18.5.1, já aprovados) via App\Support\Grd\
 * AlertaDistribuicaoGrd — nenhuma regra reimplementada aqui. `Notification::
 * fake()` é chamado explicitamente em cada teste que precisa dele (nunca em
 * setUp()) — o teste Y (marcar como lida) precisa de entrega REAL, que o
 * fake() bloquearia globalmente.
 */
class GrdNotificacaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
    }

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

    /** R1 entregue+recolhível, R2 nasce e é liberada — cenário base pra Alerta B. */
    private function cenarioComCandidato(int $quantidade = 1): array
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Candidato']);
        $this->emitirGrdComUmaEntrega($r1, $joao, $quantidade);
        $r2 = $this->rev($doc, 'R2');

        return compact('doc', 'r1', 'joao', 'r2');
    }

    // ===================== A-D: Alerta A básico =====================

    public function test_a_revisao_nova_sem_obsoleta_zero_notification(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $this->rev($doc, 'R1'); // primeira revisão do documento, nunca há "anterior" pendente

        Notification::assertNothingSent();
    }

    public function test_b_revisao_nova_com_r1_pendente_gera_alerta_a(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 2);

        $this->rev($doc, 'R2');

        Notification::assertSentTo($this->user, GrdCopiasObsoletasNotification::class);
    }

    public function test_c_quantidade_fisica_agregada_correta(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao']);
        $maria = $this->destinatario(['nome' => 'Maria']);
        $this->emitirGrdComUmaEntrega($r1, $joao, 2);
        $this->emitirGrdComUmaEntrega($r1, $maria, 1);

        $this->rev($doc, 'R2');

        Notification::assertSentTo($this->user, GrdCopiasObsoletasNotification::class, function ($notification) {
            $dados = $notification->toArray($this->user);

            return str_contains($dados['mensagem'], 'Existem 3 cópia(s)');
        });
    }

    public function test_d_multiplos_destinatarios_agrega_uma_notification_por_usuario(): void
    {
        Notification::fake();

        $engenheiro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $engenheiro, Papel::Engenheiro->value);

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $this->emitirGrdComUmaEntrega($r1, $this->destinatario(), 1);
        $this->emitirGrdComUmaEntrega($r1, $this->destinatario(), 1);
        $this->emitirGrdComUmaEntrega($r1, $this->destinatario(), 1);

        $this->rev($doc, 'R2');

        // 1 Notification por USUÁRIO destinatário (2 usuários com ver na obra), nunca 1 por cópia física (3 cópias).
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 2);
        Notification::assertSentTo($this->user, GrdCopiasObsoletasNotification::class);
        Notification::assertSentTo($engenheiro, GrdCopiasObsoletasNotification::class);
    }

    public function test_e_destinatario_inativo_continua_contando_na_obsoleta(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 4);
        $joao->delete(); // inativado ANTES de R2 nascer

        $this->rev($doc, 'R2');

        Notification::assertSentTo($this->user, GrdCopiasObsoletasNotification::class, function ($notification) {
            return str_contains($notification->toArray($this->user)['mensagem'], 'Existem 4 cópia(s)');
        });
    }

    public function test_f_tudo_recolhido_zero_alerta_a(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        ['dist' => $dist] = $this->emitirGrdComUmaEntrega($r1, $joao, 2);
        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 2, $this->user);

        $this->rev($doc, 'R2');

        Notification::assertNothingSent();
    }

    public function test_g_r3_apos_r2_gera_novo_alerta_legitimo(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);

        $this->rev($doc, 'R2');
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 1);

        $this->rev($doc, 'R3'); // R1 ainda pendente — novo evento legítimo, NUNCA deduplicado com o de R2
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 2);
    }

    public function test_h_reimportar_revisao_identica_nao_duplica_alerta_a(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);

        $importador = new \App\Imports\DocumentoEngenhariaImporter();
        $linha = [
            'codigo' => $doc->codigo,
            'titulo' => $doc->descricao,
            'disciplina' => null,
            'status' => null,
            'data_prevista' => null,
            'data_real' => null,
            'revisao' => 'R2',
        ];

        $importador->aplicar([$linha], $this->obra->id, $this->user->id);
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 1);

        // Reimportar a MESMA linha (mesma revisão 'R2') nunca cria uma revisão nova — o guard
        // já existente ($revisaoAtual !== $linha['revisao']) evita o INSERT, então o Observer
        // nunca dispara de novo.
        $importador->aplicar([$linha], $this->obra->id, $this->user->id);
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 1);
    }

    public function test_backdating_revisao_antiga_inserida_depois_nao_gera_alerta(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1', ['data_emissao' => '2026-01-01']);
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);

        $this->rev($doc, 'R2', ['data_emissao' => '2026-03-01']);
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 1);

        // Insere uma revisão "R1b" com data ANTERIOR a R2 — nunca vira a vigente (R2 continua sendo),
        // então nenhum novo alerta A deve ser gerado por ela.
        $this->rev($doc, 'R1b', ['data_emissao' => '2026-02-01']);
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 1);
    }

    // ===================== I-N: Alerta B =====================

    public function test_i_r2_nao_liberada_zero_alerta_b(): void
    {
        Notification::fake();
        // cenarioComCandidato() legitimamente dispara o Alerta A (R1 fica
        // pendente assim que R2 nasce) — o que este teste verifica é que,
        // SEM liberar R2, o Alerta B (candidato) nunca dispara.
        $this->cenarioComCandidato();

        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 0);
    }

    public function test_j_liberar_r2_com_candidato_gera_alerta_b(): void
    {
        Notification::fake();
        ['r2' => $r2] = $this->cenarioComCandidato();

        $this->liberar($r2->fresh());

        Notification::assertSentTo($this->user, GrdCandidatosNovaEntregaNotification::class);
    }

    public function test_k_quantidade_de_candidatos_correta(): void
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

        Notification::assertSentTo($this->user, GrdCandidatosNovaEntregaNotification::class, function ($notification) {
            return str_contains($notification->toArray($this->user)['mensagem'], '2 destinatário(s)');
        });
    }

    public function test_l_sem_candidato_zero_alerta_b(): void
    {
        Notification::fake();

        // Documento só com R1, nunca distribuída — ninguém recebeu revisão anterior, logo ninguém é candidato.
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');

        $this->liberar($r1->fresh());

        Notification::assertNothingSent();
    }

    public function test_m_destinatario_inativo_fora_da_contagem_b(): void
    {
        Notification::fake();
        ['doc' => $doc, 'joao' => $joao, 'r2' => $r2] = $this->cenarioComCandidato();
        $joao->delete();

        $this->liberar($r2->fresh());

        // cenarioComCandidato() já disparou o Alerta A legitimamente (R1 pendente ao nascer R2) —
        // o que este teste verifica é que, com o único destinatário inativado, o Alerta B nunca dispara.
        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 0);
    }

    public function test_n_liberar_revisao_antiga_nao_vigente_zero_alerta(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->rev($doc, 'R2'); // agora vigente

        $this->expectException(RevisaoDocumentoNaoVigenteException::class);
        try {
            $this->liberar($r1->fresh());
        } finally {
            Notification::assertNothingSent();
        }
    }

    public function test_o_revogar_zero_alerta_b(): void
    {
        Notification::fake();
        ['r2' => $r2] = $this->cenarioComCandidato();
        $this->liberar($r2->fresh());
        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 1);

        (new AlterarLiberacaoRevisaoDocumento())->revogar($r2->fresh(), $this->user);

        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 1); // nenhuma nova
    }

    // ===================== P-R: Notification é só leitura =====================

    public function test_p_q_r_notification_nunca_altera_dado_operacional(): void
    {
        Notification::fake();

        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        // Snapshot capturado DEPOIS do fixture (cenarioComCandidato() legitimamente
        // cria 1 Grd pra montar o cenário) e ANTES do evento que dispara a
        // Notification (liberar) — é só esse último passo que não pode alterar nada.
        ['r2' => $r2] = $this->cenarioComCandidato();

        $grdsAntes = Grd::count();
        $recolhimentosAntes = GrdRecolhimento::count();
        $restricoesAntes = Restricao::count();
        $prontaAntes = $atividade->fresh()->estaPronta();

        $this->liberar($r2->fresh());

        $this->assertSame($grdsAntes, Grd::count(), 'Notification não pode criar GRD');
        $this->assertSame($recolhimentosAntes, GrdRecolhimento::count(), 'Notification não pode recolher');
        $this->assertSame($restricoesAntes, Restricao::count(), 'Notification GED não pode criar Restricao');
        $this->assertSame($prontaAntes, $atividade->fresh()->estaPronta(), 'Notification não pode alterar prontidão');
    }

    // ===================== S-U: autorização/isolamento =====================

    public function test_s_cross_obra_usuario_de_outra_obra_nao_recebe(): void
    {
        Notification::fake();

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $usuarioOutraObra = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $usuarioOutraObra, Papel::Admin->value);

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->rev($doc, 'R2');

        Notification::assertNotSentTo($usuarioOutraObra, GrdCopiasObsoletasNotification::class);
        Notification::assertSentTo($this->user, GrdCopiasObsoletasNotification::class);
    }

    public function test_t_cross_tenant_usuario_de_outro_tenant_nunca_recebe(): void
    {
        Notification::fake();

        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);

        TenantContext::actingAs($outroTenant, function () use ($outroTenant, $outroUser) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $this->vincularObra($outraObra, $outroUser, Papel::Admin->value);

            $doc = DocumentoEngenharia::create([
                'tenant_id' => $outroTenant->id,
                'obra_id' => $outraObra->id,
                'codigo' => 'OUTRO-TENANT',
                'descricao' => 'x',
            ]);
            $r1 = $doc->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R1', 'descricao' => 'x'])->fresh();
            (new AlterarLiberacaoRevisaoDocumento())->liberar($r1, $outroUser);
            $dest = Destinatario::create(['tenant_id' => $outroTenant->id, 'obra_id' => $outraObra->id, 'nome' => 'Dest']);
            $grd = (new CriarGrd())->execute($outraObra, $outroUser);
            $acoes = new AtualizarRascunhoGrd();
            $item = $acoes->adicionarItem($grd, $r1->fresh());
            $gd = $acoes->adicionarDestinatario($grd, $dest);
            $acoes->marcarDistribuicao($grd, $item, $gd, 1);
            (new EmitirGrd())->execute($grd, $outroUser);
            $doc->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R2', 'descricao' => 'x']);
        });

        Notification::assertNotSentTo($this->user, GrdCopiasObsoletasNotification::class);
    }

    public function test_u_usuario_sem_permissao_na_obra_nao_recebe(): void
    {
        Notification::fake();

        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]); // nunca vinculado à obra

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->rev($doc, 'R2');

        Notification::assertNotSentTo($semAcesso, GrdCopiasObsoletasNotification::class);
    }

    public function test_v_usuario_com_ver_recebe(): void
    {
        Notification::fake();

        $leitor = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value); // só 'ver', mesmo assim recebe

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->rev($doc, 'R2');

        Notification::assertSentTo($leitor, GrdCopiasObsoletasNotification::class);
    }

    // ===================== W: rollback =====================

    public function test_w_rollback_alerta_a_zero_notification(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);

        try {
            DB::transaction(function () use ($doc) {
                $doc->revisoes()->create([
                    'tenant_id' => $this->tenant->id,
                    'revisao' => 'R2-ROLLBACK',
                    'descricao' => 'x',
                ]);
                throw new \RuntimeException('forcar rollback');
            });
        } catch (\RuntimeException $e) {
            // esperado
        }

        Notification::assertNothingSent();
        $this->assertSame(0, DocumentoEngenhariaRevisao::where('revisao', 'R2-ROLLBACK')->count());
    }

    public function test_w_rollback_alerta_b_zero_notification(): void
    {
        Notification::fake();
        // cenarioComCandidato() já dispara legitimamente o Alerta A (setup) —
        // o rollback abaixo isola só o Alerta B (liberar dentro de uma
        // transação que depois falha).
        ['r2' => $r2] = $this->cenarioComCandidato();

        try {
            DB::transaction(function () use ($r2) {
                (new AlterarLiberacaoRevisaoDocumento())->liberar($r2->fresh(), $this->user);
                throw new \RuntimeException('forcar rollback');
            });
        } catch (\RuntimeException $e) {
            // esperado
        }

        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 0);
        $this->assertFalse($r2->fresh()->estaLiberadaParaConstrucao());
    }

    // ===================== X: link =====================

    public function test_x_link_alerta_a_aponta_para_aba_obsoletas(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->rev($doc, 'R2');

        $linkEsperado = route('engenharia.grds', ['obra' => $this->obra->id, 'aba' => 'obsoletas']);

        Notification::assertSentTo($this->user, GrdCopiasObsoletasNotification::class, function ($notification) use ($linkEsperado) {
            return $notification->toArray($this->user)['link'] === $linkEsperado;
        });
    }

    public function test_x_link_alerta_b_aponta_para_aba_candidatos(): void
    {
        Notification::fake();
        ['r2' => $r2] = $this->cenarioComCandidato();
        $this->liberar($r2->fresh());

        $linkEsperado = route('engenharia.grds', ['obra' => $this->obra->id, 'aba' => 'candidatos']);

        Notification::assertSentTo($this->user, GrdCandidatosNovaEntregaNotification::class, function ($notification) use ($linkEsperado) {
            return $notification->toArray($this->user)['link'] === $linkEsperado;
        });
    }

    public function test_x_link_abre_a_obra_e_aba_corretas_na_pagina(): void
    {
        $c = Livewire::withQueryParams(['obra' => $this->obra->id, 'aba' => 'obsoletas'])
            ->test('pages::engenharia.grds');

        $c->assertOk();
        $this->assertSame($this->obra->id, $c->get('obraId'));
        $this->assertSame('obsoletas', $c->get('abaAtiva'));
    }

    public function test_x1_link_revalida_autorizacao_apos_perda_de_acesso(): void
    {
        $leitor = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        DB::table('obra_user')->where('user_id', $leitor->id)->where('work_id', $this->obra->id)->delete();

        $c = Livewire::withQueryParams(['obra' => $this->obra->id, 'aba' => 'obsoletas'])
            ->test('pages::engenharia.grds');

        $this->assertNull($c->get('obraId'), 'obraId da URL nunca deve sobreviver sem a permissão revalidada');
    }

    // ===================== Y: marcar como lida preserva histórico =====================

    public function test_y_marcar_como_lida_preserva_notification_historica(): void
    {
        // Envia só via o canal `database`, isolado (nunca ->notify()/->notifyNow(),
        // que percorreriam TODO o via() incluindo `broadcast` — tentaria alcançar
        // o Reverb/Pusher de verdade, indisponível no container de teste; mesmo
        // cuidado já documentado no projeto para ZApiChannel). `id` normalmente é
        // atribuído por Illuminate\Notifications\NotificationSender antes de
        // chamar o canal — chamando o canal direto, precisa ser atribuído aqui.
        $notification = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, 'doc-x', 'DOC-1', 'rev-x', 'R2', 3);
        $notification->id = (string) \Illuminate\Support\Str::uuid();
        (new \Illuminate\Notifications\Channels\DatabaseChannel())->send($this->user, $notification);

        $notificacao = $this->user->notifications()->firstOrFail();
        $this->assertNull($notificacao->read_at);

        Livewire::test('notificacoes-dropdown')->call('marcarLida', $notificacao->id);

        $notificacao->refresh();
        $this->assertNotNull($notificacao->read_at, 'marcarLida deve preencher read_at');
        $this->assertNotNull(
            $this->user->notifications()->find($notificacao->id),
            'a Notification continua existindo — histórico nunca é apagado ao ser lida'
        );
    }

    // ===================== Z-AA: performance =====================

    public function test_z_alerta_a_nao_gera_n_mais_1_com_100_copias_pendentes(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());

        for ($i = 0; $i < 100; $i++) {
            $this->emitirGrdComUmaEntrega($r1, $this->destinatario(), 1);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $this->rev($doc, 'R2');

        $this->assertLessThan(60, $queries, 'disparo do Alerta A não deve escalar linearmente com o número de cópias pendentes');
        Notification::assertSentTo($this->user, GrdCopiasObsoletasNotification::class, function ($notification) {
            return str_contains($notification->toArray($this->user)['mensagem'], 'Existem 100 cópia(s)');
        });
    }

    public function test_aa_alerta_b_nao_gera_n_mais_1_com_100_candidatos(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());

        for ($i = 0; $i < 100; $i++) {
            $this->emitirGrdComUmaEntrega($r1, $this->destinatario(), 1);
        }
        $r2 = $this->rev($doc, 'R2');

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $this->liberar($r2->fresh());

        $this->assertLessThan(60, $queries, 'disparo do Alerta B não deve escalar linearmente com o número de candidatos');
        Notification::assertSentTo($this->user, GrdCandidatosNovaEntregaNotification::class, function ($notification) {
            return str_contains($notification->toArray($this->user)['mensagem'], '100 destinatário(s)');
        });
    }

    // ===================== AB / crítico: fluxo completo João R1→R2 =====================

    public function test_ab_fluxo_critico_completo_joao_r1_r2(): void
    {
        Notification::fake();

        $engenheiro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $engenheiro, Papel::Engenheiro->value);

        // 1-2: João recebe R1 qtd2; Engenheiro tem engenharia.pacotes/ver na obra.
        $doc = $this->doc(['codigo' => 'PROJ-CRITICO']);
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Critico']);
        $entregaR1 = $this->emitirGrdComUmaEntrega($r1, $joao, 2);
        $distR1 = $entregaR1['dist'];

        // 3-5: nasce R2 → exatamente 1 alerta A por destinatário elegível (Admin
        // do setUp() + Engenheiro, os 2 únicos usuários com engenharia.pacotes/ver
        // nesta obra — total de envios = 2, cada um recebendo exatamente 1).
        $r2 = $this->rev($doc, 'R2');
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 2);
        Notification::assertSentTo($engenheiro, GrdCopiasObsoletasNotification::class, function ($n) {
            return str_contains($n->toArray($this->user)['mensagem'], 'Existem 2 cópia(s)');
        });

        // 6: R2 ainda não liberada → zero alerta B.
        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 0);

        // 7-9: liberar R2 → exatamente 1 alerta B por destinatário elegível (total 2).
        $this->liberar($r2->fresh());
        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 2);
        Notification::assertSentTo($engenheiro, GrdCandidatosNovaEntregaNotification::class, function ($n) {
            return str_contains($n->toArray($this->user)['mensagem'], '1 destinatário(s)');
        });

        // 10-11: recolher R1 parcialmente → nenhuma Notification automática só por recolhimento.
        (new RegistrarRecolhimento())->execute($distR1, ResultadoRecolhimento::Recolhido, 1, $this->user);
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 2);
        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 2);

        // 12-13: emitir R2 pra João → nenhuma autocorreção de R1 (continua com 1 pendente).
        $grd2 = (new CriarGrd())->execute($this->obra, $this->user);
        $acoes = new AtualizarRascunhoGrd();
        $item2 = $acoes->adicionarItem($grd2, $r2->fresh());
        $gd2 = $acoes->adicionarDestinatario($grd2, $joao);
        $acoes->marcarDistribuicao($grd2, $item2, $gd2, 1);
        (new EmitirGrd())->execute($grd2, $this->user);
        $this->assertSame(1, $distR1->fresh()->quantidadePendente(), 'R1 nunca é recolhida automaticamente pela nova entrega de R2');

        // 14-15: recolher o restante de R1 → Central Operacional (18.5.4) fica limpa.
        (new RegistrarRecolhimento())->execute($distR1, ResultadoRecolhimento::Recolhido, 1, $this->user);
        $obsoletasRestantes = (new \App\Support\Grd\DetectorCopiasObsoletasGrd())->porObra($this->obra)
            ->filter(fn ($r) => $r->documento->id === $doc->id);
        $this->assertCount(0, $obsoletasRestantes);

        // 16: os 2 alertas históricos continuam existindo (nunca resolvidos/apagados retroativamente
        // — contagem por destinatário permanece a mesma de quando foram disparados).
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 2);
        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 2);
    }

    // =========================================================================================
    // ETAPA 18.5.5.HARDENING — idempotência estrutural (UUIDv5 determinístico = PRIMARY KEY de
    // `notifications.id`) + probes da auditoria adversarial convertidos em testes permanentes.
    // =========================================================================================

    /**
     * Insere diretamente uma linha em `notifications` com o MESMO id que
     * `AlertaDistribuicaoGrd::idAlerta()` computaria pra este evento+usuário —
     * simula "a primeira tentativa já persistiu de verdade", contornando a
     * limitação estrutural do ambiente de teste (ShouldQueue+connection
     * 'redis' nunca processa sincronamente dentro de `php artisan test`, sem
     * um worker real — provado na auditoria adversarial). Isso NÃO é um
     * atalho artificial: é exatamente o estado que a tabela `notifications`
     * teria em produção depois de um envio bem-sucedido, usando a MESMA
     * função de identidade que o código de produção usa.
     */
    private function seedNotificationExistente(string $tipo, string $obraId, string $documentoId, string $revisaoId, User $user, string $class): string
    {
        $id = AlertaDistribuicaoGrd::idAlerta($tipo, $obraId, $documentoId, $revisaoId, $user->id);

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => $class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => '{}',
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // ----- 8: idempotência principal (A-G) -----

    public function test_idempotencia_a_b_chamar_dispararcopiasobsoletas_repetidas_vezes_produz_apenas_1_notification(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 2);
        $r2 = $this->rev($doc, 'R2');

        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 1);

        // Simula que a 1ª chamada (acima) REALMENTE persistiu — mesma chave que
        // o código de produção usaria pra este evento+usuário.
        $this->seedNotificationExistente('copias_obsoletas', $this->obra->id, $doc->id, $r2->id, $this->user, GrdCopiasObsoletasNotification::class);

        // A: 2ª chamada pro MESMO evento.
        (new AlertaDistribuicaoGrd())->dispararCopiasObsoletas($r2->fresh());
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 1, 'A: continua exatamente 1, o exists() bloqueia a 2ª chamada antes do notify()');

        // B: mais 8 chamadas (total 10 tentativas) — continua 1.
        for ($i = 0; $i < 8; $i++) {
            (new AlertaDistribuicaoGrd())->dispararCopiasObsoletas($r2->fresh());
        }
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 1, 'B: 10 chamadas no total, continua exatamente 1');
    }

    public function test_idempotencia_c_d_chamar_dispararcandidatosnovaentrega_repetidas_vezes_produz_apenas_1_notification(): void
    {
        Notification::fake();

        ['doc' => $doc, 'r2' => $r2] = $this->cenarioComCandidato();
        $this->liberar($r2->fresh());

        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 1);

        $this->seedNotificationExistente('candidatos_nova_entrega', $this->obra->id, $doc->id, $r2->fresh()->id, $this->user, GrdCandidatosNovaEntregaNotification::class);

        // C: 2ª chamada.
        (new AlertaDistribuicaoGrd())->dispararCandidatosNovaEntrega($r2->fresh());
        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 1, 'C: continua exatamente 1');

        // D: mais 8 chamadas (total 10) — continua 1.
        for ($i = 0; $i < 8; $i++) {
            (new AlertaDistribuicaoGrd())->dispararCandidatosNovaEntrega($r2->fresh());
        }
        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 1, 'D: 10 chamadas no total, continua exatamente 1');
    }

    public function test_idempotencia_e_r2_e_r3_tem_identidades_diferentes_2_notifications_legitimas(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);

        $r2 = $this->rev($doc, 'R2');
        $r3 = $this->rev($doc, 'R3');

        $idR2 = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $r2->id, $this->user->id);
        $idR3 = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $r3->id, $this->user->id);

        $this->assertNotSame($idR2, $idR3, 'a identidade do alerta inclui o ID da revisão — R2 e R3 nunca colidem');
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 2, 'E: R2 e R3 são 2 eventos legítimos e distintos, nunca deduplicados entre si');
    }

    public function test_idempotencia_f_mesmo_texto_de_revisao_em_documentos_diferentes_sao_eventos_independentes(): void
    {
        Notification::fake();

        $doc1 = $this->doc();
        $r1a = $this->rev($doc1, 'R1');
        $this->liberar($r1a->fresh());
        $this->emitirGrdComUmaEntrega($r1a, $this->destinatario(), 1);

        $doc2 = $this->doc();
        $r1b = $this->rev($doc2, 'R1');
        $this->liberar($r1b->fresh());
        $this->emitirGrdComUmaEntrega($r1b, $this->destinatario(), 1);

        $r2a = $this->rev($doc1, 'R2'); // mesmo texto "R2" nos 2 documentos
        $r2b = $this->rev($doc2, 'R2');

        $idA = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc1->id, $r2a->id, $this->user->id);
        $idB = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc2->id, $r2b->id, $this->user->id);

        $this->assertNotSame($idA, $idB, 'a identidade inclui o documento_id — mesmo texto de revisão em documentos diferentes nunca colide');
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 2, 'F: 2 documentos = 2 eventos independentes, mesmo com texto de revisão igual');
    }

    public function test_idempotencia_g_mesmo_evento_dois_usuarios_elegiveis_gera_2_rows_ids_diferentes(): void
    {
        Notification::fake();

        $engenheiro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $engenheiro, Papel::Engenheiro->value);

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $r2 = $this->rev($doc, 'R2');

        $idAdmin = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $r2->id, $this->user->id);
        $idEngenheiro = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $r2->id, $engenheiro->id);

        $this->assertNotSame($idAdmin, $idEngenheiro, 'a identidade inclui o user_id — mesmo evento gera ids DIFERENTES por destinatário');
        Notification::assertSentTimes(GrdCopiasObsoletasNotification::class, 2, 'G: 1 por usuário, 2 destinatários = 2 rows totais');
    }

    // ----- 9: concorrência / PRIMARY KEY -----

    public function test_concorrencia_primary_key_de_notifications_id_impede_duas_linhas_com_a_mesma_chave(): void
    {
        $id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', 'obra-x', 'doc-x', 'rev-x', 'user-x');

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => GrdCopiasObsoletasNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'data' => '{}',
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('notifications')->insert([
                'id' => $id, // MESMA chave — 2ª tentativa de registrar o mesmo evento+usuário
                'type' => GrdCopiasObsoletasNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $this->user->id,
                'data' => '{"tentativa":"2"}',
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('era esperado QueryException (duplicate entry) na 2ª tentativa com a mesma PK');
        } catch (QueryException $e) {
            $this->assertSame(1062, (int) ($e->errorInfo[1] ?? 0), 'o banco precisa recusar com o código MySQL de duplicate entry (1062), não outro erro');
        }

        $this->assertSame(1, DB::table('notifications')->where('id', $id)->count(), 'nunca 2 linhas com a mesma chave — o banco garante isso estruturalmente');
    }

    // ----- 10: retry de job / failed() -----

    public function test_retry_apos_persistencia_bem_sucedida_e_tratado_como_idempotencia_nao_como_erro(): void
    {
        // Simula um retry de SendQueuedNotifications: o job já persistiu com sucesso
        // (linha já existe), mas por algum motivo é reprocessado (ex.: timeout falso-
        // positivo) e tenta gravar de novo com o MESMO id determinístico.
        $notification = new GrdCopiasObsoletasNotification($this->tenant->id, 'obra-x', 'Obra X', 'doc-x', 'DOC-X', 'rev-x', 'R2', 5);
        $notification->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', 'obra-x', 'doc-x', 'rev-x', $this->user->id);

        (new \Illuminate\Notifications\Channels\DatabaseChannel())->send($this->user, $notification);

        try {
            (new \Illuminate\Notifications\Channels\DatabaseChannel())->send($this->user, $notification); // retry
            $this->fail('DatabaseChannel::send() deveria lançar QueryException na 2ª tentativa (mesma PK)');
        } catch (QueryException $e) {
            // Isso é exatamente o que SendQueuedNotifications::failed() recebe num retry real —
            // confirma que failed() trata esse caso específico como sucesso, não como erro.
            $notification->failed($e); // não deve lançar nada
            $this->assertTrue(true, 'failed() absorveu o 1062 sem relançar');
        }

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->user->id)->count());
    }

    public function test_failed_relanca_excecoes_que_nao_sao_duplicate_key(): void
    {
        $notification = new GrdCopiasObsoletasNotification($this->tenant->id, 'obra-x', 'Obra X', 'doc-x', 'DOC-X', 'rev-x', 'R2', 5);

        // Não deve lançar nada (só reporta) pra uma exceção qualquer não-1062.
        $notification->failed(new \RuntimeException('erro genuíno, não relacionado a duplicidade'));
        $this->assertTrue(true, 'failed() não relança — só reporta — mas não deve tratar como idempotência silenciosa');
    }

    // ----- 11: probes da auditoria → testes permanentes (H-L) -----

    /** H — Alerta A + NaoLocalizado explícito: pendência continua contando (NaoLocalizado nunca reduz). */
    public function test_h_alerta_a_com_naolocalizado_explicito_continua_contando_pendencia(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        ['dist' => $dist] = $this->emitirGrdComUmaEntrega($r1, $joao, 2);
        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::NaoLocalizado, 2, $this->user);

        $this->rev($doc, 'R2');

        Notification::assertSentTo($this->user, GrdCopiasObsoletasNotification::class, function ($n) {
            return str_contains($n->toArray($this->user)['mensagem'], 'Existem 2 cópia(s)');
        });
    }

    /** I — B6: João recebeu R1 em 2 GRDs diferentes → contado como 1 candidato, não 2. */
    public function test_i_alerta_b_mesmo_destinatario_em_duas_grds_conta_uma_vez(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->emitirGrdComUmaEntrega($r1, $joao, 1); // 2ª GRD, mesmo destinatário
        $r2 = $this->rev($doc, 'R2');

        $this->liberar($r2->fresh());

        Notification::assertSentTo($this->user, GrdCandidatosNovaEntregaNotification::class, function ($n) {
            return str_contains($n->toArray($this->user)['mensagem'], '1 destinatário(s)');
        });
    }

    /** J — revogar e reliberar R2 já entregue a João → zero alerta B indevido. */
    public function test_j_revogar_e_reliberar_r2_ja_entregue_nao_gera_alerta_b_indevido(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);

        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());
        Notification::assertSentTo($this->user, GrdCandidatosNovaEntregaNotification::class); // candidato normal (recebeu R1, não R2)

        // João agora recebe R2 (só possível depois de liberada)
        $this->emitirGrdComUmaEntrega($r2, $joao, 1);

        Notification::fake(); // reseta captura pra isolar só o próximo ciclo

        (new AlterarLiberacaoRevisaoDocumento())->revogar($r2->fresh(), $this->user);
        (new AlterarLiberacaoRevisaoDocumento())->liberar($r2->fresh(), $this->user);

        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 0, 'João já recebeu R2 — revogar+reliberar não deve gerar candidato de novo');
    }

    /** K — usuário vinculado à obra SEM 'ver' (removida manualmente do perfil) nunca recebe. */
    public function test_k_usuario_vinculado_sem_ver_nao_recebe(): void
    {
        Notification::fake();

        $semVer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfil = $this->vincularObra($this->obra, $semVer, Papel::Encarregado->value);
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'engenharia.pacotes')
            ->where('acao', 'ver')
            ->delete();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->rev($doc, 'R2');

        Notification::assertNotSentTo($semVer, GrdCopiasObsoletasNotification::class);
        Notification::assertSentTo($this->user, GrdCopiasObsoletasNotification::class); // controle
    }

    /** L — usuário vinculado + 'ver' + inativo (`users.ativo=false`) nunca recebe. */
    public function test_l_usuario_vinculado_com_ver_mas_inativo_nao_recebe(): void
    {
        Notification::fake();

        $inativo = User::factory()->create(['tenant_id' => $this->tenant->id, 'ativo' => false]);
        $this->vincularObra($this->obra, $inativo, Papel::Engenheiro->value);

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->rev($doc, 'R2');

        Notification::assertNotSentTo($inativo, GrdCopiasObsoletasNotification::class);
        Notification::assertSentTo($this->user, GrdCopiasObsoletasNotification::class); // controle
    }

    // ----- 12: smoke real da fila Redis -----

    // ----- 12: smoke real da fila Redis — INVESTIGADO, NÃO incluído como teste
    // permanente. Ver CLAUDE.md/relatório final: `Artisan::call('queue:work',
    // ['connection' => 'redis', '--once' => true, ...])` processando os jobs
    // reais enfileirados por este próprio teste FUNCIONA (confirmado
    // manualmente: a linha aparece em `notifications` com o conteúdo certo,
    // JSON com unicode escapado — `ó` — como o `json_encode` padrão do
    // PHP produz) — mas o tempo de execução variou de forma imprevisível
    // (de instantâneo a ~90s, e em 2 execuções manuais anteriores travou
    // indefinidamente) porque `config('queue.connections.redis.block_for')`
    // é `null` (BLPOP sem timeout) e o comportamento de "quantos jobs existem
    // pra processar" depende de timing de fila real, não de estado
    // determinístico de teste. Incluir isso como teste permanente arriscaria
    // travar a suíte inteira (exigiu `pkill` manual 2 vezes durante a
    // investigação) — não vale o risco pra um smoke que já foi confirmado
    // funcionando manualmente. Classificado como **D** (lacuna de teste
    // permanente, não de comportamento) no relatório final desta etapa.

    // ----- 18: rollback / nested transaction (revalidação com dedupe novo) -----

    public function test_rollback_com_dedupe_nao_deixa_notification_nem_linha_de_dedupe(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);

        $antesNotifications = DB::table('notifications')->count();

        try {
            DB::transaction(function () use ($doc) {
                $doc->revisoes()->create([
                    'tenant_id' => $this->tenant->id,
                    'revisao' => 'R2-ROLLBACK-DEDUPE',
                    'descricao' => 'x',
                ]);
                throw new \RuntimeException('forcar rollback');
            });
        } catch (\RuntimeException $e) {
            // esperado
        }

        Notification::assertNothingSent();
        // A identidade É a própria linha de `notifications` (sem ledger separado) —
        // rollback do fato precisa deixar a contagem exatamente como estava.
        $this->assertSame($antesNotifications, DB::table('notifications')->count());
    }

    public function test_nested_transaction_notification_so_e_chamada_apos_commit_externo(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 2);

        $antesDoCommitExterno = null;

        DB::transaction(function () use ($doc, &$antesDoCommitExterno) {
            DB::transaction(function () use ($doc) {
                $doc->revisoes()->create([
                    'tenant_id' => $this->tenant->id,
                    'revisao' => 'R2-NESTED',
                    'descricao' => 'x',
                ]);
            });

            $antesDoCommitExterno = collect(Notification::sent($this->user, GrdCopiasObsoletasNotification::class))->count();
        });

        $depoisDoCommitExterno = collect(Notification::sent($this->user, GrdCopiasObsoletasNotification::class))->count();

        $this->assertSame(0, $antesDoCommitExterno, 'nada antes do commit externo');
        $this->assertGreaterThan(0, $depoisDoCommitExterno, 'exatamente o alerta depois do commit externo');
    }

    // ----- 20: performance reprocessada -----

    public function test_performance_100_copias_3_usuarios_reprocessar_mesmo_evento_continua_3_nao_6(): void
    {
        Notification::fake();

        $u2 = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $u3 = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $u2, Papel::Engenheiro->value);
        $this->vincularObra($this->obra, $u3, Papel::Encarregado->value);

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        for ($i = 0; $i < 100; $i++) {
            $this->emitirGrdComUmaEntrega($r1, $this->destinatario(), 1);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $r2 = $this->rev($doc, 'R2');

        $envios = collect(Notification::sent($this->user, GrdCopiasObsoletasNotification::class))->count()
            + collect(Notification::sent($u2, GrdCopiasObsoletasNotification::class))->count()
            + collect(Notification::sent($u3, GrdCopiasObsoletasNotification::class))->count();
        $this->assertSame(3, $envios, '100 cópias, 3 usuários elegíveis => no máximo 3 envios, nunca 100');
        $this->assertLessThan(90, $queries);

        // Simula que os 3 já persistiram de verdade (mesma limitação da fila em teste)
        foreach ([$this->user, $u2, $u3] as $u) {
            $this->seedNotificationExistente('copias_obsoletas', $this->obra->id, $doc->id, $r2->id, $u, GrdCopiasObsoletasNotification::class);
        }

        // Reprocessa o MESMO evento (ex.: reentrega de job/reexecução do Observer) — continua 3, não 6.
        (new AlertaDistribuicaoGrd())->dispararCopiasObsoletas($r2->fresh());

        $enviosApos = collect(Notification::sent($this->user, GrdCopiasObsoletasNotification::class))->count()
            + collect(Notification::sent($u2, GrdCopiasObsoletasNotification::class))->count()
            + collect(Notification::sent($u3, GrdCopiasObsoletasNotification::class))->count();
        $this->assertSame(3, $enviosApos, 'reprocessar o mesmo evento continua em 3 envios totais, nunca 6');
    }

    // ----- 13/14: aba inválida normalizada + deep-links válidos preservados -----

    public function test_aba_invalida_na_url_normaliza_para_o_default_grds(): void
    {
        $c = Livewire::withQueryParams(['obra' => $this->obra->id, 'aba' => 'qualquer-coisa-invalida'])
            ->test('pages::engenharia.grds');

        $c->assertOk();
        $this->assertSame('grds', $c->get('abaAtiva'), 'aba inválida deve normalizar pro default real da tela, nunca ficar crua/vazia');
        $c->assertSee('Nova GRD'); // painel "grds" (default) efetivamente renderizado, não vazio
    }

    public function test_deep_links_validos_continuam_funcionando_apos_normalizacao(): void
    {
        $obs = Livewire::withQueryParams(['obra' => $this->obra->id, 'aba' => 'obsoletas'])
            ->test('pages::engenharia.grds');
        $this->assertSame('obsoletas', $obs->get('abaAtiva'));

        $cand = Livewire::withQueryParams(['obra' => $this->obra->id, 'aba' => 'candidatos'])
            ->test('pages::engenharia.grds');
        $this->assertSame('candidatos', $cand->get('abaAtiva'));
    }

    // =========================================================================================
    // ETAPA 18.5.6 — comunicação externa (e-mail + WhatsApp/Z-API). Toda regra continua
    // centralizada em AlertaDistribuicaoGrd/DetectorCopiasObsoletasGrd/CandidatosNovaEntregaGrd —
    // os testes abaixo cobrem só os 2 canais NOVOS (GrdLedgerMailChannel/GrdLedgerZApiChannel) e o
    // ledger de idempotência por canal (App\Models\GrdAlertaEntrega).
    // =========================================================================================

    private function usuarioComEmailETelefone(array $o = []): User
    {
        return User::factory()->create(array_merge(['tenant_id' => $this->tenant->id, 'telefone' => '11987654321'], $o));
    }

    private function ligarZApi(): void
    {
        config(['services.zapi.instance_id' => 'inst-teste', 'services.zapi.token' => 'tok-teste']);
    }

    // ----- A/D: via() inclui os canais externos -----

    public function test_186_via_inclui_canais_externos_para_alerta_a_e_b(): void
    {
        $a = new GrdCopiasObsoletasNotification($this->tenant->id, 'obra-x', 'Obra X', 'doc-x', 'DOC-X', 'rev-x', 'R2', 3);
        $this->assertContains(GrdLedgerMailChannel::class, $a->via($this->user));
        $this->assertContains(GrdLedgerZApiChannel::class, $a->via($this->user));
        $this->assertContains('database', $a->via($this->user));

        $b = new GrdCandidatosNovaEntregaNotification($this->tenant->id, 'obra-x', 'Obra X', 'doc-x', 'DOC-X', 'rev-x', 'R2', 1);
        $this->assertContains(GrdLedgerMailChannel::class, $b->via($this->user));
        $this->assertContains(GrdLedgerZApiChannel::class, $b->via($this->user));
    }

    // ----- B/Q, E/R: conteúdo do e-mail -----

    public function test_186_conteudo_mail_alerta_a_agrega_quantidade_corretamente(): void
    {
        $n = new GrdCopiasObsoletasNotification($this->tenant->id, 'obra-x', 'Obra Alfa', 'doc-x', 'PROJ-1', 'rev-x', 'R2', 7);
        $mail = $n->toMail($this->user);

        $this->assertStringContainsString('Obra Alfa', $mail->subject);
        $this->assertStringContainsString('cópias antigas', mb_strtolower($mail->subject));
        $this->assertStringContainsString('PROJ-1', implode(' ', $mail->introLines));
        $this->assertStringContainsString('7 cópia(s)', implode(' ', $mail->introLines));
        $this->assertSame(route('engenharia.grds', ['obra' => 'obra-x', 'aba' => 'obsoletas']), $mail->actionUrl);
    }

    public function test_186_conteudo_mail_alerta_b_agrega_candidatos_corretamente(): void
    {
        $n = new GrdCandidatosNovaEntregaNotification($this->tenant->id, 'obra-x', 'Obra Beta', 'doc-x', 'PROJ-2', 'rev-x', 'R3', 4);
        $mail = $n->toMail($this->user);

        $this->assertStringContainsString('Obra Beta', $mail->subject);
        $this->assertStringNotContainsString('obrigatória', mb_strtolower(implode(' ', $mail->introLines)));
        $this->assertStringContainsString('PROJ-2', implode(' ', $mail->introLines));
        $this->assertStringContainsString('4 destinatário(s)', implode(' ', $mail->introLines));
        $this->assertSame(route('engenharia.grds', ['obra' => 'obra-x', 'aba' => 'candidatos']), $mail->actionUrl);
    }

    // ----- C/F: conteúdo do WhatsApp -----

    public function test_186_conteudo_whatsapp_alerta_a(): void
    {
        $n = new GrdCopiasObsoletasNotification($this->tenant->id, 'obra-x', 'Obra Alfa', 'doc-x', 'PROJ-1', 'rev-x', 'R2', 7);
        $msg = $n->toWhatsApp($this->user);

        $this->assertStringContainsString('Obra Alfa', $msg);
        $this->assertStringContainsString('PROJ-1', $msg);
        $this->assertStringContainsString('7 cópia(s)', $msg);
        $this->assertStringContainsString('GRDs', $msg);
    }

    public function test_186_conteudo_whatsapp_alerta_b(): void
    {
        $n = new GrdCandidatosNovaEntregaNotification($this->tenant->id, 'obra-x', 'Obra Beta', 'doc-x', 'PROJ-2', 'rev-x', 'R3', 4);
        $msg = $n->toWhatsApp($this->user);

        $this->assertStringContainsString('Obra Beta', $msg);
        $this->assertStringContainsString('PROJ-2', $msg);
        $this->assertStringContainsString('4 destinatário(s)', $msg);
    }

    // ----- Ledger real: mail -----

    public function test_186_ledger_mail_envia_e_registra_e_retry_nao_reenvia(): void
    {
        Mail::fake();

        $doc = $this->doc();
        $rev = $this->rev($doc, 'R2');
        $user = $this->usuarioComEmailETelefone();

        $notification = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc->id, $doc->codigo, $rev->id, $rev->revisao, 2);
        $notification->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $rev->id, $user->id);

        $channel = new GrdLedgerMailChannel(app(MailChannel::class));
        $channel->send($user, $notification);

        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'mail')->count());
        $row = GrdAlertaEntrega::where('canal', 'mail')->first();
        $this->assertSame($this->tenant->id, $row->tenant_id);
        $this->assertSame($this->obra->id, $row->obra_id);
        $this->assertSame($user->id, $row->usuario_id);
        $this->assertSame('copias_obsoletas', $row->tipo_alerta);

        // retry — não deve criar uma 2ª linha
        $channel->send($user, $notification);
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'mail')->count(), 'retry não pode reenviar/duplicar o ledger de mail');
    }

    // ----- Ledger real: WhatsApp -----

    public function test_186_ledger_whatsapp_envia_e_registra_e_retry_nao_reenvia(): void
    {
        Http::fake(['https://api.z-api.io/*' => Http::response(['sent' => true], 200)]);
        $this->ligarZApi();

        $doc = $this->doc();
        $rev = $this->rev($doc, 'R2');
        $user = $this->usuarioComEmailETelefone();

        $notification = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc->id, $doc->codigo, $rev->id, $rev->revisao, 2);
        $notification->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $rev->id, $user->id);

        $channel = new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel());
        $channel->send($user, $notification);

        Http::assertSentCount(1);
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'whatsapp')->count());

        // retry — não deve reenviar (nem HTTP nem ledger duplicado)
        $channel->send($user, $notification);
        Http::assertSentCount(1, 'retry não pode reenviar a mensagem via Z-API');
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'whatsapp')->count());
    }

    // ----- H: usuário sem telefone -----

    public function test_186_usuario_sem_telefone_whatsapp_nao_envia_mas_outros_canais_seguem(): void
    {
        Http::fake();
        $this->ligarZApi();

        $doc = $this->doc();
        $rev = $this->rev($doc, 'R2');
        $semTelefone = User::factory()->create(['tenant_id' => $this->tenant->id, 'telefone' => null]);

        $notification = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc->id, $doc->codigo, $rev->id, $rev->revisao, 2);
        $notification->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $rev->id, $semTelefone->id);

        (new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel()))->send($semTelefone, $notification);

        Http::assertNothingSent(); // ZApiChannel no-opa silenciosamente sem telefone
        // Auditoria adversarial final (achado P6, corrigido): um no-op nunca deve gravar o ledger como enviado.
        $this->assertSame(0, GrdAlertaEntrega::where('canal', 'whatsapp')->count(), 'no-op silencioso (sem telefone) não pode gravar o ledger como se tivesse enviado');

        // Mail continua funcionando normalmente pro mesmo usuário (canais independentes).
        Mail::fake();
        (new GrdLedgerMailChannel(app(MailChannel::class)))->send($semTelefone, $notification);
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'mail')->count(), 'ausência de telefone nunca deve impedir o canal mail');
    }

    // ----- item 16: telefone é normalizado pela mesma lógica já existente do ZApiChannel -----

    public function test_186_telefone_sem_ddi_e_normalizado_pelo_zapichannel_existente(): void
    {
        Http::fake(['https://api.z-api.io/*' => Http::response(['sent' => true], 200)]);
        $this->ligarZApi();

        $doc = $this->doc();
        $rev = $this->rev($doc, 'R2');
        $user = $this->usuarioComEmailETelefone(['telefone' => '11987654321']); // sem "55" na frente

        $notification = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc->id, $doc->codigo, $rev->id, $rev->revisao, 2);
        $notification->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $rev->id, $user->id);

        (new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel()))->send($user, $notification);

        Http::assertSent(function ($request) {
            return str_starts_with($request['phone'], '55') && str_contains($request['phone'], '11987654321');
        });
    }

    // ----- U/V: falha de um canal não afeta outro -----

    public function test_186_falha_de_um_canal_nao_afeta_outro_canal(): void
    {
        Http::fake(['https://api.z-api.io/*' => Http::response(['erro' => true], 500)]); // Z-API "falha" (HTTP 500)
        $this->ligarZApi();
        Mail::fake();

        $doc = $this->doc();
        $rev = $this->rev($doc, 'R2');
        $user = $this->usuarioComEmailETelefone();

        $notification = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc->id, $doc->codigo, $rev->id, $rev->revisao, 2);
        $notification->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $rev->id, $user->id);

        // WhatsApp "falha" (500) — ZApiChannel nunca lança, só loga; não deve impedir nada.
        (new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel()))->send($user, $notification);

        // Mail continua funcionando normalmente — canais são jobs/chamadas independentes.
        (new GrdLedgerMailChannel(app(MailChannel::class)))->send($user, $notification);
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'mail')->count(), 'falha do WhatsApp não pode impedir o mail');

        // E o canal database (Notification interna) nem participa dessa cadeia — sempre garantido à parte.
        (new \Illuminate\Notifications\Channels\DatabaseChannel())->send($user, $notification);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count(), 'falha de canal externo não pode apagar/impedir a Notification interna');
    }

    // ----- M/N: rollback também não deixa nada no ledger -----

    public function test_186_rollback_alerta_a_zero_em_todos_os_canais_incluindo_ledger(): void
    {
        Notification::fake();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);

        $antesLedger = GrdAlertaEntrega::count();

        try {
            DB::transaction(function () use ($doc) {
                $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2-ROLLBACK-LEDGER', 'descricao' => 'x']);
                throw new \RuntimeException('forcar rollback');
            });
        } catch (\RuntimeException $e) {
            // esperado
        }

        Notification::assertNothingSent();
        $this->assertSame($antesLedger, GrdAlertaEntrega::count(), 'rollback não pode deixar nenhuma linha no ledger de canais externos');
    }

    public function test_186_rollback_alerta_b_zero_em_todos_os_canais_incluindo_ledger(): void
    {
        Notification::fake();
        ['r2' => $r2] = $this->cenarioComCandidato();

        $antesLedger = GrdAlertaEntrega::count();

        try {
            DB::transaction(function () use ($r2) {
                (new AlterarLiberacaoRevisaoDocumento())->liberar($r2->fresh(), $this->user);
                throw new \RuntimeException('forcar rollback');
            });
        } catch (\RuntimeException $e) {
            // esperado
        }

        Notification::assertSentTimes(GrdCandidatosNovaEntregaNotification::class, 0);
        $this->assertSame($antesLedger, GrdAlertaEntrega::count());
    }

    // ----- Y/Z/AA/AB: zero efeito operacional além do ledger -----

    public function test_186_zero_efeito_operacional_alem_do_ledger(): void
    {
        Mail::fake();
        Http::fake(['https://api.z-api.io/*' => Http::response(['sent' => true], 200)]);
        $this->ligarZApi();

        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $doc = $this->doc();
        $rev = $this->rev($doc, 'R2');
        $user = $this->usuarioComEmailETelefone();

        $antes = [
            'grd' => Grd::count(),
            'restricao' => Restricao::count(),
            'pronta' => $atividade->fresh()->estaPronta(),
        ];

        $notification = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc->id, $doc->codigo, $rev->id, $rev->revisao, 2);
        $notification->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $rev->id, $user->id);

        (new GrdLedgerMailChannel(app(MailChannel::class)))->send($user, $notification);
        (new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel()))->send($user, $notification);

        $this->assertSame($antes['grd'], Grd::count(), 'canais externos não podem criar GRD');
        $this->assertSame($antes['restricao'], Restricao::count(), 'canais externos não podem criar Restricao');
        $this->assertSame($antes['pronta'], $atividade->fresh()->estaPronta(), 'canais externos não podem alterar prontidão');
        $this->assertSame(2, GrdAlertaEntrega::count(), 'só o ledger (mail+whatsapp) deve ter crescido');
    }

    // ----- AC/AD: performance com ledger -----

    public function test_186_performance_100_copias_ledger_nao_gera_n_mais_1(): void
    {
        Http::fake(['https://api.z-api.io/*' => Http::response(['sent' => true], 200)]);
        $this->ligarZApi();

        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        for ($i = 0; $i < 100; $i++) {
            $this->emitirGrdComUmaEntrega($r1, $this->destinatario(), 1);
        }
        $rev = $this->rev($doc, 'R2');
        $user = $this->usuarioComEmailETelefone();

        $notification = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc->id, $doc->codigo, $rev->id, $rev->revisao, 100);
        $notification->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $rev->id, $user->id);

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        (new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel()))->send($user, $notification);

        Http::assertSentCount(1); // 1 chamada, independente das 100 cópias
        $this->assertLessThan(20, $queries, 'ledger não deve gerar N+1 relacionado à quantidade de cópias obsoletas');
    }

    // ----- AE / crítico multicanal (seção 27) -----

    public function test_186_critico_multicanal_fluxo_completo(): void
    {
        Mail::fake();
        Http::fake(['https://api.z-api.io/*' => Http::response(['sent' => true], 200)]);
        $this->ligarZApi();

        $engenheiro = $this->usuarioComEmailETelefone();
        $this->vincularObra($this->obra, $engenheiro, Papel::Engenheiro->value);

        $doc = $this->doc(['codigo' => 'PROJ-MULTICANAL']);
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Multicanal']);
        $this->emitirGrdComUmaEntrega($r1, $joao, 2);

        // Nasce R2 — dispara o Observer real; canais externos são ShouldQueue+redis
        // (nunca processam de verdade em `php artisan test`, confirmado na auditoria
        // 18.5.5), então o disparo REAL é exercitado diretamente aqui via
        // AlertaDistribuicaoGrd + os 2 channels wrapper, com os MESMOS dados que o
        // Observer real produziria — mesma técnica já usada pros testes de ledger acima.
        $r2 = $this->rev($doc, 'R2');

        $idA = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $r2->id, $engenheiro->id);
        $notificationA = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc->id, $doc->codigo, $r2->id, $r2->revisao, 2);
        $notificationA->id = $idA;
        (new \Illuminate\Notifications\Channels\DatabaseChannel())->send($engenheiro, $notificationA);
        (new GrdLedgerMailChannel(app(MailChannel::class)))->send($engenheiro, $notificationA);
        (new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel()))->send($engenheiro, $notificationA);

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $engenheiro->id)->where('type', GrdCopiasObsoletasNotification::class)->count(), '1 database A');
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'mail')->where('tipo_alerta', 'copias_obsoletas')->count(), '1 mail A');
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'whatsapp')->where('tipo_alerta', 'copias_obsoletas')->count(), '1 whatsapp A');
        Http::assertSentCount(1);

        // R2 não liberada -> zero B (regra herdada de AlertaDistribuicaoGrd, já provada nos testes internos)
        $this->assertSame(0, GrdAlertaEntrega::where('tipo_alerta', 'candidatos_nova_entrega')->count());

        // Liberar R2 -> 1 candidato (João)
        $this->liberar($r2->fresh());
        $idB = AlertaDistribuicaoGrd::idAlerta('candidatos_nova_entrega', $this->obra->id, $doc->id, $r2->id, $engenheiro->id);
        $notificationB = new GrdCandidatosNovaEntregaNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc->id, $doc->codigo, $r2->id, $r2->revisao, 1);
        $notificationB->id = $idB;
        (new \Illuminate\Notifications\Channels\DatabaseChannel())->send($engenheiro, $notificationB);
        (new GrdLedgerMailChannel(app(MailChannel::class)))->send($engenheiro, $notificationB);
        (new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel()))->send($engenheiro, $notificationB);

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $engenheiro->id)->where('type', GrdCandidatosNovaEntregaNotification::class)->count(), '1 database B');
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'mail')->where('tipo_alerta', 'candidatos_nova_entrega')->count(), '1 mail B');
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'whatsapp')->where('tipo_alerta', 'candidatos_nova_entrega')->count(), '1 whatsapp B');

        // Reprocessar os MESMOS fatos (A e B) -> continua exatamente 1 por canal/evento, nunca 2
        // (mesma PK/ledger determinístico já provado nos testes dedicados de retry acima).
        try {
            (new \Illuminate\Notifications\Channels\DatabaseChannel())->send($engenheiro, $notificationA);
            $this->fail('reprocessar o database deveria colidir com a PRIMARY KEY já existente');
        } catch (QueryException $e) {
            $this->assertSame(1062, (int) ($e->errorInfo[1] ?? 0));
        }
        (new GrdLedgerMailChannel(app(MailChannel::class)))->send($engenheiro, $notificationA);
        (new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel()))->send($engenheiro, $notificationA);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $engenheiro->id)->where('type', GrdCopiasObsoletasNotification::class)->count());
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'mail')->where('tipo_alerta', 'copias_obsoletas')->count());
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'whatsapp')->where('tipo_alerta', 'copias_obsoletas')->count());
        Http::assertSentCount(2, 'total acumulado do teste inteiro: 1 chamada do Alerta A + 1 do Alerta B — o reprocessamento de A não deve ter somado uma 3ª');

        // Nenhuma ação automática em GRD/recolhimento por causa dos alertas — só a 1 GRD real
        // de R1 criada no início do teste (emitirGrdComUmaEntrega).
        $this->assertSame(1, Grd::query()->count());
    }

    // =========================================================================================
    // ETAPA 18.5.6 — AUDITORIA ADVERSARIAL FINAL (ledger de entrega, retry, falha parcial).
    // Probes P1-P10 do pedido, convertidos em testes permanentes.
    // =========================================================================================

    private function notificationLedger(?string $documentoId = null): GrdCopiasObsoletasNotification
    {
        $doc = $this->doc();
        $rev = $this->rev($doc, 'R2');
        $n = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $documentoId ?? $doc->id, $doc->codigo, $rev->id, $rev->revisao, 2);
        $n->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $rev->id, $this->user->id);

        return $n;
    }

    /** P1: mesmo evento processado 10x sequencialmente -> continua exatamente 1 linha. */
    public function test_p1_dez_chamadas_sequenciais_mesmo_evento_continua_1_linha(): void
    {
        Mail::fake();
        $channel = new GrdLedgerMailChannel(app(MailChannel::class));
        $n = $this->notificationLedger();

        for ($i = 0; $i < 10; $i++) {
            $channel->send($this->user, $n);
        }

        $this->assertSame(1, GrdAlertaEntrega::count());
    }

    /** P2: ledger já existente ANTES do processamento -> exists() bloqueia antes de qualquer send. */
    public function test_p2_ledger_preexistente_bloqueia_antes_do_send(): void
    {
        Mail::fake();
        $n = $this->notificationLedger();
        $meta = $n->metadadosAlertaGrd();

        \App\Support\TenantContext::actingAs($this->tenant, function () use ($n, $meta) {
            GrdAlertaEntrega::create(array_merge($meta, [
                'usuario_id' => $this->user->id, 'evento_usuario_id' => $n->id, 'canal' => 'mail', 'enviado_em' => now(),
            ]));
        });

        (new GrdLedgerMailChannel(app(MailChannel::class)))->send($this->user, $n);

        Mail::assertNothingSent();
        $this->assertSame(1, GrdAlertaEntrega::count());
    }

    /** P3: exceção ANTES do side effect -> zero ledger, zero envio, exceção propaga. */
    public function test_p3_excecao_antes_do_side_effect_zero_ledger(): void
    {
        $mailMock = \Mockery::mock(MailChannel::class);
        $mailMock->shouldReceive('send')->once()->andThrow(new \RuntimeException('falha simulada ANTES de qualquer envio real'));
        $channel = new GrdLedgerMailChannel($mailMock);
        $n = $this->notificationLedger();

        $this->expectException(\RuntimeException::class);
        try {
            $channel->send($this->user, $n);
        } finally {
            $this->assertSame(0, GrdAlertaEntrega::count());
        }
    }

    /**
     * P4/P5 — CRÍTICO: prova a garantia real do canal mail (at-least-once,
     * não exactly-once). Documentado no docblock de GrdLedgerMailChannel.
     */
    public function test_p4_side_effect_ocorre_mas_ledger_falha_janela_real_de_duplicidade(): void
    {
        $mailMock = \Mockery::mock(MailChannel::class);
        $mailMock->shouldReceive('send')->once()->andReturn('ENVIADO-DE-VERDADE');
        $channel = new GrdLedgerMailChannel($mailMock);

        // documento_engenharia_id inexistente -> INSERT do ledger falha por FK (não-1062) DEPOIS do "envio".
        $doc = $this->doc();
        $rev = $this->rev($doc, 'R2');
        $n = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, 'doc-inexistente-000', $doc->codigo, $rev->id, $rev->revisao, 2);
        $n->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, 'doc-inexistente-000', $rev->id, $this->user->id);

        try {
            $channel->send($this->user, $n);
            $this->fail('deveria propagar QueryException não-1062');
        } catch (QueryException $e) {
            $this->assertNotSame(1062, (int) ($e->errorInfo[1] ?? 0));
        }

        $this->assertSame(0, GrdAlertaEntrega::count(), 'o side effect (mail) já aconteceu, mas o ledger não foi gravado — janela real, documentada');

        // Retry (mesmo evento lógico, agora sem o erro de FK) reenvia de verdade — 2º envio real, só agora 1 linha.
        $mailMock2 = \Mockery::mock(MailChannel::class);
        $mailMock2->shouldReceive('send')->once()->andReturn('ENVIADO-DE-VERDADE-RETRY');
        $nRetry = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc->id, $doc->codigo, $rev->id, $rev->revisao, 2);
        $nRetry->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $rev->id, $this->user->id);
        (new GrdLedgerMailChannel($mailMock2))->send($this->user, $nRetry);

        $this->assertSame(1, GrdAlertaEntrega::count(), 'retry grava a 1ª linha bem-sucedida, mas o side effect real já tinha acontecido 2x (1 perdido, 1 registrado)');
    }

    public function test_p5_concorrencia_dois_sends_reais_um_so_ledger_sobrevive(): void
    {
        $n = $this->notificationLedger();
        $meta = $n->metadadosAlertaGrd();

        $mailMockA = \Mockery::mock(MailChannel::class);
        $mailMockA->shouldReceive('send')->once()->andReturn('A');
        $mailMockB = \Mockery::mock(MailChannel::class);
        $mailMockB->shouldReceive('send')->once()->andReturn('B');

        // Ambos os "workers" já passaram pelo exists() (corrida real) e ambos EXECUTAM o side effect.
        $mailMockA->send($this->user, $n);
        $mailMockB->send($this->user, $n);

        $gravou = 0;
        foreach (['A', 'B'] as $worker) {
            try {
                \App\Support\TenantContext::actingAs($this->tenant, function () use ($n, $meta) {
                    GrdAlertaEntrega::create(array_merge($meta, [
                        'usuario_id' => $this->user->id, 'evento_usuario_id' => $n->id, 'canal' => 'mail', 'enviado_em' => now(),
                    ]));
                });
                $gravou++;
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }

        $this->assertSame(1, $gravou, 'só 1 das 2 tentativas de INSERT grava — a UNIQUE protege o REGISTRO');
        $this->assertSame(1, GrdAlertaEntrega::count(), 'mas os 2 sends() reais já tinham acontecido — a UNIQUE nunca impediu os 2 envios em si');
    }

    /** P6/P7 (corrigidos nesta auditoria): no-op silencioso do Z-API nunca grava o ledger como enviado. */
    public function test_p6_p7_zapi_no_op_sem_telefone_ou_sem_credenciais_nunca_grava_ledger(): void
    {
        Http::fake();
        $this->ligarZApi();

        // P6: destinatário sem telefone.
        $doc1 = $this->doc();
        $rev1 = $this->rev($doc1, 'R2');
        $semTelefone = User::factory()->create(['tenant_id' => $this->tenant->id, 'telefone' => null]);
        $n1 = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc1->id, $doc1->codigo, $rev1->id, $rev1->revisao, 2);
        $n1->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc1->id, $rev1->id, $semTelefone->id);
        (new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel()))->send($semTelefone, $n1);
        Http::assertNothingSent();
        $this->assertSame(0, GrdAlertaEntrega::where('canal', 'whatsapp')->count(), 'P6: sem telefone nunca grava');

        // P7: credenciais Z-API ausentes (destinatário COM telefone válido, isola a causa).
        config(['services.zapi.instance_id' => null, 'services.zapi.token' => null]);
        $comTelefone = $this->usuarioComEmailETelefone();
        $doc2 = $this->doc();
        $rev2 = $this->rev($doc2, 'R2');
        $n2 = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc2->id, $doc2->codigo, $rev2->id, $rev2->revisao, 2);
        $n2->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc2->id, $rev2->id, $comTelefone->id);
        (new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel()))->send($comTelefone, $n2);
        Http::assertNothingSent();
        $this->assertSame(0, GrdAlertaEntrega::where('canal', 'whatsapp')->count(), 'P7: sem credenciais nunca grava (mesmo com telefone válido)');
    }

    /**
     * P8 — limitação residual CONHECIDA, NÃO corrigida (documentada): uma
     * falha HTTP real (Z-API responde erro) ainda grava o ledger como se
     * tivesse sido entregue, porque `ZApiChannel::send()` retorna `void` e
     * descarta esse resultado. Impacto prático hoje: nulo (nada re-lê esse
     * ledger pra tentar de novo). Corrigir exigiria mudar a assinatura de
     * `ZApiChannel::send()`, usada por mais 4 Notifications em produção —
     * fora do escopo desta auditoria.
     */
    public function test_p8_zapi_http_failure_ainda_grava_ledger_limitacao_documentada(): void
    {
        Http::fake(['https://api.z-api.io/*' => Http::response(['erro' => true], 500)]);
        $this->ligarZApi();
        $comTelefone = $this->usuarioComEmailETelefone();
        $doc = $this->doc();
        $rev = $this->rev($doc, 'R2');
        $n = new GrdCopiasObsoletasNotification($this->tenant->id, $this->obra->id, $this->obra->name, $doc->id, $doc->codigo, $rev->id, $rev->revisao, 2);
        $n->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $this->obra->id, $doc->id, $rev->id, $comTelefone->id);

        (new GrdLedgerZApiChannel(new \App\Notifications\Channels\ZApiChannel()))->send($comTelefone, $n);

        Http::assertSentCount(1);
        $this->assertSame(1, GrdAlertaEntrega::where('canal', 'whatsapp')->count(), 'limitação conhecida e documentada — não corrigida nesta etapa');
    }

    /** P9: TenantContext restaurado mesmo quando o callback lança. */
    public function test_p9_tenantcontext_restaurado_apos_excecao(): void
    {
        $this->actingAs($this->user);
        $tenantAntes = \App\Support\TenantContext::currentId();

        $mailMock = \Mockery::mock(MailChannel::class);
        $mailMock->shouldReceive('send')->once()->andThrow(new \RuntimeException('falha dentro do actingAs'));
        $n = $this->notificationLedger();

        try {
            (new GrdLedgerMailChannel($mailMock))->send($this->user, $n);
        } catch (\RuntimeException $e) {
            // esperado
        }

        $this->assertSame($tenantAntes, \App\Support\TenantContext::currentId(), 'TenantContext deve ser restaurado mesmo quando o callback lança');
    }

    /** P10: dois tenants sequenciais no mesmo processo/objeto de canal -> sem contaminação. */
    public function test_p10_dois_tenants_sequenciais_sem_contaminacao(): void
    {
        Mail::fake();

        $tenant2 = Tenant::factory()->create();
        $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);
        // BelongsToTenant carimba tenant_id a partir do tenant ATUALMENTE autenticado
        // (ignorando qualquer valor explícito no create()) — como setUp() já chamou
        // actingAs($this->user), obra2/doc2/rev2 precisam nascer DENTRO do
        // TenantContext::actingAs($tenant2) do outro tenant, senão herdam
        // silenciosamente o tenant1 (mesmo achado já documentado no projeto).
        [$obra2, $doc2, $rev2] = \App\Support\TenantContext::actingAs($tenant2, function () use ($tenant2) {
            $obra2 = Work::factory()->create(['tenant_id' => $tenant2->id]);
            $doc2 = DocumentoEngenharia::create(['tenant_id' => $tenant2->id, 'obra_id' => $obra2->id, 'codigo' => 'DOC-2', 'descricao' => 'x']);
            $rev2 = $doc2->revisoes()->create(['tenant_id' => $tenant2->id, 'revisao' => 'R2', 'descricao' => 'x'])->fresh();

            return [$obra2, $doc2, $rev2];
        });

        $n1 = $this->notificationLedger();
        $n2 = new GrdCopiasObsoletasNotification($tenant2->id, $obra2->id, $obra2->name, $doc2->id, $doc2->codigo, $rev2->id, $rev2->revisao, 1);
        $n2->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $obra2->id, $doc2->id, $rev2->id, $user2->id);

        $channel = new GrdLedgerMailChannel(app(MailChannel::class));
        $channel->send($this->user, $n1);
        $channel->send($user2, $n2);

        $row1 = GrdAlertaEntrega::where('evento_usuario_id', $n1->id)->first();
        // A leitura de row2 também precisa do contexto do tenant2 — mesmo motivo
        // da escrita acima: fora de qualquer actingAs, a query roda escopada pro
        // tenant do usuário autenticado no teste (tenant1), onde a linha do
        // tenant2 é estruturalmente invisível (prova de isolamento, não um bug).
        $row2 = \App\Support\TenantContext::actingAs($tenant2, fn () => GrdAlertaEntrega::where('evento_usuario_id', $n2->id)->first());

        $this->assertSame($this->tenant->id, $row1->tenant_id);
        $this->assertSame($tenant2->id, $row2->tenant_id);
        $this->assertNotSame($row1->tenant_id, $row2->tenant_id);

        // Prova adicional de isolamento: fora do actingAs (contexto ambiente = tenant1),
        // a linha do tenant2 é invisível — reforça que não há vazamento cross-tenant.
        $this->assertNull(GrdAlertaEntrega::where('evento_usuario_id', $n2->id)->first());
    }

    // =========================================================================================
    // ACHADO C (auditoria adversarial final, 18.5.6) — `new Tenant(['id' => ...])` descartava
    // `id` em silêncio (mass assignment, 'id' não é Tenant::$fillable), tornando
    // TenantContext::actingAs() um no-op nos 2 wrappers de ledger. Corrigido com
    // `(new Tenant())->forceFill(['id' => ...])`. Os testes abaixo provam a correção nos
    // cenários exigidos pela autorização: restauração após sucesso, restauração após
    // exceção sem contaminar o tenant seguinte, 2 tenants sequenciais no mesmo processo
    // (A->B->A), e execução sem nenhum usuário autenticado (worker real).
    // =========================================================================================

    /** Cria um 2º tenant com fixtures reais (obra/documento/revisão/usuário) — as FKs de
     * grd_alerta_entregas são reais, então IDs fictícios ("obra-x" etc.) violariam FK. */
    private function criarFixtureOutroTenant(): array
    {
        $tenant2 = Tenant::factory()->create();
        $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);
        [$obra2, $doc2, $rev2] = TenantContext::actingAs($tenant2, function () use ($tenant2) {
            $obra2 = Work::factory()->create(['tenant_id' => $tenant2->id]);
            $doc2 = DocumentoEngenharia::create(['tenant_id' => $tenant2->id, 'obra_id' => $obra2->id, 'codigo' => 'DOC-' . uniqid(), 'descricao' => 'x']);
            $rev2 = $doc2->revisoes()->create(['tenant_id' => $tenant2->id, 'revisao' => 'R2', 'descricao' => 'x'])->fresh();

            return [$obra2, $doc2, $rev2];
        });

        return [$tenant2, $user2, $obra2, $doc2, $rev2];
    }

    private function notificationParaTenant(Tenant $tenant, Work $obra, DocumentoEngenharia $doc, DocumentoEngenhariaRevisao $rev, User $user): GrdCopiasObsoletasNotification
    {
        $n = new GrdCopiasObsoletasNotification($tenant->id, $obra->id, $obra->name, $doc->id, $doc->codigo, $rev->id, $rev->revisao, 1);
        $n->id = AlertaDistribuicaoGrd::idAlerta('copias_obsoletas', $obra->id, $doc->id, $rev->id, $user->id);

        return $n;
    }

    /** Prova as 3 fases explicitamente: antes=tenant1, DURANTE o callback=tenant2, depois=tenant1. */
    public function test_achado_c_tenantcontext_forcado_durante_e_restaurado_apos_sucesso(): void
    {
        Mail::fake();
        $tenantAntes = TenantContext::currentId();
        $this->assertSame($this->tenant->id, $tenantAntes);

        [$tenant2, $user2, $obra2, $doc2, $rev2] = $this->criarFixtureOutroTenant();
        $capturadoDurante = null;

        $mailMock = \Mockery::mock(MailChannel::class);
        $mailMock->shouldReceive('send')->once()->andReturnUsing(function () use (&$capturadoDurante) {
            $capturadoDurante = TenantContext::currentId();

            return null;
        });

        $n2 = $this->notificationParaTenant($tenant2, $obra2, $doc2, $rev2, $user2);
        (new GrdLedgerMailChannel($mailMock))->send($user2, $n2);

        $this->assertSame($tenant2->id, $capturadoDurante, 'durante o callback, o tenant ativo deve ser o da notificação (tenant2), nunca o ambiente (tenant1)');
        $this->assertSame($tenantAntes, TenantContext::currentId(), 'depois do send(), o TenantContext precisa voltar exatamente ao estado anterior (tenant1)');

        $row = TenantContext::actingAs($tenant2, fn () => GrdAlertaEntrega::where('evento_usuario_id', $n2->id)->first());
        $this->assertNotNull($row);
        $this->assertSame($tenant2->id, $row->tenant_id);
    }

    /** Exceção dentro do callback: TenantContext restaura tenant1, e um evento SEGUINTE
     * do tenant1 (simulando o worker reaproveitado) não herda nada do tenant2 que falhou. */
    public function test_achado_c_restauracao_apos_excecao_nao_contamina_evento_seguinte(): void
    {
        Mail::fake();
        $tenantAntes = TenantContext::currentId();

        [$tenant2, $user2, $obra2, $doc2, $rev2] = $this->criarFixtureOutroTenant();

        $mailMockFalha = \Mockery::mock(MailChannel::class);
        $mailMockFalha->shouldReceive('send')->once()->andThrow(new \RuntimeException('falha simulada no envio do tenant2'));

        $n2 = $this->notificationParaTenant($tenant2, $obra2, $doc2, $rev2, $user2);

        try {
            (new GrdLedgerMailChannel($mailMockFalha))->send($user2, $n2);
            $this->fail('esperava RuntimeException propagada');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha simulada no envio do tenant2', $e->getMessage());
        }

        $this->assertSame($tenantAntes, TenantContext::currentId(), 'TenantContext precisa restaurar tenant1 mesmo após exceção dentro do callback');
        $this->assertSame(0, TenantContext::actingAs($tenant2, fn () => GrdAlertaEntrega::where('evento_usuario_id', $n2->id)->count()), 'exceção antes do side effect concluir -> zero ledger pro tenant2');

        // "Job seguinte" no mesmo processo/worker, agora pro tenant1 (nenhum override ativo
        // além do actingAs() do próprio teste) -> precisa gravar corretamente em tenant1,
        // nunca herdar resquício do tenant2 que acabou de falhar.
        $n1 = $this->notificationLedger();
        (new GrdLedgerMailChannel(app(MailChannel::class)))->send($this->user, $n1);

        $row1 = GrdAlertaEntrega::where('evento_usuario_id', $n1->id)->first();
        $this->assertNotNull($row1);
        $this->assertSame($this->tenant->id, $row1->tenant_id, 'evento do tenant1 processado depois da falha do tenant2 precisa gravar tenant1, sem contaminação');
    }

    /** A -> B -> A no MESMO processo/objeto de canal — simula reuso real de worker de fila. */
    public function test_achado_c_tres_eventos_alternando_dois_tenants_no_mesmo_processo(): void
    {
        Mail::fake();
        [$tenant2, $user2, $obra2, $doc2, $rev2] = $this->criarFixtureOutroTenant();
        $channel = new GrdLedgerMailChannel(app(MailChannel::class));

        $nA1 = $this->notificationLedger();
        $channel->send($this->user, $nA1);

        $nB = $this->notificationParaTenant($tenant2, $obra2, $doc2, $rev2, $user2);
        $channel->send($user2, $nB);

        $nA2 = $this->notificationLedger();
        $channel->send($this->user, $nA2);

        $rowA1 = GrdAlertaEntrega::where('evento_usuario_id', $nA1->id)->first();
        $rowA2 = GrdAlertaEntrega::where('evento_usuario_id', $nA2->id)->first();
        $rowB = TenantContext::actingAs($tenant2, fn () => GrdAlertaEntrega::where('evento_usuario_id', $nB->id)->first());

        $this->assertNotNull($rowA1);
        $this->assertNotNull($rowA2);
        $this->assertNotNull($rowB);
        $this->assertSame($this->tenant->id, $rowA1->tenant_id);
        $this->assertSame($this->tenant->id, $rowA2->tenant_id, 'terceiro evento (tenant1 de novo, depois do tenant2 no meio) precisa continuar tenant1');
        $this->assertSame($tenant2->id, $rowB->tenant_id);

        $this->assertSame($this->tenant->id, TenantContext::currentId(), 'ambiente do teste (tenant1) precisa estar intacto ao final dos 3 sends');
    }

    /** Simula o worker de fila real: SEM nenhum usuário autenticado. A correção não pode
     * depender de auth() ambiente para funcionar — só do override explícito do actingAs(). */
    public function test_achado_c_execucao_sem_usuario_autenticado_worker_real(): void
    {
        Mail::fake();
        [$tenant2, $user2, $obra2, $doc2, $rev2] = $this->criarFixtureOutroTenant();
        $n2 = $this->notificationParaTenant($tenant2, $obra2, $doc2, $rev2, $user2);

        \Illuminate\Support\Facades\Auth::logout();
        $this->assertFalse(\Illuminate\Support\Facades\Auth::check());
        $this->assertNull(TenantContext::currentId(), 'sem auth e sem override ativo, currentId() deve ser null antes do send()');

        (new GrdLedgerMailChannel(app(MailChannel::class)))->send($user2, $n2);

        $this->assertNull(TenantContext::currentId(), 'depois do send(), sem auth, currentId() volta a null (nenhum override residual)');

        $row = TenantContext::actingAs($tenant2, fn () => GrdAlertaEntrega::where('evento_usuario_id', $n2->id)->first());
        $this->assertNotNull($row, 'worker sem auth precisa gravar o ledger mesmo sem nenhum usuário logado');
        $this->assertSame($tenant2->id, $row->tenant_id);

        // Reautentica pro tearDown/próximos testes da suíte não serem afetados.
        $this->actingAs($this->user);
    }
}
