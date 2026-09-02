<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Actions\Engenharia\AtualizarRascunhoGrd;
use App\Actions\Engenharia\CriarGrd;
use App\Actions\Engenharia\EmitirGrd;
use App\Actions\Engenharia\RegistrarAceiteEntrega;
use App\Actions\Engenharia\RegistrarRecolhimento;
use App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao;
use App\Actions\Estoque\CriarOrdemIndustrializacao;
use App\Actions\Estoque\EmitirOrdemIndustrializacao;
use App\Enums\EstadoProntidaoEngenharia;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\ResultadoRecolhimento;
use App\Enums\StatusAtividade;
use App\Enums\TipoAceiteGrd;
use App\Enums\TipoLocalEstoque;
use App\Models\Atividade;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Engenharia\InteligenciaEngenhariaQuery;
use App\Support\Engenharia\ProntidaoDocumentalAtividadeQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 22, Etapa 22.1 — camada gerencial de Engenharia. Cobre os
 * cenários A-P do pedido + performance + consistência com
 * `Atividade::scopeProntas()`/`estaPronta()`.
 */
class InteligenciaEngenhariaQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function at(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'obra_id' => ($obra ?? $this->obra)->id,
            'fora_do_cronograma' => false, 'status' => StatusAtividade::Planejado,
            'inicio_planejado' => now()->addDays(2),
        ], $overrides));
    }

    private function doc(?Work $obra = null, array $overrides = []): DocumentoEngenharia
    {
        return DocumentoEngenharia::create(array_merge([
            'tenant_id' => $this->tenant->id, 'obra_id' => ($obra ?? $this->obra)->id,
            'codigo' => 'DOC-' . uniqid(), 'descricao' => 'x',
        ], $overrides));
    }

    private function rev(DocumentoEngenharia $d, string $texto = 'R1'): DocumentoEngenhariaRevisao
    {
        return $d->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => $texto, 'descricao' => 'x'])->fresh();
    }

    private function liberar(DocumentoEngenhariaRevisao $r): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->liberar($r, $this->user);
    }

    private function vincular(Atividade $at, DocumentoEngenharia $doc): void
    {
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);
    }

    private function destinatario(?Work $obra = null): Destinatario
    {
        return Destinatario::create(['tenant_id' => $this->tenant->id, 'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Dest ' . uniqid()]);
    }

    /** @return array{grd: \App\Models\Grd, item: \App\Models\GrdItem, gd: \App\Models\GrdDestinatario, dist: \App\Models\GrdDistribuicao} */
    private function grdEmitidaComDistribuicao(DocumentoEngenhariaRevisao $revisao, ?Destinatario $dest = null, int $quantidade = 2): array
    {
        $dest ??= $this->destinatario();
        $grd = (new CriarGrd())->execute($this->obra, $this->user);
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $revisao->fresh());
        $gd = $acoes->adicionarDestinatario($grd, $dest);
        $dist = $acoes->marcarDistribuicao($grd, $item, $gd, $quantidade);
        $grd = (new EmitirGrd())->execute($grd, $this->user);

        return compact('grd', 'item', 'gd', 'dist');
    }

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarFornecedor(): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
    }

    private function criarLocalTerceiro(Fornecedor $fornecedor): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => $this->obra->id, 'nome' => 'Terceiro ' . uniqid(),
            'tipo' => TipoLocalEstoque::Terceiro->value, 'fornecedor_id' => $fornecedor->id, 'ativo' => true,
        ]);
    }

    private function linhaDaAtividade(Atividade $at, int $horizonte = 28)
    {
        return ProntidaoDocumentalAtividadeQuery::porObra($this->obra, $horizonte)
            ->first(fn ($l) => $l->atividadeId === $at->id);
    }

    // =========================================================================
    // A-G — Prontidão documental por Atividade
    // =========================================================================

    public function test_a_atividade_amanha_documento_nao_liberado_e_bloqueada(): void
    {
        $at = $this->at(['inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $this->rev($doc, 'R1');
        $this->vincular($at, $doc);

        $linha = $this->linhaDaAtividade($at);

        $this->assertSame(EstadoProntidaoEngenharia::Bloqueada, $linha->estado);
        $this->assertCount(1, $linha->documentosBloqueantes());
        $this->assertFalse($at->fresh()->estaPronta());
    }

    public function test_b_mesmo_cenario_com_documento_liberado_nao_bloqueia(): void
    {
        $at = $this->at(['inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $r = $this->rev($doc, 'R1');
        $this->liberar($r);
        $this->vincular($at, $doc);

        $linha = $this->linhaDaAtividade($at);

        $this->assertSame(EstadoProntidaoEngenharia::Liberada, $linha->estado);
        $this->assertCount(0, $linha->documentosBloqueantes());
        $this->assertTrue($at->fresh()->estaPronta());
    }

    public function test_c_atividade_com_tres_documentos_um_pendente_e_parcial(): void
    {
        $at = $this->at();
        foreach (['R1', 'R2', 'R3'] as $i => $texto) {
            $doc = $this->doc();
            $r = $this->rev($doc, $texto);
            if ($i < 2) {
                $this->liberar($r);
            }
            $this->vincular($at, $doc);
        }

        $linha = $this->linhaDaAtividade($at);

        $this->assertSame(EstadoProntidaoEngenharia::Parcial, $linha->estado);
        $this->assertSame(3, $linha->totalDocumentos());
        $this->assertSame(2, $linha->totalLiberados());
        $this->assertCount(1, $linha->documentosBloqueantes());
        $this->assertFalse($at->fresh()->estaPronta(), 'scopeProntas() nunca classifica parcial como pronta.');
    }

    public function test_d_atividade_fora_do_horizonte_nao_aparece(): void
    {
        $at = $this->at(['inicio_planejado' => now()->addDays(60)]);
        $doc = $this->doc();
        $this->rev($doc, 'R1');
        $this->vincular($at, $doc);

        $this->assertNull($this->linhaDaAtividade($at, 28));
        $this->assertNotNull(ProntidaoDocumentalAtividadeQuery::porObra($this->obra, 90)->first(fn ($l) => $l->atividadeId === $at->id));
    }

    public function test_e_atividade_concluida_nunca_gera_falsa_urgencia(): void
    {
        $at = $this->at(['status' => StatusAtividade::Concluido, 'inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $this->rev($doc, 'R1');
        $this->vincular($at, $doc);

        $this->assertNull($this->linhaDaAtividade($at));
    }

    public function test_f_atividade_fora_do_cronograma_nunca_contamina(): void
    {
        $at = $this->at(['fora_do_cronograma' => true, 'inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $this->rev($doc, 'R1');
        $this->vincular($at, $doc);

        $this->assertNull($this->linhaDaAtividade($at));
    }

    public function test_g_documento_sem_atividade_e_pendencia_documental_nunca_risco_operacional(): void
    {
        $doc = $this->doc();
        $this->rev($doc, 'R1'); // nunca liberada, zero atividade/pacote vinculado

        $inteligencia = InteligenciaEngenhariaQuery::porObra($this->obra, 28);

        $this->assertSame(0, $inteligencia->prontidaoDocumental->count());
        $this->assertSame(0, $inteligencia->totalFatosAcionaveis());
    }

    // =========================================================================
    // H — revisão substituída
    // =========================================================================

    public function test_h_r1_liberada_r2_criada_bloqueia_conforme_dominio_real(): void
    {
        $at = $this->at(['inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1);
        $this->vincular($at, $doc);

        $this->assertSame(EstadoProntidaoEngenharia::Liberada, $this->linhaDaAtividade($at)->estado);

        // R2 nasce DEPOIS de R1 (data_emissao/created_at mais recente) —
        // vira a vigente automaticamente (scopeVigentes()), sem liberação própria.
        $r2 = $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x', 'data_emissao' => now()])->fresh();

        $linha = $this->linhaDaAtividade($at);
        $this->assertSame(EstadoProntidaoEngenharia::Bloqueada, $linha->estado);
        $this->assertSame($r2->id, $linha->documentos->first()->revisaoId);
        $this->assertFalse($at->fresh()->estaPronta(), 'A liberação de R1 nunca controla o Documento depois de R2 existir.');
    }

    // =========================================================================
    // I-J — GRD aguardando aceite / aceita
    // =========================================================================

    public function test_i_grd_aguardando_aceite_e_fato_gerencial_correto(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc, 'R1');
        $this->liberar($r);
        ['gd' => $gd, 'grd' => $grd] = $this->grdEmitidaComDistribuicao($r);

        $fatos = InteligenciaEngenhariaQuery::porObra($this->obra, 28)->grdAguardandoAceite;

        $this->assertCount(1, $fatos);
        $this->assertSame($gd->id, $fatos->first()->entidadeId);
        $this->assertSame($grd->id, $fatos->first()->contexto['grd_id']);
    }

    public function test_j_grd_aceita_resolve_o_fato_correspondente(): void
    {
        $doc = $this->doc();
        $r = $this->rev($doc, 'R1');
        $this->liberar($r);
        ['gd' => $gd] = $this->grdEmitidaComDistribuicao($r);

        $this->assertCount(1, InteligenciaEngenhariaQuery::porObra($this->obra, 28)->grdAguardandoAceite);

        (new RegistrarAceiteEntrega())->execute($gd->fresh(), TipoAceiteGrd::SemAssinatura, 'Recebedor', $this->user);

        $this->assertCount(0, InteligenciaEngenhariaQuery::porObra($this->obra, 28)->grdAguardandoAceite);
    }

    // =========================================================================
    // K-L — cópia obsoleta / recolhimento concluído
    // =========================================================================

    public function test_k_copia_obsoleta_aguardando_recolhimento(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1);
        ['dist' => $dist] = $this->grdEmitidaComDistribuicao($r1, quantidade: 3);

        // R2 nasce depois — a cópia de R1 já entregue vira obsoleta.
        $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x', 'data_emissao' => now()]);

        $fatos = InteligenciaEngenhariaQuery::porObra($this->obra, 28)->copiasObsoletasPendentes;

        $this->assertCount(1, $fatos);
        $this->assertSame($dist->id, $fatos->first()->entidadeId);
        $this->assertSame(3, $fatos->first()->contexto['quantidade_pendente']);
    }

    public function test_l_recolhimento_concluido_desaparece_da_lista(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1);
        ['dist' => $dist] = $this->grdEmitidaComDistribuicao($r1, quantidade: 3);
        $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x', 'data_emissao' => now()]);

        $this->assertCount(1, InteligenciaEngenhariaQuery::porObra($this->obra, 28)->copiasObsoletasPendentes);

        (new RegistrarRecolhimento())->execute($dist->fresh(), ResultadoRecolhimento::Recolhido, 3, $this->user);

        $this->assertCount(0, InteligenciaEngenhariaQuery::porObra($this->obra, 28)->copiasObsoletasPendentes);
    }

    // =========================================================================
    // M — industrialização congelada em revisão anterior
    // =========================================================================

    public function test_m_industrializacao_congelada_em_revisao_anterior_expoe_fato_sem_acusacao(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc, 'R1');

        $fornecedor = $this->criarFornecedor();
        $local = $this->criarLocalTerceiro($fornecedor);
        $material = $this->criarMaterial();
        $ordem = (new CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $local, $this->user);
        (new AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $material, 10, $this->user, $r1);
        (new EmitirOrdemIndustrializacao())->execute($ordem, $this->user);

        // Nasce R2 — a revisão vigente do documento muda, o Produto continua congelado em R1.
        $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x', 'data_emissao' => now()]);

        $fatos = InteligenciaEngenhariaQuery::porObra($this->obra, 28)->industrializacaoComMudancaRevisao;

        $this->assertCount(1, $fatos);
        $descricao = mb_strtolower($fatos->first()->descricao);
        $this->assertStringNotContainsString('inválid', $descricao);
        $this->assertStringNotContainsString('erro', $descricao);
        $this->assertSame('R1', $fatos->first()->contexto['revisao_congelada']);
        $this->assertSame('R2', $fatos->first()->contexto['revisao_vigente']);
    }

    // =========================================================================
    // N-O — Multi-obra / cross-tenant
    // =========================================================================

    public function test_n_multiobra_isolamento(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $atB = $this->at(['inicio_planejado' => now()->addDay()], $obraB);
        $docB = $this->doc($obraB);
        $this->rev($docB, 'R1');
        $atB->documentosEngenharia()->attach($docB->id, ['tenant_id' => $this->tenant->id]);

        $at = $this->at(['inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $r = $this->rev($doc, 'R1');
        $this->liberar($r);
        $this->vincular($at, $doc);

        $resultado = InteligenciaEngenhariaQuery::porObra($this->obra, 28);

        $this->assertCount(1, $resultado->prontidaoDocumental);
        $this->assertSame($at->id, $resultado->prontidaoDocumental->first()->atividadeId);
    }

    public function test_o_cross_tenant_nunca_vaza(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);

        \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant, $outroUser) {
            $obraOutro = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $atOutro = Atividade::factory()->create([
                'tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'fora_do_cronograma' => false,
                'status' => StatusAtividade::Planejado, 'inicio_planejado' => now()->addDay(),
            ]);
            $docOutro = DocumentoEngenharia::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutro->id, 'codigo' => 'DOC-OUTRO', 'descricao' => 'x']);
            $docOutro->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
            $atOutro->documentosEngenharia()->attach($docOutro->id, ['tenant_id' => $outroTenant->id]);
        });

        $at = $this->at(['inicio_planejado' => now()->addDay()]);
        $doc = $this->doc();
        $r = $this->rev($doc, 'R1');
        $this->liberar($r);
        $this->vincular($at, $doc);

        $resultado = InteligenciaEngenhariaQuery::porObra($this->obra, 28);

        $this->assertCount(1, $resultado->prontidaoDocumental);
        $this->assertSame($at->id, $resultado->prontidaoDocumental->first()->atividadeId);
    }

    // =========================================================================
    // P — informação insuficiente
    // =========================================================================

    public function test_p_atividade_sem_nenhum_documento_e_informacao_insuficiente_nunca_liberada(): void
    {
        $at = $this->at(['inicio_planejado' => now()->addDay()]);

        $linha = $this->linhaDaAtividade($at);

        $this->assertSame(EstadoProntidaoEngenharia::InformacaoInsuficiente, $linha->estado);
        $this->assertNotSame(EstadoProntidaoEngenharia::Liberada, $linha->estado);
    }

    // =========================================================================
    // Engenharia × Suprimentos (Seção 18)
    // =========================================================================

    public function test_suprimento_bloqueado_por_documento_via_pivo_deterministico(): void
    {
        $doc = $this->doc();
        $this->rev($doc, 'R1'); // nunca liberada

        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote X']);
        $pacote->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $fatos = InteligenciaEngenhariaQuery::porObra($this->obra, 28)->suprimentoBloqueadoPorDocumento;

        $this->assertCount(1, $fatos);
        $this->assertSame($pacote->id, $fatos->first()->entidadeId);
    }

    // =========================================================================
    // Consistência com Central de Prontidão / scopeProntas()
    // =========================================================================

    public function test_consistencia_bloqueio_bate_com_scopeprontas(): void
    {
        $atBloqueada = $this->at(['inicio_planejado' => now()->addDay()]);
        $docNaoLiberado = $this->doc();
        $this->rev($docNaoLiberado, 'R1');
        $this->vincular($atBloqueada, $docNaoLiberado);

        $atLiberada = $this->at(['inicio_planejado' => now()->addDay()]);
        $docLiberado = $this->doc();
        $r = $this->rev($docLiberado, 'R1');
        $this->liberar($r);
        $this->vincular($atLiberada, $docLiberado);

        $linhas = ProntidaoDocumentalAtividadeQuery::porObra($this->obra, 28);

        foreach ($linhas as $linha) {
            $atividade = Atividade::findOrFail($linha->atividadeId);
            $estaPronta = $atividade->estaPronta();
            $bloqueadaOuParcial = in_array($linha->estado, [EstadoProntidaoEngenharia::Bloqueada, EstadoProntidaoEngenharia::Parcial], true);

            $this->assertSame(! $bloqueadaOuParcial, $estaPronta, "Atividade {$linha->atividadeId}: nova camada e scopeProntas() precisam convergir.");
        }
    }

    // =========================================================================
    // Performance (Seção 24)
    // =========================================================================

    public function test_performance_delta_10_100_atividades_sem_n_mais_1(): void
    {
        $medir = function (int $n): int {
            $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
            for ($i = 0; $i < $n; $i++) {
                $at = $this->at(['inicio_planejado' => now()->addDay()], $obra);
                $doc = $this->doc($obra);
                $r = $this->rev($doc, 'R1');
                if ($i % 2 === 0) {
                    $this->liberar($r);
                }
                $this->vincular($at, $doc);
            }

            DB::enableQueryLog();
            InteligenciaEngenhariaQuery::porObra($obra, 28);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            DB::flushQueryLog();

            return $count;
        };

        $q10 = $medir(10);
        $q100 = $medir(100);

        fwrite(STDERR, "\n[DELTA InteligenciaEngenhariaQuery] 10 atividades -> {$q10} queries | 100 atividades -> {$q100} queries\n");

        $this->assertLessThan($q10 * 3, $q100, 'Query count não pode escalar proporcionalmente ao número de atividades.');
    }
}
