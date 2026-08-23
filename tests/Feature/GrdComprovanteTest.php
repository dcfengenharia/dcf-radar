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
use App\Models\GrdRecolhimento;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Grd\MontarDadosComprovanteEntrega;
use App\Support\Grd\MontarDadosComprovanteRecolhimento;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.5.8 — Comprovante de Entrega/Recolhimento da GRD.
 * Diferente do PDF histórico da GRD inteira (18.5.3, `GrdPdfTest`), cada
 * comprovante aqui responde por UM evento operacional específico: 1
 * entrega a 1 destinatário (unidade = `GrdDestinatario`) ou 1 evento de
 * recolhimento (unidade = `GrdRecolhimento`). Cobertura A-AC + teste
 * crítico do briefing. Testa tanto os 2 `MontarDadosComprovante*` (dados
 * reais) quanto o HTML real renderizado pelos 2 templates novos — nunca só
 * "PDF retornou bytes" (mesma convenção de `GrdPdfTest`).
 */
class GrdComprovanteTest extends TestCase
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

    private function componente()
    {
        return Livewire::test('pages::engenharia.grds')->set('obraId', $this->obra->id);
    }

    private function doc(array $o = [], ?Work $obra = null): DocumentoEngenharia
    {
        return DocumentoEngenharia::create(array_merge(['tenant_id' => $this->tenant->id, 'obra_id' => ($obra ?? $this->obra)->id, 'codigo' => 'DOC-' . uniqid(), 'descricao' => 'x'], $o));
    }

    private function rev(DocumentoEngenharia $d, string $texto = 'R1'): DocumentoEngenhariaRevisao
    {
        return $d->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => $texto, 'descricao' => 'x'])->fresh();
    }

    private function liberar(DocumentoEngenhariaRevisao $r, ?User $usuario = null): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->liberar($r, $usuario ?? $this->user);
    }

    private function destinatario(array $o = [], ?Work $obra = null): Destinatario
    {
        return Destinatario::create(array_merge(['tenant_id' => $this->tenant->id, 'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Destinatario ' . uniqid()], $o));
    }

    /** Monta uma GRD Emitida completa (1 doc R1 liberado, 1 destinatário, quantidade configurável). */
    private function grdEmitida(?DocumentoEngenharia $doc = null, ?DocumentoEngenhariaRevisao $revisao = null, ?Destinatario $dest = null, int $quantidade = 2, ?User $usuario = null): array
    {
        $usuario ??= $this->user;
        $doc ??= $this->doc();
        $revisao ??= $this->rev($doc, 'R1');
        $this->liberar($revisao->fresh(), $usuario);
        $dest ??= $this->destinatario();

        $grd = (new CriarGrd())->execute($this->obra, $usuario);
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $revisao->fresh());
        $gd = $acoes->adicionarDestinatario($grd, $dest);
        $dist = $acoes->marcarDistribuicao($grd, $item, $gd, $quantidade);
        $grd = (new EmitirGrd())->execute($grd, $usuario);

        return compact('grd', 'doc', 'revisao', 'dest', 'item', 'gd', 'dist');
    }

    private function renderizarEntrega(\App\Models\GrdDestinatario $gd): string
    {
        $dados = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd);

        return view('exports.grd-comprovante-entrega-pdf', $dados)->render();
    }

    private function renderizarRecolhimento(GrdRecolhimento $evento): string
    {
        $dados = (new MontarDadosComprovanteRecolhimento())->paraEvento($evento);

        return view('exports.grd-comprovante-recolhimento-pdf', $dados)->render();
    }

    /** Perfil sob medida: só 'ver' em engenharia.pacotes, nunca 'editar'. */
    private function usuarioSoComVer(): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Só Ver GED ' . uniqid()]);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'engenharia.pacotes', 'acao' => 'ver']);
        $this->obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);

        return $user;
    }

    // ===================== A-B: escopo Rascunho x Emitida =====================

    public function test_a_rascunho_nao_gera_comprovante(): void
    {
        $doc = $this->doc();
        $rev = $this->rev($doc, 'R1');
        $this->liberar($rev->fresh());
        $dest = $this->destinatario();

        $grd = (new CriarGrd())->execute($this->obra, $this->user);
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $rev->fresh());
        $gd = $acoes->adicionarDestinatario($grd, $dest);
        $acoes->marcarDistribuicao($grd, $item, $gd, 1);

        $c = $this->componente();
        $c->call('abrirGrd', $grd->id);
        $c->assertDontSeeHtml('exportarComprovanteEntrega'); // sem botão em rascunho

        $c->call('exportarComprovanteEntrega', $gd->id)->assertStatus(404);
    }

    public function test_b_emitida_permite_acesso_aos_dois_comprovantes(): void
    {
        ['grd' => $grd, 'gd' => $gd, 'dist' => $dist] = $this->grdEmitida();
        $evento = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);

        $c = $this->componente();
        $c->call('exportarComprovanteEntrega', $gd->id)->assertOk();
        $c->call('exportarComprovanteRecolhimento', $evento->id)->assertOk();
    }

    // ===================== C-D: autorização =====================

    public function test_c_autorizacao_ver_e_suficiente(): void
    {
        ['grd' => $grd, 'gd' => $gd, 'dist' => $dist] = $this->grdEmitida();
        $evento = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);

        $soVer = $this->usuarioSoComVer();
        $this->actingAs($soVer);

        $c = $this->componente();
        $c->call('exportarComprovanteEntrega', $gd->id)->assertOk();
        $c->call('exportarComprovanteRecolhimento', $evento->id)->assertOk();
    }

    public function test_d_editar_nao_e_necessario(): void
    {
        // Mesmo teste de C, propositalmente nomeado à parte pra deixar
        // explícito no relatório: 'editar' NUNCA é exigido pra baixar
        // comprovante — só 'ver' (seção 13 do pedido).
        $this->test_c_autorizacao_ver_e_suficiente();
    }

    // ===================== E-G: isolamento =====================

    public function test_e_cross_obra_bloqueado(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $docB = $this->doc([], $obraB);
        $rB = $this->rev($docB);
        $this->liberar($rB->fresh());
        $destB = $this->destinatario([], $obraB);
        $grdB = (new CriarGrd())->execute($obraB, $this->user);
        $acoes = new AtualizarRascunhoGrd();
        $itemB = $acoes->adicionarItem($grdB, $rB->fresh());
        $gdB = $acoes->adicionarDestinatario($grdB, $destB);
        $distB = $acoes->marcarDistribuicao($grdB, $itemB, $gdB, 1);
        (new EmitirGrd())->execute($grdB, $this->user);
        $eventoB = (new RegistrarRecolhimento())->execute($distB, ResultadoRecolhimento::Recolhido, 1, $this->user);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->componente()->call('exportarComprovanteEntrega', $gdB->id); // obraId setado é $this->obra, não $obraB
    }

    public function test_e2_cross_obra_bloqueado_recolhimento(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $docB = $this->doc([], $obraB);
        $rB = $this->rev($docB);
        $this->liberar($rB->fresh());
        $destB = $this->destinatario([], $obraB);
        $grdB = (new CriarGrd())->execute($obraB, $this->user);
        $acoes = new AtualizarRascunhoGrd();
        $itemB = $acoes->adicionarItem($grdB, $rB->fresh());
        $gdB = $acoes->adicionarDestinatario($grdB, $destB);
        $distB = $acoes->marcarDistribuicao($grdB, $itemB, $gdB, 1);
        (new EmitirGrd())->execute($grdB, $this->user);
        $eventoB = (new RegistrarRecolhimento())->execute($distB, ResultadoRecolhimento::Recolhido, 1, $this->user);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->componente()->call('exportarComprovanteRecolhimento', $eventoB->id);
    }

    /** F: usuário com acesso a A e B, mas contexto atual (obraId setado) é A, tentando ID de B. */
    public function test_f_usuario_com_duas_obras_contexto_errado(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::Admin->value); // mesmo usuário, também vinculado a B

        $docB = $this->doc([], $obraB);
        $rB = $this->rev($docB);
        $this->liberar($rB->fresh());
        $destB = $this->destinatario([], $obraB);
        $grdB = (new CriarGrd())->execute($obraB, $this->user);
        $acoes = new AtualizarRascunhoGrd();
        $itemB = $acoes->adicionarItem($grdB, $rB->fresh());
        $gdB = $acoes->adicionarDestinatario($grdB, $destB);
        $acoes->marcarDistribuicao($grdB, $itemB, $gdB, 1);
        (new EmitirGrd())->execute($grdB, $this->user);

        // contexto atual (obraId) continua sendo $this->obra (A) — mesmo o
        // usuário tendo 'ver' em B, o comprovante de B não pode ser
        // resolvido enquanto o contexto ativo é A.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->componente()->call('exportarComprovanteEntrega', $gdB->id);
    }

    public function test_g_cross_tenant_bloqueado(): void
    {
        $outroTenant = Tenant::factory()->create();
        [$gdOutro, $eventoOutro] = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutro = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $userOutro = User::factory()->create(['tenant_id' => $outroTenant->id]);
            $docOutro = DocumentoEngenharia::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'codigo' => 'X', 'descricao' => 'x']);
            $rOutro = $docOutro->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R1', 'descricao' => 'x'])->fresh();
            (new AlterarLiberacaoRevisaoDocumento())->liberar($rOutro, $userOutro);
            $destOutro = Destinatario::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'nome' => 'Dest Outro']);
            $g = (new CriarGrd())->execute($obraOutro, $userOutro);
            $acoes = new AtualizarRascunhoGrd();
            $i = $acoes->adicionarItem($g, $rOutro->fresh());
            $gd = $acoes->adicionarDestinatario($g, $destOutro);
            $dist = $acoes->marcarDistribuicao($g, $i, $gd, 1);
            $g = (new EmitirGrd())->execute($g, $userOutro);
            $evento = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $userOutro);

            return [$gd, $evento];
        });

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->componente()->call('exportarComprovanteEntrega', $gdOutro->id);
    }

    // ===================== H-L: snapshots =====================

    public function test_h_i_j_snapshot_documento_descricao_revisao(): void
    {
        ['gd' => $gd, 'doc' => $doc, 'revisao' => $revisao] = $this->grdEmitida();
        $html = $this->renderizarEntrega($gd->fresh());

        $this->assertStringContainsString($doc->codigo, $html); // H
        $this->assertStringContainsString($doc->descricao, $html); // I
        $this->assertStringContainsString($revisao->revisao, $html); // J
    }

    public function test_k_l_snapshot_destinatario_empresa_setor(): void
    {
        $dest = $this->destinatario(['nome' => 'Joao Snapshot', 'empresa' => 'ACME', 'setor' => 'Obras']);
        ['gd' => $gd] = $this->grdEmitida(dest: $dest);
        $html = $this->renderizarEntrega($gd->fresh());

        $this->assertStringContainsString('Joao Snapshot', $html); // K
        $this->assertStringContainsString('ACME', $html); // L
        $this->assertStringContainsString('Obras', $html);
    }

    // ===================== M: destinatário soft-deletado =====================

    public function test_m_destinatario_soft_deletado_nao_quebra_comprovante(): void
    {
        $dest = $this->destinatario(['nome' => 'Joao Antes de Sumir']);
        ['grd' => $grd, 'gd' => $gd] = $this->grdEmitida(dest: $dest);
        $dest->delete();

        $html = $this->renderizarEntrega($gd->fresh());
        $this->assertStringContainsString('Joao Antes de Sumir', $html);

        $c = $this->componente();
        $c->call('exportarComprovanteEntrega', $gd->id)->assertOk();
    }

    // ===================== N: usuário removido =====================

    public function test_n_usuario_registrador_removido_usa_fallback(): void
    {
        $registrador = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $registrador, Papel::Admin->value);
        ['grd' => $grd, 'dist' => $dist] = $this->grdEmitida(usuario: $registrador);
        $evento = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $registrador);

        $registrador->forceDelete();

        $html = $this->renderizarRecolhimento($evento->fresh());
        $this->assertStringContainsString('Usuário removido', $html);
    }

    // ===================== O: R2 posterior não altera R1 =====================

    public function test_o_r2_posterior_nao_altera_comprovante_de_r1(): void
    {
        ['grd' => $grd, 'gd' => $gd, 'doc' => $doc] = $this->grdEmitida();
        $this->rev($doc, 'R2'); // nasce depois, não liberada

        $html = $this->renderizarEntrega($gd->fresh());
        $this->assertStringContainsString('R1', $html);
        $this->assertStringNotContainsString('>R2<', $html);
    }

    // ===================== P-S: quantidades e resultado =====================

    public function test_p_quantidade_original_correta(): void
    {
        ['gd' => $gd] = $this->grdEmitida(quantidade: 5);
        $html = $this->renderizarEntrega($gd->fresh());

        $this->assertMatchesRegularExpression('/<td>5<\/td>/', $html);
    }

    public function test_q_recolhimento_parcial(): void
    {
        ['dist' => $dist] = $this->grdEmitida(quantidade: 5);
        $evento = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 2, $this->user);

        $html = $this->renderizarRecolhimento($evento->fresh());
        $this->assertStringContainsString('Recolhido', $html);
        $this->assertStringContainsString('<td>5</td>', $html, 'quantidade distribuída original (5) precisa aparecer');
        $this->assertStringContainsString('<td>2</td>', $html, 'quantidade DESTE evento (2) precisa aparecer, distinta da original');
    }

    public function test_r_multiplos_recolhimentos_independentes(): void
    {
        ['dist' => $dist] = $this->grdEmitida(quantidade: 4);
        $e1 = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);
        $e2 = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::NaoLocalizado, 1, $this->user);
        $e3 = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 2, $this->user);

        $html1 = $this->renderizarRecolhimento($e1->fresh());
        $html2 = $this->renderizarRecolhimento($e2->fresh());
        $html3 = $this->renderizarRecolhimento($e3->fresh());

        $this->assertStringContainsString('Recolhido', $html1);
        $this->assertStringNotContainsString('Não localizado', $html1);
        $this->assertStringContainsString('Não localizado', $html2);
        $this->assertStringContainsString('Recolhido', $html3);
        $this->assertStringNotContainsString('Não localizado', $html3);
    }

    public function test_s_naolocalizado_nunca_vira_recolhido(): void
    {
        ['dist' => $dist] = $this->grdEmitida();
        $evento = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::NaoLocalizado, 1, $this->user);

        $html = $this->renderizarRecolhimento($evento->fresh());
        $this->assertStringContainsString('Não localizado', $html);
        $this->assertStringNotContainsString('Recolhido<', $html, 'nunca deve haver um rótulo "Recolhido" isolado — só como parte de "Não localizado" não existe, então isso confirma ausência real');
    }

    // ===================== T-U: temporal =====================

    public function test_t_ocorrido_em_exibido_corretamente(): void
    {
        ['dist' => $dist] = $this->grdEmitida();
        $evento = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);

        $html = $this->renderizarRecolhimento($evento->fresh());
        $this->assertStringContainsString($evento->fresh()->ocorrido_em->format('d/m/Y'), $html);
    }

    public function test_u_evento_antigo_continua_acessivel_apos_eventos_novos(): void
    {
        ['dist' => $dist] = $this->grdEmitida(quantidade: 3);
        $antigo = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);
        (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 2, $this->user);

        $c = $this->componente();
        $c->call('exportarComprovanteRecolhimento', $antigo->id)->assertOk();
    }

    // ===================== V-W: PDF / arquivo =====================

    /**
     * `response()->streamDownload()` retornado de um método Livewire vira um
     * "download effect" (`Livewire\Features\SupportFileDownloads`) — a
     * asserção correta é `assertFileDownloaded()`, não `assertHeader()`
     * cru (que checaria a resposta HTTP do próprio request Livewire/AJAX,
     * não o arquivo em si).
     */
    public function test_v_pdf_mime_e_download(): void
    {
        ['gd' => $gd, 'dist' => $dist] = $this->grdEmitida();
        $evento = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);

        $c = $this->componente();
        $c->call('exportarComprovanteEntrega', $gd->id)->assertFileDownloaded();
        $c->call('exportarComprovanteRecolhimento', $evento->id)->assertFileDownloaded();
    }

    public function test_w_nome_de_arquivo(): void
    {
        ['grd' => $grd, 'gd' => $gd, 'dist' => $dist] = $this->grdEmitida();
        $evento = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);

        $nomeEntregaEsperado = \Illuminate\Support\Str::slug("comprovante-entrega-grd-{$grd->numero}-{$gd->fresh()->nome_snapshot}") . '.pdf';
        $nomeRecolhimentoEsperado = \Illuminate\Support\Str::slug("comprovante-recolhimento-grd-{$grd->numero}-{$evento->fresh()->ocorrido_em->format('Y-m-d-Hi')}") . '.pdf';

        $c = $this->componente();
        $c->call('exportarComprovanteEntrega', $gd->id)->assertFileDownloaded($nomeEntregaEsperado);
        $c->call('exportarComprovanteRecolhimento', $evento->id)->assertFileDownloaded($nomeRecolhimentoEsperado);
    }

    // ===================== X-Y: imutabilidade / storage =====================

    public function test_x_zero_mutacao(): void
    {
        ['gd' => $gd, 'dist' => $dist] = $this->grdEmitida();
        $evento = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);

        $grdCountAntes = Grd::count();
        $distCountAntes = GrdDistribuicao::count();
        $recolhimentoCountAntes = GrdRecolhimento::count();

        $c = $this->componente();
        $c->call('exportarComprovanteEntrega', $gd->id);
        $c->call('exportarComprovanteRecolhimento', $evento->id);

        $this->assertSame($grdCountAntes, Grd::count());
        $this->assertSame($distCountAntes, GrdDistribuicao::count());
        $this->assertSame($recolhimentoCountAntes, GrdRecolhimento::count());
        $this->assertSame(2, $dist->fresh()->quantidade, 'a quantidade original nunca muda por gerar comprovante');
    }

    public function test_y_zero_storage_geracao_em_memoria(): void
    {
        Storage::fake();
        ['gd' => $gd, 'dist' => $dist] = $this->grdEmitida();
        $evento = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);

        $c = $this->componente();
        $c->call('exportarComprovanteEntrega', $gd->id);
        $c->call('exportarComprovanteRecolhimento', $evento->id);

        $this->assertEmpty(Storage::allFiles(), 'comprovante é gerado 100% em memória — nunca grava no storage');
    }

    // ===================== Z: performance =====================

    public function test_z_performance_historico_grande(): void
    {
        ['dist' => $dist] = $this->grdEmitida(quantidade: 30);

        for ($i = 0; $i < 15; $i++) {
            (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::NaoLocalizado, 1, $this->user);
        }
        $ultimo = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);

        $queryCountAntes = 0;
        DB::listen(function () use (&$queryCountAntes) {
            $queryCountAntes++;
        });

        $dados = (new MontarDadosComprovanteRecolhimento())->paraEvento($ultimo->fresh());
        view('exports.grd-comprovante-recolhimento-pdf', $dados)->render();

        $this->assertLessThan(10, $queryCountAntes, 'comprovante de 1 evento precisa de uma quantidade PEQUENA e CONSTANTE de queries, independente do tamanho do histórico da distribuição');
    }

    // ===================== AA-AB: isolamento por GRD =====================

    public function test_aa_isolamento_por_grd(): void
    {
        ['grd' => $grd1, 'gd' => $gd1] = $this->grdEmitida();
        ['grd' => $grd2, 'gd' => $gd2] = $this->grdEmitida();

        $html1 = $this->renderizarEntrega($gd1->fresh());
        $html2 = $this->renderizarEntrega($gd2->fresh());

        $this->assertStringContainsString('GRD-' . str_pad((string) $grd1->numero, 3, '0', STR_PAD_LEFT), $html1);
        $this->assertStringContainsString('GRD-' . str_pad((string) $grd2->numero, 3, '0', STR_PAD_LEFT), $html2);
        $this->assertStringNotContainsString('GRD-' . str_pad((string) $grd2->numero, 3, '0', STR_PAD_LEFT), $html1);
    }

    public function test_ab_evento_de_outra_grd_nao_e_confundido(): void
    {
        ['dist' => $dist1] = $this->grdEmitida(quantidade: 2);
        ['dist' => $dist2] = $this->grdEmitida(quantidade: 2);

        $e1 = (new RegistrarRecolhimento())->execute($dist1, ResultadoRecolhimento::Recolhido, 1, $this->user);
        $e2 = (new RegistrarRecolhimento())->execute($dist2, ResultadoRecolhimento::Recolhido, 1, $this->user);

        $dadosE1 = (new MontarDadosComprovanteRecolhimento())->paraEvento($e1->fresh());
        $dadosE2 = (new MontarDadosComprovanteRecolhimento())->paraEvento($e2->fresh());

        $this->assertNotSame($dadosE1['grd']->id, $dadosE2['grd']->id);
        $this->assertSame($dist1->id, $dadosE1['distribuicao']->id);
        $this->assertSame($dist2->id, $dadosE2['distribuicao']->id);
    }

    // ===================== AC: fluxo crítico (seção 29 do pedido) =====================

    public function test_ac_fluxo_critico_completo(): void
    {
        // 1. João recebe R1 qtd2 via GRD-001.
        $joao = $this->destinatario(['nome' => 'Joao Critico', 'empresa' => 'Empresa Original', 'setor' => 'Setor Original']);
        $doc = $this->doc(['codigo' => 'DOC-CRITICO', 'descricao' => 'Descricao Original']);
        ['grd' => $grd, 'gd' => $gd, 'dist' => $dist, 'revisao' => $r1] = $this->grdEmitida($doc, null, $joao, 2);

        // 2. congelar snapshot (implícito — já congelado na emissão).
        $htmlEntrega = $this->renderizarEntrega($gd->fresh());
        $this->assertStringContainsString('DOC-CRITICO', $htmlEntrega);
        $this->assertStringContainsString('Descricao Original', $htmlEntrega);
        $this->assertStringContainsString('Joao Critico', $htmlEntrega);
        $this->assertStringContainsString('Empresa Original', $htmlEntrega);

        // 3. R2 nasce.
        $this->rev($doc, 'R2');

        // 4. registrar recolhimento R1 qtd1.
        $evento1 = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);

        // 5. gerar comprovante desse recolhimento.
        $html1 = $this->renderizarRecolhimento($evento1->fresh());
        $this->assertStringContainsString('R1', $html1);
        $this->assertStringContainsString('Recolhido', $html1);

        // 6. alterar Documento vivo.
        $doc->update(['codigo' => 'DOC-MUDOU', 'descricao' => 'Descricao Mudou']);

        // 7. alterar Destinatario vivo.
        $joao->update(['nome' => 'Joao Mudou', 'empresa' => 'Empresa Mudou']);

        // 8. registrar NaoLocalizado qtd1.
        $evento2 = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::NaoLocalizado, 1, $this->user);

        // 9. gerar comprovante do segundo evento.
        $html2 = $this->renderizarRecolhimento($evento2->fresh());
        $this->assertStringContainsString('Não localizado', $html2);
        $this->assertStringContainsString('DOC-CRITICO', $html2); // snapshot, não o valor mudado
        $this->assertStringNotContainsString('DOC-MUDOU', $html2);
        $this->assertStringContainsString('Joao Critico', $html2);
        $this->assertStringNotContainsString('Joao Mudou', $html2);

        // 10. registrar recolhimento final qtd1.
        $evento3 = (new RegistrarRecolhimento())->execute($dist, ResultadoRecolhimento::Recolhido, 1, $this->user);

        // 11. gerar comprovante do terceiro evento.
        $html3 = $this->renderizarRecolhimento($evento3->fresh());
        $this->assertStringContainsString('Recolhido', $html3);

        // 12. confirmações finais.
        $this->assertStringContainsString('Recolhido', $this->renderizarRecolhimento($evento1->fresh()), 'comprovante 1 continua parcial/Recolhido, nunca reescrito');
        $this->assertStringContainsString('Não localizado', $this->renderizarRecolhimento($evento2->fresh()), 'comprovante 2 continua Não localizado');
        $this->assertStringContainsString('Recolhido', $this->renderizarRecolhimento($evento3->fresh()), 'comprovante 3 é o recolhimento final');

        foreach ([$evento1, $evento2, $evento3] as $evento) {
            $dados = (new MontarDadosComprovanteRecolhimento())->paraEvento($evento->fresh());
            $this->assertSame($grd->id, $dados['grd']->id, 'todos continuam associados à mesma GRD/R1, nunca migram pra R2');
            $this->assertSame('R1', $dados['distribuicao']->item->revisao_snapshot);
        }

        // distribuição original continua qtd2 (nunca reescrita pelos eventos).
        $this->assertSame(2, $dist->fresh()->quantidade);

        // snapshot do comprovante de entrega (gerado no passo 2) continua intacto mesmo agora.
        $this->assertStringContainsString('Joao Critico', $this->renderizarEntrega($gd->fresh()));
        $this->assertStringNotContainsString('Joao Mudou', $this->renderizarEntrega($gd->fresh()));

        // R2 nunca contamina o comprovante de R1.
        $this->assertStringNotContainsString('>R2<', $htmlEntrega);
    }
}
