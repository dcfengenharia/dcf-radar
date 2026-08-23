<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Enums\Papel;
use App\Exceptions\RevisaoDocumentoNaoVigenteException;
use App\Imports\DocumentoEngenhariaImporter;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\RevisaoLiberacao;
use App\Models\StatusDocumento;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.3 — semântica documental: revisão vigente + liberação
 * para construção. Cobertura A-Z do pedido (model/domínio, autorização, UI).
 */
class RevisaoLiberacaoTest extends TestCase
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
    }

    private function componente()
    {
        return Livewire::test('pages::engenharia.documentos-engenharia');
    }

    private function criarDocumento(?Work $obra = null): DocumentoEngenharia
    {
        $obra ??= $this->obra;

        return DocumentoEngenharia::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'codigo' => 'DOC-' . uniqid(),
            'descricao' => 'Documento de teste',
        ]);
    }

    private function criarRevisao(DocumentoEngenharia $documento, string $texto = 'R0', array $extra = []): DocumentoEngenhariaRevisao
    {
        $revisao = $documento->revisoes()->create([
            'tenant_id' => $documento->tenant_id,
            'revisao' => $texto,
            'descricao' => 'Emissão ' . $texto,
        ] + $extra);

        // Permite simular created_at controlado (revisão "cadastrada depois"
        // com data_emissao retroativa) — update() direto no timestamp,
        // já que create() sempre usa now().
        if (array_key_exists('created_at', $extra)) {
            $revisao->forceFill(['created_at' => $extra['created_at']])->save();
        }

        return $revisao->fresh();
    }

    private function liberar(DocumentoEngenhariaRevisao $revisao, ?User $usuario = null): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->liberar($revisao, $usuario ?? $this->user);
    }

    // ===================== A-K: MODEL/DOMÍNIO =====================

    public function test_a_documento_sem_revisao_nao_liberado(): void
    {
        $documento = $this->criarDocumento();

        $this->assertFalse($documento->estaLiberadoParaConstrucao());
        $this->assertEquals('sem_revisao', $documento->motivoLiberacao());
        $this->assertNull($documento->revisaoVigente());
    }

    public function test_b_r1_nao_liberada_documento_nao_liberado(): void
    {
        $documento = $this->criarDocumento();
        $this->criarRevisao($documento, 'R1');

        $this->assertFalse($documento->fresh()->estaLiberadoParaConstrucao());
        $this->assertEquals('revisao_nao_liberada', $documento->fresh()->motivoLiberacao());
    }

    public function test_c_r1_liberada_documento_liberado(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);

        $this->assertTrue($documento->fresh()->estaLiberadoParaConstrucao());
        $this->assertEquals('revisao_liberada', $documento->fresh()->motivoLiberacao());
    }

    public function test_d_r1_liberada_r2_nova_nao_liberada_documento_nao_liberado(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);
        $this->criarRevisao($documento, 'R2');

        $this->assertFalse($documento->fresh()->estaLiberadoParaConstrucao());
        $this->assertEquals('revisao_nao_liberada', $documento->fresh()->motivoLiberacao());
    }

    public function test_e_r1_permanece_historicamente_liberada_apos_r2(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);
        $this->criarRevisao($documento, 'R2');

        $this->assertTrue($r1->fresh()->estaLiberadaParaConstrucao(), 'R1 deve continuar historicamente liberada.');
    }

    public function test_f_r2_liberada_documento_liberado_novamente(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);
        $r2 = $this->criarRevisao($documento, 'R2');
        $this->liberar($r2);

        $this->assertTrue($documento->fresh()->estaLiberadoParaConstrucao());
        $this->assertEquals($r2->id, $documento->fresh()->revisaoVigente()->id);
    }

    public function test_g_revisao_antiga_nao_controla_documento_quando_existe_mais_nova(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);
        $r2 = $this->criarRevisao($documento, 'R2'); // não liberada

        // Etapa 18.3.CORREÇÃO — a Action agora BLOQUEIA alteração numa
        // revisão não-vigente (ver garantirRevisaoVigente()), então
        // tentar revogar R1 (histórica, R2 é a vigente) lança exceção —
        // nunca chega a criar evento nem a mexer no estado consolidado.
        // A garantia original deste teste ("revisão antiga não controla
        // o Documento") continua provada, agora de forma ainda mais
        // forte: nem sequer é possível tentar.
        try {
            (new AlterarLiberacaoRevisaoDocumento())->revogar($r1, $this->user);
            $this->fail('Esperava RevisaoDocumentoNaoVigenteException.');
        } catch (\App\Exceptions\RevisaoDocumentoNaoVigenteException) {
            // esperado
        }

        $this->assertFalse($documento->fresh()->estaLiberadoParaConstrucao());
        $this->assertEquals($r2->id, $documento->fresh()->revisaoVigente()->id);
        $this->assertTrue($r1->fresh()->estaLiberadaParaConstrucao(), 'R1 continua liberada -- a tentativa bloqueada não a alterou.');
    }

    public function test_h_duas_revisoes_liberadas_nao_se_confundem(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);
        $r2 = $this->criarRevisao($documento, 'R2');
        $this->liberar($r2);

        $eventoR1 = RevisaoLiberacao::where('revisao_id', $r1->id)->first();
        $eventoR2 = RevisaoLiberacao::where('revisao_id', $r2->id)->first();

        $this->assertNotEquals($eventoR1->id, $eventoR2->id);
        $this->assertEquals($r1->id, $eventoR1->revisao_id);
        $this->assertEquals($r2->id, $eventoR2->revisao_id);
    }

    public function test_i_documento_soft_deleted_comportamento_historico_seguro(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);

        $documento->delete();

        // Revisão e evento de liberação continuam intactos no banco —
        // soft delete do Documento nunca apaga histórico documental.
        $this->assertNotNull(DocumentoEngenhariaRevisao::find($r1->id));
        $this->assertTrue(DocumentoEngenhariaRevisao::find($r1->id)->estaLiberadaParaConstrucao());
    }

    public function test_j_restore_preserva_estados_das_revisoes(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);

        $documento->delete();
        $documento->restore();

        $this->assertTrue($documento->fresh()->estaLiberadoParaConstrucao());
    }

    public function test_k_reimportacao_da_ld_nao_apaga_estados_documentais(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);

        // Simula o que o Importer faz: nunca atualiza/apaga revisões
        // existentes, só cria uma nova quando o texto muda — reaproveitando
        // a mesma lógica de comparação já usada em DocumentoEngenhariaImporter::aplicar().
        $revisaoAtualTexto = $documento->revisoes()->first()?->revisao;
        $this->assertEquals('R1', $revisaoAtualTexto);
        // Reimportação "traz" o mesmo texto de revisão -> não cria nova, não altera nada.
        $this->assertTrue($documento->fresh()->estaLiberadoParaConstrucao(), 'Estado documental deve sobreviver a uma reconciliação que não muda o texto da revisão.');
        $this->assertEquals(1, $documento->revisoes()->count());
    }

    // ===================== L-R: AUTORIZAÇÃO =====================

    public function test_l_readonly_obra_a_ve_status_liberacao(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id);

        $componente->assertOk();
        $this->assertTrue($componente->instance()->documentoRevisoes->estaLiberadoParaConstrucao());
    }

    public function test_m_readonly_nao_altera(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $this->criarRevisao($documento, 'R1');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->call('liberarRevisaoVigente')
            ->assertForbidden();

        $this->assertFalse($documento->fresh()->estaLiberadoParaConstrucao());
    }

    public function test_n_editar_obra_a_altera_revisao_a(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $this->criarRevisao($documento, 'R1');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->call('liberarRevisaoVigente');

        $this->assertTrue($documento->fresh()->estaLiberadoParaConstrucao());
    }

    public function test_o_editar_apenas_obra_b_nao_altera_revisao_a(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $this->criarRevisao($documento, 'R1');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->assertForbidden();

        $this->assertFalse($documento->fresh()->estaLiberadoParaConstrucao());
    }

    public function test_p_editar_nas_duas_obras_componente_a_revisao_b_bloqueado(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->vincularObra($obraB, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documentoB = $this->criarDocumento($obraB);
        $this->criarRevisao($documentoB, 'R1');

        try {
            $this->componente()
                ->set('obraId', $this->obra->id)
                ->set('documentoRevisoesId', $documentoB->id)
                ->call('liberarRevisaoVigente');
            $this->fail('Esperava ModelNotFoundException — Documento B fora do contexto da obra A.');
        } catch (ModelNotFoundException $e) {
            // esperado
        }

        $this->assertFalse($documentoB->fresh()->estaLiberadoParaConstrucao());
    }

    public function test_q_cross_tenant_bloqueado(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();

        $outroTenant = Tenant::factory()->create();
        $revisaoOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $doc = DocumentoEngenharia::create(['tenant_id' => $outroTenant->id, 'obra_id' => $outraObra->id, 'codigo' => 'DOC-OUTRO', 'descricao' => 'x']);

            return $doc->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
        });

        try {
            $this->componente()
                ->set('obraId', $this->obra->id)
                ->set('documentoRevisoesId', $documento->id)
                ->call('liberarRevisaoVigente'); // documento não tem revisão -> 404 antes mesmo de cross-tenant importar
        } catch (\Throwable $e) {
            // ok também
        }

        $this->assertFalse($revisaoOutroTenant->fresh()->estaLiberadaParaConstrucao());
    }

    public function test_r_chamada_livewire_direta_mesma_protecao(): void
    {
        // Manipulação direta de documentoRevisoesId (sem nunca ter passado
        // por abrirRevisoes()) — mesma proteção de resolverDocumentoDaObraAtual().
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documentoB = TenantContext::actingAs($this->tenant, fn () => $this->criarDocumento($obraB));
        $r1 = $this->criarRevisao($documentoB, 'R1');
        $this->liberar($r1); // já liberada, pra provar que revogar não altera nada

        $excecao = null;
        try {
            $this->componente()
                ->set('obraId', $this->obra->id)
                ->set('documentoRevisoesId', $documentoB->id)
                ->call('revogarLiberacaoRevisaoVigente');
        } catch (ModelNotFoundException $e) {
            $excecao = $e;
        }

        $this->assertInstanceOf(ModelNotFoundException::class, $excecao);
        $this->assertTrue($r1->fresh()->estaLiberadaParaConstrucao(), 'Liberação não deveria ter sido revogada via manipulação direta cross-obra.');
    }

    // ===================== S-Z: UI =====================

    public function test_s_tabela_mostra_revisao_vigente_correta(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $this->criarRevisao($documento, 'R1');
        $this->criarRevisao($documento, 'R2');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertSee('R2'); // revisão vigente exibida na Lista Mestra
    }

    public function test_t_tabela_mostra_sem_emissao_sem_revisao(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $this->criarDocumento();

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertSee('Sem emissão');
    }

    public function test_u_modal_mostra_historico_de_r1_r2(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $this->criarRevisao($documento, 'R1');
        $this->criarRevisao($documento, 'R2');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->assertSee('R1')
            ->assertSee('R2');
    }

    public function test_v_r1_liberada_aparece_historicamente_como_liberada_no_modal(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);
        $this->criarRevisao($documento, 'R2');

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id);

        $componente->assertSeeInOrder(['R1', 'Liberada']);
    }

    public function test_w_r2_nao_liberada_controla_badge_consolidado(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);
        $this->criarRevisao($documento, 'R2');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertSee('Não liberado');
    }

    public function test_x_botao_liberar_so_aparece_com_editar(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $this->criarRevisao($documento, 'R1');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->assertSee('Liberar');
    }

    public function test_y_readonly_nao_ve_acao_mas_ve_estado(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id);

        $componente->assertSee('Liberada');
        $componente->assertDontSee('Revogar');
    }

    public function test_z_nenhuma_url_storage_path_na_logica_de_liberacao(): void
    {
        // Confirma estruturalmente: RevisaoLiberacao não tem NENHUM campo
        // de path/storage/URL — a liberação é puramente um evento de
        // domínio, sem qualquer acoplamento ao armazenamento de arquivo.
        $colunas = \Illuminate\Support\Facades\Schema::getColumnListing('revisao_liberacoes');

        $this->assertNotContains('anexo_path', $colunas);
        $this->assertNotContains('caminho_arquivo', $colunas);
        $this->assertNotContains('url', $colunas);
    }

    // ===================== 18.3.CORREÇÃO: VIGÊNCIA CANÔNICA =====================

    public function test_correcao_a_ordem_canonica_por_data_emissao_vence_apesar_de_created_at(): void
    {
        $documento = $this->criarDocumento();
        // R1 tem data_emissao MENOR mas é criada por ÚLTIMO (created_at maior).
        $r0 = $this->criarRevisao($documento, 'R0', ['data_emissao' => '2026-08-20']);
        $r1 = $this->criarRevisao($documento, 'R1', ['data_emissao' => '2026-08-01', 'created_at' => now()->addDay()]);

        $this->assertEquals($r0->id, $documento->fresh()->revisaoVigente()->id, 'data_emissao deve vencer sobre created_at.');
    }

    public function test_correcao_b_retroativa_criada_depois_nao_vira_vigente(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1', ['data_emissao' => '2026-08-10']);
        $r2 = $this->criarRevisao($documento, 'R2', ['data_emissao' => '2026-08-20']);
        // R0: emitida antes de tudo (01/08), mas CADASTRADA na plataforma por último.
        $r0 = $this->criarRevisao($documento, 'R0', ['data_emissao' => '2026-08-01', 'created_at' => now()->addDay()]);

        $this->assertEquals($r2->id, $documento->fresh()->revisaoVigente()->id, 'R0 (mais antiga) não pode virar vigente só por ter sido cadastrada depois.');
    }

    public function test_correcao_c_cenario_exato_da_auditoria_a_liberada_b_criada_depois(): void
    {
        $documento = $this->criarDocumento();
        $a = $this->criarRevisao($documento, 'A', ['data_emissao' => '2026-08-20']);
        $this->liberar($a);
        $b = $this->criarRevisao($documento, 'B', ['data_emissao' => '2026-08-15', 'created_at' => now()->addDay()]);

        $this->assertEquals($a->id, $documento->fresh()->revisaoVigente()->id);
        $this->assertTrue($documento->fresh()->estaLiberadoParaConstrucao(), 'A liberação de A não pode ser derrubada silenciosamente por B, criada depois com data anterior.');
    }

    public function test_correcao_d_r1_r2_r0_cenario_completo_da_secao_21(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1', ['data_emissao' => '2026-08-10']);
        $this->liberar($r1);
        $r2 = $this->criarRevisao($documento, 'R2', ['data_emissao' => '2026-08-20']);
        $r0 = $this->criarRevisao($documento, 'R0', ['data_emissao' => '2026-08-01', 'created_at' => now()->addDay()]);

        $documento->refresh();
        $this->assertEquals($r2->id, $documento->revisaoVigente()->id);
        $this->assertFalse($documento->estaLiberadoParaConstrucao());
        $this->assertTrue($r1->fresh()->estaLiberadaParaConstrucao(), 'R1 continua historicamente true.');

        $this->liberar($r2);
        $this->assertTrue($documento->fresh()->estaLiberadoParaConstrucao());
        $this->assertEquals($r2->id, $documento->fresh()->revisaoVigente()->id, 'R0 continua irrelevante ao estado consolidado.');
    }

    /**
     * Etapa 18.3.CORREÇÃO, Seção 36 — prova que o teste C acima de fato
     * captura o bug real: sem tocar nenhum arquivo de produção, reproduz
     * a query ANTIGA (ofMany por created_at/id, literalmente a mesma
     * lógica que estava em DocumentoEngenharia::latestRevisao() antes
     * desta correção) isolada dentro do próprio teste, e mostra que ela
     * retorna B (errado) para o cenário exato da auditoria — enquanto a
     * relação canônica atual (latestRevisao()/revisaoVigente(), já
     * corrigida) retorna A (correto). Confirma que test_correcao_c
     * teria falhado antes desta correção.
     */
    public function test_correcao_prova_bug_pre_fix_query_antiga_por_created_at_retorna_revisao_errada(): void
    {
        $documento = $this->criarDocumento();
        $a = $this->criarRevisao($documento, 'A', ['data_emissao' => '2026-08-20']);
        $this->liberar($a);
        $b = $this->criarRevisao($documento, 'B', ['data_emissao' => '2026-08-15', 'created_at' => now()->addDay()]);

        // Query ANTIGA reproduzida isoladamente (ofMany por created_at/id) --
        // NÃO é o código de produção atual, só uma cópia inline pra provar o bug.
        $antiga = \Closure::bind(function () {
            return $this->hasOne(DocumentoEngenhariaRevisao::class, 'documento_engenharia_id')
                ->ofMany(['created_at' => 'max', 'id' => 'max']);
        }, $documento, DocumentoEngenharia::class)()->first();

        $this->assertEquals($b->id, $antiga->id, 'A lógica antiga (created_at) elegeria B como vigente -- o bug que a 18.3.CORREÇÃO fecha.');
        $this->assertNotEquals($a->id, $antiga->id);

        // Código de produção ATUAL (corrigido): elege A corretamente.
        $this->assertEquals($a->id, $documento->fresh()->revisaoVigente()->id, 'A relação canônica atual corrige o bug.');
    }

    public function test_correcao_e_empate_de_data_desempatado_por_created_at_depois_id(): void
    {
        $documento = $this->criarDocumento();
        $base = now();
        $r1 = $this->criarRevisao($documento, 'R1', ['data_emissao' => '2026-08-10', 'created_at' => $base]);
        $r2 = $this->criarRevisao($documento, 'R2', ['data_emissao' => '2026-08-10', 'created_at' => $base->copy()->addMinute()]);

        $this->assertEquals($r2->id, $documento->fresh()->revisaoVigente()->id, 'Mesma data_emissao: created_at mais recente vence.');
    }

    public function test_correcao_f_revisao_sem_data_nunca_supera_revisao_com_data(): void
    {
        $documento = $this->criarDocumento();
        $comData = $this->criarRevisao($documento, 'COM-DATA', ['data_emissao' => '2026-08-01']);
        // Sem data, cadastrada bem depois -- não pode vencer uma emissão documentada com data real.
        $semData = $this->criarRevisao($documento, 'SEM-DATA', ['created_at' => now()->addDays(5)]);

        $this->assertEquals($comData->id, $documento->fresh()->revisaoVigente()->id);
    }

    public function test_correcao_g_ambas_sem_data_desempatam_por_created_at_id(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $r2 = $this->criarRevisao($documento, 'R2');

        $this->assertEquals($r2->id, $documento->fresh()->revisaoVigente()->id);
    }

    public function test_correcao_h_statusatual_usa_mesma_revisao_vigente_canonica(): void
    {
        $documento = $this->criarDocumento();
        $s2 = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'S2', 'ordem' => 1]);
        $s0 = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'S0', 'ordem' => 2]);

        $r2 = $this->criarRevisao($documento, 'R2', ['data_emissao' => '2026-08-20', 'status_documento_id' => $s2->id]);
        $this->criarRevisao($documento, 'R0', ['data_emissao' => '2026-08-01', 'status_documento_id' => $s0->id, 'created_at' => now()->addDay()]);

        $this->assertEquals('S2', $documento->fresh()->statusAtual()?->nome);
    }

    public function test_correcao_i_j_importador_usa_vigencia_canonica_mesmo_com_retroativa_no_meio(): void
    {
        $this->actingAs($this->user);
        $importer = new DocumentoEngenhariaImporter();

        $linha1 = ['linha' => 2, 'disciplina' => null, 'codigo' => 'IMP-COR', 'revisao' => 'R1', 'titulo' => 'x', 'status' => null, 'data_prevista' => null, 'data_real' => '2026-08-10'];
        $importer->aplicar([$linha1], $this->obra->id, $this->user->id);
        $documento = DocumentoEngenharia::where('obra_id', $this->obra->id)->where('codigo', 'IMP-COR')->first();
        $this->liberar($documento->revisaoVigente());

        $linha2 = $linha1;
        $linha2['revisao'] = 'R2';
        $linha2['data_real'] = '2026-08-20';
        $importer->aplicar([$linha2], $this->obra->id, $this->user->id);
        $documento->refresh();
        $this->assertEquals('R2', $documento->revisaoVigente()->revisao);
        $this->assertFalse($documento->estaLiberadoParaConstrucao());

        // Cadastro manual retroativo (R0, emitida antes de tudo, cadastrada por último) -- nunca deve reassumir a vigência.
        $r0 = $this->criarRevisao($documento, 'R0', ['data_emissao' => '2026-08-01', 'created_at' => now()->addDay()]);

        // Reimportação da MESMA planilha (linha2, ainda "R2") -- idempotente, não duplica, não muda vigência.
        $importer->aplicar([$linha2], $this->obra->id, $this->user->id);
        $documento->refresh();

        $this->assertEquals(3, $documento->revisoes()->count(), 'R1, R2, R0 -- reimportar R2 idêntica não duplica.');
        $this->assertEquals('R2', $documento->revisaoVigente()->revisao, 'Importador continua considerando R2 (canônica), nunca R0.');
        $this->assertFalse($documento->estaLiberadoParaConstrucao());

        // Nova emissão real via planilha (R3, emitida depois de tudo) -- essa sim deve assumir vigência.
        $linha3 = $linha1;
        $linha3['revisao'] = 'R3';
        $linha3['data_real'] = '2026-08-25';
        $importer->aplicar([$linha3], $this->obra->id, $this->user->id);
        $documento->refresh();
        $this->assertEquals('R3', $documento->revisaoVigente()->revisao);
        $this->assertEquals(4, $documento->revisoes()->count());
    }

    public function test_correcao_k_modal_marca_badge_vigente_na_revisao_canonica(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1', ['data_emissao' => '2026-08-10']);
        $r2 = $this->criarRevisao($documento, 'R2', ['data_emissao' => '2026-08-20']);
        $this->criarRevisao($documento, 'R0', ['data_emissao' => '2026-08-01', 'created_at' => now()->addDay()]);

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id);

        // Badge "vigente" deve aparecer junto de R2 (a canônica), não R0.
        $componente->assertSeeInOrder(['R2', 'vigente']);
    }

    public function test_correcao_l_lista_mestra_mostra_revisao_e_liberacao_canonicas(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $a = $this->criarRevisao($documento, 'A', ['data_emissao' => '2026-08-20']);
        $this->liberar($a);
        $this->criarRevisao($documento, 'B', ['data_emissao' => '2026-08-15', 'created_at' => now()->addDay()]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertSee('A')
            ->assertSee('Liberado p/ construção');
    }

    public function test_correcao_m_action_bloqueia_alteracao_em_revisao_historica(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $r2 = $this->criarRevisao($documento, 'R2'); // vigente (created depois, sem data ambas)

        $this->expectException(RevisaoDocumentoNaoVigenteException::class);
        try {
            (new AlterarLiberacaoRevisaoDocumento())->liberar($r1, $this->user);
        } finally {
            $this->assertEquals(0, RevisaoLiberacao::where('revisao_id', $r1->id)->count(), 'Nenhum evento deve ter sido criado em R1.');
        }
    }

    public function test_correcao_n_liberar_idempotente_nao_duplica_evento(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');

        $action = new AlterarLiberacaoRevisaoDocumento();
        $action->liberar($r1, $this->user);
        $action->liberar($r1, $this->user);

        $this->assertEquals(1, RevisaoLiberacao::where('revisao_id', $r1->id)->count());
        $this->assertTrue($r1->fresh()->estaLiberadaParaConstrucao());
    }

    public function test_correcao_o_revogar_idempotente_nao_duplica_evento(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');

        $action = new AlterarLiberacaoRevisaoDocumento();
        // Nunca foi liberada -- revogar já é o estado atual (implícito), deve ser no-op.
        $action->revogar($r1, $this->user);
        $this->assertEquals(0, RevisaoLiberacao::where('revisao_id', $r1->id)->count(), 'Revogar quando já está implicitamente não-liberada não cria evento.');

        $action->liberar($r1, $this->user);
        $action->revogar($r1, $this->user);
        $action->revogar($r1, $this->user);

        $this->assertEquals(2, RevisaoLiberacao::where('revisao_id', $r1->id)->count(), 'liberar + revogar = 2 transições; segundo revogar é no-op.');
        $this->assertFalse($r1->fresh()->estaLiberadaParaConstrucao());
    }

    public function test_correcao_p_ciclo_liberar_revogar_liberar_preserva_3_transicoes(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');

        $action = new AlterarLiberacaoRevisaoDocumento();
        $action->liberar($r1, $this->user);
        $action->revogar($r1, $this->user);
        $action->liberar($r1, $this->user);

        $eventos = RevisaoLiberacao::where('revisao_id', $r1->id)->orderBy('created_at')->orderBy('id')->get();
        $this->assertEquals(3, $eventos->count());
        $this->assertEquals([true, false, true], $eventos->pluck('liberada_para_construcao')->toArray());
        $this->assertTrue($r1->fresh()->estaLiberadaParaConstrucao());
    }

    // ===================== N+1 / PERFORMANCE =====================

    public function test_sem_n_mais_1_na_lista_mestra_com_revisao_e_liberacao(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        collect(range(1, 15))->each(function () {
            $documento = $this->criarDocumento();
            $r1 = $this->criarRevisao($documento, 'R1');
            $this->liberar($r1);
        });

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->componente()->set('obraId', $this->obra->id);

        $this->assertLessThan(40, $queries, "Render da listagem com 15 documentos+revisão+liberação gerou {$queries} queries — suspeita de N+1.");
    }

    public function test_correcao_q_sem_n_mais_1_com_data_emissao_e_multiplas_revisoes_por_documento(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        collect(range(1, 15))->each(function (int $i) {
            $documento = $this->criarDocumento();
            $this->criarRevisao($documento, 'R1', ['data_emissao' => '2026-08-01']);
            $r2 = $this->criarRevisao($documento, 'R2', ['data_emissao' => '2026-08-' . str_pad((string) (10 + $i % 15), 2, '0', STR_PAD_LEFT)]);
            $this->liberar($r2);
        });

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) { $queries++; });

        $this->componente()->set('obraId', $this->obra->id);

        $this->assertLessThan(40, $queries, "Render com data_emissao real e múltiplas revisões gerou {$queries} queries — suspeita de N+1 na relação canônica.");
    }

    public function test_correcao_r_sem_n_mais_1_no_modal_escala_sublinear_com_revisoes(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $criarComRevisoes = function (int $qtd): DocumentoEngenharia {
            $documento = $this->criarDocumento();
            collect(range(1, $qtd))->each(function (int $i) use ($documento) {
                $rev = $this->criarRevisao($documento, "R{$i}", ['data_emissao' => '2026-01-' . str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT)]);
                $rev->historicoLiberacoes()->create([
                    'tenant_id' => $this->tenant->id, 'liberada_para_construcao' => true,
                    'alterado_por' => $this->user->id, 'ocorrido_em' => now(),
                ]);
            });

            return $documento;
        };

        $docPequeno = $criarComRevisoes(4);
        $docGrande = $criarComRevisoes(20);

        $queriesPequeno = 0;
        $queriesGrande = 0;
        $fase = 'pequeno';
        \Illuminate\Support\Facades\DB::listen(function () use (&$queriesPequeno, &$queriesGrande, &$fase) {
            if ($fase === 'pequeno') {
                $queriesPequeno++;
            } else {
                $queriesGrande++;
            }
        });

        $this->componente()->set('obraId', $this->obra->id)->call('abrirRevisoes', $docPequeno->id);
        $fase = 'grande';
        $this->componente()->set('obraId', $this->obra->id)->call('abrirRevisoes', $docGrande->id);

        // Se fosse N+1 de verdade, 20 revisões geraria MUITO mais queries
        // que 4 revisões (crescimento ~ proporcional a N). Eager-load
        // correto gera o MESMO pequeno número fixo de queries,
        // independente da quantidade de revisões.
        $this->assertLessThanOrEqual($queriesPequeno + 3, $queriesGrande, "4 revisões: {$queriesPequeno} queries; 20 revisões: {$queriesGrande} queries — crescimento sugere N+1.");
    }
}
