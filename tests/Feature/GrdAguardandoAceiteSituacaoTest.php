<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Actions\Engenharia\AtualizarRascunhoGrd;
use App\Actions\Engenharia\CriarGrd;
use App\Actions\Engenharia\EmitirGrd;
use App\Actions\Engenharia\InvalidarAceiteEntrega;
use App\Actions\Engenharia\RegistrarAceiteEntrega;
use App\Enums\Papel;
use App\Enums\SeveridadeSituacao;
use App\Enums\StatusSituacaoOcorrencia;
use App\Enums\TipoAceiteGrd;
use App\Enums\TipoSituacaoGerencial;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\SituacaoOcorrencia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Gestao\CockpitEngenhariaQuery;
use App\Support\Gestao\CockpitObraQuery;
use App\Support\Gestao\PoliticaEntregaSituacao;
use App\Support\Gestao\SincronizarSituacoesGerenciais;
use App\Support\Gestao\SituacoesGerenciaisQuery;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 22, Etapa 22.3 — integração seletiva de Engenharia ao motor
 * gerencial. Único fato promovido: `TipoSituacaoGerencial::
 * GrdAguardandoAceite`. Cobre os cenários H-T + R (dedup/50x) + Q
 * (escalada, documentada como N/A pra este tipo — severidade sempre
 * `Informativa`, sem possibilidade de escalar) da Seção 30/31, mais
 * testes negativos confirmando que Industrialização/Suprimentos/
 * cópia obsoleta NUNCA foram promovidas (decisão desta etapa).
 */
class GrdAguardandoAceiteSituacaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

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

    // ---- helpers ----

    private function doc(?Work $obra = null): DocumentoEngenharia
    {
        return DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => ($obra ?? $this->obra)->id,
            'codigo' => 'DOC-' . uniqid(), 'descricao' => 'x',
        ]);
    }

    private function rev(DocumentoEngenharia $d): DocumentoEngenhariaRevisao
    {
        return $d->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R1', 'descricao' => 'x'])->fresh();
    }

    private function destinatario(?Work $obra = null): Destinatario
    {
        return Destinatario::create(['tenant_id' => $this->tenant->id, 'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Dest ' . uniqid()]);
    }

    /** @return array{grd, item, gd, dist} */
    private function grdEmitidaComDistribuicao(DocumentoEngenhariaRevisao $revisao, ?Work $obra = null, ?User $usuario = null): array
    {
        $obra ??= $this->obra;
        $usuario ??= $this->user;
        if (! $revisao->fresh()->estaLiberadaParaConstrucao()) {
            (new AlterarLiberacaoRevisaoDocumento())->liberar($revisao->fresh(), $usuario);
        }
        $dest = $this->destinatario($obra);
        $grd = (new CriarGrd())->execute($obra, $usuario);
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $revisao->fresh());
        $gd = $acoes->adicionarDestinatario($grd, $dest);
        $dist = $acoes->marcarDistribuicao($grd, $item, $gd, 2);
        $grd = (new EmitirGrd())->execute($grd, $usuario);

        return compact('grd', 'item', 'gd', 'dist');
    }

    private function chave(string $destinatarioId): string
    {
        return "grd_aguardando_aceite:{$destinatarioId}";
    }

    // =========================================================================
    // H-J — Aparece / resolve / reabre
    // =========================================================================

    public function test_h_grd_aguardando_aceite_aparece(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc);
        $this->grdEmitidaComDistribuicao($r);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        $situacao = $situacoes->first(fn ($s) => $s->tipo === TipoSituacaoGerencial::GrdAguardandoAceite);

        $this->assertNotNull($situacao);
        $this->assertSame(SeveridadeSituacao::Informativa, $situacao->severidade);
    }

    public function test_i_aceite_concluido_resolve(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc);
        ['gd' => $gd] = $this->grdEmitidaComDistribuicao($r);

        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $chave = $this->chave($gd->id);
        $ocorrencia = SituacaoOcorrencia::where('chave_logica', $chave)->firstOrFail();
        $this->assertSame(StatusSituacaoOcorrencia::Ativa, $ocorrencia->status);
        $this->assertCount(1, $this->user->notifications()->get());

        (new RegistrarAceiteEntrega())->execute($gd->fresh(), TipoAceiteGrd::SemAssinatura, 'Recebedor', $this->user);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertSame(StatusSituacaoOcorrencia::Resolvida, $ocorrencia->fresh()->status);
        $this->assertNotNull($ocorrencia->fresh()->resolvida_em);
        // resolução nunca gera comunicação nova (mesma regra do resto do Ciclo 21).
        $this->assertCount(1, $this->user->notifications()->get());
    }

    public function test_p_reabertura_via_invalidacao_do_aceite(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc);
        ['gd' => $gd] = $this->grdEmitidaComDistribuicao($r);

        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $aceite = (new RegistrarAceiteEntrega())->execute($gd->fresh(), TipoAceiteGrd::SemAssinatura, 'Recebedor', $this->user);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $chave = $this->chave($gd->id);
        $ocorrencia = SituacaoOcorrencia::where('chave_logica', $chave)->firstOrFail();
        $this->assertSame(StatusSituacaoOcorrencia::Resolvida, $ocorrencia->status);
        $this->assertSame(1, $ocorrencia->episodio);

        (new InvalidarAceiteEntrega())->execute($aceite, 'assinatura errada', $this->user);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $ocorrencia = $ocorrencia->fresh();
        $this->assertSame(StatusSituacaoOcorrencia::Ativa, $ocorrencia->status, 'invalidar o aceite reabre a situação (novo episódio).');
        $this->assertSame(2, $ocorrencia->episodio);
        $this->assertNull($ocorrencia->resolvida_em);
        $this->assertCount(2, $this->user->notifications()->get(), 'reabertura gera nova comunicação.');
    }

    // =========================================================================
    // N-O — Multi-obra / cross-tenant
    // =========================================================================

    public function test_n_multiobra_isolamento(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $docB = $this->doc($obraB);
        $rB = $this->rev($docB);
        $this->grdEmitidaComDistribuicao($rB, $obraB);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra, 28);

        $this->assertEmpty($situacoes->filter(fn ($s) => $s->tipo === TipoSituacaoGerencial::GrdAguardandoAceite));
    }

    public function test_o_cross_tenant_nunca_vaza(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);

        TenantContext::actingAs($outroTenant, function () use ($outroTenant, $outroUser) {
            $obraOutro = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $doc = DocumentoEngenharia::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'codigo' => 'DOC-OUTRO', 'descricao' => 'x']);
            $rev = $doc->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R1', 'descricao' => 'x'])->fresh();
            (new AlterarLiberacaoRevisaoDocumento())->liberar($rev->fresh(), $outroUser);
            $dest = Destinatario::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'nome' => 'Dest Outro']);
            $grd = (new CriarGrd())->execute($obraOutro, $outroUser);
            $acoes = new AtualizarRascunhoGrd();
            $item = $acoes->adicionarItem($grd, $rev->fresh());
            $gd = $acoes->adicionarDestinatario($grd, $dest);
            $acoes->marcarDistribuicao($grd, $item, $gd, 1);
            (new EmitirGrd())->execute($grd, $outroUser);
        });

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra, 28);

        $this->assertEmpty($situacoes->filter(fn ($s) => $s->tipo === TipoSituacaoGerencial::GrdAguardandoAceite));
    }

    // =========================================================================
    // Q — Escalada (N/A pra este tipo, documentado explicitamente)
    // =========================================================================

    public function test_q_severidade_permanece_sempre_informativa_nunca_escala(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc);
        $this->grdEmitidaComDistribuicao($r);

        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        // Passa o tempo — nada no domínio de aceite eleva severidade (sem
        // prazo/SLA formal, confirmado no fresh-read). Reafirma que o
        // tipo nunca escala, por design, nunca por omissão de teste.
        Carbon::setTestNow(now()->addDays(30));
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $ocorrencia = SituacaoOcorrencia::where('tipo', TipoSituacaoGerencial::GrdAguardandoAceite)->firstOrFail();
        $this->assertSame(SeveridadeSituacao::Informativa, $ocorrencia->severidade_atual);
        $this->assertCount(1, $this->user->notifications()->get(), 'sem escalada, sem imediato — nenhuma comunicação nova além da 1ª detecção.');
    }

    // =========================================================================
    // R — Dedup sob 50 sincronizações
    // =========================================================================

    public function test_r_cinquenta_sincronizacoes_identicas_produzem_1_comunicacao(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc);
        $this->grdEmitidaComDistribuicao($r);

        for ($i = 0; $i < 50; $i++) {
            SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        }

        $this->assertSame(1, SituacaoOcorrencia::where('tipo', TipoSituacaoGerencial::GrdAguardandoAceite)->count());
        $this->assertCount(1, $this->user->notifications()->get());
    }

    // =========================================================================
    // S — Ganho/perda de permissão
    // =========================================================================

    public function test_s_usuario_ganha_e_perde_permissao(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc);
        $this->grdEmitidaComDistribuicao($r);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $novoUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->assertSame(0, $novoUsuario->notifications()->count());

        $this->vincularObra($this->obra, $novoUsuario, Papel::GerentePlanejamento->value);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertSame(1, $novoUsuario->notifications()->count());

        DB::table('obra_user')->where('user_id', $novoUsuario->id)->where('work_id', $this->obra->id)->delete();

        // reabre pra gerar uma comunicação NOVA e confirmar que quem perdeu
        // acesso não recebe, mas quem ficou continua recebendo normalmente.
        (new RegistrarAceiteEntrega())->execute(
            \App\Models\GrdDestinatario::whereHas('grd', fn ($q) => $q->where('obra_id', $this->obra->id))->firstOrFail(),
            TipoAceiteGrd::SemAssinatura,
            'Recebedor',
            $this->user
        );
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        (new InvalidarAceiteEntrega())->execute(
            \App\Models\GrdAceiteEntrega::whereNull('invalidado_em')->firstOrFail(),
            'motivo',
            $this->user
        );
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertSame(1, $novoUsuario->notifications()->count(), 'histórico intacto, nada novo pra quem perdeu acesso.');
        $this->assertSame(2, $this->user->notifications()->count(), 'quem ficou continua recebendo a reabertura.');
    }

    // =========================================================================
    // T — Anti-duplicidade com legado GED/GRD
    // =========================================================================

    public function test_t_nenhuma_notification_legada_cobre_aceite_de_grd(): void
    {
        $arquivo = glob(app_path('Notifications/Grd*.php'));
        $this->assertNotEmpty($arquivo, 'sanity check: as Notifications de GRD existem.');

        foreach ($arquivo as $caminho) {
            $conteudo = strtolower(file_get_contents($caminho));
            $this->assertStringNotContainsString(
                'aceite',
                $conteudo,
                basename($caminho) . ' não deveria mencionar aceite — confirma que nenhuma Notification legada cobre este fato, evitando duplicidade de canal.'
            );
        }
    }

    public function test_t2_grdaguardandoaceite_nunca_suprimida_como_pedidoatrasado(): void
    {
        // Diferente de PedidoAtrasado (suprimido por já ter Notification
        // legada equivalente), GrdAguardandoAceite tem comunicação normal
        // — nenhuma supressão necessária (nada a duplicar).
        $doc = $this->doc();
        $r = $this->rev($doc);
        $this->grdEmitidaComDistribuicao($r);

        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertCount(1, $this->user->notifications()->get());
    }

    // =========================================================================
    // Política de entrega — digest-only, nunca imediato
    // =========================================================================

    public function test_politica_de_entrega_e_digest_only_nunca_imediato(): void
    {
        $politica = PoliticaEntregaSituacao::para(TipoSituacaoGerencial::GrdAguardandoAceite);

        $this->assertFalse($politica->elegivelImediato);
        $this->assertTrue($politica->elegivelDigest);
        $this->assertFalse($politica->elegivelParaEmailAgora(SeveridadeSituacao::Critica, true, null, now()));
    }

    // =========================================================================
    // Negativo — Industrialização/Suprimentos/cópia obsoleta NUNCA promovidas
    // =========================================================================

    public function test_industrializacao_com_mudanca_de_revisao_nunca_gera_situacao_global(): void
    {
        $tipos = collect(TipoSituacaoGerencial::cases())->map(fn ($t) => $t->value);
        $this->assertNotContains('industrializacao_com_mudanca_de_revisao', $tipos);
        $this->assertNotContains('industrializacao_documento', $tipos);
    }

    public function test_suprimento_bloqueado_por_documento_nunca_gera_situacao_global(): void
    {
        $tipos = collect(TipoSituacaoGerencial::cases())->map(fn ($t) => $t->value);
        $this->assertNotContains('suprimento_bloqueado_por_documento', $tipos);
        $this->assertNotContains('suprimento_documento', $tipos);
    }

    public function test_copia_obsoleta_nunca_promovida_legado_continua_unico_canal(): void
    {
        $tipos = collect(TipoSituacaoGerencial::cases())->map(fn ($t) => $t->value);
        $this->assertNotContains('copia_obsoleta_pendente_recolhimento', $tipos);
        $this->assertNotContains('grd_copia_obsoleta', $tipos);
    }

    // =========================================================================
    // Consistência — Cockpit Executivo / Cockpit de Engenharia
    // =========================================================================

    public function test_cockpit_executivo_conta_como_informativa_nunca_em_riscos_ou_acoes(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc);
        $this->grdEmitidaComDistribuicao($r);

        $resumo = CockpitObraQuery::resumo($this->obra, 28);

        $this->assertFalse($resumo->riscos->contains(fn ($s) => $s->tipo === TipoSituacaoGerencial::GrdAguardandoAceite));
        $this->assertFalse($resumo->acoesHoje->contains(fn ($s) => $s->tipo === TipoSituacaoGerencial::GrdAguardandoAceite));
    }

    public function test_cockpit_engenharia_nunca_duplica_o_fato_de_aceite(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc);
        $this->grdEmitidaComDistribuicao($r);

        $resumo = CockpitEngenhariaQuery::resumo($this->obra, 28);

        // A ação prioritária do Cockpit de Engenharia só puxa
        // "documento_bloqueante" de SituacoesGerenciaisQuery — o fato de
        // GRD vem SEMPRE de InteligenciaEngenhariaQuery (22.1) diretamente,
        // nunca dos dois ao mesmo tempo.
        $ocorrenciasGrdNaAcao = $resumo->acaoPrioritaria->filter(fn ($l) => $l['tipo'] === 'grd_aguardando_aceite');
        $this->assertCount(1, $ocorrenciasGrdNaAcao);
        $this->assertCount(1, $resumo->grdAguardandoAceite);
    }

    // =========================================================================
    // Performance
    // =========================================================================

    public function test_performance_delta_10_100_grds(): void
    {
        $medir = function (int $n): int {
            $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
            for ($i = 0; $i < $n; $i++) {
                $doc = $this->doc($obra);
                $r = $this->rev($doc);
                $this->grdEmitidaComDistribuicao($r, $obra);
            }

            DB::enableQueryLog();
            SituacoesGerenciaisQuery::porObra($obra, 28);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            DB::flushQueryLog();

            return $count;
        };

        $q10 = $medir(10);
        $q100 = $medir(100);

        fwrite(STDERR, "\n[DELTA GrdAguardandoAceite via SituacoesGerenciaisQuery] 10 -> {$q10} queries | 100 -> {$q100} queries\n");

        $this->assertLessThan($q10 * 3, $q100, 'Query count não pode escalar proporcionalmente ao número de GRDs.');
    }
}
