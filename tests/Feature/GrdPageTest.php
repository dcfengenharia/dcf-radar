<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Enums\Papel;
use App\Enums\ResultadoRecolhimento;
use App\Enums\StatusGrd;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Grd;
use App\Models\GrdDistribuicao;
use App\Models\InconsistenciaAvanco;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.5.2 — UI operacional de GRD. Cobertura A-AP do
 * briefing. Toda mutação passa pelo componente Livewire real
 * (`pages::engenharia.grds`), que por sua vez delega para as Actions já
 * aprovadas em 18.5.1/18.5.1.HARDENING — nunca chamado diretamente aqui.
 */
class GrdPageTest extends TestCase
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
        return Livewire::test('pages::engenharia.grds');
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
        return Destinatario::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Destinatario ' . uniqid(),
        ], $o));
    }

    // ===================== A-D: acesso e autorização =====================

    public function test_a_usuario_com_ver_acessa(): void
    {
        $leitor = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertOk()
            ->assertSee('GRD');
    }

    public function test_b_sem_ver_bloqueia(): void
    {
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($semAcesso);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertForbidden();
    }

    public function test_c_editar_ve_botao_nova_grd(): void
    {
        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertSee('Nova GRD');
    }

    public function test_d_sem_editar_nao_ve_botao_e_mutacao_bloqueada(): void
    {
        $leitor = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertDontSee('Nova GRD')
            ->call('criarGrd')
            ->assertForbidden();
    }

    // ===================== E-F: criação de rascunho =====================

    public function test_e_f_criar_rascunho_sem_numero(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');

        $grdId = $c->get('grdAbertaId');
        $this->assertNotNull($grdId);
        $grd = Grd::findOrFail($grdId);
        $this->assertTrue($grd->estaRascunho());
        $this->assertNull($grd->numero);
        $c->assertSee('Número será atribuído na emissão');
    }

    // ===================== G-H: documentos do rascunho =====================

    public function test_g_adicionar_documento_ao_rascunho(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');
        $c->call('adicionarDocumento', $r1->fresh()->id);

        $grd = Grd::findOrFail($c->get('grdAbertaId'));
        $this->assertSame(1, $grd->itens()->count());
    }

    public function test_h_stale_revision_aparece_como_alerta_sem_remover_automaticamente(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');
        $c->call('adicionarDocumento', $r1->fresh()->id);

        $this->rev($doc, 'R2'); // nasce depois — R1 deixa de ser vigente

        $grd = Grd::findOrFail($c->get('grdAbertaId'));
        $this->assertSame(1, $grd->itens()->count(), 'item nao deve ser removido automaticamente');
        $c->call('$refresh')
            ->assertSee('revisão vigente diferente');
    }

    // ===================== I-J: destinatários =====================

    public function test_i_adicionar_destinatario_existente(): void
    {
        $dest = $this->destinatario(['nome' => 'Fulano Existente']);
        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');
        $c->call('adicionarDestinatarioExistente', $dest->id);

        $grd = Grd::findOrFail($c->get('grdAbertaId'));
        $this->assertSame(1, $grd->destinatarios()->count());
    }

    public function test_j_criar_destinatario_inline(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');
        $c->set('novoDestinatarioNome', 'Novo Fulano')
            ->set('novoDestinatarioEmpresa', 'Acme')
            ->call('salvarNovoDestinatarioEAdicionar');

        $this->assertDatabaseHas('destinatarios', ['nome' => 'Novo Fulano', 'empresa' => 'Acme', 'obra_id' => $this->obra->id]);
        $grd = Grd::findOrFail($c->get('grdAbertaId'));
        $this->assertSame(1, $grd->destinatarios()->count());
    }

    // ===================== K-L: cross-obra / cross-tenant =====================

    public function test_k_cross_obra_destinatario_via_livewire_rejeita(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $destB = $this->destinatario([], $obraB);

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');

        // Mesmo padrão já usado no projeto pra rejeição escopada por obra
        // via findOrFail (ex.: WorkManagementTest/ClientManagementTest):
        // 404 é a rejeição — nunca um 500 nem uma adição silenciosa. Como
        // a exceção esperada interrompe o teste, a prova de "nada foi
        // adicionado" é o próprio fato de nunca alcançarmos código após
        // a chamada rejeitada.
        $this->expectException(ModelNotFoundException::class);
        $c->call('adicionarDestinatarioExistente', $destB->id);
    }

    public function test_l_cross_tenant_grd_nao_acessivel(): void
    {
        $outroTenant = Tenant::factory()->create();
        $grdOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutro = Work::factory()->create(['tenant_id' => $outroTenant->id]);

            return Grd::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'status' => 'rascunho']);
        });

        $c = $this->componente()->set('obraId', $this->obra->id);

        $this->expectException(ModelNotFoundException::class);
        $c->call('abrirGrd', $grdOutroTenant->id);
    }

    // ===================== M-P: matriz / quantidade / emissão =====================

    private function montarRascunhoCompleto($c, ?DocumentoEngenhariaRevisao $revisao = null, ?Destinatario $dest = null, int $quantidade = 1): array
    {
        $doc = $this->doc();
        $revisao ??= $this->rev($doc);
        $this->liberar($revisao->fresh());
        $dest ??= $this->destinatario();

        $c->call('criarGrd');
        $c->call('adicionarDocumento', $revisao->fresh()->id);
        $c->call('adicionarDestinatarioExistente', $dest->id);

        $grd = Grd::findOrFail($c->get('grdAbertaId'));
        $item = $grd->itens()->first();
        $gd = $grd->destinatarios()->first();
        $c->call('marcarCelula', $item->id, $gd->id);
        if ($quantidade !== 1) {
            $c->call('atualizarQuantidadeCelula', $item->id, $gd->id, $quantidade);
        }

        return compact('grd', 'item', 'gd', 'revisao', 'dest', 'doc');
    }

    public function test_m_matriz_parcial(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        $doc1 = $this->doc();
        $r1 = $this->rev($doc1);
        $this->liberar($r1->fresh());
        $doc2 = $this->doc();
        $r2 = $this->rev($doc2);
        $this->liberar($r2->fresh());
        $destA = $this->destinatario();
        $destB = $this->destinatario();

        $c->call('criarGrd');
        $c->call('adicionarDocumento', $r1->fresh()->id);
        $c->call('adicionarDocumento', $r2->fresh()->id);
        $c->call('adicionarDestinatarioExistente', $destA->id);
        $c->call('adicionarDestinatarioExistente', $destB->id);

        $grd = Grd::findOrFail($c->get('grdAbertaId'));
        $item1 = $grd->itens()->orderBy('created_at')->first();
        $gdA = $grd->destinatarios()->first();

        $c->call('marcarCelula', $item1->id, $gdA->id);

        $this->assertSame(1, GrdDistribuicao::whereIn('grd_item_id', $grd->itens()->pluck('id'))->count());
    }

    public function test_n_quantidade_alteravel_na_matriz(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        ['grd' => $grd, 'item' => $item, 'gd' => $gd] = $this->montarRascunhoCompleto($c);

        $c->call('atualizarQuantidadeCelula', $item->id, $gd->id, 5);

        $dist = GrdDistribuicao::where('grd_item_id', $item->id)->where('grd_destinatario_id', $gd->id)->first();
        $this->assertSame(5, $dist->quantidade);
    }

    public function test_o_p_emitir_com_sucesso_e_confirmacao_torna_imutavel(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        $this->montarRascunhoCompleto($c);

        $c->call('abrirModalEmitir');
        $c->call('confirmarEmissao');

        $grd = Grd::findOrFail($c->get('grdAbertaId'));
        $this->assertTrue($grd->estaEmitida());
        $this->assertSame(1, $grd->numero);
        $this->assertNull($c->get('erroEmissao'));

        // P: emitida não mostra controles de edição
        $c->call('$refresh')->assertDontSee('Adicionar')->assertDontSee('Emitir GRD');
    }

    // ===================== Q-R: revisão não vigente / não liberada =====================

    public function test_q_emitir_com_revisao_nao_vigente_mostra_erro_didatico(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        ['grd' => $grd] = $this->montarRascunhoCompleto($c, $r1);

        $this->rev($doc, 'R2'); // torna R1 obsoleta

        $c->call('abrirModalEmitir');
        $c->call('confirmarEmissao');

        $this->assertNotNull($c->get('erroEmissao'));
        $c->assertSee($c->get('erroEmissao'));
        $this->assertTrue(Grd::findOrFail($grd->id)->estaRascunho(), 'nunca 500 cru, GRD continua rascunho');
    }

    public function test_r_emitir_com_revisao_nao_liberada_mostra_erro_didatico(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1'); // nunca liberada
        $dest = $this->destinatario();

        $c->call('criarGrd');
        $c->call('adicionarDocumento', $r1->fresh()->id);
        $c->call('adicionarDestinatarioExistente', $dest->id);
        $grd = Grd::findOrFail($c->get('grdAbertaId'));
        $item = $grd->itens()->first();
        $gd = $grd->destinatarios()->first();
        $c->call('marcarCelula', $item->id, $gd->id);

        $c->call('abrirModalEmitir');
        $c->call('confirmarEmissao');

        $this->assertNotNull($c->get('erroEmissao'));
        $this->assertTrue(Grd::findOrFail($grd->id)->estaRascunho());
    }

    // ===================== S-T: snapshots =====================

    public function test_s_t_detalhe_usa_snapshots_alterar_cadastro_nao_muda_historico(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        $dest = $this->destinatario(['nome' => 'Nome Original']);
        ['grd' => $grd] = $this->montarRascunhoCompleto($c, null, $dest);

        $c->call('abrirModalEmitir');
        $c->call('confirmarEmissao');

        $dest->update(['nome' => 'Nome Alterado Depois']);

        $c->call('$refresh');
        $c->assertSee('Nome Original')->assertDontSee('Nome Alterado Depois');
    }

    // ===================== U-Y: obsoletas / candidatos / nova revisão =====================

    public function test_u_v_w_x_y_fluxo_completo_obsoleta_candidato_nova_revisao(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Fluxo']);
        ['grd' => $grd1] = $this->montarRascunhoCompleto($c, $r1, $joao, 2);
        $c->call('abrirModalEmitir')->call('confirmarEmissao');

        $r2 = $this->rev($doc, 'R2'); // V: ainda não liberada

        $c->call('fecharGrd');
        $c->call('setAba', 'candidatos');
        $c->assertSee('Nenhuma recomendação'); // V: sem candidato enquanto R2 não liberada

        $c->call('setAba', 'obsoletas');
        $c->assertSee('Joao Fluxo'); // U: obsoleta aparece após R2 nascer

        $this->liberar($r2->fresh()); // W: liberar R2 gera candidato
        $c->call('$refresh')->call('setAba', 'candidatos');
        $c->assertSee('Joao Fluxo');

        // X: entregar R2 remove candidato
        $c->call('criarGrdAPartirDeCandidato', $doc->id, $joao->id);
        $grd2 = Grd::findOrFail($c->get('grdAbertaId'));
        $item2 = $grd2->itens()->first();
        $gd2 = $grd2->destinatarios()->first();
        $c->call('abrirModalEmitir')->call('confirmarEmissao');

        $c->call('setAba', 'candidatos');
        $c->assertDontSee('Joao Fluxo');

        // Y: R1 continua pendente (2, nunca recolhido por causa de R2)
        $dist1 = GrdDistribuicao::whereHas('item', fn ($q) => $q->where('documento_engenharia_revisao_id', $r1->id))->firstOrFail();
        $this->assertSame(2, $dist1->quantidadePendente());
    }

    // ===================== Z-AC: recolhimento =====================

    public function test_z_aa_ab_ac_registrar_recolhimento_parcial_nao_localizado_e_historico(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        ['grd' => $grd] = $this->montarRascunhoCompleto($c, null, null, 3);
        $c->call('abrirModalEmitir')->call('confirmarEmissao');

        $dist = GrdDistribuicao::whereHas('item.grd', fn ($q) => $q->where('id', $grd->id))->firstOrFail();

        // Z: parcial
        $c->call('abrirModalRecolhimento', $dist->id);
        $c->set('recolhimentoResultado', 'recolhido')->set('recolhimentoQuantidade', 1)->call('confirmarRecolhimento');
        $this->assertSame(2, $dist->fresh()->quantidadePendente());

        // AA: não localizado
        $c->call('abrirModalRecolhimento', $dist->id);
        $c->set('recolhimentoResultado', 'nao_localizado')->set('recolhimentoQuantidade', 2)->call('confirmarRecolhimento');
        $this->assertSame(2, $dist->fresh()->quantidadePendente(), 'nao_localizado nunca reduz pendencia');

        // AB: quantidade inválida rejeita (excede pendente para NaoLocalizado)
        $c->call('abrirModalRecolhimento', $dist->id);
        $c->set('recolhimentoResultado', 'nao_localizado')->set('recolhimentoQuantidade', 100)->call('confirmarRecolhimento');
        $this->assertNotNull($c->get('recolhimentoErro'));

        // AC: histórico de tentativas aparece
        $c->call('$refresh');
        $c->assertSeeHtml('Histórico de tentativas de recolhimento');
    }

    // ===================== AD-AE: destinatário soft-deleted =====================

    public function test_ad_ae_soft_deleted_destinatario_permanece_obsoletas_some_candidatos(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $dest = $this->destinatario(['nome' => 'Vai Ser Desativado']);
        $this->montarRascunhoCompleto($c, $r1, $dest);
        $c->call('abrirModalEmitir')->call('confirmarEmissao');

        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());

        $dest->delete();

        $c->call('setAba', 'obsoletas')->assertSee('Vai Ser Desativado'); // AD

        $c->call('setAba', 'candidatos')->assertDontSee('Vai Ser Desativado'); // AE
    }

    // ===================== AF-AG: cross-obra/tenant da própria página =====================

    public function test_af_grd_de_outra_obra_nao_aparece_na_listagem(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $grdB = Grd::create(['tenant_id' => $this->tenant->id, 'obra_id' => $obraB->id, 'status' => 'rascunho']);

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->assertDontSeeHtml($grdB->id);
    }

    public function test_ag_cross_tenant_obra_nao_selecionavel(): void
    {
        $outroTenant = Tenant::factory()->create();
        $obraOutroTenant = TenantContext::actingAs($outroTenant, fn () => Work::factory()->create(['tenant_id' => $outroTenant->id]));

        $c = $this->componente();
        $c->set('obraId', $obraOutroTenant->id)->assertForbidden();
    }

    // ===================== AH-AI: emitida imutável na UI =====================

    public function test_ah_ai_emitida_nao_mostra_controles_e_chamada_direta_falha(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        ['grd' => $grd] = $this->montarRascunhoCompleto($c);
        $c->call('abrirModalEmitir')->call('confirmarEmissao');

        $c->assertDontSee('Nova GRD para'); // sem controles de rascunho

        // AI: chamada Livewire direta numa Emitida deve falhar (Action guard, não a UI)
        $doc = $this->doc();
        $rLivre = $this->rev($doc);
        $this->liberar($rLivre->fresh());
        $c->call('adicionarDocumento', $rLivre->fresh()->id); // não deve lançar 500 — tratado e toast de erro
        $this->assertSame(1, Grd::findOrFail($grd->id)->itens()->count(), 'nada foi adicionado numa GRD emitida');
    }

    // ===================== AJ-AM: performance =====================

    public function test_aj_listagem_nao_tem_n_mais_1_com_20_grds(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $doc = $this->doc();
            $r1 = $this->rev($doc);
            $this->liberar($r1->fresh());
            $dest = $this->destinatario();
            $c = $this->componente()->set('obraId', $this->obra->id);
            $this->montarRascunhoCompleto($c, $r1, $dest);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->componente()->set('obraId', $this->obra->id);
        $contagem20 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(60, $contagem20, 'listagem de 20 GRDs não deve escalar linearmente por linha');
    }

    public function test_ak_detalhe_matriz_nao_tem_n_mais_1_com_10x10(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');
        $grd = Grd::findOrFail($c->get('grdAbertaId'));

        for ($i = 0; $i < 10; $i++) {
            $doc = $this->doc();
            $r = $this->rev($doc);
            $this->liberar($r->fresh());
            $c->call('adicionarDocumento', $r->fresh()->id);
        }
        for ($i = 0; $i < 10; $i++) {
            $dest = $this->destinatario();
            $c->call('adicionarDestinatarioExistente', $dest->id);
        }

        $grd = $grd->fresh();
        $itemIds = $grd->itens()->pluck('id');
        $gdIds = $grd->destinatarios()->pluck('id');
        foreach ($itemIds as $itemId) {
            foreach ($gdIds as $gdId) {
                $c->call('marcarCelula', $itemId, $gdId);
            }
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $c->call('$refresh');
        $contagem = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(80, $contagem, 'matriz 10x10 (100 distribuições) não deve gerar 1 query por célula');
    }

    public function test_al_am_obsoletas_e_candidatos_nao_tem_n_mais_1_com_100_distribuicoes(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        for ($i = 0; $i < 15; $i++) {
            $doc = $this->doc();
            $r1 = $this->rev($doc, 'R1');
            $this->liberar($r1->fresh());
            $dest = $this->destinatario();
            $this->montarRascunhoCompleto($c, $r1, $dest);
            $c->call('abrirModalEmitir')->call('confirmarEmissao');
            $r2 = $this->rev($doc, 'R2');
            $this->liberar($r2->fresh());
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $c->call('setAba', 'obsoletas');
        $contagemObs = count(DB::getQueryLog());
        DB::flushQueryLog();
        $c->call('setAba', 'candidatos');
        $contagemCand = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(60, $contagemObs);
        $this->assertLessThan(60, $contagemCand);
    }

    // ===================== AN-AP: independência de prontidão/avanço =====================

    public function test_an_ao_ap_zero_alteracao_de_prontidao_restricao_inconsistencia(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);
        ['grd' => $grd] = $this->montarRascunhoCompleto($c, null, null, 2);
        $c->call('abrirModalEmitir')->call('confirmarEmissao');

        $dist = GrdDistribuicao::whereHas('item.grd', fn ($q) => $q->where('id', $grd->id))->firstOrFail();
        $c->call('abrirModalRecolhimento', $dist->id);
        $c->set('recolhimentoResultado', 'recolhido')->set('recolhimentoQuantidade', 1)->call('confirmarRecolhimento');

        $this->assertSame(0, Restricao::count());
        $this->assertSame(0, InconsistenciaAvanco::count());
    }

    // ===================== Teste crítico (seção 27 do briefing) =====================

    /**
     * Automatiza o fluxo completo pela UI Livewire (nunca bypassando as
     * Actions): criar destinatário → GRD001 com R1 → emitir → nasce/
     * libera R2 → obsoleta+candidato aparecem → recolhe parcial → tenta
     * não localizado → emite GRD002 com R2 → candidato some, R1 continua
     * pendente → recolhe última R1 → zero obsoleta pendente.
     */
    public function test_teste_critico_fluxo_completo_pela_ui(): void
    {
        $c = $this->componente()->set('obraId', $this->obra->id);

        // 1. criar João
        $c->call('criarGrd');
        $c->set('novoDestinatarioNome', 'João')->call('salvarNovoDestinatarioEAdicionar');
        $grd1Id = $c->get('grdAbertaId');
        $joao = Destinatario::where('nome', 'João')->firstOrFail();

        // 2-3. GRD rascunho + adicionar D/R1
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $c->call('adicionarDocumento', $r1->fresh()->id);

        // 4-5. João já adicionado (passo 1) + marcar matriz quantidade=2
        $grd1 = Grd::findOrFail($grd1Id);
        $item1 = $grd1->itens()->first();
        $gd1 = $grd1->destinatarios()->first();
        $c->call('marcarCelula', $item1->id, $gd1->id);
        $c->call('atualizarQuantidadeCelula', $item1->id, $gd1->id, 2);

        // 6. emitir GRD001
        $c->call('abrirModalEmitir')->call('confirmarEmissao');
        $grd1 = $grd1->fresh();
        $this->assertTrue($grd1->estaEmitida());
        $this->assertSame(1, $grd1->numero);

        // 7. criar/liberar R2
        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());

        // 8. abrir tela: R1 aparece obsoleta pendente=2; João candidato a R2
        $c->call('fecharGrd');
        $c->call('setAba', 'obsoletas');
        $c->assertSee('João');
        $dist1 = GrdDistribuicao::whereHas('item', fn ($q) => $q->where('documento_engenharia_revisao_id', $r1->id))->firstOrFail();
        $this->assertSame(2, $dist1->quantidadePendente());

        $c->call('setAba', 'candidatos');
        $c->assertSee('João');

        // 9. recolher 1 de R1
        $c->call('abrirModalRecolhimento', $dist1->id);
        $c->set('recolhimentoResultado', 'recolhido')->set('recolhimentoQuantidade', 1)->call('confirmarRecolhimento');
        $this->assertSame(1, $dist1->fresh()->quantidadePendente());

        // 10. registrar 1 Não localizado
        $c->call('abrirModalRecolhimento', $dist1->id);
        $c->set('recolhimentoResultado', 'nao_localizado')->set('recolhimentoQuantidade', 1)->call('confirmarRecolhimento');
        $this->assertSame(1, $dist1->fresh()->quantidadePendente());
        $this->assertSame('nao_localizado', $dist1->fresh()->estado());

        // 11. criar/emitir GRD002 com R2 para João
        $c->call('criarGrdAPartirDeCandidato', $doc->id, $joao->id);
        $grd2 = Grd::findOrFail($c->get('grdAbertaId'));
        $c->call('abrirModalEmitir')->call('confirmarEmissao');
        $this->assertTrue($grd2->fresh()->estaEmitida());
        $this->assertSame(2, $grd2->fresh()->numero);

        // 12. João não é mais candidato; R1 continua pendente=1
        $c->call('fecharGrd')->call('setAba', 'candidatos');
        $c->assertDontSee('João');
        $this->assertSame(1, $dist1->fresh()->quantidadePendente(), 'entregar R2 nunca recolhe R1');

        // 13. recolher última R1
        $c->call('abrirModalRecolhimento', $dist1->id);
        $c->set('recolhimentoResultado', 'recolhido')->set('recolhimentoQuantidade', 1)->call('confirmarRecolhimento');

        // 14. zero obsoleta pendente
        $this->assertSame(0, $dist1->fresh()->quantidadePendente());
        $c->call('setAba', 'obsoletas');
        $c->assertSee('Nenhuma cópia obsoleta em campo');

        $this->assertSame(0, Restricao::count());
        $this->assertSame(0, InconsistenciaAvanco::count());
    }

    // ===================== 18.5.2.HARDENING — D1: "Usuário removido" =====================

    public function test_hardening_a_b_usuario_removido_mostra_fallback_didatico(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();

        $emitente = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fulano', 'last_name' => 'Emitente']);
        $this->vincularObra($this->obra, $emitente, Papel::Admin->value);

        $grd = (new \App\Actions\Engenharia\CriarGrd())->execute($this->obra, $emitente);
        $acoes = new \App\Actions\Engenharia\AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $r1->fresh());
        $gd = $acoes->adicionarDestinatario($grd, $dest);
        $acoes->marcarDistribuicao($grd, $item, $gd, 1);
        $grd = (new \App\Actions\Engenharia\EmitirGrd())->execute($grd, $emitente);

        $emitente->forceDelete();

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('abrirGrd', $grd->id);

        $c->assertSee('Usuário removido'); // A
        $c->assertDontSee('Fulano Emitente'); // B — nome antigo não aparece
        $this->assertTrue(Grd::findOrFail($grd->id)->estaEmitida(), 'GRD continua íntegra');
    }

    // ===================== 18.5.2.HARDENING — D2: candidato removido entre render e clique =====================

    public function test_hardening_c_d_e_candidato_soft_deletado_antes_do_clique_mostra_erro_didatico_sem_criar_nada(): void
    {
        ['doc' => $doc, 'joao' => $joao] = $this->cenarioComCandidato();

        $antesGrds = Grd::count();

        $joao->delete(); // soft-delete entre a listagem e o clique

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('setAba', 'candidatos');
        $c->call('criarGrdAPartirDeCandidato', $doc->id, $joao->id);

        $c->assertOk(); // nunca 500/exceção crua

        // D: zero GRD nova
        $this->assertSame($antesGrds, Grd::count());
        // E: zero item/destinatário/distribuição parcial (nenhum GRD novo criado pra checar em detalhe)
        $this->assertNull($c->get('grdAbertaId'), 'nenhuma GRD deve ter sido aberta/criada');
    }

    /** F — mesmo atalho, cenário stale (R2 candidato, R3 nasce antes do clique): sempre resolve a vigente FRESH, nunca a stale. */
    public function test_hardening_f_atalho_candidato_resolve_revisao_vigente_fresca_mesmo_com_nova_revisao_no_intervalo(): void
    {
        ['doc' => $doc, 'joao' => $joao, 'r2' => $r2] = $this->cenarioComCandidato();

        // R3 nasce DEPOIS que o candidato (baseado em R2) já apareceu na lista, ANTES do clique.
        $r3 = $this->rev($doc, 'R3');
        $this->liberar($r3->fresh());

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrdAPartirDeCandidato', $doc->id, $joao->id);

        $grd = Grd::findOrFail($c->get('grdAbertaId'));
        $item = $grd->itens()->firstOrFail();
        $this->assertSame($r3->id, $item->documento_engenharia_revisao_id, 'deve usar a revisão vigente FRESH (R3), nunca a stale (R2) que aparecia na lista');
        $this->assertNotSame($r2->id, $item->documento_engenharia_revisao_id);
    }

    private function cenarioComCandidato(): array
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Candidato']);

        $grd = (new \App\Actions\Engenharia\CriarGrd())->execute($this->obra, $this->user);
        $acoes = new \App\Actions\Engenharia\AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $r1->fresh());
        $gd = $acoes->adicionarDestinatario($grd, $joao);
        $acoes->marcarDistribuicao($grd, $item, $gd, 1);
        (new \App\Actions\Engenharia\EmitirGrd())->execute($grd, $this->user);

        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());

        return compact('doc', 'joao', 'r1', 'r2');
    }

    // ===================== 18.5.2.HARDENING — B1: busca de revisões escalável =====================

    public function test_hardening_g_busca_vazia_respeita_limite(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $d = $this->doc(['codigo' => 'DOC-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
            $r = $this->rev($d);
            $this->liberar($r->fresh());
        }

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');
        $c->call('abrirModalAdicionarDocumento');

        $this->assertLessThanOrEqual(30, $c->instance()->revisoesDisponiveisParaAdicionar->count());
    }

    public function test_hardening_h_busca_por_codigo(): void
    {
        $alvo = $this->doc(['codigo' => 'ACHAVEL-123', 'descricao' => 'x']);
        $ralvo = $this->rev($alvo);
        $this->liberar($ralvo->fresh());
        $outro = $this->doc(['codigo' => 'OUTRO-999']);
        $router = $this->rev($outro);
        $this->liberar($router->fresh());

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');
        $c->call('abrirModalAdicionarDocumento');
        $c->set('buscaDocumento', 'ACHAVEL');

        $resultados = $c->instance()->revisoesDisponiveisParaAdicionar;
        $this->assertCount(1, $resultados);
        $this->assertSame('ACHAVEL-123', $resultados->first()->codigo);
    }

    public function test_hardening_i_busca_por_descricao(): void
    {
        $alvo = $this->doc(['codigo' => 'DOC-D1', 'descricao' => 'Planta Hidráulica Específica']);
        $ralvo = $this->rev($alvo);
        $this->liberar($ralvo->fresh());
        $outro = $this->doc(['codigo' => 'DOC-D2', 'descricao' => 'Outra Coisa']);
        $router = $this->rev($outro);
        $this->liberar($router->fresh());

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');
        $c->call('abrirModalAdicionarDocumento');
        $c->set('buscaDocumento', 'Hidráulica');

        $resultados = $c->instance()->revisoesDisponiveisParaAdicionar;
        $this->assertCount(1, $resultados);
        $this->assertSame('DOC-D1', $resultados->first()->codigo);
    }

    public function test_hardening_j_k_busca_nao_vaza_cross_obra_nem_cross_tenant(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $docB = DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $obraB->id, 'codigo' => 'CROSSOBRA-1', 'descricao' => 'x']);
        $rB = $this->rev($docB);
        $this->liberar($rB->fresh());

        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutro = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $docOutro = DocumentoEngenharia::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'codigo' => 'CROSSTENANT-1', 'descricao' => 'x']);
            $docOutro->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
        });

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');
        $c->call('abrirModalAdicionarDocumento');
        $c->set('buscaDocumento', 'CROSS');

        $this->assertCount(0, $c->instance()->revisoesDisponiveisParaAdicionar);
    }

    public function test_hardening_l_m_300_documentos_respeita_limite_sem_n_mais_1(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $d = $this->doc(['codigo' => 'MASSA-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
            $r = $this->rev($d);
            $this->liberar($r->fresh());
        }

        $c = $this->componente()->set('obraId', $this->obra->id);
        $c->call('criarGrd');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $c->call('abrirModalAdicionarDocumento');
        $contagem = count(DB::getQueryLog());
        DB::disableQueryLog();

        $resultados = $c->instance()->revisoesDisponiveisParaAdicionar;
        $this->assertLessThanOrEqual(30, $resultados->count(), 'L: resultado nunca pode exceder o limite mesmo com 300 documentos na obra');
        $this->assertLessThan(20, $contagem, 'M: sem N+1 — contagem de queries não pode escalar com o total de documentos');
    }
}
