<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Client;
use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithTenant(): User
    {
        return User::factory()->create();
    }

    private function createClient(User $user): Client
    {
        return Client::factory()->create(['tenant_id' => $user->tenant_id]);
    }

    // =========================================================================
    // Renderização
    // =========================================================================

    public function test_obras_page_renders_livewire_component(): void
    {
        $user = $this->createUserWithTenant();

        $this->actingAs($user)
            ->get('/app/cadastros/obras')
            ->assertStatus(200)
            ->assertSeeLivewire('pages::obras.index');
    }

    public function test_unauthenticated_user_cannot_access_obras_page(): void
    {
        $this->get('/app/cadastros/obras')
            ->assertRedirect('/login');
    }

    public function test_index_component_shows_empty_state_when_no_works(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('pages::obras.index')
            ->assertSee('Nenhuma Obra Cadastrada');
    }

    public function test_index_component_lists_only_tenant_works(): void
    {
        $user  = $this->createUserWithTenant();
        $other = Tenant::factory()->create();

        $clientOwn   = Client::factory()->create(['tenant_id' => $user->tenant_id]);
        $clientOther = Client::factory()->create(['tenant_id' => $other->id]);

        Work::create(['tenant_id' => $user->tenant_id, 'client_id' => $clientOwn->id,   'name' => 'Obra do Tenant',    'status' => 'planejamento']);
        Work::create(['tenant_id' => $other->id,       'client_id' => $clientOther->id, 'name' => 'Obra Outro Tenant', 'status' => 'planejamento']);

        Livewire::actingAs($user)
            ->test('pages::obras.index')
            ->assertSee('Obra do Tenant')
            ->assertDontSee('Obra Outro Tenant');
    }

    // =========================================================================
    // Criação
    // =========================================================================

    public function test_create_component_has_correct_initial_state(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('obras.create')
            ->assertSet('isEditing', false)
            ->assertSet('formTitle', 'Cadastrar Obra')
            ->assertSet('status', 'planejamento');
    }

    public function test_can_create_work_via_livewire(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->set('name', 'Residencial das Palmeiras')
            ->set('clientId', $client->id)
            ->set('location', 'São Paulo / SP')
            ->set('budgetTotal', '1500000.00')
            ->set('status', 'planejamento')
            ->call('saveWork')
            ->assertDispatched('close-obra-modal')
            ->assertDispatched('work-created')
            ->assertDispatched('show-toast', message: 'Obra cadastrada com sucesso!');

        $this->assertDatabaseHas('works', [
            'tenant_id' => $user->tenant_id,
            'client_id' => $client->id,
            'name'      => 'Residencial das Palmeiras',
            'location'  => 'São Paulo / SP',
            'status'    => 'planejamento',
        ]);
    }

    public function test_creator_is_attached_as_gerente_planejamento(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->set('name', 'Obra Vinculada')
            ->set('clientId', $client->id)
            ->set('status', 'planejamento')
            ->call('saveWork');

        $work = Work::where('name', 'Obra Vinculada')->first();
        $perfilGerente = Perfil::porSlugPadrao($user->tenant, 'gerente_planejamento');

        $this->assertDatabaseHas('obra_user', [
            'work_id' => $work->id,
            'user_id' => $user->id,
            'perfil_id' => $perfilGerente->id,
        ]);
    }

    public function test_work_is_scoped_to_current_tenant_on_save(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->set('name', 'Obra Segura')
            ->set('clientId', $client->id)
            ->set('status', 'planejamento')
            ->call('saveWork');

        $work = Work::where('name', 'Obra Segura')->first();
        $this->assertEquals($user->tenant_id, $work->tenant_id);
    }

    public function test_create_work_requires_name(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->set('name', '')
            ->set('clientId', $client->id)
            ->call('saveWork')
            ->assertHasErrors(['name' => 'required']);
    }

    public function test_create_work_name_must_have_minimum_length(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->set('name', 'AB')
            ->set('clientId', $client->id)
            ->call('saveWork')
            ->assertHasErrors(['name' => 'min']);
    }

    public function test_create_work_requires_client(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('obras.create')
            ->set('name', 'Obra Sem Cliente')
            ->set('clientId', null)
            ->call('saveWork')
            ->assertHasErrors(['clientId' => 'required']);
    }

    public function test_end_date_must_be_after_or_equal_start_date(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->set('name', 'Obra Datas')
            ->set('clientId', $client->id)
            ->set('startDateBaseline', '2026-12-01')
            ->set('endDateBaseline', '2026-06-01')
            ->call('saveWork')
            ->assertHasErrors(['endDateBaseline']);
    }

    public function test_clear_form_resets_all_fields(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('obras.create')
            ->set('name', 'Alguma Obra')
            ->set('location', 'Algum Lugar')
            ->set('status', 'em_andamento')
            ->call('clearForm')
            ->assertSet('name', '')
            ->assertSet('location', null)
            ->assertSet('status', 'planejamento')
            ->assertSet('isEditing', false)
            ->assertSet('formTitle', 'Cadastrar Obra');
    }

    // =========================================================================
    // Edição
    // =========================================================================

    public function test_edit_work_loads_data_into_form(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);
        $work   = Work::factory()->create([
            'tenant_id' => $user->tenant_id,
            'client_id' => $client->id,
            'name'      => 'Obra Original',
            'location'  => 'São Paulo',
            'status'    => 'em_andamento',
        ]);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->call('editWork', $work->id)
            ->assertSet('workId', $work->id)
            ->assertSet('name', 'Obra Original')
            ->assertSet('clientId', $client->id)
            ->assertSet('location', 'São Paulo')
            ->assertSet('status', 'em_andamento')
            ->assertSet('isEditing', true)
            ->assertDispatched('open-obra-modal');
    }

    public function test_can_update_work_data(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);
        $work   = Work::factory()->create([
            'tenant_id' => $user->tenant_id,
            'client_id' => $client->id,
            'name'      => 'Nome Antigo',
            'status'    => 'planejamento',
        ]);
        $this->vincularObra($work, $user, Papel::GerentePlanejamento->value);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->call('editWork', $work->id)
            ->set('name', 'Nome Atualizado')
            ->set('location', 'Rio de Janeiro')
            ->set('status', 'em_andamento')
            ->call('saveWork')
            ->assertDispatched('work-updated')
            ->assertDispatched('show-toast', message: 'Obra atualizada com sucesso!');

        $this->assertDatabaseHas('works', [
            'id'       => $work->id,
            'name'     => 'Nome Atualizado',
            'location' => 'Rio de Janeiro',
            'status'   => 'em_andamento',
        ]);
    }

    public function test_edit_cannot_access_other_tenant_work(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $user        = $this->createUserWithTenant();
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherWork   = Work::factory()->create(['tenant_id' => $otherTenant->id, 'client_id' => $otherClient->id]);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->call('editWork', $otherWork->id);
    }

    public function test_update_resets_form_after_save(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);
        $work   = Work::factory()->create([
            'tenant_id' => $user->tenant_id,
            'client_id' => $client->id,
            'name'      => 'Obra Teste',
            'status'    => 'planejamento',
        ]);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->call('editWork', $work->id)
            ->call('saveWork')
            ->assertSet('isEditing', false)
            ->assertSet('workId', null)
            ->assertSet('name', '');
    }

    // =========================================================================
    // Exclusão
    // =========================================================================

    public function test_confirm_delete_sets_properties_and_dispatches_event(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);
        $work   = Work::factory()->create([
            'tenant_id' => $user->tenant_id,
            'client_id' => $client->id,
            'name'      => 'Obra para Excluir',
            'status'    => 'planejamento',
        ]);

        Livewire::actingAs($user)
            ->test('pages::obras.index')
            ->call('confirmDelete', $work->id)
            ->assertSet('confirmDeleteId', $work->id)
            ->assertSet('confirmDeleteName', 'Obra para Excluir')
            ->assertDispatched('show-delete-modal');
    }

    public function test_can_delete_work(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);
        $work   = Work::factory()->create([
            'tenant_id' => $user->tenant_id,
            'client_id' => $client->id,
            'status'    => 'planejamento',
        ]);
        $this->vincularObra($work, $user, Papel::Admin->value);

        Livewire::actingAs($user)
            ->test('pages::obras.index')
            ->call('confirmDelete', $work->id)
            ->call('deleteWork')
            ->assertDispatched('hide-delete-modal')
            ->assertDispatched('show-toast', message: 'Obra excluída com sucesso!');

        $this->assertDatabaseMissing('works', ['id' => $work->id]);
    }

    public function test_cancel_delete_clears_state(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);
        $work   = Work::factory()->create([
            'tenant_id' => $user->tenant_id,
            'client_id' => $client->id,
            'status'    => 'planejamento',
        ]);

        Livewire::actingAs($user)
            ->test('pages::obras.index')
            ->call('confirmDelete', $work->id)
            ->call('cancelDelete')
            ->assertSet('confirmDeleteId', null)
            ->assertSet('confirmDeleteName', null)
            ->assertDispatched('hide-delete-modal');

        $this->assertDatabaseHas('works', ['id' => $work->id]);
    }

    public function test_delete_cannot_remove_other_tenant_work(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $user        = $this->createUserWithTenant();
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherWork   = Work::factory()->create(['tenant_id' => $otherTenant->id, 'client_id' => $otherClient->id]);

        Livewire::actingAs($user)
            ->test('pages::obras.index')
            ->call('confirmDelete', $otherWork->id);
    }

    // =========================================================================
    // Exclusão em massa
    // =========================================================================

    public function test_can_bulk_delete_selected_works(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);
        $work1  = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);
        $work2  = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);
        $work3  = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);
        $this->vincularObra($work1, $user, Papel::Admin->value);
        $this->vincularObra($work2, $user, Papel::Admin->value);

        Livewire::actingAs($user)
            ->test('pages::obras.index')
            ->set('selectedIds', [$work1->id, $work2->id])
            ->call('bulkDeleteWorks')
            ->assertDispatched('hide-bulk-delete-modal')
            ->assertDispatched('show-toast')
            ->assertSet('selectedIds', []);

        $this->assertDatabaseMissing('works', ['id' => $work1->id]);
        $this->assertDatabaseMissing('works', ['id' => $work2->id]);
        $this->assertDatabaseHas('works', ['id' => $work3->id]);
    }

    public function test_bulk_delete_only_removes_own_tenant_works(): void
    {
        $user        = $this->createUserWithTenant();
        $client      = $this->createClient($user);
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->create(['tenant_id' => $otherTenant->id]);

        $ownWork   = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);
        $otherWork = Work::factory()->create(['tenant_id' => $otherTenant->id, 'client_id' => $otherClient->id]);
        $this->vincularObra($ownWork, $user, Papel::Admin->value);

        Livewire::actingAs($user)
            ->test('pages::obras.index')
            ->set('selectedIds', [$ownWork->id, $otherWork->id])
            ->call('bulkDeleteWorks');

        $this->assertDatabaseMissing('works', ['id' => $ownWork->id]);
        $this->assertDatabaseHas('works', ['id' => $otherWork->id]);
    }

    // =========================================================================
    // Paginação
    // =========================================================================

    public function test_works_list_is_paginated_with_seven_per_page(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);
        Work::factory()->count(10)->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);

        $component = Livewire::actingAs($user)->test('pages::obras.index');
        $works = $component->instance()->works;

        $this->assertEquals(7, $works->perPage());
        $this->assertEquals(10, $works->total());
        $this->assertCount(7, $works->items());
    }

    // =========================================================================
    // Busca
    // =========================================================================

    public function test_can_filter_works_by_name(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);
        Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id, 'name' => 'Residencial Alpha']);
        Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id, 'name' => 'Comercial Beta']);

        Livewire::actingAs($user)
            ->test('pages::obras.index')
            ->set('search', 'Alpha')
            ->assertSee('Residencial Alpha')
            ->assertDontSee('Comercial Beta');
    }

    public function test_search_shows_empty_result_state(): void
    {
        $user   = $this->createUserWithTenant();
        $client = $this->createClient($user);
        Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id, 'name' => 'Obra Real']);

        Livewire::actingAs($user)
            ->test('pages::obras.index')
            ->set('search', 'xyzinexistente')
            ->assertSee('Nenhuma obra encontrada');
    }

    // =========================================================================
    // Gestão — Minhas Obras
    // =========================================================================

    public function test_gestao_minhas_obras_page_renders(): void
    {
        $user = $this->createUserWithTenant();

        $this->actingAs($user)
            ->get('/app/gestao/minhas-obras')
            ->assertStatus(200)
            ->assertSeeLivewire('pages::gestao.minhas-obras');
    }

    public function test_unauthenticated_user_cannot_access_gestao_page(): void
    {
        $this->get('/app/gestao/minhas-obras')
            ->assertRedirect('/login');
    }

    public function test_minhas_obras_tab_shows_only_user_works(): void
    {
        $user        = $this->createUserWithTenant();
        $otherUser   = User::factory()->create(['tenant_id' => $user->tenant_id]);
        $client      = $this->createClient($user);

        $myWork    = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id, 'name' => 'Minha Obra']);
        $otherWork = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id, 'name' => 'Obra do Outro']);

        $this->vincularObra($myWork, $user, Papel::GerentePlanejamento->value);
        $this->vincularObra($otherWork, $otherUser, Papel::Engenheiro->value);

        $component = Livewire::actingAs($user)->test('pages::gestao.minhas-obras');
        $myWorks = $component->instance()->myWorks;

        $myWorksIds = $myWorks->pluck('id')->toArray();
        $this->assertContains($myWork->id, $myWorksIds);
        $this->assertNotContains($otherWork->id, $myWorksIds);
    }

    public function test_todas_obras_tab_shows_all_tenant_works(): void
    {
        $user      = $this->createUserWithTenant();
        $otherUser = User::factory()->create(['tenant_id' => $user->tenant_id]);
        $client    = $this->createClient($user);

        $work1 = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id, 'name' => 'Obra Alpha']);
        $work2 = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id, 'name' => 'Obra Beta']);

        $this->vincularObra($work1, $user, Papel::GerentePlanejamento->value);

        $component = Livewire::actingAs($user)->test('pages::gestao.minhas-obras');
        $allWorks = $component->instance()->allWorks;

        $allIds = $allWorks->pluck('id')->toArray();
        $this->assertContains($work1->id, $allIds);
        $this->assertContains($work2->id, $allIds);
    }

    public function test_avanco_mostra_zero_por_cento_quando_obra_nao_tem_report(): void
    {
        $user = $this->createUserWithTenant();
        $client = $this->createClient($user);
        $work = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);
        $this->vincularObra($work, $user, Papel::GerentePlanejamento->value);

        $this->actingAs($user)
            ->get('/app/gestao/minhas-obras')
            ->assertOk()
            ->assertSee('0,0%');
    }

    public function test_avanco_vem_do_ultimo_report_emitido(): void
    {
        $user = $this->createUserWithTenant();
        $client = $this->createClient($user);
        $work = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);
        $this->vincularObra($work, $user, Papel::GerentePlanejamento->value);

        $importacao = \App\Models\CronogramaImportacao::create([
            'tenant_id' => $user->tenant_id,
            'obra_id' => $work->id,
            'user_id' => $user->id,
            'arquivo' => 'teste.xml',
            'data_status' => now(),
            'metodo_distribuicao' => 'linear',
            'tipo' => 'ambos',
            'criadas' => 0,
            'atualizadas' => 0,
            'removidas' => 0,
            'importado_em' => now(),
        ]);
        $report = \App\Models\Report::factory()->emitido()->create([
            'tenant_id' => $user->tenant_id,
            'obra_id' => $work->id,
            'criado_por' => $user->id,
            'cronograma_importacao_id' => $importacao->id,
        ]);
        $curva = \App\Models\ReportCurva::factory()->create([
            'tenant_id' => $user->tenant_id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => null,
        ]);
        \App\Models\ReportCurvaDatapoint::create([
            'tenant_id' => $user->tenant_id,
            'report_curva_id' => $curva->id,
            'granularidade' => 'mensal',
            'serie' => 'realizado',
            'periodo_inicio' => '2026-06-01',
            'horas' => 100,
            'percentual_acumulado' => 37.2,
        ]);

        $this->actingAs($user)
            ->get('/app/gestao/minhas-obras')
            ->assertOk()
            ->assertSee('37,2%');
    }

    public function test_pagina_de_obras_tem_alternador_de_visualizacao_lista_e_cards(): void
    {
        $user = $this->createUserWithTenant();
        $client = $this->createClient($user);
        $work = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);
        $this->vincularObra($work, $user, Papel::GerentePlanejamento->value);

        $this->actingAs($user)
            ->get('/app/gestao/minhas-obras')
            ->assertOk()
            ->assertSee('Ver em lista', false)
            ->assertSee('Ver em cards', false)
            ->assertSee('Orçamento Total');
    }

    public function test_lista_de_obras_mostra_contagem_e_avatares_da_equipe(): void
    {
        $user = $this->createUserWithTenant();
        $client = $this->createClient($user);
        $work = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);
        $this->vincularObra($work, $user, Papel::GerentePlanejamento->value);

        $membro = User::factory()->create(['tenant_id' => $user->tenant_id, 'first_name' => 'Ana', 'last_name' => 'Souza']);
        $this->vincularObra($work, $membro, Papel::Engenheiro->value);

        $response = $this->actingAs($user)
            ->get('/app/gestao/minhas-obras')
            ->assertOk()
            ->assertSee('2 membros');

        $response->assertSee('Ana Souza', false);
    }

    public function test_obra_sem_equipe_mostra_zero_membros(): void
    {
        $user = $this->createUserWithTenant();
        $client = $this->createClient($user);
        Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);

        $this->actingAs($user)
            ->get('/app/gestao/minhas-obras')
            ->assertOk()
            ->assertSee('0 membros');
    }

    public function test_badge_de_prazo_mostra_dias_restantes_quando_dentro_do_prazo(): void
    {
        $user = $this->createUserWithTenant();
        $client = $this->createClient($user);
        Work::factory()->create([
            'tenant_id' => $user->tenant_id,
            'client_id' => $client->id,
            'end_date_baseline' => now()->addDays(28),
        ]);

        $this->actingAs($user)
            ->get('/app/gestao/minhas-obras')
            ->assertOk()
            ->assertSee('28 DIAS RESTANTES');
    }

    public function test_badge_de_prazo_mostra_dias_em_atraso_quando_prazo_vencido(): void
    {
        $user = $this->createUserWithTenant();
        $client = $this->createClient($user);
        Work::factory()->create([
            'tenant_id' => $user->tenant_id,
            'client_id' => $client->id,
            'end_date_baseline' => now()->subDays(10),
        ]);

        $this->actingAs($user)
            ->get('/app/gestao/minhas-obras')
            ->assertOk()
            ->assertSee('10 DIAS EM ATRASO');
    }

    public function test_badge_de_prazo_nao_aparece_sem_data_de_termino(): void
    {
        $user = $this->createUserWithTenant();
        $client = $this->createClient($user);
        Work::factory()->create([
            'tenant_id' => $user->tenant_id,
            'client_id' => $client->id,
            'end_date_baseline' => null,
        ]);

        $this->actingAs($user)
            ->get('/app/gestao/minhas-obras')
            ->assertOk()
            ->assertDontSee('DIAS RESTANTES')
            ->assertDontSee('DIAS EM ATRASO');
    }

    // =========================================================================
    // Gestão — Minhas Obras: canva de filtros + exportação
    // =========================================================================

    public function test_canva_de_filtros_aparece_com_busca_e_botoes_de_exportar(): void
    {
        $user = $this->createUserWithTenant();
        $client = $this->createClient($user);
        Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);

        $this->actingAs($user)
            ->get('/app/gestao/minhas-obras')
            ->assertOk()
            ->assertSee('canva-filtros-minhas-obras', false)
            ->assertSee('Buscar por nome, cliente ou localização...', false)
            ->assertSee('wire:click="exportarExcel"', false)
            ->assertSee('wire:click="exportarPdf"', false);
    }

    public function test_tem_filtros_ativos_reflete_estado_da_busca(): void
    {
        $user = $this->createUserWithTenant();

        $component = Livewire::actingAs($user)->test('pages::gestao.minhas-obras');
        $this->assertFalse($component->instance()->temFiltrosAtivos());

        $component->set('search', 'Alpha');
        $this->assertTrue($component->instance()->temFiltrosAtivos());
    }

    public function test_limpar_filtros_reseta_a_busca(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('pages::gestao.minhas-obras')
            ->set('search', 'Alpha')
            ->call('limparFiltros')
            ->assertSet('search', '');
    }

    public function test_exportar_excel_dispara_download_respeitando_busca(): void
    {
        $user = $this->createUserWithTenant();
        $client = $this->createClient($user);
        Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id, 'name' => 'Obra Alpha']);
        Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id, 'name' => 'Obra Beta']);

        Livewire::actingAs($user)
            ->test('pages::gestao.minhas-obras')
            ->set('search', 'Alpha')
            ->call('exportarExcel')
            ->assertFileDownloaded("obras-{$user->tenant_id}.xlsx");
    }

    public function test_exportar_pdf_dispara_download(): void
    {
        $user = $this->createUserWithTenant();
        $client = $this->createClient($user);
        Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id]);

        Livewire::actingAs($user)
            ->test('pages::gestao.minhas-obras')
            ->call('exportarPdf')
            ->assertFileDownloaded("obras-{$user->tenant_id}.pdf");
    }

    public function test_exportacao_respeita_aba_minhas_obras_ativa(): void
    {
        $user = $this->createUserWithTenant();
        $otherUser = User::factory()->create(['tenant_id' => $user->tenant_id]);
        $client = $this->createClient($user);

        $myWork = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id, 'name' => 'Minha Obra']);
        $otherWork = Work::factory()->create(['tenant_id' => $user->tenant_id, 'client_id' => $client->id, 'name' => 'Obra do Outro']);
        $this->vincularObra($myWork, $user, Papel::GerentePlanejamento->value);
        $this->vincularObra($otherWork, $otherUser, Papel::Engenheiro->value);

        \Maatwebsite\Excel\Facades\Excel::fake();

        Livewire::actingAs($user)
            ->test('pages::gestao.minhas-obras')
            ->set('activeTab', 'minhas')
            ->call('exportarExcel');

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded("obras-{$user->tenant_id}.xlsx", function (\App\Exports\ObrasExport $export) {
            $rows = $export->collection();

            return $rows->count() === 1 && $rows->first()->name === 'Minha Obra';
        });
    }
}
