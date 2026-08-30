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
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Grd\MontarDadosPdfGrd;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.5.3 — PDF/impressão histórica da GRD. Cobertura
 * A-AE do briefing. Testa tanto `MontarDadosPdfGrd` (dados reais que
 * alimentam o template) quanto o HTML real renderizado por
 * `exports.grd-pdf.blade.php` (mesmo template que o DomPDF consome) —
 * nunca só "PDF retornou bytes".
 */
class GrdPdfTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        // Nome determinístico (não Faker) — evita flake quando o Faker
        // sorteia um nome com apóstrofo (ex.: "O'Hara"): o HTML renderizado
        // escapa a aspas simples, então a comparação de string crua contra
        // "{$first} {$last}" falharia por escaping, não por bug de produto.
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Usuario',
            'last_name' => 'Teste',
        ]);
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

    private function renderizarHtml(Grd $grd): string
    {
        $dados = (new MontarDadosPdfGrd())->paraGrd($grd);

        return view('exports.grd-pdf', $dados)->render();
    }

    // ===================== A-B: escopo Rascunho x Emitida =====================

    public function test_a_rascunho_nao_disponibiliza_pdf_formal(): void
    {
        $grd = (new CriarGrd())->execute($this->obra, $this->user);

        $c = $this->componente();
        $c->call('abrirGrd', $grd->id);
        $c->assertDontSeeHtml('exportarPdfGrd'); // sem botão PDF em rascunho

        // abort()/HttpException dentro de um método Livewire é convertido pelo
        // próprio framework numa Response com o status certo — nunca propaga
        // como exceção crua pro teste (mesmo padrão já usado no projeto, ex.:
        // DocumentosEngenhariaPageTest::test_..._bloqueia -> ->assertForbidden()).
        $c->call('exportarPdfGrd', $grd->id)->assertStatus(404);
    }

    public function test_b_emitida_gera_pdf(): void
    {
        ['grd' => $grd] = $this->grdEmitida();

        $c = $this->componente();
        $response = $c->call('exportarPdfGrd', $grd->id);
        $response->assertOk();
    }

    // ===================== C-K: cabeçalho e conteúdo =====================

    public function test_c_d_e_f_cabecalho_numero_obra_emitida_em_por(): void
    {
        ['grd' => $grd] = $this->grdEmitida();
        $html = $this->renderizarHtml($grd->fresh());

        $this->assertStringContainsString('GRD-' . str_pad((string) $grd->numero, 3, '0', STR_PAD_LEFT), $html); // C
        $this->assertStringContainsString($this->obra->name, $html); // D
        $this->assertStringContainsString($grd->emitida_em->format('d/m/Y'), $html); // E
        $this->assertStringContainsString("{$this->user->first_name} {$this->user->last_name}", $html); // F
    }

    public function test_g_fallback_usuario_removido(): void
    {
        $emitente = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fulano', 'last_name' => 'Sumiu']);
        $this->vincularObra($this->obra, $emitente, Papel::Admin->value);
        ['grd' => $grd] = $this->grdEmitida(usuario: $emitente);
        $emitente->forceDelete();

        $html = $this->renderizarHtml($grd->fresh());

        $this->assertStringContainsString('Usuário removido', $html);
        $this->assertStringNotContainsString('Fulano Sumiu', $html);
    }

    public function test_h_observacao(): void
    {
        $grd = (new CriarGrd())->execute($this->obra, $this->user, 'Observação de teste XYZ');
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $r1->fresh());
        $gd = $acoes->adicionarDestinatario($grd, $dest);
        $acoes->marcarDistribuicao($grd, $item, $gd, 1);
        $grd = (new EmitirGrd())->execute($grd, $this->user);

        $html = $this->renderizarHtml($grd->fresh());
        $this->assertStringContainsString('Observação de teste XYZ', $html);
    }

    public function test_i_j_k_snapshots_codigo_descricao_revisao(): void
    {
        $doc = $this->doc(['codigo' => 'COD-SNAP-1', 'descricao' => 'Descricao Snapshot 1']);
        ['grd' => $grd] = $this->grdEmitida($doc, $this->rev($doc, 'R7'));

        $html = $this->renderizarHtml($grd->fresh());
        $this->assertStringContainsString('COD-SNAP-1', $html);
        $this->assertStringContainsString('Descricao Snapshot 1', $html);
        $this->assertStringContainsString('R7', $html);
    }

    public function test_l_m_nome_empresa_setor_destinatario_snapshot(): void
    {
        $dest = $this->destinatario(['nome' => 'Nome Snap', 'empresa' => 'Empresa Snap', 'setor' => 'Setor Snap']);
        ['grd' => $grd] = $this->grdEmitida(dest: $dest);

        $html = $this->renderizarHtml($grd->fresh());
        $this->assertStringContainsString('Nome Snap', $html);
        $this->assertStringContainsString('Empresa Snap', $html);
        $this->assertStringContainsString('Setor Snap', $html);
    }

    public function test_n_quantidade_distribuicao(): void
    {
        ['grd' => $grd] = $this->grdEmitida(quantidade: 7);
        $html = $this->renderizarHtml($grd->fresh());
        $this->assertMatchesRegularExpression('/<td>7<\/td>/', $html);
    }

    public function test_o_matriz_parcial_nao_inventa_combinacoes(): void
    {
        $doc1 = $this->doc();
        $r1 = $this->rev($doc1, 'RA');
        $this->liberar($r1->fresh());
        $doc2 = $this->doc();
        $r2 = $this->rev($doc2, 'RB');
        $this->liberar($r2->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Matriz']);
        $maria = $this->destinatario(['nome' => 'Maria Matriz']);

        $grd = (new CriarGrd())->execute($this->obra, $this->user);
        $acoes = new AtualizarRascunhoGrd();
        $i1 = $acoes->adicionarItem($grd, $r1->fresh());
        $i2 = $acoes->adicionarItem($grd, $r2->fresh());
        $gj = $acoes->adicionarDestinatario($grd, $joao);
        $gm = $acoes->adicionarDestinatario($grd, $maria);
        $acoes->marcarDistribuicao($grd, $i1, $gj, 1); // D1 x Joao
        $acoes->marcarDistribuicao($grd, $i2, $gm, 1); // D2 x Maria
        // D1xMaria e D2xJoao NUNCA marcados
        $grd = (new EmitirGrd())->execute($grd, $this->user);

        $dados = (new MontarDadosPdfGrd())->paraGrd($grd->fresh());
        $this->assertCount(2, $dados['distribuicoes'], 'só as 2 combinações realmente marcadas devem aparecer, nunca 4 (2x2)');
    }

    // ===================== P-R: imutabilidade histórica =====================

    public function test_p_nova_revisao_nao_altera_pdf_antigo(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        ['grd' => $grd] = $this->grdEmitida($doc, $r1);

        $this->rev($doc, 'R2'); // nasce depois, torna R1 obsoleta

        $html = $this->renderizarHtml($grd->fresh());
        $this->assertStringContainsString('R1', $html);
    }

    public function test_q_alteracao_posterior_do_documento_nao_altera_pdf(): void
    {
        $doc = $this->doc(['codigo' => 'ORIGINAL-COD', 'descricao' => 'Descricao Original']);
        ['grd' => $grd] = $this->grdEmitida($doc, $this->rev($doc));

        $doc->update(['codigo' => 'MUDOU-DEPOIS', 'descricao' => 'Descricao Mudou']);

        $html = $this->renderizarHtml($grd->fresh());
        $this->assertStringContainsString('ORIGINAL-COD', $html);
        $this->assertStringContainsString('Descricao Original', $html);
        $this->assertStringNotContainsString('MUDOU-DEPOIS', $html);
        $this->assertStringNotContainsString('Descricao Mudou', $html);
    }

    public function test_r_alteracao_posterior_do_destinatario_nao_altera_pdf(): void
    {
        $dest = $this->destinatario(['nome' => 'Nome Original Dest', 'empresa' => 'Empresa Original']);
        ['grd' => $grd] = $this->grdEmitida(dest: $dest);

        $dest->update(['nome' => 'Nome Mudou Dest', 'empresa' => 'Empresa Mudou']);

        $html = $this->renderizarHtml($grd->fresh());
        $this->assertStringContainsString('Nome Original Dest', $html);
        $this->assertStringContainsString('Empresa Original', $html);
        $this->assertStringNotContainsString('Nome Mudou Dest', $html);
        $this->assertStringNotContainsString('Empresa Mudou', $html);
    }

    // ===================== S: destinatário soft-deletado =====================

    public function test_s_destinatario_soft_deletado_nao_quebra_pdf(): void
    {
        $dest = $this->destinatario(['nome' => 'Nome Preservado']);
        ['grd' => $grd] = $this->grdEmitida(dest: $dest);

        $dest->delete();

        $html = $this->renderizarHtml($grd->fresh());
        $this->assertStringContainsString('Nome Preservado', $html, 'snapshot continua disponível mesmo com cadastro soft-deletado');

        $response = $this->componente()->call('exportarPdfGrd', $grd->id);
        $response->assertOk();
    }

    // ===================== T-V: recolhimentos =====================

    public function test_t_recolhimento_parcial_aparece_corretamente(): void
    {
        ['grd' => $grd, 'dist' => $dist] = $this->grdEmitida(quantidade: 3);
        (new RegistrarRecolhimento())->execute($dist->fresh(), ResultadoRecolhimento::Recolhido, 1, $this->user);

        $html = $this->renderizarHtml($grd->fresh());
        $this->assertMatchesRegularExpression('/Recolhido/', $html);
    }

    public function test_u_nao_localizado_nao_reduz_quantidade_entregue_original(): void
    {
        ['grd' => $grd, 'dist' => $dist] = $this->grdEmitida(quantidade: 2);
        (new RegistrarRecolhimento())->execute($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 1, $this->user);

        $dados = (new MontarDadosPdfGrd())->paraGrd($grd->fresh());
        $distRefeita = $dados['distribuicoes']->first();
        $this->assertSame(2, $distRefeita->quantidadeEntregue(), 'quantidade entregue original nunca muda');
        $this->assertSame(0, $distRefeita->quantidadeRecolhida());

        $html = $this->renderizarHtml($grd->fresh());
        $this->assertStringContainsString('Não localizado', $html);
    }

    public function test_v_multiplos_recolhimentos_preservados(): void
    {
        ['grd' => $grd, 'dist' => $dist] = $this->grdEmitida(quantidade: 5);
        (new RegistrarRecolhimento())->execute($dist->fresh(), ResultadoRecolhimento::Recolhido, 1, $this->user, 'primeira tentativa');
        (new RegistrarRecolhimento())->execute($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 2, $this->user, 'segunda tentativa');
        (new RegistrarRecolhimento())->execute($dist->fresh(), ResultadoRecolhimento::Recolhido, 1, $this->user, 'terceira tentativa');

        $html = $this->renderizarHtml($grd->fresh());
        $this->assertStringContainsString('primeira tentativa', $html);
        $this->assertStringContainsString('segunda tentativa', $html);
        $this->assertStringContainsString('terceira tentativa', $html);
    }

    public function test_w_registrado_por_removido_usa_fallback(): void
    {
        $registrador = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Registrador', 'last_name' => 'Removido']);
        $this->vincularObra($this->obra, $registrador, Papel::Admin->value);
        ['grd' => $grd, 'dist' => $dist] = $this->grdEmitida(quantidade: 1);
        (new RegistrarRecolhimento())->execute($dist->fresh(), ResultadoRecolhimento::Recolhido, 1, $registrador);
        $registrador->forceDelete();

        $html = $this->renderizarHtml($grd->fresh());
        $this->assertStringContainsString('Usuário removido', $html);
        $this->assertStringNotContainsString('Registrador Removido', $html);
    }

    // ===================== X: "Cancelada" — achado de domínio =====================

    public function test_x_dominio_nao_possui_status_cancelada(): void
    {
        // Achado documentado no relatório: StatusGrd só tem Rascunho|Emitida.
        $valores = array_map(fn ($c) => $c->value, \App\Enums\StatusGrd::cases());
        $this->assertSame(['rascunho', 'emitida'], $valores);
    }

    // ===================== Y-AA: autorização =====================

    public function test_y_sem_permissao_403(): void
    {
        ['grd' => $grd] = $this->grdEmitida();
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($semAcesso);

        // updatedObraId() já bloqueia 'ver' no set() — usuário sem nenhum
        // vínculo na obra nunca alcança exportarPdfGrd (mesma defesa em
        // profundidade já provada em GrdPageTest::test_b_sem_ver_bloqueia).
        Livewire::test('pages::engenharia.grds')->set('obraId', $this->obra->id)->assertForbidden();
    }

    public function test_z_cross_obra_bloqueado(): void
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
        $grdB = (new EmitirGrd())->execute($grdB, $this->user);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->componente()->call('exportarPdfGrd', $grdB->id); // obraId setado é $this->obra, não $obraB
    }

    public function test_aa_cross_tenant_bloqueado(): void
    {
        $outroTenant = Tenant::factory()->create();
        $grdOutro = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
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

            return (new EmitirGrd())->execute($g, $userOutro);
        });

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->componente()->call('exportarPdfGrd', $grdOutro->id);
    }

    // ===================== AB: performance =====================

    public function test_ab_performance_sem_n_mais_1(): void
    {
        $doc1 = $this->doc();
        $r1 = $this->rev($doc1, 'R1');
        $this->liberar($r1->fresh());
        $doc2 = $this->doc();
        $r2 = $this->rev($doc2, 'R1');
        $this->liberar($r2->fresh());

        $grd = (new CriarGrd())->execute($this->obra, $this->user);
        $acoes = new AtualizarRascunhoGrd();
        $item1 = $acoes->adicionarItem($grd, $r1->fresh());
        $item2 = $acoes->adicionarItem($grd, $r2->fresh());

        for ($i = 0; $i < 20; $i++) {
            $dest = $this->destinatario();
            $gd = $acoes->adicionarDestinatario($grd, $dest);
            $acoes->marcarDistribuicao($grd, $item1, $gd, 1);
            $acoes->marcarDistribuicao($grd, $item2, $gd, 1);
        }
        $grd = (new EmitirGrd())->execute($grd, $this->user);

        // registra recolhimentos em várias distribuições (100+ distribuições no total: 20 destinatários x 2 itens = 40, mais que suficiente pra provar ausência de N+1)
        $todas = GrdDistribuicao::whereIn('grd_item_id', [$item1->id, $item2->id])->get();
        foreach ($todas->take(10) as $d) {
            (new RegistrarRecolhimento())->execute($d->fresh(), ResultadoRecolhimento::Recolhido, 1, $this->user);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $dados = (new MontarDadosPdfGrd())->paraGrd($grd->fresh());
        view('exports.grd-pdf', $dados)->render();
        $contagem = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(30, $contagem, "Montagem+render do PDF gerou {$contagem} queries — não pode escalar com o total de distribuições/recolhimentos.");
    }

    // ===================== AC-AD: botão na UI =====================

    public function test_ac_botao_nao_aparece_em_rascunho(): void
    {
        $grd = (new CriarGrd())->execute($this->obra, $this->user);
        $c = $this->componente();
        $c->call('abrirGrd', $grd->id);
        $c->assertDontSee('PDF');
    }

    public function test_ad_botao_aparece_em_emitida(): void
    {
        ['grd' => $grd] = $this->grdEmitida();
        $c = $this->componente();
        $c->call('abrirGrd', $grd->id);
        $c->assertSee('PDF');
    }

    // ===================== AE: teste crítico completo =====================

    public function test_ae_teste_critico_completo_de_snapshot(): void
    {
        $doc = $this->doc(['codigo' => 'D-CRIT', 'descricao' => 'Descricao Critica']);
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Critico', 'empresa' => 'Empresa A', 'setor' => 'Setor X']);

        $grd = (new CriarGrd())->execute($this->obra, $this->user);
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $r1->fresh());
        $gd = $acoes->adicionarDestinatario($grd, $joao);
        $acoes->marcarDistribuicao($grd, $item, $gd, 2);
        $grd = (new EmitirGrd())->execute($grd, $this->user);

        $htmlAntes = $this->renderizarHtml($grd->fresh());
        $this->assertStringContainsString('D-CRIT', $htmlAntes);
        $this->assertStringContainsString('Descricao Critica', $htmlAntes);
        $this->assertStringContainsString('Joao Critico', $htmlAntes);
        $this->assertStringContainsString('Empresa A', $htmlAntes);
        $this->assertStringContainsString('Setor X', $htmlAntes);

        $doc->update(['codigo' => 'D-MUDOU', 'descricao' => 'Descricao Mudou']);
        $joao->update(['nome' => 'Joao Mudou', 'empresa' => 'Empresa B', 'setor' => 'Setor Y']);

        $htmlDepois = $this->renderizarHtml($grd->fresh());

        $this->assertStringContainsString('D-CRIT', $htmlDepois, 'PDF continua mostrando o codigo ORIGINAL');
        $this->assertStringContainsString('Descricao Critica', $htmlDepois);
        $this->assertStringContainsString('Joao Critico', $htmlDepois);
        $this->assertStringContainsString('Empresa A', $htmlDepois);
        $this->assertStringContainsString('Setor X', $htmlDepois);

        $this->assertStringNotContainsString('D-MUDOU', $htmlDepois);
        $this->assertStringNotContainsString('Descricao Mudou', $htmlDepois);
        $this->assertStringNotContainsString('Joao Mudou', $htmlDepois);
        $this->assertStringNotContainsString('Empresa B', $htmlDepois);
        $this->assertStringNotContainsString('Setor Y', $htmlDepois);

        $this->assertSame($htmlAntes, $htmlDepois, 'o HTML do PDF é byte-a-byte idêntico antes e depois das alterações vivas');
    }
}
