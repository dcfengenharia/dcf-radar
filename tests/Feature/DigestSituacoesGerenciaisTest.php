<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Models\DocumentoEngenharia;
use App\Models\SituacaoComunicacaoEntrega;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\DigestSituacoesGerenciaisNotification;
use App\Support\Gestao\SincronizarSituacoesGerenciais;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Ciclo 21, Etapa 21.4 — Seção 25 do pedido: J, K, L, M, N (digest).
 * Reaproveita os mesmos helpers de fixture já usados em
 * `AutomacaoSituacoesGerenciaisTest`/`SincronizarSituacoesGerenciaisTest`
 * (Atividade + Documento não liberado → `DocumentoBloqueante`, único tipo
 * cuja política já é "sempre digest, nunca imediato" em severidade
 * Atenção — mesma classe usada em 21.4 pra provar E/E2) — o digest
 * NUNCA recalcula nada, só LÊ `SituacaoOcorrencia` já mantida em dia pelo
 * sincronizador (por isso todo teste chama `sincronizarObra()` antes de
 * rodar o Command de digest).
 */
class DigestSituacoesGerenciaisTest extends TestCase
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

    private function criarAtividade(array $overrides = [], ?Work $obra = null): \App\Models\Atividade
    {
        return \App\Models\Atividade::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Atividade '.uniqid(),
            'codigo_cronograma' => 'A'.uniqid(),
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => Carbon::today()->addDays(20), // Atenção (documentoBloqueante)
            'data_termino' => Carbon::today()->addDays(25),
            'fora_do_cronograma' => false,
        ], $overrides));
    }

    private function criarDocumentoBloqueante(\App\Models\Atividade $atividade, ?Work $obra = null, string $codigo = null): array
    {
        $obra ??= $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => $codigo ?? 'DOC-'.uniqid(), 'descricao' => 'Doc']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $doc->atividades()->sync([$atividade->id]);

        return [$doc, $rev];
    }

    private function contarEmailsDigest(): int
    {
        // Digest sempre nasce com o mesmo id determinístico
        // (`situacao-digest:{obra}:{dia}:{user}`) — o único jeito
        // confiável de contar "foi enviado" é o ledger de mail (mesma
        // técnica já usada em AutomacaoSituacoesGerenciaisTest), nunca
        // `notification->data`, que nunca expõe canais internos.
        return SituacaoComunicacaoEntrega::where('canal', 'mail')->count();
    }

    private function rodarDigest(): void
    {
        Artisan::call('gestao:digest-situacoes');
    }

    // =========================================================
    // J — Várias situações agrupam num único e-mail (por usuário/obra)
    // =========================================================

    public function test_j_varias_situacoes_geram_1_email_agregado(): void
    {
        $a1 = $this->criarAtividade();
        $this->criarDocumentoBloqueante($a1, null, 'DOC-J1');
        $a2 = $this->criarAtividade();
        $this->criarDocumentoBloqueante($a2, null, 'DOC-J2');

        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertSame(2, \App\Models\SituacaoOcorrencia::count());

        $this->rodarDigest();

        $this->assertSame(1, $this->contarEmailsDigest());
        $this->assertSame(1, $this->user->notifications()->where('type', DigestSituacoesGerenciaisNotification::class)->count());
    }

    // =========================================================
    // K — Digest vazio nunca envia nada
    // =========================================================

    public function test_k_sem_situacoes_elegiveis_zero_email(): void
    {
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertSame(0, \App\Models\SituacaoOcorrencia::count());

        $this->rodarDigest();

        $this->assertSame(0, $this->contarEmailsDigest());
        $this->assertSame(0, $this->user->notifications()->where('type', DigestSituacoesGerenciaisNotification::class)->count());
    }

    // =========================================================
    // L — Multi-obra: agrupamento correto, nunca misturado
    // =========================================================

    public function test_l_multiobra_agrupamento_correto_nunca_misturado(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::GerentePlanejamento->value);

        $a1 = $this->criarAtividade();
        $this->criarDocumentoBloqueante($a1, null, 'DOC-LA');
        $a2 = $this->criarAtividade([], $obraB);
        $this->criarDocumentoBloqueante($a2, $obraB, 'DOC-LB');

        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        SincronizarSituacoesGerenciais::sincronizarObra($obraB);

        $this->rodarDigest();

        // 2 obras elegíveis para o MESMO usuário -> 2 e-mails distintos
        // (1 por obra, nunca 1 combinando o tenant inteiro — Seção 9).
        $this->assertSame(2, $this->contarEmailsDigest());

        $notificacoes = $this->user->notifications()->where('type', DigestSituacoesGerenciaisNotification::class)->get();
        $this->assertCount(2, $notificacoes);
        $obraIds = $notificacoes->pluck('data.obra_id')->sort()->values()->all();
        $this->assertSame(collect([$this->obra->id, $obraB->id])->sort()->values()->all(), $obraIds);
    }

    // =========================================================
    // M — Multiusuário: isolamento correto por permissão
    // =========================================================

    public function test_m_multiusuario_isolamento_correto_por_permissao(): void
    {
        $usuarioSemAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // Nunca vinculado à obra — não deve ser candidato.

        $a1 = $this->criarAtividade();
        $this->criarDocumentoBloqueante($a1, null, 'DOC-M');
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->rodarDigest();

        $this->assertSame(1, $this->contarEmailsDigest());
        $this->assertSame(1, $this->user->notifications()->where('type', DigestSituacoesGerenciaisNotification::class)->count());
        $this->assertSame(0, $usuarioSemAcesso->notifications()->where('type', DigestSituacoesGerenciaisNotification::class)->count());
    }

    // =========================================================
    // N — Usuário perdeu acesso: nunca recebe, mesmo com ocorrência ativa
    // =========================================================

    public function test_n_usuario_que_perdeu_acesso_nao_recebe_digest(): void
    {
        $a1 = $this->criarAtividade();
        $this->criarDocumentoBloqueante($a1, null, 'DOC-N');
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        // Revalidação de acesso acontece no MOMENTO DO ENVIO, não na
        // detecção (Seção 17/18) — desvincular a obra do usuário depois
        // da ocorrência já existir precisa bloquear o digest mesmo assim.
        $this->obra->users()->detach($this->user->id);

        $this->rodarDigest();

        $this->assertSame(0, $this->contarEmailsDigest());
        $this->assertSame(0, $this->user->notifications()->where('type', DigestSituacoesGerenciaisNotification::class)->count());
    }

    public function test_n2_usuario_inativo_nao_recebe_digest(): void
    {
        $a1 = $this->criarAtividade();
        $this->criarDocumentoBloqueante($a1, null, 'DOC-N2');
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->user->update(['ativo' => false]);

        $this->rodarDigest();

        $this->assertSame(0, $this->contarEmailsDigest());
    }

    // =========================================================
    // Idempotência do digest (mesmo dia, 2 execuções -> 1 e-mail só)
    // =========================================================

    public function test_idempotencia_mesmo_dia_nao_duplica(): void
    {
        $a1 = $this->criarAtividade();
        $this->criarDocumentoBloqueante($a1, null, 'DOC-IDEMP');
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->rodarDigest();
        $this->rodarDigest();
        $this->rodarDigest();

        $this->assertSame(1, $this->contarEmailsDigest());
    }
}
