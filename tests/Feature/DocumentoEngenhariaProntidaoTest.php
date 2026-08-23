<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\CentralProntidao\CentralProntidaoQuery;
use App\Support\CentralProntidao\StatusOperacionalProntidao;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.4 — Documentos de Engenharia não liberados como
 * pendência DERIVADA de prontidão da Atividade (vínculo direto Ciclo 18.1,
 * nunca via Restricao persistida). Cobertura A-Z do pedido.
 */
class DocumentoEngenhariaProntidaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private CentralProntidaoQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->query = new CentralProntidaoQuery();
    }

    private function criarAtividade(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'fora_do_cronograma' => false,
        ], $overrides));
    }

    private function criarDocumento(array $overrides = [], ?Work $obra = null): DocumentoEngenharia
    {
        $obra ??= $this->obra;

        return DocumentoEngenharia::create(array_merge([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'codigo' => 'DOC-' . uniqid(),
            'descricao' => 'Documento de teste',
        ], $overrides));
    }

    private function criarRevisao(DocumentoEngenharia $documento, string $texto = 'R1', array $extra = []): DocumentoEngenhariaRevisao
    {
        return $documento->revisoes()->create(['tenant_id' => $documento->tenant_id, 'revisao' => $texto, 'descricao' => 'x'] + $extra);
    }

    private function liberar(DocumentoEngenhariaRevisao $r): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->liberar($r, $this->user);
    }

    private function revogar(DocumentoEngenhariaRevisao $r): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->revogar($r, $this->user);
    }

    private function viewDe(Atividade $atividade, ?Work $obra = null)
    {
        return $this->query->paraObra($obra ?? $this->obra)
            ->first(fn ($v) => $v->atividadeId === $atividade->id);
    }

    // ===================== A-F: regra funcional canônica =====================

    public function test_a_atividade_sem_documento_ged_nao_bloqueia(): void
    {
        $at = $this->criarAtividade();

        $view = $this->viewDe($at);

        $this->assertSame([], $view->documentosBloqueantes);
        $this->assertSame(StatusOperacionalProntidao::Pronta, $view->statusOperacional);
    }

    public function test_b_documento_sem_revisao_bloqueia(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);

        $this->assertCount(1, $view->documentosBloqueantes);
        $this->assertSame('sem_revisao', $view->documentosBloqueantes[0]->motivo);
        $this->assertSame(StatusOperacionalProntidao::NaoPronta, $view->statusOperacional);
    }

    public function test_c_revisao_nao_liberada_bloqueia(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $r = $this->criarRevisao($doc, 'R1');
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);

        $this->assertCount(1, $view->documentosBloqueantes);
        $bloqueio = $view->documentosBloqueantes[0];
        $this->assertSame('revisao_nao_liberada', $bloqueio->motivo);
        $this->assertSame('R1', $bloqueio->revisaoVigente);
        $this->assertSame($doc->codigo, $bloqueio->codigo);
        $this->assertSame(StatusOperacionalProntidao::NaoPronta, $view->statusOperacional);
    }

    public function test_d_revisao_liberada_nao_bloqueia(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $r = $this->criarRevisao($doc, 'R1');
        $this->liberar($r);
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);

        $this->assertSame([], $view->documentosBloqueantes);
        $this->assertSame(StatusOperacionalProntidao::Pronta, $view->statusOperacional);
    }

    public function test_e_varios_docs_um_bloqueante(): void
    {
        $at = $this->criarAtividade();
        $d1 = $this->criarDocumento(['codigo' => 'D1']);
        $r1 = $this->criarRevisao($d1, 'R1');
        $this->liberar($r1);
        $d2 = $this->criarDocumento(['codigo' => 'D2']);
        $this->criarRevisao($d2, 'R1'); // nao liberada

        $at->documentosEngenharia()->attach([$d1->id, $d2->id], ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);

        $this->assertCount(1, $view->documentosBloqueantes);
        $this->assertSame('D2', $view->documentosBloqueantes[0]->codigo);
    }

    public function test_f_varios_docs_varios_bloqueantes(): void
    {
        $at = $this->criarAtividade();
        $d1 = $this->criarDocumento(['codigo' => 'D1']);
        $this->criarRevisao($d1, 'R1');
        $d2 = $this->criarDocumento(['codigo' => 'D2']);
        // D2 sem revisao alguma
        $d3 = $this->criarDocumento(['codigo' => 'D3']);
        $r3 = $this->criarRevisao($d3, 'R1');
        $this->liberar($r3);

        $at->documentosEngenharia()->attach([$d1->id, $d2->id, $d3->id], ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);

        $this->assertCount(2, $view->documentosBloqueantes);
        $codigos = collect($view->documentosBloqueantes)->pluck('codigo')->sort()->values()->all();
        $this->assertSame(['D1', 'D2'], $codigos);
    }

    // ===================== G-I: dinâmica ao vivo =====================

    public function test_g_nova_revisao_derruba_liberacao_anterior(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $r1 = $this->criarRevisao($doc, 'R1');
        $this->liberar($r1);
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $this->assertSame([], $this->viewDe($at)->documentosBloqueantes, 'R1 liberada -- nao deveria bloquear ainda.');

        $this->criarRevisao($doc, 'R2'); // nasce nao liberada

        $view = $this->viewDe($at);
        $this->assertCount(1, $view->documentosBloqueantes);
        $this->assertSame('R2', $view->documentosBloqueantes[0]->revisaoVigente);
    }

    public function test_h_liberar_nova_revisao_remove_bloqueio(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $r1 = $this->criarRevisao($doc, 'R1');
        $this->liberar($r1);
        $r2 = $this->criarRevisao($doc, 'R2');
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $this->assertCount(1, $this->viewDe($at)->documentosBloqueantes);

        $this->liberar($r2);

        $this->assertSame([], $this->viewDe($at)->documentosBloqueantes);
    }

    public function test_i_revogar_recria_bloqueio(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $r1 = $this->criarRevisao($doc, 'R1');
        $this->liberar($r1);
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $this->assertSame([], $this->viewDe($at)->documentosBloqueantes);

        $this->revogar($r1);

        $this->assertCount(1, $this->viewDe($at)->documentosBloqueantes);
    }

    // ===================== J-L: vínculo/N:N =====================

    public function test_j_vincular_documento_bloqueante_altera_prontidao(): void
    {
        $at = $this->criarAtividade();
        $this->assertSame(StatusOperacionalProntidao::Pronta, $this->viewDe($at)->statusOperacional);

        $doc = $this->criarDocumento();
        $this->criarRevisao($doc, 'R1');
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $this->assertSame(StatusOperacionalProntidao::NaoPronta, $this->viewDe($at)->statusOperacional);
    }

    public function test_k_desvincular_remove_pendencia_sem_row_orfa(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $this->criarRevisao($doc, 'R1');
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $this->assertCount(1, $this->viewDe($at)->documentosBloqueantes);

        $at->documentosEngenharia()->detach($doc->id);

        $this->assertSame([], $this->viewDe($at)->documentosBloqueantes);
        $this->assertSame(0, DB::table('documento_engenharia_atividades')->where('atividade_id', $at->id)->count());
    }

    public function test_l_mesmo_documento_bloqueia_varias_atividades(): void
    {
        $a1 = $this->criarAtividade();
        $a2 = $this->criarAtividade();
        $a3 = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $r1 = $this->criarRevisao($doc, 'R1');

        $doc->atividades()->attach([$a1->id, $a2->id, $a3->id], ['tenant_id' => $this->tenant->id]);

        $views = $this->query->paraObra($this->obra);
        foreach ([$a1, $a2, $a3] as $a) {
            $view = $views->first(fn ($v) => $v->atividadeId === $a->id);
            $this->assertCount(1, $view->documentosBloqueantes, "Atividade {$a->id} deveria estar bloqueada.");
        }

        $this->liberar($r1);

        $viewsDepois = $this->query->paraObra($this->obra);
        foreach ([$a1, $a2, $a3] as $a) {
            $view = $viewsDepois->first(fn ($v) => $v->atividadeId === $a->id);
            $this->assertSame([], $view->documentosBloqueantes, "Atividade {$a->id} deveria estar desbloqueada.");
        }
    }

    // ===================== M: atividade concluída =====================

    public function test_m_atividade_concluida_preserva_precedencia_existente(): void
    {
        $at = $this->criarAtividade(['concluido_em' => now()]);
        $doc = $this->criarDocumento();
        $this->criarRevisao($doc, 'R1'); // bloqueante
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);

        $this->assertSame(StatusOperacionalProntidao::Concluida, $view->statusOperacional, 'Concluida deve dominar mesmo com GED bloqueante.');
        $this->assertSame([], $view->resumoMotivos, 'Concluida nao lista motivos, mesma regra ja existente pra Restricao.');
    }

    // ===================== N-O: isolamento =====================

    public function test_n_cross_obra_nao_influencia(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $at = $this->criarAtividade(); // obra A
        $docObraB = $this->criarDocumento([], $obraB);
        $this->criarRevisao($docObraB, 'R1');

        // Vinculo "corrompido" manualmente via DB::table -- simula dado
        // legado/corrompido que aponte pra documento de OUTRA obra (mesmo
        // tenant), contornando a validação normal de vincularAtividade()
        // (que só permite vincular dentro da mesma obra). Prova que a
        // consulta de prontidão tem defesa própria (filtro obra_id ===
        // obra_id), não confia apenas na integridade do pivot na criação.
        DB::table('documento_engenharia_atividades')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $this->tenant->id,
            'documento_engenharia_id' => $docObraB->id,
            'atividade_id' => $at->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $view = $this->viewDe($at);

        $this->assertSame([], $view->documentosBloqueantes, 'Documento de outra obra (mesmo tenant), mesmo com pivô corrompido, nunca deve bloquear.');
    }

    public function test_o_cross_tenant_nao_influencia(): void
    {
        $at = $this->criarAtividade();

        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $atOutroTenant = Atividade::factory()->create([
                'tenant_id' => $outroTenant->id, 'obra_id' => $obraOutroTenant->id, 'fora_do_cronograma' => false,
            ]);
            $docOutroTenant = DocumentoEngenharia::create([
                'tenant_id' => $outroTenant->id, 'obra_id' => $obraOutroTenant->id,
                'codigo' => 'DOC-OUTRO', 'descricao' => 'x',
            ]);
            $docOutroTenant->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
            // Vinculo real, sob o tenant/obra corretos do OUTRO tenant --
            // nunca deveria aparecer pra Atividade $at (tenant A).
            $docOutroTenant->atividades()->attach($atOutroTenant->id, ['tenant_id' => $outroTenant->id]);
        });

        $view = $this->viewDe($at);

        $this->assertSame([], $view->documentosBloqueantes);
    }

    // ===================== P-Q: soft-delete/restore =====================

    public function test_p_soft_delete_documento_remove_da_prontidao(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $this->criarRevisao($doc, 'R1');
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $this->assertCount(1, $this->viewDe($at)->documentosBloqueantes);

        $doc->delete();

        $view = $this->viewDe($at);
        $this->assertSame([], $view->documentosBloqueantes, 'Documento soft-deletado nao deve participar da prontidao operacional.');
    }

    public function test_q_restore_documento_volta_a_participar(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $this->criarRevisao($doc, 'R1');
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);
        $doc->delete();
        $this->assertSame([], $this->viewDe($at)->documentosBloqueantes);

        $doc->restore();

        $this->assertCount(1, $this->viewDe($at)->documentosBloqueantes);
    }

    // ===================== R-T: importação =====================

    public function test_r_reimportacao_cronograma_preserva_vinculo_e_pendencia(): void
    {
        // Vinculo ja provado persistente pela 18.1 (DocumentoEngenhariaAtividadeTest);
        // aqui confirmamos que a PENDENCIA continua refletida sem reprocessamento.
        $at = $this->criarAtividade(['external_uid' => 'UID-123']);
        $doc = $this->criarDocumento();
        $this->criarRevisao($doc, 'R1');
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $this->assertCount(1, $this->viewDe($at)->documentosBloqueantes);

        // Simula reimportacao que apenas atualiza campos da mesma atividade
        // (mesma PK, reconciliada por external_uid) -- vinculo intocado.
        $at->update(['nome' => 'Nome Atualizado']);

        $view = $this->viewDe($at->fresh());
        $this->assertCount(1, $view->documentosBloqueantes);
    }

    public function test_s_reimportacao_ld_identica_preserva_pendencia(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $r1 = $this->criarRevisao($doc, 'R1');
        $this->liberar($r1);
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $importer = new \App\Imports\DocumentoEngenhariaImporter();
        $linha = ['linha' => 2, 'disciplina' => null, 'codigo' => $doc->codigo, 'revisao' => 'R1', 'titulo' => $doc->descricao, 'status' => null, 'data_prevista' => null, 'data_real' => null];
        $importer->aplicar([$linha], $this->obra->id, $this->user->id);

        $this->assertSame([], $this->viewDe($at)->documentosBloqueantes, 'Reimportar mesma revisao nao deve criar pendencia nova.');
    }

    public function test_t_nova_emissao_pela_ld_volta_a_bloquear(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $r1 = $this->criarRevisao($doc, 'R1');
        $this->liberar($r1);
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $importer = new \App\Imports\DocumentoEngenhariaImporter();
        $linha = ['linha' => 2, 'disciplina' => null, 'codigo' => $doc->codigo, 'revisao' => 'R2', 'titulo' => $doc->descricao, 'status' => null, 'data_prevista' => null, 'data_real' => '2026-08-20'];
        $importer->aplicar([$linha], $this->obra->id, $this->user->id);

        $view = $this->viewDe($at);
        $this->assertCount(1, $view->documentosBloqueantes);
        $this->assertSame('R2', $view->documentosBloqueantes[0]->revisaoVigente);
    }

    // ===================== U-V: UI =====================

    public function test_u_central_lista_mostra_indicador_via_resumo_motivos(): void
    {
        $this->vincularObra($this->obra, $this->user, \App\Enums\Papel::Admin->value);
        $at = $this->criarAtividade(['nome' => 'Atividade GED Teste', 'inicio_planejado' => now()->addDays(5)]);
        $doc = $this->criarDocumento();
        $this->criarRevisao($doc, 'R1');
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        \Livewire\Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertSee('Atividade GED Teste')
            ->assertSee('Documento de engenharia não liberado');
    }

    public function test_v_central_detalhe_mostra_documentos_e_motivos(): void
    {
        $this->vincularObra($this->obra, $this->user, \App\Enums\Papel::Admin->value);
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento(['codigo' => 'DOC-DETALHE']);
        $this->criarRevisao($doc, 'R1');
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $view = $this->viewDe($at);
        $html = view('pages.radar._partials.central-prontidao-detalhe', ['view' => $view])->render();

        $this->assertStringContainsString('DOC-DETALHE', $html);
        $this->assertStringContainsString('Revisão vigente não liberada para construção', $html);
    }

    // ===================== W-X: performance =====================

    public function test_w_sem_n_mais_1_com_30_atividades(): void
    {
        collect(range(1, 30))->each(function (int $i) {
            $at = $this->criarAtividade();
            $doc = $this->criarDocumento(['codigo' => "DOC-{$i}"]);
            if ($i % 2 === 0) {
                $r = $this->criarRevisao($doc, 'R1');
                $this->liberar($r);
            } else {
                $this->criarRevisao($doc, 'R1');
            }
            $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);
        });

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $views = $this->query->paraObra($this->obra);

        $this->assertCount(30, $views);
        $this->assertLessThan(20, $queries, "paraObra() com 30 atividades gerou {$queries} queries -- suspeita de N+1.");
    }

    public function test_x_sem_n_mais_1_com_n_n_mesmo_documento_varias_atividades(): void
    {
        $doc = $this->criarDocumento();
        $this->criarRevisao($doc, 'R1');

        $atividades = collect(range(1, 10))->map(function () use ($doc) {
            $at = $this->criarAtividade();
            $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);
            return $at;
        });

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $views = $this->query->paraObra($this->obra);

        $this->assertCount(10, $views);
        $this->assertLessThan(20, $queries, "paraObra() com N:N (1 doc, 10 atividades) gerou {$queries} queries.");
        foreach ($views as $v) {
            $this->assertCount(1, $v->documentosBloqueantes);
        }
    }

    // ===================== Y: exportação =====================

    public function test_y_export_inclui_folha_de_documentos_bloqueantes(): void
    {
        $at = $this->criarAtividade(['nome' => 'Atividade Export']);
        $doc = $this->criarDocumento(['codigo' => 'DOC-EXPORT']);
        $this->criarRevisao($doc, 'R1');
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $views = $this->query->paraObra($this->obra);
        $export = new \App\Exports\CentralProntidaoExport([
            'obra' => $this->obra,
            'geradoEm' => now(),
            'horizonteLabel' => 'Todas',
            'filtros' => [],
            'resumo' => ['total' => 1, 'pronta' => 0, 'atencao' => 0, 'nao_pronta' => 1, 'concluida' => 0],
            'views' => $views,
        ]);

        $sheets = $export->sheets();
        $this->assertCount(9, $sheets, 'Deveria ter 1 folha nova alem das 8 ja existentes.');
        $folhaGed = end($sheets);
        $this->assertSame('Documentos GED Bloqueantes', $folhaGed->title());
        $linhas = $folhaGed->array();
        $this->assertCount(1, $linhas);
        $this->assertSame('DOC-EXPORT', $linhas[0][1]);
    }

    // ===================== Z: histórico não recalculado =====================

    public function test_z_snapshots_historicos_nao_sao_alterados_pela_liberacao(): void
    {
        $at = $this->criarAtividade();
        $doc = $this->criarDocumento();
        $r1 = $this->criarRevisao($doc, 'R1');
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $this->viewDe($at); // computa prontidao (nao deve escrever nada)

        $this->assertSame(0, DB::table('atividade_snapshots')->count(), 'Nenhum snapshot deveria ser criado pela consulta de prontidao.');
        $this->assertSame(0, DB::table('atividade_snapshot_operacionais')->count());
        $this->assertSame(0, DB::table('inconsistencias_avanco')->count());

        $this->liberar($r1);
        $this->viewDe($at);

        $this->assertSame(0, DB::table('atividade_snapshots')->count(), 'Liberacao GED nunca deve gerar/alterar Fotografias F/O/P historicas.');
    }
}
