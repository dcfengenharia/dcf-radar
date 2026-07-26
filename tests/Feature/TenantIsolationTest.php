<?php

namespace Tests\Feature;

use App\Models\Assinatura;
use App\Models\AssinaturaFatura;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\Client;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaReprogramacao;
use App\Models\Feedback;
use App\Models\Feriado;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemProntidao;
use App\Models\ItemSuprimento;
use App\Models\Plano;
use App\Models\ProgramacaoSemanal;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Report;
use App\Models\ReportIndicadorSemana;
use App\Models\Restricao;
use App\Models\Tenant;
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
}
