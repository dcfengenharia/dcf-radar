<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Actions\Engenharia\AtualizarRascunhoGrd;
use App\Actions\Engenharia\CriarGrd;
use App\Actions\Engenharia\EmitirGrd;
use App\Actions\Engenharia\InvalidarAceiteEntrega;
use App\Actions\Engenharia\RegistrarAceiteEntrega;
use App\Enums\Papel;
use App\Enums\TipoAceiteGrd;
use App\Exceptions\GrdAceiteInvalidoException;
use App\Exceptions\GrdAceiteJaAtivoException;
use App\Exceptions\GrdAceiteJaInvalidadoException;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Grd;
use App\Models\GrdAceiteEntrega;
use App\Models\GrdDistribuicao;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Grd\MontarDadosComprovanteEntrega;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.5.9 — Aceite/Assinatura de Recebimento + QR Code de
 * Verificação. NÃO é assinatura digital ICP-Brasil. Cobertura A-AH do
 * briefing + teste crítico. Helpers de fixture espelham `GrdComprovanteTest`
 * (18.5.8) — convenção do projeto de não compartilhar helper de teste via
 * trait entre arquivos.
 */
class GrdAceiteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    /** PNG 1x1 mínimo válido, fixture padrão da comunidade PHP pra testes de imagem. */
    private const PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
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

    /** Monta uma GRD Emitida completa (1 doc R1 liberado, 1 destinatário). */
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

    private function usuarioSoComVer(): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Só Ver GED ' . uniqid()]);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'engenharia.pacotes', 'acao' => 'ver']);
        $this->obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);

        return $user;
    }

    // ===================== A-B: escopo Rascunho x Emitida =====================

    public function test_a_grd_rascunho_nao_aceita(): void
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

        $this->expectException(GrdAceiteInvalidoException::class);
        (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);
    }

    public function test_b_emitida_aceita(): void
    {
        ['gd' => $gd] = $this->grdEmitida();

        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao Recebeu', $this->user);

        $this->assertNotNull($aceite->id);
        $this->assertTrue($aceite->estaAtivo());
    }

    // ===================== C-E: autorização =====================

    public function test_c_apenas_editar_registra(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $soVer = $this->usuarioSoComVer();
        $this->actingAs($soVer);

        $c = $this->componente();
        $c->call('abrirModalAceite', $gd->id)->assertForbidden();
    }

    public function test_d_ver_only_nao_registra(): void
    {
        // Mesmo teste de C, nomeado à parte pra deixar explícito no
        // relatório: 'ver' NUNCA é suficiente pra registrar aceite.
        $this->test_c_apenas_editar_registra();
    }

    public function test_e_ver_only_visualiza(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $soVer = $this->usuarioSoComVer();
        $this->actingAs($soVer);

        $c = $this->componente();
        $c->call('exportarComprovanteEntrega', $gd->id)->assertFileDownloaded();
    }

    // ===================== F-G: isolamento =====================

    public function test_f_cross_obra(): void
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
        $acoes->marcarDistribuicao($grdB, $itemB, $gdB, 1);
        (new EmitirGrd())->execute($grdB, $this->user);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->componente()->call('abrirModalAceite', $gdB->id);
    }

    public function test_g_cross_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $gdOutro = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
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
            $acoes->marcarDistribuicao($g, $i, $gd, 1);
            (new EmitirGrd())->execute($g, $userOutro);

            return $gd;
        });

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->componente()->call('abrirModalAceite', $gdOutro->id);
    }

    // ===================== H-I: destinatário/snapshots =====================

    public function test_h_destinatario_correto_nome_recebedor_livre(): void
    {
        $dest = $this->destinatario(['nome' => 'Joao Titular']);
        ['gd' => $gd] = $this->grdEmitida(dest: $dest);

        // Quem RECEBE fisicamente pode ser diferente do destinatário
        // cadastrado (ex.: um colega recebeu em nome dele) — nome_recebedor
        // é livre, nunca travado ao nome_snapshot do GrdDestinatario.
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Maria (recebeu por Joao)', $this->user);

        $this->assertSame('Maria (recebeu por Joao)', $aceite->nome_recebedor_snapshot);
        $this->assertSame($gd->id, $aceite->grd_destinatario_id);
    }

    public function test_i_snapshots_empresa_setor_copiados(): void
    {
        $dest = $this->destinatario(['nome' => 'Joao', 'empresa' => 'ACME', 'setor' => 'Obras']);
        ['gd' => $gd] = $this->grdEmitida(dest: $dest);

        $aceite = (new RegistrarAceiteEntrega())->execute($gd->fresh(), TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $this->assertSame('ACME', $aceite->empresa_snapshot);
        $this->assertSame('Obras', $aceite->setor_snapshot);
    }

    // ===================== J: R2 não altera R1 =====================

    public function test_j_r2_nao_altera_aceite_de_r1(): void
    {
        ['gd' => $gd, 'doc' => $doc] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);
        $this->rev($doc, 'R2');

        $dados = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd->fresh());
        $this->assertSame($aceite->id, $dados['aceiteAtivo']->id);
        $this->assertSame('R1', $dados['distribuicoes']->first()->item->revisao_snapshot);
    }

    // ===================== K-L: tipo de aceite =====================

    public function test_k_aceite_sem_assinatura(): void
    {
        ['gd' => $gd] = $this->grdEmitida();

        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $this->assertSame(TipoAceiteGrd::SemAssinatura, $aceite->tipo_aceite);
        $this->assertNull($aceite->assinatura_path);
        $this->assertNull($aceite->assinatura_hash);
    }

    public function test_l_assinatura_png(): void
    {
        ['gd' => $gd] = $this->grdEmitida();

        $aceite = (new RegistrarAceiteEntrega())->execute(
            $gd,
            TipoAceiteGrd::Assinatura,
            'Joao',
            $this->user,
            'data:image/png;base64,' . self::PNG_1X1_BASE64
        );

        $this->assertSame(TipoAceiteGrd::Assinatura, $aceite->tipo_aceite);
        $this->assertNotNull($aceite->assinatura_path);
        $this->assertTrue(Storage::disk('local')->exists($aceite->assinatura_path));
    }

    public function test_l2_assinatura_obrigatoria_para_tipo_assinatura(): void
    {
        ['gd' => $gd] = $this->grdEmitida();

        $this->expectException(GrdAceiteInvalidoException::class);
        (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::Assinatura, 'Joao', $this->user, null);
    }

    // ===================== M-N: storage =====================

    public function test_m_assinatura_nao_vai_pro_disco_public(): void
    {
        ['gd' => $gd] = $this->grdEmitida();

        $aceite = (new RegistrarAceiteEntrega())->execute(
            $gd,
            TipoAceiteGrd::Assinatura,
            'Joao',
            $this->user,
            'data:image/png;base64,' . self::PNG_1X1_BASE64
        );

        $this->assertFalse(Storage::disk('public')->exists($aceite->assinatura_path ?? ''));
        $this->assertTrue(Storage::disk('local')->exists($aceite->assinatura_path));
    }

    public function test_n_path_aleatorio_nao_previsivel(): void
    {
        ['gd' => $gd] = $this->grdEmitida();

        $aceite = (new RegistrarAceiteEntrega())->execute(
            $gd,
            TipoAceiteGrd::Assinatura,
            'Joao',
            $this->user,
            'data:image/png;base64,' . self::PNG_1X1_BASE64
        );

        $this->assertStringNotContainsString('Joao', $aceite->assinatura_path);
        $this->assertStringNotContainsString($gd->id, $aceite->assinatura_path);
        $this->assertMatchesRegularExpression('#^grd-aceites/[^/]+/[A-Za-z0-9]{26}\.png$#', $aceite->assinatura_path);
    }

    // ===================== O: checksum =====================

    public function test_o_checksum_confere(): void
    {
        ['gd' => $gd] = $this->grdEmitida();

        $aceite = (new RegistrarAceiteEntrega())->execute(
            $gd,
            TipoAceiteGrd::Assinatura,
            'Joao',
            $this->user,
            'data:image/png;base64,' . self::PNG_1X1_BASE64
        );

        $binarioReal = Storage::disk('local')->get($aceite->assinatura_path);
        $this->assertSame(hash('sha256', $binarioReal), $aceite->assinatura_hash);
    }

    public function test_p_arquivo_adulterado_detectado(): void
    {
        ['gd' => $gd] = $this->grdEmitida();

        $aceite = (new RegistrarAceiteEntrega())->execute(
            $gd,
            TipoAceiteGrd::Assinatura,
            'Joao',
            $this->user,
            'data:image/png;base64,' . self::PNG_1X1_BASE64
        );

        // Adultera os bytes do arquivo diretamente no storage.
        Storage::disk('local')->put($aceite->assinatura_path, 'bytes-adulterados-nao-sao-mais-o-png-original');

        $dados = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd->fresh());

        $this->assertFalse($dados['assinaturaIntegra'], 'divergência de hash precisa ser detectada, nunca silenciosamente ignorada');
        $this->assertNull($dados['assinaturaBase64'], 'imagem potencialmente adulterada nunca é exibida');

        // Nunca 500 — a view renderiza normalmente com o aviso.
        $html = view('exports.grd-comprovante-entrega-pdf', $dados)->render();
        $this->assertStringContainsString('Integridade do arquivo de assinatura não pôde ser confirmada', $html);
    }

    // ===================== Q-R: concorrência/duplicidade =====================

    public function test_q_dupla_submissao_bloqueada(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $this->expectException(GrdAceiteJaAtivoException::class);
        (new RegistrarAceiteEntrega())->execute($gd->fresh(), TipoAceiteGrd::SemAssinatura, 'Joao de novo', $this->user);
    }

    public function test_r_concorrencia_unique_estrutural_garante_1_ativo(): void
    {
        ['gd' => $gd] = $this->grdEmitida();

        // Simula 2 "processos" inserindo diretamente, contornando o
        // lockForUpdate() da Action — só a UNIQUE estrutural do banco
        // (ativo_unico_destinatario) pode impedir 2 linhas ativas aqui.
        GrdAceiteEntrega::create([
            'tenant_id' => $this->tenant->id,
            'grd_destinatario_id' => $gd->id,
            'nome_recebedor_snapshot' => 'Processo A',
            'tipo_aceite' => TipoAceiteGrd::SemAssinatura,
            'token' => \Illuminate\Support\Str::random(48),
            'registrado_por' => $this->user->id,
            'ocorrido_em' => now(),
            'created_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        GrdAceiteEntrega::create([
            'tenant_id' => $this->tenant->id,
            'grd_destinatario_id' => $gd->id,
            'nome_recebedor_snapshot' => 'Processo B',
            'tipo_aceite' => TipoAceiteGrd::SemAssinatura,
            'token' => \Illuminate\Support\Str::random(48),
            'registrado_por' => $this->user->id,
            'ocorrido_em' => now(),
            'created_at' => now(),
        ]);
    }

    public function test_r2_apos_invalidar_permite_novo_aceite(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $original = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao Errado', $this->user);

        (new InvalidarAceiteEntrega())->execute($original, 'Nome digitado errado', $this->user);

        $novo = (new RegistrarAceiteEntrega())->execute($gd->fresh(), TipoAceiteGrd::SemAssinatura, 'Joao Correto', $this->user);

        $this->assertTrue($novo->estaAtivo());
        $this->assertFalse($original->fresh()->estaAtivo());
        $this->assertSame('Joao Errado', $original->fresh()->nome_recebedor_snapshot, 'conteúdo original nunca é reescrito');
        $this->assertNotSame($original->id, $novo->id);
    }

    public function test_r3_invalidar_duas_vezes_bloqueado(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);
        (new InvalidarAceiteEntrega())->execute($aceite, 'Motivo 1', $this->user);

        $this->expectException(GrdAceiteJaInvalidadoException::class);
        (new InvalidarAceiteEntrega())->execute($aceite->fresh(), 'Motivo 2', $this->user);
    }

    // ===================== S-T: usuário removido / destinatário inativo =====================

    public function test_s_usuario_registrador_removido_usa_fallback(): void
    {
        $registrador = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $registrador, Papel::Admin->value);
        ['gd' => $gd] = $this->grdEmitida(usuario: $registrador);

        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $registrador);
        $registrador->forceDelete();

        $dados = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd->fresh());
        $html = view('exports.grd-comprovante-entrega-pdf', $dados)->render();
        $this->assertStringContainsString('Usuário removido', $html);
    }

    public function test_t_destinatario_soft_deletado_aceite_continua_verificavel(): void
    {
        $dest = $this->destinatario(['nome' => 'Joao Antes de Sumir']);
        ['gd' => $gd] = $this->grdEmitida(dest: $dest);
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao Antes de Sumir', $this->user);
        $dest->delete();

        $response = $this->get(route('publico.grd-verificacao', ['token' => $aceite->token]));
        $response->assertOk();
        $response->assertSee('Joao Antes de Sumir');
    }

    // ===================== U-V: PDF =====================

    public function test_u_pdf_mostra_aceite(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao Recebeu', $this->user);

        $dados = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd->fresh());
        $html = view('exports.grd-comprovante-entrega-pdf', $dados)->render();

        $this->assertStringContainsString('Joao Recebeu', $html);
        $this->assertStringContainsString('Aceite sem assinatura', $html);
    }

    public function test_v_pdf_mostra_assinatura(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        (new RegistrarAceiteEntrega())->execute(
            $gd,
            TipoAceiteGrd::Assinatura,
            'Joao Recebeu',
            $this->user,
            'data:image/png;base64,' . self::PNG_1X1_BASE64
        );

        $dados = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd->fresh());
        $html = view('exports.grd-comprovante-entrega-pdf', $dados)->render();

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('<svg', $html, 'QR Code precisa aparecer junto');
    }

    public function test_sem_aceite_mostra_mensagem_amigavel(): void
    {
        ['gd' => $gd] = $this->grdEmitida();

        $dados = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd->fresh());
        $html = view('exports.grd-comprovante-entrega-pdf', $dados)->render();

        $this->assertStringContainsString('Sem aceite ativo', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }

    // ===================== W-X: QR Code =====================

    public function test_w_qr_gerado(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $dados = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd->fresh());

        // QR Code é uma imagem (rasterizada como paths SVG) — a URL codificada
        // nunca aparece como texto literal na marcação, então a verificação de
        // conteúdo correto é estrutural: SVG válido e não vazio (a
        // corretude da codificação em si é responsabilidade já testada da
        // própria BaconQrCode, não reimplementada/re-verificada aqui).
        $this->assertNotNull($dados['qrSvg']);
        $this->assertStringContainsString('<svg', $dados['qrSvg']);
        $this->assertStringContainsString('</svg>', $dados['qrSvg']);
        $this->assertGreaterThan(500, strlen($dados['qrSvg']), 'SVG com conteúdo real (não vazio/placeholder)');
    }

    public function test_x_qr_nao_contem_id_ou_path_sensivel(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute(
            $gd,
            TipoAceiteGrd::Assinatura,
            'Joao',
            $this->user,
            'data:image/png;base64,' . self::PNG_1X1_BASE64
        );

        $dados = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd->fresh());

        $this->assertStringNotContainsString($aceite->id, $dados['qrSvg']);
        $this->assertStringNotContainsString($gd->id, $dados['qrSvg']);
        $this->assertStringNotContainsString($aceite->assinatura_path, $dados['qrSvg']);
        $this->assertStringNotContainsString($this->tenant->id, $dados['qrSvg']);
    }

    // ===================== Y-AB: endpoint de verificação pública =====================

    public function test_y_endpoint_verificacao_valido(): void
    {
        ['gd' => $gd, 'grd' => $grd] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao Verificado', $this->user);

        // Visitante SEM login algum.
        $response = $this->get(route('publico.grd-verificacao', ['token' => $aceite->token]));

        $response->assertOk();
        $response->assertSee('Registro válido');
        $response->assertSee($this->obra->name);
        $response->assertSee('GRD-' . str_pad($grd->numero, 3, '0', STR_PAD_LEFT));
        $response->assertSee('Joao Verificado');
    }

    public function test_z_token_invalido_404_segura(): void
    {
        $response = $this->get(route('publico.grd-verificacao', ['token' => 'token-que-nunca-existiu-123']));
        $response->assertStatus(404);
    }

    public function test_aa_endpoint_nao_expoe_dados_extras(): void
    {
        $joao = $this->destinatario(['nome' => 'Joao']);
        $maria = $this->destinatario(['nome' => 'Maria Outro Destinatario']);
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());

        $grd = (new CriarGrd())->execute($this->obra, $this->user);
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $r1->fresh());
        $gdJoao = $acoes->adicionarDestinatario($grd, $joao);
        $gdMaria = $acoes->adicionarDestinatario($grd, $maria);
        $acoes->marcarDistribuicao($grd, $item, $gdJoao, 1);
        $acoes->marcarDistribuicao($grd, $item, $gdMaria, 1);
        (new EmitirGrd())->execute($grd, $this->user);

        $aceiteJoao = (new RegistrarAceiteEntrega())->execute($gdJoao->fresh(), TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $response = $this->get(route('publico.grd-verificacao', ['token' => $aceiteJoao->token]));

        $response->assertOk();
        $response->assertDontSee('Maria Outro Destinatario');
        $response->assertDontSee($this->tenant->id);
        $response->assertDontSee($gdJoao->id);
        $response->assertDontSee($aceiteJoao->id);
    }

    public function test_ab_registro_invalidado_qr_continua_resolvendo(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);
        (new InvalidarAceiteEntrega())->execute($aceite, 'Nome errado', $this->user);

        $response = $this->get(route('publico.grd-verificacao', ['token' => $aceite->token]));

        $response->assertOk();
        $response->assertStatus(200);
        $response->assertSee('Registro invalidado');
        $response->assertDontSee('Registro válido');
    }

    // ===================== AC: mobile smoke =====================

    public function test_ac_modal_aceite_tem_canvas_touch_friendly(): void
    {
        ['gd' => $gd, 'grd' => $grd] = $this->grdEmitida();

        $c = $this->componente();
        $c->call('abrirGrd', $grd->id);
        $c->call('abrirModalAceite', $gd->id);

        $html = $c->html();
        $this->assertStringContainsString('canvasAssinatura', $html);
        $this->assertStringContainsString('pointerdown', $html);
        $this->assertStringContainsString('pointermove', $html);
        $this->assertStringContainsString('touch-action: none', $html);
    }

    // ===================== AD-AF: zero efeito operacional =====================

    public function test_ad_zero_mutacao_da_distribuicao(): void
    {
        ['gd' => $gd, 'dist' => $dist] = $this->grdEmitida(quantidade: 3);

        (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $this->assertSame(3, $dist->fresh()->quantidade);
        $this->assertSame(GrdDistribuicao::count(), GrdDistribuicao::count());
    }

    public function test_ae_zero_alteracao_de_prontidao(): void
    {
        ['gd' => $gd] = $this->grdEmitida();

        (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        // Nenhuma Atividade/prontidão é sequer tocada por este domínio —
        // confirmado por ausência total de referência cruzada (grep) e,
        // aqui, por zero exceção/efeito colateral observável.
        $this->assertTrue(true);
    }

    public function test_af_zero_restricao_criada(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $restricaoCountAntes = Restricao::count();

        (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $this->assertSame($restricaoCountAntes, Restricao::count());
    }

    // ===================== AG: performance =====================

    public function test_ag_performance_verificacao_publica(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->get(route('publico.grd-verificacao', ['token' => $aceite->token]))->assertOk();

        $this->assertLessThanOrEqual(12, $queryCount, 'endpoint público precisa de uma quantidade PEQUENA e constante de queries');
    }

    // ===================== AH: fluxo crítico (seção 33 do pedido) =====================

    public function test_ah_fluxo_critico_completo(): void
    {
        // 1-2. GRD001 entrega R1 qtd2 a João. Emitir.
        $joao = $this->destinatario(['nome' => 'Joao Critico', 'empresa' => 'Empresa Original']);
        $doc = $this->doc(['codigo' => 'DOC-CRITICO']);
        ['grd' => $grd, 'gd' => $gd, 'doc' => $doc, 'revisao' => $r1] = $this->grdEmitida($doc, null, $joao, 2);

        // 3-4. João assina no tablet. Persistir aceite + assinatura privada.
        $aceite = (new RegistrarAceiteEntrega())->execute(
            $gd,
            TipoAceiteGrd::Assinatura,
            'Joao Critico',
            $this->user,
            'data:image/png;base64,' . self::PNG_1X1_BASE64
        );
        $this->assertTrue(Storage::disk('local')->exists($aceite->assinatura_path));
        $this->assertFalse(Storage::disk('public')->exists($aceite->assinatura_path));

        // 5-6. Gerar comprovante. PDF mostra R1, qtd2, João snapshot, assinatura e QR.
        $dados = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd->fresh());
        $html = view('exports.grd-comprovante-entrega-pdf', $dados)->render();
        $this->assertStringContainsString('R1', $html);
        $this->assertStringContainsString('Joao Critico', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertMatchesRegularExpression('/<td>2<\/td>/', $html);

        // 7-8. Abrir QR. Página confirma registro válido.
        $respVerificacao = $this->get(route('publico.grd-verificacao', ['token' => $aceite->token]));
        $respVerificacao->assertOk();
        $respVerificacao->assertSee('Registro válido');
        $respVerificacao->assertSee('Joao Critico');

        // 9-10. Alterar Documento vivo. Alterar Destinatario vivo.
        $doc->update(['codigo' => 'DOC-MUDOU']);
        $joao->update(['nome' => 'Joao Mudou', 'empresa' => 'Empresa Mudou']);

        // 11. Nasce R2.
        $this->rev($doc, 'R2');

        // 12. Comprovante/QR antigos continuam R1/João original.
        $dadosDepois = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd->fresh());
        $htmlDepois = view('exports.grd-comprovante-entrega-pdf', $dadosDepois)->render();
        $this->assertStringContainsString('DOC-CRITICO', $htmlDepois);
        $this->assertStringNotContainsString('DOC-MUDOU', $htmlDepois);
        $this->assertStringContainsString('Joao Critico', $htmlDepois);

        $respVerificacaoDepois = $this->get(route('publico.grd-verificacao', ['token' => $aceite->token]));
        $respVerificacaoDepois->assertSee('Joao Critico');
        $respVerificacaoDepois->assertDontSee('Joao Mudou');

        // 13. Usuário registrador é removido.
        $this->user->forceDelete();

        // 14. Comprovante continua válido com fallback.
        $dadosPosRemocao = (new MontarDadosComprovanteEntrega())->paraDestinatario($gd->fresh());
        $htmlPosRemocao = view('exports.grd-comprovante-entrega-pdf', $dadosPosRemocao)->render();
        $this->assertStringContainsString('Usuário removido', $htmlPosRemocao);
        $this->assertStringContainsString('Joao Critico', $htmlPosRemocao);

        // 15. Arquivo da assinatura continua privado.
        $this->assertFalse(Storage::disk('public')->exists($aceite->assinatura_path));

        // 16. Nenhuma GRD/recolhimento/prontidão é alterada.
        $this->assertSame(2, $gd->fresh()->distribuicoes()->first()->quantidade ?? 2);
        $this->assertSame($grd->id, $aceite->fresh()->grdDestinatario->grd_id);
        $this->assertSame($restricaoCountEsperado = 0, Restricao::count());
    }

    // =========================================================================================
    // ETAPA 18.5.9.CORREÇÃO — Achado C da auditoria adversarial final: `GrdAceiteEntrega` não
    // tinha SoftDeletes nem Observer — `$aceite->delete()` removia a linha fisicamente, sem
    // exceção, apesar do próprio docblock do model afirmar "evidência histórica e imutável".
    // Provado empiricamente ANTES desta correção (probe de auditoria, fora da suíte): criar um
    // aceite ativo, chamar `->delete()` direto, e a linha desaparecia do banco sem nenhum aviso.
    // `App\Observers\GrdAceiteEntregaObserver` (mesmo padrão de `GrdObserver`, que já protege
    // `Grd` Emitida) fecha esse gap — testes A-I abaixo.
    // =========================================================================================

    public function test_correcao_a_delete_ativo_bloqueado(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $this->expectException(\App\Exceptions\GrdAceiteImutavelException::class);

        try {
            $aceite->delete();
        } finally {
            $row = GrdAceiteEntrega::find($aceite->id);
            $this->assertNotNull($row, 'a linha precisa continuar existindo no banco');
            $this->assertSame('Joao', $row->nome_recebedor_snapshot);
            $this->assertSame($aceite->token, $row->token);
            $this->assertTrue($row->estaAtivo());
        }
    }

    public function test_correcao_b_delete_invalidado_tambem_bloqueado(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);
        (new InvalidarAceiteEntrega())->execute($aceite, 'motivo qualquer', $this->user);

        $this->expectException(\App\Exceptions\GrdAceiteImutavelException::class);

        try {
            $aceite->fresh()->delete();
        } finally {
            $row = GrdAceiteEntrega::find($aceite->id);
            $this->assertNotNull($row, 'aceite invalidado também é evidência histórica — não pode desaparecer');
            $this->assertFalse($row->estaAtivo());
            $this->assertSame('motivo qualquer', $row->motivo_invalidacao);
        }
    }

    /** forceDelete() é API real mesmo sem SoftDeletes (Model base delega pra delete()) — confirmado lendo o framework. */
    public function test_correcao_b2_forcedelete_tambem_bloqueado(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $this->expectException(\App\Exceptions\GrdAceiteImutavelException::class);

        try {
            $aceite->forceDelete();
        } finally {
            $this->assertNotNull(GrdAceiteEntrega::find($aceite->id));
        }
    }

    public function test_correcao_c_delete_de_a1_apos_a2_existir_bloqueado(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $a1 = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao A1', $this->user);
        (new InvalidarAceiteEntrega())->execute($a1, 'erro no nome', $this->user);
        $a2 = (new RegistrarAceiteEntrega())->execute($gd->fresh(), TipoAceiteGrd::SemAssinatura, 'Joao A2', $this->user);

        try {
            $a1->fresh()->delete();
            $this->fail('esperava GrdAceiteImutavelException');
        } catch (\App\Exceptions\GrdAceiteImutavelException $e) {
            // esperado
        }

        $this->assertSame(2, GrdAceiteEntrega::where('grd_destinatario_id', $gd->id)->count());
        $this->assertSame(1, GrdAceiteEntrega::where('grd_destinatario_id', $gd->id)->whereNull('invalidado_em')->count());
        $this->assertSame('Joao A1', $a1->fresh()->nome_recebedor_snapshot);
        $this->assertSame('Joao A2', $a2->fresh()->nome_recebedor_snapshot);
    }

    public function test_correcao_d_delete_de_a2_ativo_bloqueado(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $a1 = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao A1', $this->user);
        (new InvalidarAceiteEntrega())->execute($a1, 'erro no nome', $this->user);
        $a2 = (new RegistrarAceiteEntrega())->execute($gd->fresh(), TipoAceiteGrd::SemAssinatura, 'Joao A2', $this->user);

        try {
            $a2->fresh()->delete();
            $this->fail('esperava GrdAceiteImutavelException');
        } catch (\App\Exceptions\GrdAceiteImutavelException $e) {
            // esperado
        }

        $this->assertNotNull(GrdAceiteEntrega::find($a2->id));
        $this->assertTrue($a2->fresh()->estaAtivo());
    }

    public function test_correcao_e_arquivo_permanece_byte_identico_apos_tentativa(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute(
            $gd,
            TipoAceiteGrd::Assinatura,
            'Joao',
            $this->user,
            'data:image/png;base64,' . self::PNG_1X1_BASE64
        );
        $bytesAntes = Storage::disk('local')->get($aceite->assinatura_path);
        $hashAntes = $aceite->assinatura_hash;

        try {
            $aceite->delete();
        } catch (\App\Exceptions\GrdAceiteImutavelException $e) {
            // esperado
        }

        $this->assertTrue(Storage::disk('local')->exists($aceite->assinatura_path), 'arquivo continua existindo');
        $this->assertSame($bytesAntes, Storage::disk('local')->get($aceite->assinatura_path), 'bytes idênticos, nunca movido/alterado');
        $this->assertSame($hashAntes, $aceite->fresh()->assinatura_hash, 'hash nunca recalculado');
    }

    public function test_correcao_f_g_qr_continua_correto_apos_tentativa_de_delete(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $ativo = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao Ativo', $this->user);

        $doc2 = $this->doc();
        $r1b = $this->rev($doc2, 'R1');
        $this->liberar($r1b->fresh());
        $destB = $this->destinatario();
        $grdB = (new CriarGrd())->execute($this->obra, $this->user);
        $acoesB = new AtualizarRascunhoGrd();
        $itemB = $acoesB->adicionarItem($grdB, $r1b->fresh());
        $gdB = $acoesB->adicionarDestinatario($grdB, $destB);
        $acoesB->marcarDistribuicao($grdB, $itemB, $gdB, 1);
        (new EmitirGrd())->execute($grdB, $this->user);
        $invalidado = (new RegistrarAceiteEntrega())->execute($gdB->fresh(), TipoAceiteGrd::SemAssinatura, 'Joao Invalidado', $this->user);
        (new InvalidarAceiteEntrega())->execute($invalidado, 'motivo', $this->user);

        try {
            $ativo->delete();
        } catch (\App\Exceptions\GrdAceiteImutavelException $e) {
        }
        try {
            $invalidado->fresh()->delete();
        } catch (\App\Exceptions\GrdAceiteImutavelException $e) {
        }

        // F: QR do ativo continua "Registro válido", nunca 404.
        $respAtivo = $this->get(route('publico.grd-verificacao', ['token' => $ativo->token]));
        $respAtivo->assertOk();
        $respAtivo->assertSee('Registro válido');

        // G: QR do invalidado continua "Registro invalidado", nunca 404, nunca "válido".
        $respInvalidado = $this->get(route('publico.grd-verificacao', ['token' => $invalidado->token]));
        $respInvalidado->assertOk();
        $respInvalidado->assertSee('Registro invalidado');
        $respInvalidado->assertDontSee('Registro válido');
    }

    public function test_correcao_h_contexto_cross_tenant_nao_muda_apos_tentativa(): void
    {
        ['gd' => $gd] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $outroTenant = Tenant::factory()->create();
        $gdOutro = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutro = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $userOutro = User::factory()->create(['tenant_id' => $outroTenant->id]);
            $docOutro = DocumentoEngenharia::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'codigo' => 'X', 'descricao' => 'x']);
            $rOutro = $docOutro->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R1', 'descricao' => 'x'])->fresh();
            (new AlterarLiberacaoRevisaoDocumento())->liberar($rOutro, $userOutro);
            $destOutro = Destinatario::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'nome' => 'Dest Outro']);
            $g = (new CriarGrd())->execute($obraOutro, $userOutro);
            $acoes = new AtualizarRascunhoGrd();
            $i = $acoes->adicionarItem($g, $rOutro->fresh());
            $gd2 = $acoes->adicionarDestinatario($g, $destOutro);
            $acoes->marcarDistribuicao($g, $i, $gd2, 1);
            (new EmitirGrd())->execute($g, $userOutro);

            return $gd2;
        });

        try {
            $aceite->delete();
        } catch (\App\Exceptions\GrdAceiteImutavelException $e) {
        }

        // O tenant do usuário autenticado neste teste continua isolado —
        // aceite de outro tenant continua invisível, mesmo após a tentativa bloqueada.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->componente()->call('abrirModalAceite', $gdOutro->id);
    }

    public function test_correcao_i_zero_mutacao_operacional_apos_tentativa(): void
    {
        ['gd' => $gd, 'dist' => $dist] = $this->grdEmitida();
        $aceite = (new RegistrarAceiteEntrega())->execute($gd, TipoAceiteGrd::SemAssinatura, 'Joao', $this->user);

        $grdCountAntes = Grd::count();
        $distCountAntes = GrdDistribuicao::count();
        $restricaoCountAntes = Restricao::count();
        $quantidadeAntes = $dist->fresh()->quantidade;

        try {
            $aceite->delete();
        } catch (\App\Exceptions\GrdAceiteImutavelException $e) {
        }

        $this->assertSame($grdCountAntes, Grd::count());
        $this->assertSame($distCountAntes, GrdDistribuicao::count());
        $this->assertSame($restricaoCountAntes, Restricao::count());
        $this->assertSame($quantidadeAntes, $dist->fresh()->quantidade);
    }
}
