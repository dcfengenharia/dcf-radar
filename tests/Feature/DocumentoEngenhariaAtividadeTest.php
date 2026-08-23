<?php

namespace Tests\Feature;

use App\Enums\OrigemAtividade;
use App\Enums\Papel;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaAtividade;
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
 * Ciclo 18, Etapa 18.1 — vínculo N:N Documento de Engenharia ↔ Atividade.
 * Cobertura A-S do pedido. Testes M (reimportação real) e o cenário de
 * mudança de "WBS" (aqui: codigo_cronograma, único campo equivalente a
 * WBS que a Atividade de fato tem) provam que o vínculo nunca depende de
 * nada além de atividade_id — mesmo princípio já provado no Ciclo 17 para
 * anexos/restrições/snapshots.
 */
class DocumentoEngenhariaAtividadeTest extends TestCase
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

    private function criarAtividade(?Work $obra = null, array $atributos = []): Atividade
    {
        $obra ??= $this->obra;

        return Atividade::factory()->create(array_merge([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'codigo_cronograma' => '1.' . rand(1, 999),
            'fora_do_cronograma' => false,
        ], $atributos));
    }

    // ===================== A-G: CRUD básico do vínculo =====================

    public function test_a_documento_sem_atividade_vinculada(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id);

        $this->assertCount(0, $componente->instance()->atividadesVinculadas);
        $componente->assertSee('Nenhuma atividade vinculada ainda.');
    }

    public function test_b_vinculo_unico(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $atividade = $this->criarAtividade();

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->call('vincularAtividade', $atividade->id);

        $this->assertDatabaseHas('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividade->id,
        ]);
        $this->assertCount(1, $documento->atividades()->get());
    }

    public function test_c_documento_com_multiplas_atividades(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $atividades = Atividade::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
        ]);

        $componente = $this->componente()->set('obraId', $this->obra->id)->call('abrirRevisoes', $documento->id);
        foreach ($atividades as $atividade) {
            $componente->call('vincularAtividade', $atividade->id);
        }

        $this->assertCount(3, $documento->atividades()->get());
    }

    public function test_d_atividade_com_multiplos_documentos(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $atividade = $this->criarAtividade();
        $doc1 = $this->criarDocumento();
        $doc2 = $this->criarDocumento();

        $this->componente()->set('obraId', $this->obra->id)->call('abrirRevisoes', $doc1->id)->call('vincularAtividade', $atividade->id);
        $this->componente()->set('obraId', $this->obra->id)->call('abrirRevisoes', $doc2->id)->call('vincularAtividade', $atividade->id);

        $this->assertCount(2, $atividade->documentosEngenharia()->get());
    }

    public function test_e_duplicate_attach_nao_cria_segunda_linha(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $atividade = $this->criarAtividade();

        $componente = $this->componente()->set('obraId', $this->obra->id)->call('abrirRevisoes', $documento->id);
        $componente->call('vincularAtividade', $atividade->id);
        $componente->call('vincularAtividade', $atividade->id);
        $componente->call('vincularAtividade', $atividade->id);

        $this->assertEquals(
            1,
            DocumentoEngenhariaAtividade::where('documento_engenharia_id', $documento->id)
                ->where('atividade_id', $atividade->id)
                ->count()
        );
    }

    public function test_f_desvincular_remove_so_aquele_vinculo(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();

        $componente = $this->componente()->set('obraId', $this->obra->id)->call('abrirRevisoes', $documento->id);
        $componente->call('vincularAtividade', $atividadeA->id);
        $componente->call('vincularAtividade', $atividadeB->id);

        $componente->call('desvincularAtividade', $atividadeA->id);

        $this->assertDatabaseMissing('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividadeA->id,
        ]);
    }

    public function test_g_outro_vinculo_permanece(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $atividadeA = $this->criarAtividade();
        $atividadeB = $this->criarAtividade();

        $componente = $this->componente()->set('obraId', $this->obra->id)->call('abrirRevisoes', $documento->id);
        $componente->call('vincularAtividade', $atividadeA->id);
        $componente->call('vincularAtividade', $atividadeB->id);

        $componente->call('desvincularAtividade', $atividadeA->id);

        $this->assertDatabaseHas('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividadeB->id,
        ]);
        $this->assertCount(1, $documento->atividades()->get());
    }

    // ===================== H-I, Q-R: isolamento cross-obra/tenant =====================

    /**
     * H + R juntos — "cross-obra bloqueado" e "tentativa manual via método
     * Livewire com ID de outra obra falha" são exatamente o mesmo cenário
     * (chamada direta do método Livewire, nunca confiando no <select> da
     * UI): documento de uma obra, atividade de OUTRA obra do MESMO tenant.
     *
     * Etapa 18.1.CORREÇÃO — mecanismo mudou (não a garantia de segurança):
     * antes, vincularAtividade() resolvia a Atividade só por tenant
     * (Atividade::findOrFail) e comparava manualmente
     * atividade.obra_id === documento.obra_id, resultando em 403
     * (abort_unless). Agora a Atividade é resolvida diretamente já
     * escopada à obra ATUAL do componente
     * (Atividade::where('obra_id', $this->obraId)->findOrFail(...)) — uma
     * atividade de outra obra simplesmente não existe nesse escopo, o que
     * gera ModelNotFoundException, mesmo padrão já usado pelo cenário
     * cross-tenant (teste I) — nunca criação de vínculo, nunca 500.
     */
    public function test_h_e_r_cross_obra_bloqueado_mesmo_via_chamada_livewire_direta(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $atividadeOutraObra = $this->criarAtividade($outraObra);

        try {
            $this->componente()
                ->set('obraId', $this->obra->id)
                ->call('abrirRevisoes', $documento->id)
                ->call('vincularAtividade', $atividadeOutraObra->id);
            $this->fail('Esperava ModelNotFoundException — atividade de outra obra não deveria resolver no escopo da obra atual.');
        } catch (ModelNotFoundException $e) {
            // esperado
        }

        $this->assertDatabaseMissing('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividadeOutraObra->id,
        ]);
    }

    public function test_i_cross_tenant_bloqueado(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $outroTenant = Tenant::factory()->create();
        // Criada DENTRO do TenantContext do outro tenant — BelongsToTenant
        // ignora qualquer tenant_id explícito e usa sempre o tenant do
        // usuário autenticado (aqui, ainda $this->user) se não fizermos
        // isso; mesma lição já documentada no Ciclo 17 (Score Etapa 4).
        $atividadeOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);

            return $this->criarAtividade($outraObra);
        });

        $documento = $this->criarDocumento();

        try {
            $this->componente()
                ->set('obraId', $this->obra->id)
                ->call('abrirRevisoes', $documento->id)
                ->call('vincularAtividade', $atividadeOutroTenant->id);
            $this->fail('Esperava ModelNotFoundException (atividade de outro tenant nunca deveria resolver).');
        } catch (ModelNotFoundException $e) {
            // esperado — BelongsToTenant já filtra antes de qualquer checagem de obra.
        }

        $this->assertDatabaseMissing('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
        ]);
    }

    public function test_q_busca_de_atividades_nao_oferece_outra_obra(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->criarAtividade($outraObra, ['nome' => 'Fundação Bloco X']);
        $atividadeMesmaObra = $this->criarAtividade($this->obra, ['nome' => 'Fundação Bloco A']);

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->set('buscaAtividadeVincular', 'Fundação');

        $resultados = $componente->instance()->atividadesParaVincular;

        $this->assertCount(1, $resultados);
        $this->assertEquals($atividadeMesmaObra->id, $resultados->first()->id);
    }

    // ===================== J-L: autorização =====================

    public function test_j_usuario_sem_editar_nao_vincula(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $atividade = $this->criarAtividade();

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->call('vincularAtividade', $atividade->id)
            ->assertForbidden();

        $this->assertDatabaseMissing('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividade->id,
        ]);
    }

    public function test_k_usuario_sem_editar_nao_desvincula(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $atividade = $this->criarAtividade();
        $documento->atividades()->attach($atividade->id);

        $outroUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $outroUser, Papel::Encarregado->value);
        $this->actingAs($outroUser);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->call('desvincularAtividade', $atividade->id)
            ->assertForbidden();

        $this->assertDatabaseHas('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividade->id,
        ]);
    }

    public function test_l_leitura_com_permissao_funciona(): void
    {
        // Encarregado tem 'ver' (todo perfil padrão vê tudo) mas não 'editar'.
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $atividade = $this->criarAtividade();
        $documento->atividades()->attach($atividade->id);

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id);

        $componente->assertOk();
        $this->assertCount(1, $componente->instance()->atividadesVinculadas);
    }

    // ===================== M-O: reimportação, WBS, arquivamento =====================

    /**
     * Teste crítico — usa o MsProjectImporter REAL (nunca simulado), com
     * a MESMA fixture reimportada (mesmo external_uid=2), confirmando que
     * a Atividade preserva sua PK e o vínculo sobrevive automaticamente,
     * mesmo a reimportação trazendo dados diferentes (baseline/datas —
     * ver cronograma_v2.xml, já usado por CronogramaImportacaoTest para
     * o mesmo propósito).
     */
    public function test_m_reimportacao_real_preserva_vinculo_e_pk(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $importer = app(ImportadorCronograma::class);
        $plano = $importer->analisar(__DIR__ . '/../Fixtures/cronograma_sample.xml', $this->obra);
        $importer->aplicar($plano, $this->obra, $this->user->id, 'sample.xml');

        $atividade = Atividade::where('obra_id', $this->obra->id)
            ->where('external_uid', '2')
            ->where('origem', OrigemAtividade::MsProject)
            ->firstOrFail();
        $pkOriginal = $atividade->id;

        $documento = $this->criarDocumento();
        $documento->atividades()->attach($atividade->id);

        $planoV2 = $importer->analisar(__DIR__ . '/../Fixtures/cronograma_v2.xml', $this->obra);
        $importer->aplicar($planoV2, $this->obra, $this->user->id, 'v2.xml');

        $atividade->refresh();
        $this->assertEquals($pkOriginal, $atividade->id, 'A PK da Atividade deve permanecer a mesma após reimportação (mesmo external_uid).');
        $this->assertDatabaseHas('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $pkOriginal,
        ]);
        $this->assertCount(1, $documento->atividades()->get());
        $this->assertEquals($pkOriginal, $documento->atividades()->first()->id);
    }

    /**
     * N — o vínculo é indexado por atividade_id, nunca por codigo_cronograma
     * (equivalente de "WBS" que a Atividade de fato possui) — simula uma
     * reorganização de WBS feita entre importações e confirma que o
     * vínculo (já criado antes da mudança) continua resolvendo a mesma
     * atividade corretamente, sem nenhuma dependência do valor antigo/novo
     * de codigo_cronograma.
     */
    public function test_n_mudanca_de_codigo_cronograma_wbs_preserva_vinculo(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $atividade = $this->criarAtividade($this->obra, ['codigo_cronograma' => '1.1']);
        $documento = $this->criarDocumento();
        $documento->atividades()->attach($atividade->id);

        $atividade->update(['codigo_cronograma' => '2.5']);

        $this->assertDatabaseHas('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividade->id,
        ]);
        $this->assertCount(1, $documento->fresh()->atividades()->get());
        $this->assertEquals('2.5', $documento->atividades()->first()->codigo_cronograma);
    }

    public function test_o_atividade_arquivada_preserva_vinculo(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $atividade = $this->criarAtividade();
        $documento = $this->criarDocumento();
        $documento->atividades()->attach($atividade->id);

        $atividade->update(['fora_do_cronograma' => true]);

        $componente = $this->componente()->set('obraId', $this->obra->id)->call('abrirRevisoes', $documento->id);
        $vinculadas = $componente->instance()->atividadesVinculadas;

        $this->assertCount(1, $vinculadas, 'Vínculo já existente com atividade arquivada deve continuar visível.');
        $this->assertTrue($vinculadas->first()->fora_do_cronograma);
    }

    // ===================== P: UI =====================

    public function test_p_ui_mostra_os_vinculos_corretos(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $atividade = $this->criarAtividade($this->obra, ['codigo_cronograma' => '3.2', 'nome' => 'Instalação Elétrica Bloco B']);
        $documento->atividades()->attach($atividade->id);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->assertSee('3.2')
            ->assertSee('Instalação Elétrica Bloco B');
    }

    // ===================== S: performance/N+1 =====================

    public function test_s_listagem_principal_nao_gera_queries_de_vinculo_por_documento(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        // 5 documentos, cada um com 3 atividades vinculadas — a listagem
        // principal (aba "lista") NUNCA exibe/carrega atividades, então o
        // número de documentos/vínculos não deve influenciar a contagem
        // de queries do render principal.
        for ($i = 0; $i < 5; $i++) {
            $documento = $this->criarDocumento();
            $atividades = Atividade::factory()->count(3)->create([
                'tenant_id' => $this->tenant->id,
                'obra_id' => $this->obra->id,
                'fora_do_cronograma' => false,
            ]);
            $documento->atividades()->attach($atividades->pluck('id'));
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->componente()->set('obraId', $this->obra->id);

        $this->assertLessThan(
            40,
            $queries,
            "Render da listagem gerou {$queries} queries — suspeita de N+1 relacionado a vínculos de Engenharia."
        );
    }

    public function test_s_modal_com_muitas_atividades_vinculadas_usa_query_unica(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento();
        $atividades = Atividade::factory()->count(20)->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
        ]);
        $documento->atividades()->attach($atividades->pluck('id'));

        $queriesAntes = 0;
        DB::listen(function () use (&$queriesAntes) {
            $queriesAntes++;
        });

        $componente = $this->componente()->set('obraId', $this->obra->id)->call('abrirRevisoes', $documento->id);
        $vinculadas = $componente->instance()->atividadesVinculadas;

        $this->assertCount(20, $vinculadas);
        // Teto generoso o bastante pra não quebrar por overhead normal de
        // auth/mount do Livewire, baixo o bastante pra pegar N+1 real (se
        // atividadesVinculadas() virasse 1 query por atividade, 20
        // atividades por si só já estourariam esse teto).
        $this->assertLessThan(60, $queriesAntes, "Abrir o modal com 20 atividades vinculadas gerou {$queriesAntes} queries — suspeita de N+1.");
    }

    // =====================================================================
    // ETAPA 18.1.CORREÇÃO — regressões A-V dos exploits da auditoria
    // adversarial (NÃO APROVAR 18.1: C1 autorização contextual de escrita,
    // C2 desvincular sem validação de obra, C3 leitura cross-obra). Letras
    // seguem a numeração do pedido de correção — algumas se sobrepõem
    // parcialmente aos testes A-S originais (N/O/P/R/S/T), mantidas aqui
    // explícitas mesmo assim, por pedido: "adicionar testes permanentes
    // específicos para TODOS os exploits descobertos".
    // =====================================================================

    public function test_correcao_a_editar_apenas_obra_b_nao_vincula_em_a(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value); // editar SÓ na obra B — zero perfil na A
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $atividade = $this->criarAtividade($this->obra);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->assertForbidden();

        $this->assertDatabaseMissing('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividade->id,
        ]);
    }

    public function test_correcao_b_editar_apenas_obra_b_nao_desvincula_em_a(): void
    {
        // Vínculo criado por um Admin de verdade da obra A (ação legítima anterior).
        $adminA = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $adminA, Papel::Admin->value);
        $documento = $this->criarDocumento($this->obra);
        $atividade = $this->criarAtividade($this->obra);
        $this->actingAs($adminA);
        $documento->atividades()->attach($atividade->id);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value); // editar SÓ na obra B
        $this->actingAs($this->user);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->assertForbidden();

        $this->assertDatabaseHas('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividade->id,
        ]);
    }

    public function test_correcao_c_ver_apenas_obra_b_nao_abre_documento_a(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Encarregado->value); // só 'ver', só na obra B
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->assertForbidden();
    }

    public function test_correcao_d_acesso_nas_duas_obras_abrirrevisoes_documento_b_bloqueado_por_contexto(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value); // editar nas DUAS
        $this->actingAs($this->user);

        $documentoB = $this->criarDocumento($outraObra);

        $excecao = null;
        try {
            $this->componente()
                ->set('obraId', $this->obra->id) // componente contextualizado em A
                ->call('abrirRevisoes', $documentoB->id); // documento é da B
        } catch (ModelNotFoundException $e) {
            $excecao = $e;
        }

        $this->assertInstanceOf(ModelNotFoundException::class, $excecao, 'Esperava ModelNotFoundException — mesmo com permissão nas duas obras, o contexto é A.');
    }

    public function test_correcao_e_manipulacao_direta_documentorevisoesid_nao_vaza_nada(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documentoB = $this->criarDocumento($outraObra);
        $atividadeB = $this->criarAtividade($outraObra);
        $documentoB->atividades()->attach($atividadeB->id);

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->set('documentoRevisoesId', $documentoB->id); // manipulação direta, nunca via abrirRevisoes()

        $this->assertNull($componente->instance()->documentoRevisoes, 'documentoRevisoes não deve materializar Documento de outra obra.');
        $this->assertCount(0, $componente->instance()->atividadesVinculadas, 'atividadesVinculadas não deve vazar nada de outra obra.');
    }

    public function test_correcao_f_documento_b_atividade_b_componente_a_vincular_bloqueado_mesmo_com_editar_nas_duas(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documentoB = $this->criarDocumento($outraObra);
        $atividadeB = $this->criarAtividade($outraObra);

        try {
            $this->componente()
                ->set('obraId', $this->obra->id)
                ->set('documentoRevisoesId', $documentoB->id)
                ->call('vincularAtividade', $atividadeB->id);
            $this->fail('Esperava exceção — Documento e Atividade da obra B não podem ser vinculados com o componente em A.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // esperado (404 do resolverDocumentoDaObraAtual, já que documentoRevisoesId
            // aponta pra um Documento fora do escopo obra_id = $this->obraId)
        }

        $this->assertDatabaseMissing('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documentoB->id,
            'atividade_id' => $atividadeB->id,
        ]);
    }

    public function test_correcao_g_vinculo_real_documento_b_atividade_b_desvincular_bloqueado_mesmo_com_editar_nas_duas(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documentoB = $this->criarDocumento($outraObra);
        $atividadeB = $this->criarAtividade($outraObra);
        $documentoB->atividades()->attach($atividadeB->id);

        try {
            $this->componente()
                ->set('obraId', $this->obra->id)
                ->set('documentoRevisoesId', $documentoB->id)
                ->call('desvincularAtividade', $atividadeB->id);
            $this->fail('Esperava exceção — vínculo real da obra B não pode ser desfeito com o componente em A.');
        } catch (ModelNotFoundException $e) {
            // esperado
        }

        $this->assertDatabaseHas('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documentoB->id,
            'atividade_id' => $atividadeB->id,
        ]);
    }

    public function test_correcao_h_manipulacao_direta_documentorevisoesid_desvincular_bloqueada(): void
    {
        // Mesmo cenário de G, mas explicitamente enfatizando a manipulação
        // direta de propriedade (já coberta em G também, mantida separada
        // por pedido explícito da correção).
        $this->test_correcao_g_vinculo_real_documento_b_atividade_b_desvincular_bloqueado_mesmo_com_editar_nas_duas();
    }

    public function test_correcao_i_cross_tenant_vinculo_bloqueado(): void
    {
        // Já coberto por test_i_cross_tenant_bloqueado() — mantido aqui
        // como referência explícita pedida pela correção, sem duplicar lógica.
        $this->test_i_cross_tenant_bloqueado();
    }

    public function test_correcao_j_cross_tenant_desvinculo_bloqueado(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);

        $outroTenant = Tenant::factory()->create();
        $atividadeOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $doc = DocumentoEngenharia::create([
                'tenant_id' => $outroTenant->id,
                'obra_id' => $outraObra->id,
                'codigo' => 'DOC-OUTRO-TENANT',
                'descricao' => 'x',
            ]);
            $at = $this->criarAtividade($outraObra);
            $doc->atividades()->attach($at->id);

            return $at;
        });

        $excecao = null;
        try {
            $this->componente()
                ->set('obraId', $this->obra->id)
                ->call('abrirRevisoes', $documento->id)
                ->call('desvincularAtividade', $atividadeOutroTenant->id);
        } catch (ModelNotFoundException $e) {
            $excecao = $e;
        }

        $this->assertInstanceOf(ModelNotFoundException::class, $excecao, 'Esperava ModelNotFoundException — atividade de outro tenant nunca deveria resolver.');
    }

    public function test_correcao_k_readonly_obra_a_pode_ver_documento_e_vinculos(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value); // só 'ver', na obra CERTA
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $atividade = $this->criarAtividade($this->obra);
        $documento->atividades()->attach($atividade->id);

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id);

        $componente->assertOk();
        $this->assertNotNull($componente->instance()->documentoRevisoes);
        $this->assertCount(1, $componente->instance()->atividadesVinculadas);
    }

    public function test_correcao_l_readonly_obra_a_nao_vincula(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $atividade = $this->criarAtividade($this->obra);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->call('vincularAtividade', $atividade->id)
            ->assertForbidden();
    }

    public function test_correcao_m_readonly_obra_a_nao_desvincula(): void
    {
        $adminA = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $adminA, Papel::Admin->value);
        $documento = $this->criarDocumento($this->obra);
        $atividade = $this->criarAtividade($this->obra);
        $this->actingAs($adminA);
        $documento->atividades()->attach($atividade->id);

        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->call('desvincularAtividade', $atividade->id)
            ->assertForbidden();

        $this->assertDatabaseHas('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividade->id,
        ]);
    }

    public function test_correcao_n_editar_obra_a_vinculo_legitimo_funciona(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $atividade = $this->criarAtividade($this->obra);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->call('vincularAtividade', $atividade->id);

        $this->assertDatabaseHas('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividade->id,
        ]);
    }

    public function test_correcao_o_editar_obra_a_desvinculo_legitimo_funciona(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $atividade = $this->criarAtividade($this->obra);
        $documento->atividades()->attach($atividade->id);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->call('desvincularAtividade', $atividade->id);

        $this->assertDatabaseMissing('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividade->id,
        ]);
    }

    public function test_correcao_p_atividade_arquivada_busca_nao_oferece(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $arquivada = $this->criarAtividade($this->obra, ['nome' => 'Arquivada Teste', 'fora_do_cronograma' => true]);

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->set('buscaAtividadeVincular', 'Arquivada');

        $this->assertCount(0, $componente->instance()->atividadesParaVincular);
    }

    public function test_correcao_q_atividade_arquivada_chamada_manual_vincular_tambem_bloqueia(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $arquivada = $this->criarAtividade($this->obra, ['fora_do_cronograma' => true]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->call('vincularAtividade', $arquivada->id)
            ->assertForbidden();

        $this->assertDatabaseMissing('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $arquivada->id,
        ]);
    }

    public function test_correcao_r_vinculo_ativo_depois_arquivada_permanece(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $atividade = $this->criarAtividade($this->obra);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->call('vincularAtividade', $atividade->id);

        $atividade->update(['fora_do_cronograma' => true]);

        $this->assertDatabaseHas('documento_engenharia_atividades', [
            'documento_engenharia_id' => $documento->id,
            'atividade_id' => $atividade->id,
        ]);
    }

    public function test_correcao_s_remover_um_vinculo_nao_afeta_outros(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $doc1 = $this->criarDocumento($this->obra);
        $doc2 = $this->criarDocumento($this->obra);
        $a1 = $this->criarAtividade($this->obra);
        $a2 = $this->criarAtividade($this->obra);

        $doc1->atividades()->attach([$a1->id, $a2->id]);
        $doc2->atividades()->attach([$a1->id]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $doc1->id)
            ->call('desvincularAtividade', $a1->id);

        $this->assertDatabaseMissing('documento_engenharia_atividades', ['documento_engenharia_id' => $doc1->id, 'atividade_id' => $a1->id]);
        $this->assertDatabaseHas('documento_engenharia_atividades', ['documento_engenharia_id' => $doc1->id, 'atividade_id' => $a2->id]);
        $this->assertDatabaseHas('documento_engenharia_atividades', ['documento_engenharia_id' => $doc2->id, 'atividade_id' => $a1->id]);
    }

    public function test_correcao_t_ciclo_completo_mesma_instancia(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $atividade = $this->criarAtividade($this->obra);

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id);

        $this->assertCount(0, $componente->instance()->atividadesVinculadas);
        $componente->call('vincularAtividade', $atividade->id);
        $this->assertCount(1, $componente->instance()->atividadesVinculadas);
        $componente->call('desvincularAtividade', $atividade->id);
        $this->assertCount(0, $componente->instance()->atividadesVinculadas);
    }

    public function test_correcao_u_trocar_de_documento_reseta_estado_de_busca(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $doc1 = $this->criarDocumento($this->obra);
        $doc2 = $this->criarDocumento($this->obra);
        $this->criarAtividade($this->obra, ['nome' => 'Termo Exclusivo Doc1']);

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $doc1->id)
            ->set('buscaAtividadeVincular', 'Termo Exclusivo Doc1');

        $this->assertCount(1, $componente->instance()->atividadesParaVincular);

        $componente->call('abrirRevisoes', $doc2->id);

        $this->assertSame('', $componente->get('buscaAtividadeVincular'), 'busca deveria ser resetada ao trocar de documento');
        $this->assertCount(0, $componente->instance()->atividadesParaVincular, 'busca antiga não pode contaminar o novo documento');
    }

    public function test_correcao_v_usuario_sem_nenhuma_permissao_engenharia_nao_le_via_manipulacao_direta(): void
    {
        // Usuário sem NENHUM perfil em NENHUMA obra (só existe no tenant).
        $this->actingAs($this->user);

        $documento = $this->criarDocumento($this->obra);
        $atividade = $this->criarAtividade($this->obra);
        $documento->atividades()->attach($atividade->id);

        $componente = $this->componente()
            ->set('obraId', $this->obra->id)
            ->set('documentoRevisoesId', $documento->id); // manipulação direta, sem nunca ter passado por abrirRevisoes()

        $this->assertNull($componente->instance()->documentoRevisoes);
        $this->assertCount(0, $componente->instance()->atividadesVinculadas);
    }
}
