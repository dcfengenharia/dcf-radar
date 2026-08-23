<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Actions\Engenharia\AtualizarRascunhoGrd;
use App\Actions\Engenharia\CriarGrd;
use App\Actions\Engenharia\EmitirGrd;
use App\Actions\Engenharia\RegistrarRecolhimento;
use App\Enums\Papel;
use App\Enums\ResultadoRecolhimento;
use App\Exceptions\GrdEmissaoInvalidaException;
use App\Exceptions\GrdImutavelException;
use App\Exceptions\GrdRecolhimentoInvalidoException;
use App\Models\Atividade;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Grd;
use App\Models\GrdDestinatario;
use App\Models\GrdDistribuicao;
use App\Models\GrdItem;
use App\Models\GrdRecolhimento;
use App\Models\InconsistenciaAvanco;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Grd\CandidatosNovaEntregaGrd;
use App\Support\Grd\DetectorCopiasObsoletasGrd;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.5.1 — fundação do domínio de GRD (Guia de Remessa de
 * Documentos): distribuição física de revisões EXATAS de Documento de
 * Engenharia. Cobertura A-AY do briefing 18.5.1. Sem UI ainda — só
 * domínio (migrations, models, Actions, queries de leitura).
 */
class GrdDominioTest extends TestCase
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

    // ===================== helpers =====================

    private function doc(array $overrides = [], ?Work $obra = null): DocumentoEngenharia
    {
        return DocumentoEngenharia::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'codigo' => 'DOC-' . uniqid(),
            'descricao' => 'x',
        ], $overrides));
    }

    private function rev(DocumentoEngenharia $d, string $texto = 'R1', array $extra = []): DocumentoEngenhariaRevisao
    {
        $r = $d->revisoes()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'revisao' => $texto,
            'descricao' => 'x',
        ], $extra));

        return $r->fresh();
    }

    private function liberar(DocumentoEngenhariaRevisao $r, ?User $usuario = null): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->liberar($r, $usuario ?? $this->user);
    }

    private function destinatario(array $overrides = [], ?Work $obra = null): Destinatario
    {
        return Destinatario::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Destinatario ' . uniqid(),
        ], $overrides));
    }

    private function criarGrd(?string $observacao = null, ?Work $obra = null, ?User $usuario = null): Grd
    {
        return (new CriarGrd())->execute($obra ?? $this->obra, $usuario ?? $this->user, $observacao);
    }

    private function emitir(Grd $grd, ?User $usuario = null): Grd
    {
        return (new EmitirGrd())->execute($grd, $usuario ?? $this->user);
    }

    private function recolher(GrdDistribuicao $d, ResultadoRecolhimento $resultado, int $quantidade, ?string $obs = null, ?\DateTimeInterface $ocorridoEm = null, ?User $usuario = null): GrdRecolhimento
    {
        return (new RegistrarRecolhimento())->execute($d, $resultado, $quantidade, $usuario ?? $this->user, $obs, $ocorridoEm);
    }

    /** Monta uma GRD Rascunho já com 1 item + 1 destinatário + 1 distribuição marcada (pronta pra emitir, se a revisão estiver liberada). */
    private function montarGrdComItemDestinatarioDistribuicao(DocumentoEngenhariaRevisao $revisao, Destinatario $dest, int $quantidade = 1, ?Grd $grd = null): array
    {
        $grd = $grd ?? $this->criarGrd();
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $revisao);
        $grdDest = $acoes->adicionarDestinatario($grd, $dest);
        $distribuicao = $acoes->marcarDistribuicao($grd, $item, $grdDest, $quantidade);

        return compact('grd', 'item', 'grdDest', 'distribuicao');
    }

    /** Documento com R1 liberada, distribuída (GRD Emitida) e depois R2 (liberada) — deixa R1 obsoleta e o destinatário candidato a R2. */
    private function cenarioObsoleto(Work $obra, ?User $usuario = null): array
    {
        $usuario ??= $this->user;
        $doc = $this->doc([], $obra);
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh(), $usuario);
        $dest = $this->destinatario([], $obra);

        ['grd' => $grd] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 1, $this->criarGrd(null, $obra, $usuario));
        $this->emitir($grd, $usuario);

        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh(), $usuario);

        return compact('doc', 'dest');
    }

    private function assertLancaImutavel(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Esperava GrdImutavelException.');
        } catch (GrdImutavelException $e) {
            $this->assertTrue(true);
        }
    }

    // ===================== A-C: Destinatario =====================

    public function test_a_destinatario_criado_com_campos_esperados(): void
    {
        $dest = $this->destinatario(['nome' => 'Fulano', 'empresa' => 'Acme', 'setor' => 'Elétrica', 'email' => 'f@x.com', 'telefone' => '11999999999']);

        $this->assertSame('Fulano', $dest->nome);
        $this->assertSame('Acme', $dest->empresa);
        $this->assertSame($this->obra->id, $dest->obra_id);
        $this->assertSame($this->tenant->id, $dest->tenant_id);
    }

    public function test_b_destinatario_e_obra_scoped_duas_obras_coexistem_com_mesmo_nome(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $destA = $this->destinatario(['nome' => 'Mesmo Nome']);
        $destB = $this->destinatario(['nome' => 'Mesmo Nome'], $obraB);

        $this->assertNotSame($destA->id, $destB->id);
        $this->assertSame($this->obra->id, $destA->obra_id);
        $this->assertSame($obraB->id, $destB->obra_id);
    }

    public function test_c_destinatario_cross_tenant_nao_e_visivel(): void
    {
        $outroTenant = Tenant::factory()->create();
        $destOutro = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutro = Work::factory()->create(['tenant_id' => $outroTenant->id]);

            return Destinatario::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'nome' => 'Outro Tenant']);
        });

        $this->assertNull(Destinatario::find($destOutro->id));
    }

    // ===================== D-E: Grd rascunho =====================

    public function test_d_e_grd_nasce_rascunho_com_numero_null(): void
    {
        $grd = $this->criarGrd('obs teste');

        $this->assertTrue($grd->estaRascunho());
        $this->assertFalse($grd->estaEmitida());
        $this->assertNull($grd->numero);
        $this->assertSame('obs teste', $grd->observacao);
        $this->assertSame($this->user->id, $grd->criado_por);
    }

    // ===================== F-I: itens/destinatários/matriz/quantidade =====================

    public function test_f_multiplos_itens_na_mesma_grd(): void
    {
        $doc1 = $this->doc();
        $r1 = $this->rev($doc1);
        $this->liberar($r1->fresh());
        $doc2 = $this->doc();
        $r2 = $this->rev($doc2);
        $this->liberar($r2->fresh());

        $grd = $this->criarGrd();
        $acoes = new AtualizarRascunhoGrd();
        $acoes->adicionarItem($grd, $r1->fresh());
        $acoes->adicionarItem($grd, $r2->fresh());

        $this->assertSame(2, $grd->itens()->count());
    }

    public function test_g_multiplos_destinatarios_na_mesma_grd(): void
    {
        $grd = $this->criarGrd();
        $acoes = new AtualizarRascunhoGrd();
        $acoes->adicionarDestinatario($grd, $this->destinatario());
        $acoes->adicionarDestinatario($grd, $this->destinatario());

        $this->assertSame(2, $grd->destinatarios()->count());
    }

    public function test_h_matriz_parcial_representa_so_combinacoes_marcadas(): void
    {
        $doc1 = $this->doc();
        $r1 = $this->rev($doc1);
        $this->liberar($r1->fresh());
        $doc2 = $this->doc();
        $r2 = $this->rev($doc2);
        $this->liberar($r2->fresh());
        $destA = $this->destinatario();
        $destB = $this->destinatario();

        $grd = $this->criarGrd();
        $acoes = new AtualizarRascunhoGrd();
        $item1 = $acoes->adicionarItem($grd, $r1->fresh());
        $item2 = $acoes->adicionarItem($grd, $r2->fresh());
        $gdA = $acoes->adicionarDestinatario($grd, $destA);
        $gdB = $acoes->adicionarDestinatario($grd, $destB);

        $acoes->marcarDistribuicao($grd, $item1, $gdA, 1);
        $acoes->marcarDistribuicao($grd, $item1, $gdB, 1);
        $acoes->marcarDistribuicao($grd, $item2, $gdA, 1);
        // item2 x gdB NUNCA marcado — matriz explícita, nunca cartesiano implícito.

        $this->assertSame(3, GrdDistribuicao::whereIn('grd_item_id', [$item1->id, $item2->id])->count());

        $emitida = $this->emitir($grd);
        $this->assertTrue($emitida->estaEmitida());
    }

    public function test_i_quantidade_default_1_e_alteravel_em_rascunho(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);

        $this->assertSame(1, $dist->quantidade);

        (new AtualizarRascunhoGrd())->alterarQuantidade($grd, $dist, 5);

        $this->assertSame(5, $dist->fresh()->quantidade);
    }

    public function test_j_item_referencia_revisao_exata_nao_o_documento(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());

        $grd = $this->criarGrd();
        $item = (new AtualizarRascunhoGrd())->adicionarItem($grd, $r1->fresh());
        $this->assertSame($r1->id, $item->documento_engenharia_revisao_id);

        $this->rev($doc, 'R2');

        $item = $item->fresh();
        $this->assertSame($r1->id, $item->documento_engenharia_revisao_id);
        $this->assertNotSame($doc->revisaoVigente()->id, $item->documento_engenharia_revisao_id);
    }

    // ===================== K-L: cross-obra na adição =====================

    public function test_k_adicionar_item_com_revisao_de_outra_obra_rejeitado(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $docB = $this->doc([], $obraB);
        $revB = $this->rev($docB, 'R1');

        $grd = $this->criarGrd();

        $this->expectException(\InvalidArgumentException::class);
        (new AtualizarRascunhoGrd())->adicionarItem($grd, $revB->fresh());
    }

    public function test_l_adicionar_destinatario_de_outra_obra_rejeitado(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $destB = $this->destinatario([], $obraB);

        $grd = $this->criarGrd();

        $this->expectException(\InvalidArgumentException::class);
        (new AtualizarRascunhoGrd())->adicionarDestinatario($grd, $destB);
    }

    // ===================== M, N-O: emissão / numeração =====================

    public function test_n_o_numeracao_sequencial_por_obra_e_independente_entre_obras(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $destA1 = $this->destinatario();
        $destA2 = $this->destinatario();

        ['grd' => $g1] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $destA1);
        $g1 = $this->emitir($g1);
        ['grd' => $g2] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $destA2);
        $g2 = $this->emitir($g2);

        $this->assertSame(1, $g1->numero);
        $this->assertSame(2, $g2->numero);

        $docB = $this->doc([], $obraB);
        $r1B = $this->rev($docB, 'R1');
        $this->liberar($r1B->fresh());
        $destB = $this->destinatario([], $obraB);

        $grdB = $this->criarGrd(null, $obraB);
        ['grd' => $grdB] = $this->montarGrdComItemDestinatarioDistribuicao($r1B->fresh(), $destB, 1, $grdB);
        $grdB = $this->emitir($grdB);

        $this->assertSame(1, $grdB->numero, 'obra B tem sequência própria, independente da obra A');
    }

    // ===================== P-R: emissão sem conteúdo suficiente =====================

    public function test_p_emitir_sem_item_rejeitado(): void
    {
        $grd = $this->criarGrd();

        $this->expectException(GrdEmissaoInvalidaException::class);
        $this->emitir($grd);
    }

    public function test_q_emitir_sem_destinatario_rejeitado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());

        $grd = $this->criarGrd();
        (new AtualizarRascunhoGrd())->adicionarItem($grd, $r1->fresh());

        $this->expectException(GrdEmissaoInvalidaException::class);
        $this->emitir($grd);
    }

    public function test_r_emitir_sem_distribuicao_rejeitado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();

        $grd = $this->criarGrd();
        $acoes = new AtualizarRascunhoGrd();
        $acoes->adicionarItem($grd, $r1->fresh());
        $acoes->adicionarDestinatario($grd, $dest);

        $this->expectException(GrdEmissaoInvalidaException::class);
        $this->emitir($grd);
    }

    // ===================== S-U: revisão vigente/liberada =====================

    public function test_s_emitir_com_revisao_nao_vigente_rejeitado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());
        $dest = $this->destinatario();

        ['grd' => $grd] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);

        $this->expectException(GrdEmissaoInvalidaException::class);
        $this->emitir($grd);
    }

    public function test_t_emitir_com_revisao_vigente_nao_liberada_rejeitado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $dest = $this->destinatario();

        ['grd' => $grd] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);

        $this->expectException(GrdEmissaoInvalidaException::class);
        $this->emitir($grd);
    }

    public function test_m_u_emitir_com_revisao_vigente_liberada_sucesso(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();

        ['grd' => $grd] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $emitida = $this->emitir($grd);

        $this->assertTrue($emitida->estaEmitida());
        $this->assertNotNull($emitida->numero);
        $this->assertNotNull($emitida->emitida_em);
        $this->assertSame($this->user->id, $emitida->emitida_por);
    }

    // ===================== V-Z: snapshots / imutabilidade =====================

    public function test_v_snapshots_congelados_na_emissao(): void
    {
        $doc = $this->doc(['codigo' => 'DOC-SNAP', 'descricao' => 'Descrição Original']);
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $dest = $this->destinatario(['nome' => 'Nome Original', 'empresa' => 'Empresa X', 'setor' => 'Setor Y']);

        ['grd' => $grd, 'item' => $item, 'grdDest' => $grdDest] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $this->assertNull($item->fresh()->codigo_documento_snapshot);
        $this->assertNull($grdDest->fresh()->nome_snapshot);

        $this->emitir($grd);

        $item = $item->fresh();
        $grdDest = $grdDest->fresh();
        $this->assertSame('DOC-SNAP', $item->codigo_documento_snapshot);
        $this->assertSame('Descrição Original', $item->descricao_documento_snapshot);
        $this->assertSame('R1', $item->revisao_snapshot);
        $this->assertSame('Nome Original', $grdDest->nome_snapshot);
        $this->assertSame('Empresa X', $grdDest->empresa_snapshot);
        $this->assertSame('Setor Y', $grdDest->setor_snapshot);
    }

    public function test_w_alterar_cadastro_depois_nao_afeta_grd_emitida(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario(['nome' => 'Original']);
        ['grd' => $grd, 'grdDest' => $grdDest] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $this->emitir($grd);

        $dest->update(['nome' => 'Alterado']);

        $this->assertSame('Original', $grdDest->fresh()->nome_snapshot);
    }

    public function test_x_grd_emitida_tem_status_correto(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);

        $emitida = $this->emitir($grd);

        $this->assertTrue($emitida->estaEmitida());
        $this->assertFalse($emitida->estaRascunho());
        $this->assertSame(\App\Enums\StatusGrd::Emitida, $emitida->status);
    }

    public function test_y_todas_mutacoes_de_rascunho_bloqueadas_apos_emissao(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $docLivre = $this->doc();
        $rLivre = $this->rev($docLivre, 'R1');
        $dest = $this->destinatario();
        $outroDest = $this->destinatario();

        ['grd' => $grd, 'item' => $item, 'grdDest' => $grdDest, 'distribuicao' => $dist] =
            $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 1);
        $grd = $this->emitir($grd);

        $acoes = new AtualizarRascunhoGrd();

        $this->assertLancaImutavel(fn () => $acoes->adicionarItem($grd, $rLivre->fresh()));
        $this->assertLancaImutavel(fn () => $acoes->removerItem($grd, $item->fresh()));
        $this->assertLancaImutavel(fn () => $acoes->adicionarDestinatario($grd, $outroDest));
        $this->assertLancaImutavel(fn () => $acoes->removerDestinatario($grd, $grdDest->fresh()));
        $this->assertLancaImutavel(fn () => $acoes->marcarDistribuicao($grd, $item->fresh(), $grdDest->fresh(), 2));
        $this->assertLancaImutavel(fn () => $acoes->desmarcarDistribuicao($grd, $item->fresh(), $grdDest->fresh()));
        $this->assertLancaImutavel(fn () => $acoes->alterarQuantidade($grd, $dist->fresh(), 5));
        $this->assertLancaImutavel(fn () => $acoes->atualizarObservacao($grd, 'nova obs'));

        // Nada mudou de verdade.
        $this->assertSame(1, $dist->fresh()->quantidade);
        $this->assertNull($grd->fresh()->observacao);
    }

    public function test_z_nova_revisao_nao_altera_grd_historica(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'item' => $item] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $this->emitir($grd);

        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());

        $item = $item->fresh();
        $this->assertSame($r1->id, $item->documento_engenharia_revisao_id);
        $this->assertSame('R1', $item->revisao_snapshot);
    }

    public function test_rascunho_pode_ser_excluido_e_cascade_limpa_itens_e_destinatarios(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'item' => $item, 'grdDest' => $grdDest, 'distribuicao' => $dist] =
            $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);

        $grd->forceDelete();

        $this->assertDatabaseMissing('grds', ['id' => $grd->id]);
        $this->assertDatabaseMissing('grd_itens', ['id' => $item->id]);
        $this->assertDatabaseMissing('grd_destinatarios', ['id' => $grdDest->id]);
        $this->assertDatabaseMissing('grd_distribuicoes', ['id' => $dist->id]);
        $this->assertDatabaseHas('documento_engenharia_revisoes', ['id' => $r1->id]);
        $this->assertDatabaseHas('destinatarios', ['id' => $dest->id]);
    }

    // ===================== AA-AD: obsolescência / candidatos =====================

    public function test_aa_deteccao_de_copia_obsoleta(): void
    {
        ['doc' => $doc, 'dest' => $dest] = $this->cenarioObsoleto($this->obra);

        $obsoletas = (new DetectorCopiasObsoletasGrd())->porObra($this->obra);

        $this->assertCount(1, $obsoletas);
        $registro = $obsoletas->first();
        $this->assertSame($doc->id, $registro->documento->id);
        $this->assertSame('R1', $registro->revisao_entregue->revisao);
        $this->assertSame('R2', $registro->revisao_vigente->revisao);
        $this->assertSame(1, $registro->quantidade_entregue);
        $this->assertSame(0, $registro->quantidade_recolhida);
        $this->assertSame(1, $registro->quantidade_pendente);
        $this->assertSame($dest->id, $registro->grd_destinatario->destinatario_id);
    }

    public function test_ab_r2_nao_liberada_nao_gera_candidato(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $this->emitir($grd);

        $this->rev($doc, 'R2'); // NÃO liberada

        $candidatos = (new CandidatosNovaEntregaGrd())->porObra($this->obra);
        $this->assertCount(0, $candidatos);
    }

    public function test_ac_liberar_r2_gera_candidato(): void
    {
        ['dest' => $dest] = $this->cenarioObsoleto($this->obra);

        $candidatos = (new CandidatosNovaEntregaGrd())->porObra($this->obra);

        $this->assertCount(1, $candidatos);
        $this->assertSame($dest->id, $candidatos->first()->destinatario->id);
        $this->assertSame('R2', $candidatos->first()->revisao_vigente->revisao);
    }

    public function test_ad_entregar_r2_nao_recolhe_r1(): void
    {
        ['doc' => $doc, 'dest' => $dest] = $this->cenarioObsoleto($this->obra);
        $r1 = $doc->revisoes()->where('revisao', 'R1')->firstOrFail();
        $r2 = $doc->revisoes()->where('revisao', 'R2')->firstOrFail();
        $dist1 = GrdDistribuicao::whereHas('item', fn ($q) => $q->where('documento_engenharia_revisao_id', $r1->id))->firstOrFail();

        ['grd' => $grd2] = $this->montarGrdComItemDestinatarioDistribuicao($r2->fresh(), $dest, 1);
        $grd2 = $this->emitir($grd2);

        $this->assertSame(2, $grd2->numero);
        $dist1 = $dist1->fresh();
        $this->assertSame(1, $dist1->quantidadePendente());
        $this->assertSame(0, $dist1->quantidadeRecolhida());

        $candidatosApos = (new CandidatosNovaEntregaGrd())->porObra($this->obra);
        $this->assertCount(0, $candidatosApos);
    }

    // ===================== AE-AK: recolhimento =====================

    public function test_ae_af_ag_ah_ciclo_de_recolhimento_parcial_total_e_nao_localizado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 3);
        $this->emitir($grd);

        $this->recolher($dist->fresh(), ResultadoRecolhimento::Recolhido, 1);
        $atual = $dist->fresh();
        $this->assertSame(2, $atual->quantidadePendente());
        $this->assertSame('pendente', $atual->estado());

        $this->recolher($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 1);
        $atual = $dist->fresh();
        $this->assertSame(2, $atual->quantidadePendente(), 'nao_localizado nunca reduz pendencia');
        $this->assertSame('nao_localizado', $atual->estado());

        $this->recolher($dist->fresh(), ResultadoRecolhimento::Recolhido, 2);
        $atual = $dist->fresh();
        $this->assertSame(0, $atual->quantidadePendente());
        $this->assertSame('recolhido', $atual->estado());

        $this->assertSame(3, GrdRecolhimento::where('grd_distribuicao_id', $dist->id)->count());
    }

    public function test_ai_excesso_de_recolhimento_rejeitado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 2);
        $this->emitir($grd);

        $this->expectException(GrdRecolhimentoInvalidoException::class);
        $this->recolher($dist->fresh(), ResultadoRecolhimento::Recolhido, 3);
    }

    public function test_aj_recolhimento_em_grd_rascunho_rejeitado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);

        $this->expectException(GrdRecolhimentoInvalidoException::class);
        $this->recolher($dist, ResultadoRecolhimento::Recolhido, 1);
    }

    public function test_ak_dois_destinatarios_da_mesma_grd_tem_estados_independentes(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $destA = $this->destinatario();
        $destB = $this->destinatario();

        $grd = $this->criarGrd();
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $r1->fresh());
        $gdA = $acoes->adicionarDestinatario($grd, $destA);
        $gdB = $acoes->adicionarDestinatario($grd, $destB);
        $distA = $acoes->marcarDistribuicao($grd, $item, $gdA, 1);
        $distB = $acoes->marcarDistribuicao($grd, $item, $gdB, 1);
        $this->emitir($grd);

        $this->recolher($distA->fresh(), ResultadoRecolhimento::Recolhido, 1);

        $this->assertSame('recolhido', $distA->fresh()->estado());
        $this->assertSame('pendente', $distB->fresh()->estado());
    }

    // ===================== AL-AM: isolamento nas queries =====================

    public function test_al_detector_obsoletas_nunca_mistura_obras(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cenarioObsoleto($this->obra);
        $this->cenarioObsoleto($obraB);

        $resultadoA = (new DetectorCopiasObsoletasGrd())->porObra($this->obra);
        $resultadoB = (new DetectorCopiasObsoletasGrd())->porObra($obraB);

        $this->assertCount(1, $resultadoA);
        $this->assertCount(1, $resultadoB);
        $this->assertSame($this->obra->id, $resultadoA->first()->grd->obra_id);
        $this->assertSame($obraB->id, $resultadoB->first()->grd->obra_id);
    }

    public function test_am_candidatos_nunca_atravessam_tenant(): void
    {
        $this->cenarioObsoleto($this->obra);

        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutro = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $userOutro = User::factory()->create(['tenant_id' => $outroTenant->id]);
            $docO = DocumentoEngenharia::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'codigo' => 'DOC-O', 'descricao' => 'x']);
            $r1o = $docO->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
            (new AlterarLiberacaoRevisaoDocumento())->liberar($r1o->fresh(), $userOutro);
            $destO = Destinatario::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'nome' => 'Dest O']);
            $grdO = (new CriarGrd())->execute($obraOutro, $userOutro);
            $acoesO = new AtualizarRascunhoGrd();
            $itemO = $acoesO->adicionarItem($grdO, $r1o->fresh());
            $gdO = $acoesO->adicionarDestinatario($grdO, $destO);
            $acoesO->marcarDistribuicao($grdO, $itemO, $gdO, 1);
            (new EmitirGrd())->execute($grdO, $userOutro);
            $r2o = $docO->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R2', 'descricao' => 'x']);
            (new AlterarLiberacaoRevisaoDocumento())->liberar($r2o->fresh(), $userOutro);
        });

        $candidatosA = (new CandidatosNovaEntregaGrd())->porObra($this->obra);
        $this->assertCount(1, $candidatosA);
    }

    // ===================== AN-AP: soft-delete / forceDelete =====================

    public function test_an_ao_soft_delete_destinatario_preserva_historico_forcedelete_bloqueado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario(['nome' => 'Vai Ser Desativado']);
        ['grd' => $grd, 'grdDest' => $grdDest] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $this->emitir($grd);

        $dest->delete();
        $this->assertSoftDeleted('destinatarios', ['id' => $dest->id]);
        $this->assertSame('Vai Ser Desativado', $grdDest->fresh()->nome_snapshot);

        $this->expectException(QueryException::class);
        $dest->forceDelete();
    }

    public function test_ap_forcedelete_revisao_referenciada_bloqueado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);

        $this->expectException(QueryException::class);
        $r1->fresh()->delete();
    }

    // ===================== AQ-AS: independência de prontidão/avanço/Restrição/Inconsistência =====================

    public function test_aq_distribuicao_fisica_nunca_afeta_prontidao_da_atividade(): void
    {
        $at = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'fora_do_cronograma' => false]);
        $doc = $this->doc();
        $r1 = $this->rev($doc);

        DB::table('documento_engenharia_atividades')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $this->tenant->id,
            'documento_engenharia_id' => $doc->id, 'atividade_id' => $at->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse($at->fresh()->estaPronta(), 'documento não liberado bloqueia — regra da 18.4, intocada');

        $this->liberar($r1->fresh());
        $this->assertTrue($at->fresh()->estaPronta(), 'liberação (18.4) já resolve prontidão, independente de qualquer GRD');

        $dest = $this->destinatario();
        ['grd' => $grd, 'distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $this->emitir($grd);
        $this->assertTrue($at->fresh()->estaPronta(), 'emitir uma GRD nunca muda prontidão');

        $this->recolher($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 1);
        $this->assertTrue($at->fresh()->estaPronta(), 'recolhimento (inclusive nao_localizado) nunca muda prontidão');
    }

    public function test_ar_as_grd_nunca_cria_restricao_ou_inconsistencia_avanco(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 2);
        $this->emitir($grd);

        $this->recolher($dist->fresh(), ResultadoRecolhimento::Recolhido, 1);
        $this->recolher($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 1);
        $this->recolher($dist->fresh(), ResultadoRecolhimento::Recolhido, 1);

        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());

        (new DetectorCopiasObsoletasGrd())->porObra($this->obra);
        (new CandidatosNovaEntregaGrd())->porObra($this->obra);

        $this->assertSame(0, Restricao::count());
        $this->assertSame(0, InconsistenciaAvanco::count());
    }

    // ===================== AT-AU: performance (N+1) =====================

    public function test_at_au_queries_de_obsoletas_e_candidatos_nao_crescem_com_n(): void
    {
        $obra5 = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $obra30 = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $obra100 = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $cenarios = ['n5' => [$obra5, 5], 'n30' => [$obra30, 30], 'n100' => [$obra100, 100]];

        $contagensObsoletas = [];
        $contagensCandidatos = [];

        foreach ($cenarios as $chave => [$obra, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $this->cenarioObsoleto($obra);
            }

            DB::enableQueryLog();
            DB::flushQueryLog();
            $obsoletas = (new DetectorCopiasObsoletasGrd())->porObra($obra);
            $contagensObsoletas[$chave] = count(DB::getQueryLog());
            $this->assertCount($n, $obsoletas);

            DB::flushQueryLog();
            $candidatos = (new CandidatosNovaEntregaGrd())->porObra($obra);
            $contagensCandidatos[$chave] = count(DB::getQueryLog());
            $this->assertCount($n, $candidatos);
            DB::disableQueryLog();
        }

        $this->assertSame($contagensObsoletas['n5'], $contagensObsoletas['n30'], 'contagem de queries de obsoletas deve ser constante');
        $this->assertSame($contagensObsoletas['n5'], $contagensObsoletas['n100'], 'contagem de queries de obsoletas deve ser constante');
        $this->assertSame($contagensCandidatos['n5'], $contagensCandidatos['n30'], 'contagem de queries de candidatos deve ser constante');
        $this->assertSame($contagensCandidatos['n5'], $contagensCandidatos['n100'], 'contagem de queries de candidatos deve ser constante');
    }

    // ===================== AV-AX: rollback / concorrência / idempotência =====================

    public function test_av_emissao_falha_no_meio_nao_deixa_estado_parcial(): void
    {
        $doc1 = $this->doc();
        $r1 = $this->rev($doc1);
        $this->liberar($r1->fresh());
        $doc2 = $this->doc();
        $r2 = $this->rev($doc2); // NÃO liberada
        $dest = $this->destinatario();

        $grd = $this->criarGrd();
        $acoes = new AtualizarRascunhoGrd();
        $item1 = $acoes->adicionarItem($grd, $r1->fresh());
        $item2 = $acoes->adicionarItem($grd, $r2->fresh());
        $grdDest = $acoes->adicionarDestinatario($grd, $dest);
        $acoes->marcarDistribuicao($grd, $item1, $grdDest, 1);
        $acoes->marcarDistribuicao($grd, $item2, $grdDest, 1);

        try {
            $this->emitir($grd);
            $this->fail('Esperava GrdEmissaoInvalidaException.');
        } catch (GrdEmissaoInvalidaException $e) {
            // esperado
        }

        $grd = $grd->fresh();
        $this->assertTrue($grd->estaRascunho());
        $this->assertNull($grd->numero);
        $this->assertNull($item1->fresh()->codigo_documento_snapshot);
        $this->assertNull($grdDest->fresh()->nome_snapshot);
    }

    public function test_aw_unique_constraint_protege_numeracao_duplicada_a_nivel_de_banco(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $emitida = $this->emitir($grd);

        $this->assertSame(1, $emitida->numero);

        $this->expectException(QueryException::class);
        Grd::create(['obra_id' => $this->obra->id, 'numero' => 1, 'status' => 'rascunho']);
    }

    public function test_ax_adicionar_item_duas_vezes_e_idempotente(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $grd = $this->criarGrd();
        $acoes = new AtualizarRascunhoGrd();

        $item1 = $acoes->adicionarItem($grd, $r1->fresh());
        $item2 = $acoes->adicionarItem($grd, $r1->fresh());

        $this->assertSame($item1->id, $item2->id);
        $this->assertSame(1, GrdItem::where('grd_id', $grd->id)->count());
    }

    public function test_ax_emitir_grd_ja_emitida_lanca_imutavel_sem_reincrementar_numero(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $grd = $this->emitir($grd);
        $numeroOriginal = $grd->numero;

        try {
            $this->emitir($grd);
            $this->fail('Esperava GrdImutavelException.');
        } catch (GrdImutavelException $e) {
            // esperado
        }

        $this->assertSame($numeroOriginal, $grd->fresh()->numero);
    }

    // ===================== AY: cenário crítico completo =====================

    public function test_ay_cenario_critico_completo_r1_r2_recolhimento_e_nao_localizado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'João']);

        ['grd' => $grd1, 'distribuicao' => $dist1] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $joao, 2);
        $grd1 = $this->emitir($grd1);
        $dist1 = $dist1->fresh();

        $this->assertSame(1, $grd1->numero);
        $this->assertSame(2, $dist1->quantidadeEntregue());
        $this->assertSame(0, $dist1->quantidadeRecolhida());
        $this->assertSame(2, $dist1->quantidadePendente());

        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());

        $obsoletas = (new DetectorCopiasObsoletasGrd())->porObra($this->obra);
        $this->assertCount(1, $obsoletas);
        $this->assertSame(2, $obsoletas->first()->quantidade_pendente);
        $this->assertTrue($obsoletas->first()->revisao_entregue->is($r1->fresh()));

        $candidatos = (new CandidatosNovaEntregaGrd())->porObra($this->obra);
        $this->assertCount(1, $candidatos);
        $this->assertTrue($candidatos->first()->destinatario->is($joao));

        $this->recolher($dist1->fresh(), ResultadoRecolhimento::Recolhido, 1);
        $dist1 = $dist1->fresh();
        $this->assertSame(1, $dist1->quantidadePendente());
        $this->assertSame('pendente', $dist1->estado());

        $this->recolher($dist1->fresh(), ResultadoRecolhimento::NaoLocalizado, 1);
        $dist1 = $dist1->fresh();
        $this->assertSame(1, $dist1->quantidadePendente());
        $this->assertSame('nao_localizado', $dist1->estado());

        ['grd' => $grd2] = $this->montarGrdComItemDestinatarioDistribuicao($r2->fresh(), $joao, 1);
        $grd2 = $this->emitir($grd2);
        $this->assertSame(2, $grd2->numero);

        $dist1 = $dist1->fresh();
        $this->assertSame(1, $dist1->quantidadePendente(), 'entregar R2 nunca recolhe R1');

        $candidatosApos = (new CandidatosNovaEntregaGrd())->porObra($this->obra);
        $this->assertCount(0, $candidatosApos, 'João já recebeu a vigente — deixa de ser candidato');

        $this->recolher($dist1->fresh(), ResultadoRecolhimento::Recolhido, 1);
        $dist1 = $dist1->fresh();
        $this->assertSame(0, $dist1->quantidadePendente());
        $this->assertSame('recolhido', $dist1->estado());

        $obsoletasFinal = (new DetectorCopiasObsoletasGrd())->porObra($this->obra);
        $this->assertCount(0, $obsoletasFinal);

        $this->assertSame(3, GrdRecolhimento::where('grd_distribuicao_id', $dist1->id)->count(), 'histórico completo preservado: recolhido parcial + nao_localizado + recolhido final');
    }

    // ===================== 18.5.1.HARDENING — B1: GRD Emitida imutável contra delete =====================

    public function test_hardening_a_rascunho_pode_ser_soft_deletado(): void
    {
        $grd = $this->criarGrd();

        $grd->delete();

        $this->assertSoftDeleted('grds', ['id' => $grd->id]);
    }

    public function test_hardening_b_emitida_nao_pode_ser_soft_deletada(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $grd = $this->emitir($grd);

        $this->expectException(GrdImutavelException::class);
        $grd->delete();
    }

    public function test_hardening_c_emitida_nao_pode_ser_force_deletada(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $grd = $this->emitir($grd);

        $this->expectException(GrdImutavelException::class);
        $grd->forceDelete();
    }

    public function test_hardening_d_e_tentativa_de_delete_preserva_tudo_intacto(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'item' => $item, 'grdDest' => $grdDest, 'distribuicao' => $dist] =
            $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 2);
        $grd = $this->emitir($grd);
        $this->recolher($dist->fresh(), ResultadoRecolhimento::Recolhido, 1);

        $numeroOriginal = $grd->numero;
        $statusOriginal = $grd->status;
        $emitidaEmOriginal = $grd->emitida_em;
        $snapshotItemOriginal = $item->fresh()->codigo_documento_snapshot;
        $snapshotDestOriginal = $grdDest->fresh()->nome_snapshot;

        foreach ([fn () => $grd->delete(), fn () => $grd->forceDelete()] as $tentativa) {
            try {
                $tentativa();
            } catch (GrdImutavelException $e) {
                // esperado
            }
        }

        $grd = $grd->fresh();
        $this->assertNotNull($grd, '[D] GRD precisa continuar existindo (mesma PK)');
        $this->assertSame($numeroOriginal, $grd->numero);
        $this->assertEquals($statusOriginal, $grd->status);
        $this->assertEquals($emitidaEmOriginal, $grd->emitida_em);
        $this->assertNotNull(GrdItem::find($item->id), '[D] item precisa continuar existindo');
        $this->assertNotNull(GrdDestinatario::find($grdDest->id), '[D] destinatário precisa continuar existindo');
        $this->assertNotNull(GrdDistribuicao::find($dist->id), '[D] distribuição precisa continuar existindo');
        $this->assertSame($snapshotItemOriginal, $item->fresh()->codigo_documento_snapshot, '[D] snapshot do item intacto');
        $this->assertSame($snapshotDestOriginal, $grdDest->fresh()->nome_snapshot, '[D] snapshot do destinatário intacto');
        $this->assertSame(1, GrdRecolhimento::where('grd_distribuicao_id', $dist->id)->count(), '[E] recolhimento já registrado não é apagado');
    }

    // ===================== 18.5.1.HARDENING — B2: NaoLocalizado limitado à quantidade pendente =====================

    public function test_hardening_h_nao_localizado_maior_que_entregue_rejeitado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 1);
        $this->emitir($grd);

        $this->expectException(GrdRecolhimentoInvalidoException::class);
        try {
            $this->recolher($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 100);
        } finally {
            $this->assertSame(0, GrdRecolhimento::where('grd_distribuicao_id', $dist->id)->count(), '[P] nenhum evento parcial deve ser criado');
        }
    }

    public function test_hardening_i_j_nao_localizado_ate_pendente_permite_acima_rejeita(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 3);
        $this->emitir($grd);
        $this->recolher($dist->fresh(), ResultadoRecolhimento::Recolhido, 1); // pendente = 2

        // I: exatamente o pendente é permitido
        $this->recolher($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 2);
        $this->assertSame(2, $dist->fresh()->quantidadePendente(), '[L] nao_localizado nunca altera pendente');
        $this->assertSame(1, $dist->fresh()->quantidadeRecolhida(), '[L] nao_localizado nunca altera recolhida');

        // J: acima do pendente é rejeitado
        $this->expectException(GrdRecolhimentoInvalidoException::class);
        $this->recolher($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 3);
    }

    public function test_hardening_k_multiplas_tentativas_nao_localizado_mesma_quantidade_sao_preservadas(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 2);
        $this->emitir($grd);

        $this->recolher($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 2, null, Carbon::parse('2026-08-10'));
        $this->recolher($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 2, null, Carbon::parse('2026-08-12'));

        $this->assertSame(2, $dist->fresh()->quantidadePendente(), 'pendente continua 2 — nao_localizado nunca consome');
        $this->assertSame(2, GrdRecolhimento::where('grd_distribuicao_id', $dist->id)->count(), 'as DUAS tentativas ficam preservadas, sem sobrescrever uma a outra');
    }

    public function test_hardening_m_n_recolhido_funciona_normalmente_apos_nao_localizado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 2);
        $this->emitir($grd);

        $this->recolher($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 2);
        $this->recolher($dist->fresh(), ResultadoRecolhimento::Recolhido, 1);

        $atual = $dist->fresh();
        $this->assertSame(1, $atual->quantidadeRecolhida());
        $this->assertSame(1, $atual->quantidadePendente(), '[N] pendente cai corretamente apos o Recolhido');
    }

    public function test_hardening_o_nao_localizado_com_pendente_zero_rejeitado(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 1);
        $this->emitir($grd);
        $this->recolher($dist->fresh(), ResultadoRecolhimento::Recolhido, 1); // pendente = 0

        $this->expectException(GrdRecolhimentoInvalidoException::class);
        $this->recolher($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 1);
    }

    // ===================== 18.5.1.HARDENING — D1: forceDelete do Documento pai =====================

    public function test_hardening_d1_forcedelete_documento_pai_bloqueado_quando_revisao_distribuida(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'item' => $item] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $this->emitir($grd);

        try {
            $doc->forceDelete();
            $this->fail('Esperava QueryException — Documento com revisão distribuída não pode ser forceDeleted.');
        } catch (QueryException $e) {
            // esperado
        }

        $this->assertNotNull(DocumentoEngenharia::withTrashed()->find($doc->id), 'Documento continua existindo');
        $this->assertNotNull(DocumentoEngenhariaRevisao::find($r1->id), 'Revisão continua existindo');
        $this->assertNotNull(GrdItem::find($item->id), 'GrdItem continua existindo');
        $this->assertNotNull(Grd::find($grd->id), 'GRD continua existindo');
        $this->assertSame(1, GrdDistribuicao::where('grd_item_id', $item->id)->count(), 'distribuição continua existindo');
    }

    // ===================== 18.5.1.HARDENING — D2: Destinatario soft-deleted, semântica explícita =====================

    public function test_hardening_d2_destinatario_soft_deleted_permanece_em_obsoletas_mas_some_dos_candidatos(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $dest = $this->destinatario(['nome' => 'Sera Desativado']);
        ['grd' => $grd] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest);
        $this->emitir($grd);

        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());

        $obsoletasAntes = (new DetectorCopiasObsoletasGrd())->porObra($this->obra);
        $candidatosAntes = (new CandidatosNovaEntregaGrd())->porObra($this->obra);
        $this->assertCount(1, $obsoletasAntes);
        $this->assertCount(1, $candidatosAntes);

        $dest->delete();

        $obsoletasDepois = (new DetectorCopiasObsoletasGrd())->porObra($this->obra);
        $candidatosDepois = (new CandidatosNovaEntregaGrd())->porObra($this->obra);

        $this->assertCount(1, $obsoletasDepois, 'cópia obsoleta CONTINUA aparecendo mesmo com destinatário soft-deletado');
        $this->assertSame('Sera Desativado', $obsoletasDepois->first()->grd_destinatario->nome_snapshot);
        $this->assertCount(0, $candidatosDepois, 'destinatário soft-deletado NÃO aparece como candidato operacional');
    }

    // ===================== 18.5.1.HARDENING — ordem temporal congelada =====================

    public function test_hardening_ordem_do_ultimo_evento_usa_created_at_nunca_ocorrido_em(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $dest = $this->destinatario();
        ['grd' => $grd, 'distribuicao' => $dist] = $this->montarGrdComItemDestinatarioDistribuicao($r1->fresh(), $dest, 2);
        $this->emitir($grd);

        // Evento A: registrado primeiro (created_at menor), mas ocorrido_em MAIS RECENTE (20/08)
        $this->recolher($dist->fresh(), ResultadoRecolhimento::NaoLocalizado, 1, null, Carbon::parse('2026-08-20'));
        // Evento B: registrado depois (created_at maior), mas ocorrido_em MAIS ANTIGO (18/08) que o de A
        $this->recolher($dist->fresh(), ResultadoRecolhimento::Recolhido, 1, null, Carbon::parse('2026-08-18'));

        // Por ocorrido_em, A (nao_localizado, 20/08) seria o "mais recente" — mas a regra congelada
        // usa created_at (ordem de registro), entao B (Recolhido, registrado por ultimo) e o que conta.
        $this->assertSame('pendente', $dist->fresh()->estado(), 'estado deve seguir a ORDEM DE REGISTRO (created_at), nunca ocorrido_em');
    }
}
