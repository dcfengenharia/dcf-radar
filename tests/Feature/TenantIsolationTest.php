<?php

namespace Tests\Feature;

use App\Models\Assinatura;
use App\Models\AssinaturaFatura;
use App\Models\Atividade;
use App\Models\AtividadeAnexo;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeSnapshot;
use App\Models\Client;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaAtividade;
use App\Models\DocumentoEngenhariaReprogramacao;
use App\Models\Feedback;
use App\Models\FamiliaMaterial;
use App\Models\Feriado;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\Grd;
use App\Models\GrdDestinatario;
use App\Models\GrdDistribuicao;
use App\Models\GrdItem;
use App\Models\GrdAlertaEntrega;
use App\Models\GrdRecolhimento;
use App\Models\CronogramaImportacao;
use App\Models\ItemProntidao;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\LinhaBase;
use App\Models\Plano;
use App\Models\ProgramacaoSemanal;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Report;
use App\Models\ReportIndicadorSemana;
use App\Models\Restricao;
use App\Models\RevisaoLiberacao;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithUser(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        return [$tenant, $user];
    }

    public function test_client_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        // Criado sem auth → tenant_id do factory é preservado
        $clientB = Client::factory()->for($tenantB)->create();

        $this->actingAs($userA);

        $this->assertNull(Client::find($clientB->id));
    }

    public function test_client_collection_excludes_other_tenant_records(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $clientA = Client::factory()->for($tenantA)->create();
        $clientB = Client::factory()->for($tenantB)->create();

        $this->actingAs($userA);

        $ids = Client::pluck('id')->toArray();

        $this->assertContains($clientA->id, $ids);
        $this->assertNotContains($clientB->id, $ids);
    }

    public function test_work_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $clientB = Client::factory()->for($tenantB)->create();
        $workB = Work::factory()->for($tenantB)->for($clientB)->create();

        $this->actingAs($userA);

        $this->assertNull(Work::find($workB->id));
    }

    public function test_work_collection_excludes_other_tenant_records(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $clientA = Client::factory()->for($tenantA)->create();
        $workA = Work::factory()->for($tenantA)->for($clientA)->create();

        $clientB = Client::factory()->for($tenantB)->create();
        $workB = Work::factory()->for($tenantB)->for($clientB)->create();

        $this->actingAs($userA);

        $ids = Work::pluck('id')->toArray();

        $this->assertContains($workA->id, $ids);
        $this->assertNotContains($workB->id, $ids);
    }

    public function test_tenant_context_acting_as_allows_cross_tenant_query_in_platform_commands(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $clientB = Client::factory()->for($tenantB)->create();

        $this->actingAs($userA);

        $found = TenantContext::actingAs($tenantB, fn () => Client::find($clientB->id));

        $this->assertNotNull($found);
        $this->assertEquals($clientB->id, $found->id);
    }

    public function test_creating_with_auth_stamps_tenant_id_from_context(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();

        $this->actingAs($userA);

        $client = Client::create(['name' => 'Teste']);

        $this->assertEquals($tenantA->id, $client->tenant_id);
    }

    public function test_creating_cannot_override_tenant_id_when_authenticated(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $this->actingAs($userA);

        // Mesmo que passe tenant_id de outro tenant, o trait sobrescreve
        $client = Client::create(['name' => 'Invasão', 'tenant_id' => $tenantB->id]);

        $this->assertEquals($tenantA->id, $client->tenant_id);
    }

    public function test_programacao_semanal_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $programacaoB = ProgramacaoSemanal::create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
            'semana_inicio' => now()->startOfWeek()->toDateString(),
            'semana_fim' => now()->endOfWeek()->toDateString(),
            'congelada_em' => now(),
        ]);

        $this->actingAs($userA);

        $this->assertNull(ProgramacaoSemanal::find($programacaoB->id));
    }

    public function test_programacao_semanal_item_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id]);
        $programacaoB = ProgramacaoSemanal::create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
            'semana_inicio' => now()->startOfWeek()->toDateString(),
            'semana_fim' => now()->endOfWeek()->toDateString(),
            'congelada_em' => now(),
        ]);
        $itemB = ProgramacaoSemanalItem::create([
            'tenant_id' => $tenantB->id,
            'programacao_semanal_id' => $programacaoB->id,
            'atividade_id' => $atividadeB->id,
            'origem' => 'manual',
        ]);

        $this->actingAs($userA);

        $this->assertNull(ProgramacaoSemanalItem::find($itemB->id));
    }

    /**
     * Guarda de regressão pro PPC do Relatório de Restrições
     * (⚡relatorios-restricoes.blade.php::ppcPorSemana) — a query junta
     * ProgramacaoSemanalItem + programacoes_semanais + atividades, então
     * confirma que nenhuma dessas três tabelas vaza entre tenants nesse
     * caminho específico (join com ps.obra_id, não só o scope padrão).
     */
    public function test_ppc_por_semana_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraA = Work::factory()->create(['tenant_id' => $tenantA->id]);
        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);

        $semanaInicio = now()->subWeeks(2)->startOfWeek();
        $semanaFim = $semanaInicio->copy()->endOfWeek();

        $atividadeB = Atividade::factory()->create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
            'status' => 'concluido',
            'concluido_em' => $semanaInicio->copy()->addDay(),
        ]);
        $programacaoB = ProgramacaoSemanal::create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
            'semana_inicio' => $semanaInicio->toDateString(),
            'semana_fim' => $semanaFim->toDateString(),
            'congelada_em' => now(),
        ]);
        ProgramacaoSemanalItem::create([
            'tenant_id' => $tenantB->id,
            'programacao_semanal_id' => $programacaoB->id,
            'atividade_id' => $atividadeB->id,
            'origem' => 'manual',
        ]);

        $this->actingAs($userA);

        $ppc = Livewire::test('pages::radar.relatorios-restricoes', ['obra' => $obraA])
            ->set('filtroDataInicio', $semanaInicio->copy()->subWeek()->toDateString())
            ->set('filtroDataFim', $semanaInicio->copy()->addWeek()->toDateString())
            ->instance()->ppcPorSemana;

        $this->assertCount(0, $ppc);
    }

    public function test_fornecedor_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $fornecedorB = Fornecedor::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Fornecedor B']);

        $this->actingAs($userA);

        $this->assertNull(Fornecedor::find($fornecedorB->id));
    }

    public function test_feriado_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $feriadoB = Feriado::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'data' => '2026-12-25']);

        $this->actingAs($userA);

        $this->assertNull(Feriado::find($feriadoB->id));
    }

    public function test_fluxo_suprimento_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $fluxoB = FluxoSuprimento::create(['tenant_id' => $tenantB->id, 'nome' => 'Fluxo B']);

        $this->actingAs($userA);

        $this->assertNull(FluxoSuprimento::find($fluxoB->id));
    }

    public function test_item_suprimento_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $itemB = ItemSuprimento::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Item B']);

        $this->actingAs($userA);

        $this->assertNull(ItemSuprimento::find($itemB->id));
    }

    public function test_documento_engenharia_reprogramacao_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $documentoB = DocumentoEngenharia::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'codigo' => 'DOC-B', 'descricao' => 'Documento B']);
        $reprogramacaoB = DocumentoEngenhariaReprogramacao::create([
            'tenant_id' => $tenantB->id,
            'documento_engenharia_id' => $documentoB->id,
            'data_anterior' => '2026-07-01',
            'data_nova' => '2026-08-01',
        ]);

        $this->actingAs($userA);

        $this->assertNull(DocumentoEngenhariaReprogramacao::find($reprogramacaoB->id));
    }

    /**
     * Guarda de regressão pro Relatório de Restrições (⚡relatorios-restricoes.blade.php):
     * a query base do relatório usa join() em vez de whereHas() — confirma que o
     * JOIN não contorna o global scope de tenant do Restricao.
     */
    public function test_restricao_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id]);
        $restricaoB = Restricao::factory()->create(['tenant_id' => $tenantB->id, 'atividade_id' => $atividadeB->id]);

        $this->actingAs($userA);

        $this->assertNull(Restricao::find($restricaoB->id));

        $idsViaJoin = Restricao::join('atividades as a', 'a.id', '=', 'restricoes.atividade_id')
            ->select('restricoes.*')
            ->pluck('restricoes.id');

        $this->assertNotContains($restricaoB->id, $idsViaJoin);
    }

    public function test_atividade_item_prontidao_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id]);
        $itemB = ItemProntidao::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Item B', 'ordem' => 1]);
        $registroB = AtividadeItemProntidao::create([
            'tenant_id' => $tenantB->id,
            'atividade_id' => $atividadeB->id,
            'item_prontidao_id' => $itemB->id,
            'concluido' => false,
        ]);

        $this->actingAs($userA);

        $this->assertNull(AtividadeItemProntidao::find($registroB->id));
    }

    /**
     * Assinatura/AssinaturaFatura são de propósito as ÚNICAS tabelas do
     * projeto SEM BelongsToTenant (ver comentário em App\Models\Assinatura)
     * — o dono da plataforma precisa enxergar todos os tenants ao mesmo
     * tempo. Isso significa que NENHUM scope global protege essas tabelas:
     * toda tela do TENANT (ex.: ⚡empresa/assinatura.blade.php) precisa
     * filtrar `tenant_id` manualmente. Este teste documenta o risco
     * explicitamente — confirma que o comportamento "sem scope" é real
     * (pra nunca presumir engano se aparecer dado de outro tenant numa
     * query direta) e que o padrão de filtro manual (`where('tenant_id', ...)`)
     * é o único jeito seguro de consultar essas tabelas numa tela do tenant.
     */
    public function test_assinatura_e_assinatura_fatura_nao_tem_scope_automatico_de_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $planoA = Plano::factory()->create();
        $planoB = Plano::factory()->create();

        $assinaturaA = Assinatura::factory()->create(['tenant_id' => $tenantA->id, 'plano_id' => $planoA->id]);
        $assinaturaB = Assinatura::factory()->create(['tenant_id' => $tenantB->id, 'plano_id' => $planoB->id]);

        $faturaB = AssinaturaFatura::factory()->create([
            'tenant_id' => $tenantB->id,
            'assinatura_id' => $assinaturaB->id,
        ]);

        $this->actingAs($userA);

        // Confirma a ausência de scope: mesmo autenticado como tenant A,
        // uma query direta (sem filtro manual) SEMPRE enxerga o registro
        // do tenant B — nunca presumir que isso é bug, é o design.
        $this->assertNotNull(Assinatura::find($assinaturaB->id));
        $this->assertNotNull(AssinaturaFatura::find($faturaB->id));

        // O único jeito seguro de consultar essas tabelas numa tela do
        // TENANT é filtrando tenant_id manualmente — confirma que esse
        // padrão exclui corretamente o dado de outro tenant.
        $faturasFiltradas = AssinaturaFatura::where('tenant_id', $tenantA->id)->pluck('id');
        $this->assertNotContains($faturaB->id, $faturasFiltradas);

        $assinaturasFiltradas = Assinatura::where('tenant_id', $tenantA->id)->pluck('id');
        $this->assertContains($assinaturaA->id, $assinaturasFiltradas);
        $this->assertNotContains($assinaturaB->id, $assinaturasFiltradas);
    }

    public function test_feedback_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB, $userB] = $this->makeTenantWithUser();

        $feedbackB = Feedback::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $userB->id,
            'tipo' => 'erro',
            'mensagem' => 'Mensagem do tenant B',
        ]);

        $this->actingAs($userA);

        $this->assertNull(Feedback::find($feedbackB->id));
    }

    public function test_report_indicador_semana_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $importacaoB = \App\Models\CronogramaImportacao::create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
            'data_status' => now(),
            'importado_em' => now(),
        ]);
        $reportB = Report::factory()->create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
            'cronograma_importacao_id' => $importacaoB->id,
        ]);
        $indicadorB = ReportIndicadorSemana::factory()->create([
            'tenant_id' => $tenantB->id,
            'report_id' => $reportB->id,
        ]);

        $this->actingAs($userA);

        $this->assertNull(ReportIndicadorSemana::find($indicadorB->id));
    }

    public function test_atividade_anexo_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $atividadeB = Atividade::factory()->create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
        ]);
        $anexoB = AtividadeAnexo::create([
            'tenant_id' => $tenantB->id,
            'atividade_id' => $atividadeB->id,
            'nome_original' => 'documento.pdf',
            'caminho_arquivo' => 'lookahead-anexos/fake-obra/fake-atividade/fake.pdf',
            'mime_type' => 'application/pdf',
            'tamanho_bytes' => 1024,
        ]);

        $this->actingAs($userA);

        $this->assertNull(AtividadeAnexo::find($anexoB->id));
    }

    /** Ciclo 18, Etapa 18.1.CORREÇÃO — prova estrutural do global scope no pivô Documento de Engenharia ↔ Atividade. */
    public function test_documento_engenharia_atividade_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $atividadeB = Atividade::factory()->create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
        ]);
        $documentoB = DocumentoEngenharia::create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
            'codigo' => 'DOC-TENANT-B',
            'descricao' => 'Documento do tenant B',
        ]);
        // TenantContext::actingAs necessário: sem usuário autenticado nem
        // contexto de tenant ativo, BelongsToTenant não estampa tenant_id
        // no pivô (que só é estampado quando há TenantContext::currentId()
        // resolvível — mesma lição já documentada no Ciclo 17, Score Etapa 4).
        TenantContext::actingAs($tenantB, function () use ($documentoB, $atividadeB) {
            $documentoB->atividades()->syncWithoutDetaching([$atividadeB->id]);
        });
        $vinculoB = DocumentoEngenhariaAtividade::where('documento_engenharia_id', $documentoB->id)
            ->where('atividade_id', $atividadeB->id)
            ->firstOrFail();

        $this->actingAs($userA);

        $this->assertNull(DocumentoEngenhariaAtividade::find($vinculoB->id));
    }

    /** Ciclo 18, Etapa 18.3 — prova estrutural do global scope no histórico de liberação de revisão. */
    public function test_revisao_liberacao_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $eventoB = TenantContext::actingAs($tenantB, function () use ($tenantB) {
            $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
            $documentoB = DocumentoEngenharia::create([
                'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'codigo' => 'DOC-TENANT-B-LIB', 'descricao' => 'x',
            ]);
            $revisaoB = $documentoB->revisoes()->create(['tenant_id' => $tenantB->id, 'revisao' => 'R0', 'descricao' => 'x']);

            return $revisaoB->historicoLiberacoes()->create([
                'tenant_id' => $tenantB->id,
                'liberada_para_construcao' => true,
                'ocorrido_em' => now(),
            ]);
        });

        $this->actingAs($userA);

        $this->assertNull(RevisaoLiberacao::find($eventoB->id));
    }

    /**
     * Ciclo 17, correção pós-QA (recriação de Linha de Base) — a checagem
     * nova de salvar() usa onlyTrashed()/withTrashed() pra localizar uma
     * LinhaBase excluída da MESMA importação antes de recriar; nenhuma
     * dessas variantes pode enxergar a linha de outro tenant, mesmo já
     * soft-deleted.
     */
    public function test_linha_base_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $importacaoB = CronogramaImportacao::create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'importado_em' => now(),
        ]);
        $linhaBaseB = LinhaBase::create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
            'nome' => 'Baseline B',
            'cronograma_importacao_id' => $importacaoB->id,
        ]);
        $linhaBaseB->delete();

        $this->actingAs($userA);

        $this->assertNull(LinhaBase::find($linhaBaseB->id));
        $this->assertNull(LinhaBase::onlyTrashed()->find($linhaBaseB->id));
        $this->assertNull(LinhaBase::withTrashed()->find($linhaBaseB->id));
    }

    /** Ciclo 17, A.9.2 — Fotografia F: percentual_concluido/real_inicio/real_termino em atividade_snapshots seguem o mesmo scope automático das demais colunas. */
    public function test_atividade_snapshot_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $importacaoB = CronogramaImportacao::create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'importado_em' => now(),
        ]);
        $atividadeB = Atividade::factory()->create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
        ]);
        $snapshotB = AtividadeSnapshot::create([
            'tenant_id' => $tenantB->id,
            'cronograma_importacao_id' => $importacaoB->id,
            'atividade_id' => $atividadeB->id,
            'percentual_concluido' => 55,
            'real_inicio' => now()->subDays(3),
        ]);

        $this->actingAs($userA);

        $this->assertNull(AtividadeSnapshot::find($snapshotB->id));
    }

    /**
     * Ciclo 18, Etapa 18.5.1 — prova estrutural do global scope nas 6
     * tabelas novas do domínio de GRD (Destinatario/Grd/GrdItem/
     * GrdDestinatario/GrdDistribuicao/GrdRecolhimento).
     */
    public function test_grd_dominio_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $ids = TenantContext::actingAs($tenantB, function () use ($tenantB) {
            $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
            $documentoB = DocumentoEngenharia::create([
                'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'codigo' => 'DOC-TENANT-B-GRD', 'descricao' => 'x',
            ]);
            $revisaoB = $documentoB->revisoes()->create(['tenant_id' => $tenantB->id, 'revisao' => 'R0', 'descricao' => 'x']);

            $destinatarioB = Destinatario::create([
                'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Fulano B',
            ]);
            $grdB = Grd::create([
                'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'status' => 'rascunho',
            ]);
            $itemB = GrdItem::create([
                'tenant_id' => $tenantB->id, 'grd_id' => $grdB->id, 'documento_engenharia_revisao_id' => $revisaoB->id,
            ]);
            $grdDestinatarioB = GrdDestinatario::create([
                'tenant_id' => $tenantB->id, 'grd_id' => $grdB->id, 'destinatario_id' => $destinatarioB->id,
            ]);
            $distribuicaoB = GrdDistribuicao::create([
                'tenant_id' => $tenantB->id, 'grd_item_id' => $itemB->id, 'grd_destinatario_id' => $grdDestinatarioB->id, 'quantidade' => 1,
            ]);
            $recolhimentoB = GrdRecolhimento::create([
                'tenant_id' => $tenantB->id, 'grd_distribuicao_id' => $distribuicaoB->id,
                'resultado' => 'recolhido', 'quantidade' => 1, 'ocorrido_em' => now(),
            ]);
            $alertaEntregaB = GrdAlertaEntrega::create([
                'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'documento_engenharia_id' => $documentoB->id,
                'revisao_id' => $revisaoB->id, 'tipo_alerta' => 'copias_obsoletas',
                'evento_usuario_id' => (string) \Illuminate\Support\Str::uuid(), 'canal' => 'mail', 'enviado_em' => now(),
            ]);
            $aceiteEntregaB = \App\Models\GrdAceiteEntrega::create([
                'tenant_id' => $tenantB->id, 'grd_destinatario_id' => $grdDestinatarioB->id,
                'nome_recebedor_snapshot' => 'Fulano B', 'tipo_aceite' => 'sem_assinatura',
                'token' => \Illuminate\Support\Str::random(48), 'ocorrido_em' => now(), 'created_at' => now(),
            ]);

            return compact('destinatarioB', 'grdB', 'itemB', 'grdDestinatarioB', 'distribuicaoB', 'recolhimentoB', 'alertaEntregaB', 'aceiteEntregaB');
        });

        $this->actingAs($userA);

        $this->assertNull(Destinatario::find($ids['destinatarioB']->id));
        $this->assertNull(Grd::find($ids['grdB']->id));
        $this->assertNull(GrdItem::find($ids['itemB']->id));
        $this->assertNull(GrdDestinatario::find($ids['grdDestinatarioB']->id));
        $this->assertNull(GrdDistribuicao::find($ids['distribuicaoB']->id));
        $this->assertNull(GrdRecolhimento::find($ids['recolhimentoB']->id));
        $this->assertNull(GrdAlertaEntrega::find($ids['alertaEntregaB']->id));
        $this->assertNull(\App\Models\GrdAceiteEntrega::find($ids['aceiteEntregaB']->id));
    }

    /**
     * Ciclo 19, Etapa 19.1 — Take Off (LM/LI) e os 2 catálogos novos
     * (UnidadeMedida/FamiliaMaterial). ItemTakeOff não tem obra_id
     * próprio (só chega à obra via revisão -> documento), mesma cadeia
     * de dependência de GrdItem/DocumentoEngenhariaAtividade.
     */
    public function test_take_off_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $documentoB = DocumentoEngenharia::create([
            'tenant_id' => $tenantB->id,
            'obra_id' => $obraB->id,
            'codigo' => 'DOC-B',
            'descricao' => 'Documento B',
        ]);
        $revisaoB = $documentoB->revisoes()->create([
            'tenant_id' => $tenantB->id,
            'revisao' => 'R1',
            'data_emissao' => now(),
            'descricao' => 'Emissão B',
        ]);
        $unidadeB = UnidadeMedida::create(['tenant_id' => $tenantB->id, 'codigo' => 'UN', 'nome' => 'Unidade B']);
        $familiaB = FamiliaMaterial::create(['tenant_id' => $tenantB->id, 'nome' => 'Família B']);
        $listaB = \App\Models\ListaEngenharia::create([
            'tenant_id' => $tenantB->id,
            'documento_engenharia_revisao_id' => $revisaoB->id,
            'tipo' => 'material',
            'codigo' => 'LM-001',
        ]);
        $itemB = ItemTakeOff::create([
            'tenant_id' => $tenantB->id,
            'lista_engenharia_id' => $listaB->id,
            'descricao' => 'Item B',
            'unidade_medida_id' => $unidadeB->id,
            'familia_material_id' => $familiaB->id,
            'quantidade' => 10,
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\ListaEngenharia::find($listaB->id));
        $this->assertNull(ItemTakeOff::find($itemB->id));
        $this->assertNull(UnidadeMedida::find($unidadeB->id));
        $this->assertNull(FamiliaMaterial::find($familiaB->id));
    }

    /** Ciclo 19, Etapa 19.2 — requisicoes_planejamento/requisicao_planejamento_itens. */
    public function test_requisicao_planejamento_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $documentoB = DocumentoEngenharia::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'codigo' => 'DOC-B', 'descricao' => 'Documento B',
        ]);
        $revisaoB = $documentoB->revisoes()->create([
            'tenant_id' => $tenantB->id, 'revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão B',
        ]);
        $listaB = \App\Models\ListaEngenharia::create([
            'tenant_id' => $tenantB->id, 'documento_engenharia_revisao_id' => $revisaoB->id, 'tipo' => 'material', 'codigo' => 'LM-001',
        ]);
        $itemB = ItemTakeOff::create([
            'tenant_id' => $tenantB->id, 'lista_engenharia_id' => $listaB->id, 'descricao' => 'Item B', 'quantidade' => 10,
        ]);
        $rpB = \App\Models\RequisicaoPlanejamento::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'status' => 'rascunho',
        ]);
        $rpItemB = \App\Models\RequisicaoPlanejamentoItem::create([
            'tenant_id' => $tenantB->id, 'requisicao_planejamento_id' => $rpB->id, 'item_take_off_id' => $itemB->id, 'quantidade_requisitada' => 5,
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\RequisicaoPlanejamento::find($rpB->id));
        $this->assertNull(\App\Models\RequisicaoPlanejamentoItem::find($rpItemB->id));
    }

    /** Ciclo 19, Etapa 19.3 — alocacoes_requisicao_pacote. */
    public function test_alocacao_requisicao_pacote_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $documentoB = DocumentoEngenharia::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'codigo' => 'DOC-B', 'descricao' => 'Documento B',
        ]);
        $revisaoB = $documentoB->revisoes()->create([
            'tenant_id' => $tenantB->id, 'revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão B',
        ]);
        $listaB = \App\Models\ListaEngenharia::create([
            'tenant_id' => $tenantB->id, 'documento_engenharia_revisao_id' => $revisaoB->id, 'tipo' => 'material', 'codigo' => 'LM-001',
        ]);
        $itemB = ItemTakeOff::create([
            'tenant_id' => $tenantB->id, 'lista_engenharia_id' => $listaB->id, 'descricao' => 'Item B', 'quantidade' => 10,
        ]);
        $rpB = \App\Models\RequisicaoPlanejamento::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'status' => 'rascunho',
        ]);
        $rpItemB = \App\Models\RequisicaoPlanejamentoItem::create([
            'tenant_id' => $tenantB->id, 'requisicao_planejamento_id' => $rpB->id, 'item_take_off_id' => $itemB->id, 'quantidade_requisitada' => 5,
        ]);
        $pacoteB = ItemSuprimento::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Pacote B']);
        $alocacaoB = \App\Models\AlocacaoRequisicaoPacote::create([
            'tenant_id' => $tenantB->id, 'requisicao_planejamento_item_id' => $rpItemB->id, 'item_suprimento_id' => $pacoteB->id, 'quantidade_alocada' => 3,
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\AlocacaoRequisicaoPacote::find($alocacaoB->id));
    }

    /** Ciclo 19, Etapa 19.4 — requisicoes_compra/requisicao_compra_itens/requisicao_compra_etapas. */
    public function test_requisicao_compra_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $documentoB = DocumentoEngenharia::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'codigo' => 'DOC-B', 'descricao' => 'Documento B',
        ]);
        $revisaoB = $documentoB->revisoes()->create([
            'tenant_id' => $tenantB->id, 'revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão B',
        ]);
        $listaB = \App\Models\ListaEngenharia::create([
            'tenant_id' => $tenantB->id, 'documento_engenharia_revisao_id' => $revisaoB->id, 'tipo' => 'material', 'codigo' => 'LM-001',
        ]);
        $itemB = ItemTakeOff::create([
            'tenant_id' => $tenantB->id, 'lista_engenharia_id' => $listaB->id, 'descricao' => 'Item B', 'quantidade' => 10,
        ]);
        $rpB = \App\Models\RequisicaoPlanejamento::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'status' => 'rascunho',
        ]);
        $rpItemB = \App\Models\RequisicaoPlanejamentoItem::create([
            'tenant_id' => $tenantB->id, 'requisicao_planejamento_id' => $rpB->id, 'item_take_off_id' => $itemB->id, 'quantidade_requisitada' => 5,
        ]);
        $pacoteB = ItemSuprimento::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Pacote B']);
        $alocacaoB = \App\Models\AlocacaoRequisicaoPacote::create([
            'tenant_id' => $tenantB->id, 'requisicao_planejamento_item_id' => $rpItemB->id, 'item_suprimento_id' => $pacoteB->id, 'quantidade_alocada' => 5,
        ]);
        $rcB = \App\Models\RequisicaoCompra::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'item_suprimento_id' => $pacoteB->id, 'status' => 'rascunho',
        ]);
        $rcItemB = \App\Models\RequisicaoCompraItem::create([
            'tenant_id' => $tenantB->id, 'requisicao_compra_id' => $rcB->id, 'alocacao_requisicao_pacote_id' => $alocacaoB->id, 'quantidade' => 3,
        ]);
        $rcEtapaB = \App\Models\RequisicaoCompraEtapa::create([
            'tenant_id' => $tenantB->id, 'requisicao_compra_id' => $rcB->id, 'ordem' => 1,
            'nome_snapshot' => 'Cotação', 'prazo_dias_snapshot' => 3, 'data_prevista' => now()->toDateString(),
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\RequisicaoCompra::find($rcB->id));
        $this->assertNull(\App\Models\RequisicaoCompraItem::find($rcItemB->id));
        $this->assertNull(\App\Models\RequisicaoCompraEtapa::find($rcEtapaB->id));
    }

    /** Ciclo 19, Etapa 19.5 — pedidos_compra/pedido_compra_itens. */
    public function test_pedido_compra_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $documentoB = DocumentoEngenharia::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'codigo' => 'DOC-B', 'descricao' => 'Documento B',
        ]);
        $revisaoB = $documentoB->revisoes()->create([
            'tenant_id' => $tenantB->id, 'revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão B',
        ]);
        $listaB = \App\Models\ListaEngenharia::create([
            'tenant_id' => $tenantB->id, 'documento_engenharia_revisao_id' => $revisaoB->id, 'tipo' => 'material', 'codigo' => 'LM-001',
        ]);
        $itemB = ItemTakeOff::create([
            'tenant_id' => $tenantB->id, 'lista_engenharia_id' => $listaB->id, 'descricao' => 'Item B', 'quantidade' => 10,
        ]);
        $rpB = \App\Models\RequisicaoPlanejamento::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'status' => 'rascunho',
        ]);
        $rpItemB = \App\Models\RequisicaoPlanejamentoItem::create([
            'tenant_id' => $tenantB->id, 'requisicao_planejamento_id' => $rpB->id, 'item_take_off_id' => $itemB->id, 'quantidade_requisitada' => 5,
        ]);
        $pacoteB = ItemSuprimento::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Pacote B']);
        $alocacaoB = \App\Models\AlocacaoRequisicaoPacote::create([
            'tenant_id' => $tenantB->id, 'requisicao_planejamento_item_id' => $rpItemB->id, 'item_suprimento_id' => $pacoteB->id, 'quantidade_alocada' => 5,
        ]);
        $rcB = \App\Models\RequisicaoCompra::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'item_suprimento_id' => $pacoteB->id, 'status' => 'emitida',
        ]);
        $rcItemB = \App\Models\RequisicaoCompraItem::create([
            'tenant_id' => $tenantB->id, 'requisicao_compra_id' => $rcB->id, 'alocacao_requisicao_pacote_id' => $alocacaoB->id, 'quantidade' => 3,
        ]);
        $fornecedorB = \App\Models\Fornecedor::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Fornecedor B',
        ]);
        $pedidoB = \App\Models\PedidoCompra::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'requisicao_compra_id' => $rcB->id,
            'fornecedor_id' => $fornecedorB->id, 'status' => 'rascunho',
        ]);
        $pedidoItemB = \App\Models\PedidoCompraItem::create([
            'tenant_id' => $tenantB->id, 'pedido_compra_id' => $pedidoB->id, 'requisicao_compra_item_id' => $rcItemB->id, 'quantidade_pedida' => 2,
        ]);

        $recebimentoB = \App\Models\RecebimentoPedido::create([
            'tenant_id' => $tenantB->id, 'pedido_compra_item_id' => $pedidoItemB->id,
            'quantidade_recebida' => 1, 'recebido_em' => now(),
        ]);

        // Ciclo 19, Etapa 19.7 — Restrição automática da cadeia formal.
        $restricaoAutomaticaB = \App\Models\Restricao::create([
            'tenant_id' => $tenantB->id, 'atividade_id' => Atividade::factory()->create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id])->id,
            'descricao' => 'Restrição automática B', 'bloqueante' => true, 'status' => 'aberta', 'aberta_em' => now(),
            'origem_cadeia_suprimento_id' => $pacoteB->id,
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\PedidoCompra::find($pedidoB->id));
        $this->assertNull(\App\Models\PedidoCompraItem::find($pedidoItemB->id));
        $this->assertNull(\App\Models\RecebimentoPedido::find($recebimentoB->id));
        $this->assertNull(\App\Models\Restricao::find($restricaoAutomaticaB->id));
    }

    /** Ciclo 20, Etapa 20.1 — materiais/locais_estoque/unidades_estoque/movimentacoes_estoque. */
    public function test_estoque_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $unidadeB = UnidadeMedida::create(['tenant_id' => $tenantB->id, 'codigo' => 'M', 'nome' => 'Metro B']);
        $materialB = \App\Models\Material::create([
            'tenant_id' => $tenantB->id, 'codigo' => 'MAT-B', 'descricao' => 'Material B',
            'unidade_medida_id' => $unidadeB->id, 'modo_rastreabilidade' => 'quantitativo', 'ativo' => true,
        ]);
        $localB = \App\Models\LocalEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Local B', 'tipo' => 'almoxarifado', 'ativo' => true,
        ]);
        $unidadeEstoqueB = \App\Models\UnidadeEstoque::create([
            'tenant_id' => $tenantB->id, 'material_id' => $materialB->id, 'local_estoque_id' => $localB->id, 'codigo_lote' => 'B-001',
        ]);
        $movimentacaoB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'entrada',
            'material_id' => $materialB->id, 'local_estoque_id' => $localB->id, 'unidade_estoque_id' => $unidadeEstoqueB->id,
            'quantidade' => 100, 'ocorrido_em' => now(),
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\Material::find($materialB->id));
        $this->assertNull(\App\Models\LocalEstoque::find($localB->id));
        $this->assertNull(\App\Models\UnidadeEstoque::find($unidadeEstoqueB->id));
        $this->assertNull(\App\Models\MovimentacaoEstoque::find($movimentacaoB->id));
    }

    public function test_destinacao_planejada_material_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $unidadeB = UnidadeMedida::create(['tenant_id' => $tenantB->id, 'codigo' => 'M', 'nome' => 'Metro B']);
        $materialB = \App\Models\Material::create([
            'tenant_id' => $tenantB->id, 'codigo' => 'MAT-DEST-B', 'descricao' => 'Material B',
            'unidade_medida_id' => $unidadeB->id, 'modo_rastreabilidade' => 'quantitativo', 'ativo' => true,
        ]);
        $pacoteB = \App\Models\ItemSuprimento::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Pacote B']);
        $frenteB = \App\Models\FrenteTrabalho::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Frente B']);
        $destinacaoB = \App\Models\DestinacaoPlanejadaMaterial::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'item_suprimento_id' => $pacoteB->id,
            'material_id' => $materialB->id, 'frente_trabalho_id' => $frenteB->id, 'quantidade_planejada' => 100,
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\DestinacaoPlanejadaMaterial::find($destinacaoB->id));
    }

    public function test_reserva_estoque_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $unidadeB = UnidadeMedida::create(['tenant_id' => $tenantB->id, 'codigo' => 'M', 'nome' => 'Metro B']);
        $materialB = \App\Models\Material::create([
            'tenant_id' => $tenantB->id, 'codigo' => 'MAT-RES-B', 'descricao' => 'Material B',
            'unidade_medida_id' => $unidadeB->id, 'modo_rastreabilidade' => 'quantitativo', 'ativo' => true,
        ]);
        $localB = \App\Models\LocalEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Local B', 'tipo' => 'almoxarifado', 'ativo' => true,
        ]);
        $pacoteB = \App\Models\ItemSuprimento::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Pacote B']);
        $reservaB = \App\Models\ReservaEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'item_suprimento_id' => $pacoteB->id, 'material_id' => $materialB->id,
            'local_estoque_id' => $localB->id, 'quantidade' => 50, 'status' => 'ativa',
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\ReservaEstoque::find($reservaB->id));
    }

    public function test_aplicacao_material_estoque_query_is_scoped_to_authenticated_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $unidadeB = UnidadeMedida::create(['tenant_id' => $tenantB->id, 'codigo' => 'M', 'nome' => 'Metro B']);
        $materialB = \App\Models\Material::create([
            'tenant_id' => $tenantB->id, 'codigo' => 'MAT-APL-B', 'descricao' => 'Material B',
            'unidade_medida_id' => $unidadeB->id, 'modo_rastreabilidade' => 'quantitativo', 'ativo' => true,
        ]);
        $localB = \App\Models\LocalEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Local B', 'tipo' => 'almoxarifado', 'ativo' => true,
        ]);
        $frenteB = \App\Models\FrenteTrabalho::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Frente B']);
        $movimentacaoB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'saida',
            'material_id' => $materialB->id, 'local_estoque_id' => $localB->id,
            'quantidade' => 10, 'ocorrido_em' => now(),
        ]);
        $aplicacaoB = \App\Models\AplicacaoMaterialEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'movimentacao_estoque_id' => $movimentacaoB->id,
            'frente_trabalho_id' => $frenteB->id, 'quantidade' => 10, 'aplicado_em' => now(),
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\AplicacaoMaterialEstoque::find($aplicacaoB->id));
    }

    public function test_ordem_industrializacao_e_entidades_filhas_sao_escopadas_ao_tenant_autenticado(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $unidadeB = UnidadeMedida::create(['tenant_id' => $tenantB->id, 'codigo' => 'M', 'nome' => 'Metro B']);
        $materialB = \App\Models\Material::create([
            'tenant_id' => $tenantB->id, 'codigo' => 'MAT-IND-B', 'descricao' => 'Material B',
            'unidade_medida_id' => $unidadeB->id, 'modo_rastreabilidade' => 'quantitativo', 'ativo' => true,
        ]);
        $fornecedorB = \App\Models\Fornecedor::create(['tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Fornecedor B']);
        $localProprioB = \App\Models\LocalEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Local Próprio B', 'tipo' => 'almoxarifado', 'ativo' => true,
        ]);
        $localTerceiroB = \App\Models\LocalEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Local Terceiro B', 'tipo' => 'terceiro',
            'fornecedor_id' => $fornecedorB->id, 'ativo' => true,
        ]);
        $ordemB = \App\Models\OrdemIndustrializacao::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'fornecedor_id' => $fornecedorB->id,
            'local_terceiro_id' => $localTerceiroB->id, 'status' => 'rascunho',
        ]);
        $produtoB = \App\Models\ProdutoIndustrializado::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'ordem_industrializacao_id' => $ordemB->id,
            'material_id' => $materialB->id, 'quantidade_prevista' => 10,
        ]);
        $ordemB->update(['status' => 'emitida', 'numero' => 1]);
        $saidaB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'saida',
            'material_id' => $materialB->id, 'local_estoque_id' => $localProprioB->id, 'quantidade' => 10, 'ocorrido_em' => now(),
        ]);
        $entradaB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'entrada',
            'material_id' => $materialB->id, 'local_estoque_id' => $localTerceiroB->id, 'quantidade' => 10, 'ocorrido_em' => now(),
        ]);
        $remessaB = \App\Models\RemessaIndustrializacao::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'ordem_industrializacao_id' => $ordemB->id,
            'material_id' => $materialB->id, 'direcao' => 'envio', 'quantidade' => 10, 'ocorrido_em' => now(),
            'movimentacao_saida_id' => $saidaB->id, 'movimentacao_entrada_id' => $entradaB->id,
        ]);
        $consumoMovB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'saida',
            'material_id' => $materialB->id, 'local_estoque_id' => $localTerceiroB->id, 'quantidade' => 5, 'ocorrido_em' => now(),
        ]);
        $consumoB = \App\Models\ProdutoIndustrializadoConsumo::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'produto_industrializado_id' => $produtoB->id,
            'remessa_industrializacao_id' => $remessaB->id, 'quantidade_consumida' => 5, 'ocorrido_em' => now(),
            'movimentacao_consumo_id' => $consumoMovB->id,
        ]);
        $producaoMovB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'entrada',
            'material_id' => $materialB->id, 'local_estoque_id' => $localTerceiroB->id, 'quantidade' => 3, 'ocorrido_em' => now(),
        ]);
        $producaoB = \App\Models\ProducaoIndustrializada::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'produto_industrializado_id' => $produtoB->id,
            'quantidade' => 3, 'ocorrido_em' => now(), 'movimentacao_entrada_id' => $producaoMovB->id,
        ]);
        $entregaSaidaB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'saida',
            'material_id' => $materialB->id, 'local_estoque_id' => $localTerceiroB->id, 'quantidade' => 3, 'ocorrido_em' => now(),
        ]);
        $entregaEntradaB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'entrada',
            'material_id' => $materialB->id, 'local_estoque_id' => $localProprioB->id, 'quantidade' => 3, 'ocorrido_em' => now(),
        ]);
        $entregaB = \App\Models\EntregaProdutoIndustrializado::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'produto_industrializado_id' => $produtoB->id,
            'quantidade' => 3, 'modalidade' => 'retorno_estoque_obra', 'ocorrido_em' => now(),
            'movimentacao_saida_terceiro_id' => $entregaSaidaB->id, 'movimentacao_entrada_destino_id' => $entregaEntradaB->id,
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\OrdemIndustrializacao::find($ordemB->id));
        $this->assertNull(\App\Models\ProdutoIndustrializado::find($produtoB->id));
        $this->assertNull(\App\Models\RemessaIndustrializacao::find($remessaB->id));
        $this->assertNull(\App\Models\ProdutoIndustrializadoConsumo::find($consumoB->id));
        $this->assertNull(\App\Models\ProducaoIndustrializada::find($producaoB->id));
        $this->assertNull(\App\Models\EntregaProdutoIndustrializado::find($entregaB->id));
    }

    public function test_transferencia_estoque_e_escopada_ao_tenant_autenticado(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $unidadeB = UnidadeMedida::create(['tenant_id' => $tenantB->id, 'codigo' => 'M', 'nome' => 'Metro B']);
        $materialB = \App\Models\Material::create([
            'tenant_id' => $tenantB->id, 'codigo' => 'MAT-TRANSF-B', 'descricao' => 'Material B',
            'unidade_medida_id' => $unidadeB->id, 'modo_rastreabilidade' => 'quantitativo', 'ativo' => true,
        ]);
        $localOrigemB = \App\Models\LocalEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Origem B', 'tipo' => 'almoxarifado', 'ativo' => true,
        ]);
        $localDestinoB = \App\Models\LocalEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Destino B', 'tipo' => 'patio', 'ativo' => true,
        ]);
        $saidaB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'saida',
            'material_id' => $materialB->id, 'local_estoque_id' => $localOrigemB->id, 'quantidade' => 10, 'ocorrido_em' => now(),
        ]);
        $entradaB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'entrada',
            'material_id' => $materialB->id, 'local_estoque_id' => $localDestinoB->id, 'quantidade' => 10, 'ocorrido_em' => now(),
        ]);
        $transferenciaB = \App\Models\TransferenciaEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'material_id' => $materialB->id,
            'local_origem_id' => $localOrigemB->id, 'local_destino_id' => $localDestinoB->id,
            'quantidade' => 10, 'ocorrido_em' => now(),
            'movimentacao_saida_id' => $saidaB->id, 'movimentacao_entrada_id' => $entradaB->id,
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\TransferenciaEstoque::find($transferenciaB->id));
    }

    public function test_inventario_estoque_e_entidades_filhas_sao_escopadas_ao_tenant_autenticado(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB, $userB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $unidadeB = UnidadeMedida::create(['tenant_id' => $tenantB->id, 'codigo' => 'M', 'nome' => 'Metro B']);
        $materialB = \App\Models\Material::create([
            'tenant_id' => $tenantB->id, 'codigo' => 'MAT-INV-B', 'descricao' => 'Material B',
            'unidade_medida_id' => $unidadeB->id, 'modo_rastreabilidade' => 'quantitativo', 'ativo' => true,
        ]);
        $localB = \App\Models\LocalEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'nome' => 'Almox B', 'tipo' => 'almoxarifado', 'ativo' => true,
        ]);
        $entradaB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'entrada',
            'material_id' => $materialB->id, 'local_estoque_id' => $localB->id, 'quantidade' => 100, 'ocorrido_em' => now(),
        ]);
        $inventarioB = \App\Models\InventarioEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'local_estoque_id' => $localB->id,
            'status' => 'em_analise', 'numero' => 1,
        ]);
        $itemB = \App\Models\InventarioItem::create([
            'tenant_id' => $tenantB->id, 'inventario_estoque_id' => $inventarioB->id, 'material_id' => $materialB->id,
            'unidade_estoque_id' => null, 'quantidade_sistema_snapshot' => 100, 'created_at' => now(),
        ]);
        $contagemB = \App\Models\ContagemInventario::create([
            'tenant_id' => $tenantB->id, 'inventario_item_id' => $itemB->id, 'quantidade_contada' => 97,
            'contado_em' => now(), 'contador_id' => $userB->id,
        ]);
        $ajusteMovB = \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'tipo' => 'saida',
            'material_id' => $materialB->id, 'local_estoque_id' => $localB->id, 'quantidade' => 3, 'ocorrido_em' => now(),
        ]);
        $ajusteB = \App\Models\InventarioAjuste::create([
            'tenant_id' => $tenantB->id, 'inventario_item_id' => $itemB->id, 'movimentacao_estoque_id' => $ajusteMovB->id,
            'quantidade' => 3, 'justificativa' => 'Falta identificada na contagem física.', 'aprovado_por' => $userB->id,
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\InventarioEstoque::find($inventarioB->id));
        $this->assertNull(\App\Models\InventarioItem::find($itemB->id));
        $this->assertNull(\App\Models\ContagemInventario::find($contagemB->id));
        $this->assertNull(\App\Models\InventarioAjuste::find($ajusteB->id));
    }

    /** Ciclo 21, Etapa 21.3 — situacao_ocorrencias (ciclo de vida das Situações Gerenciais). */
    public function test_situacao_ocorrencia_e_escopada_ao_tenant_autenticado(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $ocorrenciaB = \App\Models\SituacaoOcorrencia::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id,
            'tipo' => 'documento_bloqueante', 'chave_logica' => 'documento_bloqueante:x:y',
            'status' => 'ativa', 'episodio' => 1,
            'severidade_atual' => 'atencao', 'severidade_peso_comunicado' => 1,
            'entidade_tipo' => 'DocumentoEngenharia', 'entidade_id' => 'x', 'descricao_atual' => 'd',
            'primeira_deteccao_em' => now(), 'ultima_deteccao_em' => now(),
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\SituacaoOcorrencia::find($ocorrenciaB->id));
    }

    /** Ciclo 21, Etapa 21.4 — situacao_comunicacao_entregas (ledger de e-mail). */
    public function test_situacao_comunicacao_entrega_e_escopada_ao_tenant_autenticado(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB, $userB] = $this->makeTenantWithUser();

        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $entregaB = \App\Models\SituacaoComunicacaoEntrega::create([
            'tenant_id' => $tenantB->id, 'obra_id' => $obraB->id, 'usuario_id' => $userB->id,
            'evento_usuario_id' => (string) \Illuminate\Support\Str::uuid(),
            'canal' => 'mail', 'enviado_em' => now(),
        ]);

        $this->actingAs($userA);

        $this->assertNull(\App\Models\SituacaoComunicacaoEntrega::find($entregaB->id));
    }
}
