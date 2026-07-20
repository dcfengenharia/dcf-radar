<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithTenant(): User
    {
        return User::factory()->create();
    }

    public function test_clientes_page_renders_livewire_component(): void
    {
        $user = $this->createUserWithTenant();

        $this->actingAs($user)
            ->get('/app/cadastros/clientes')
            ->assertStatus(200)
            ->assertSeeLivewire('pages::clientes.index');
    }

    public function test_index_component_shows_empty_state_when_no_clients(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->assertSee('Canteiro de Obras Vazio por Aqui!');
    }

    public function test_index_component_lists_only_tenant_clients(): void
    {
        $user = $this->createUserWithTenant();

        $otherTenant = Tenant::factory()->create();

        Client::create(['tenant_id' => $user->tenant_id, 'name' => 'Cliente do Tenant']);
        Client::create(['tenant_id' => $otherTenant->id, 'name' => 'Cliente de Outro Tenant']);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->assertSee('Cliente do Tenant')
            ->assertDontSee('Cliente de Outro Tenant');
    }

    public function test_create_component_has_correct_initial_state(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->assertSet('isEditing', false)
            ->assertSet('formTitle', 'Cadastrar Cliente');
    }

    public function test_can_create_client_via_livewire(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->set('name', 'Incorporadora Alpha LTDA')
            ->set('trading_name', 'Incorporadora Alpha')
            ->set('cnpj', '12.345.678/0001-90')
            ->set('email', 'contato@alpha.com')
            ->set('phone', '(11) 99999-0001')
            ->call('saveClient')
            ->assertDispatched('close-client-modal')
            ->assertDispatched('client-created')
            ->assertDispatched('show-client-toast', message: 'Cliente cadastrado com sucesso!');

        $this->assertDatabaseHas('clients', [
            'tenant_id' => $user->tenant_id,
            'name' => 'Incorporadora Alpha LTDA',
            'email' => 'contato@alpha.com',
        ]);
    }

    public function test_can_create_client_with_logo(): void
    {
        Storage::fake('public');

        $user = $this->createUserWithTenant();
        $logo = UploadedFile::fake()->image('logo.png', 200, 200);

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->set('name', 'Empresa com Logo')
            ->set('trading_name', 'Empresa com Logo')
            ->set('logo', $logo)
            ->call('saveClient')
            ->assertDispatched('client-created');

        $client = Client::where('name', 'Empresa com Logo')->first();

        $this->assertNotNull($client->logo_path);
        Storage::disk('public')->assertExists($client->logo_path);
    }

    public function test_logo_is_optional_when_creating_client(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->set('name', 'Empresa Sem Logo')
            ->set('trading_name', 'Empresa Sem Logo')
            ->call('saveClient')
            ->assertDispatched('client-created')
            ->assertHasNoErrors('logo');

        $this->assertDatabaseHas('clients', [
            'name' => 'Empresa Sem Logo',
            'logo_path' => null,
        ]);
    }

    public function test_logo_must_be_an_image(): void
    {
        $user = $this->createUserWithTenant();
        $pdf = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->set('name', 'Empresa Válida')
            ->set('trading_name', 'Empresa Válida')
            ->set('logo', $pdf)
            ->call('saveClient')
            ->assertHasErrors(['logo' => 'image']);
    }

    public function test_logo_must_not_be_svg(): void
    {
        $user = $this->createUserWithTenant();
        $svg = UploadedFile::fake()->create('logo.svg', 50, 'image/svg+xml');

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->set('name', 'Empresa SVG')
            ->set('trading_name', 'Empresa SVG')
            ->set('logo', $svg)
            ->call('saveClient')
            ->assertHasErrors(['logo']);
    }

    public function test_logo_max_size_is_2mb(): void
    {
        $user = $this->createUserWithTenant();
        $largeLogo = UploadedFile::fake()->image('logo.png', 100, 100)->size(3000);

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->set('name', 'Empresa Grande Logo')
            ->set('trading_name', 'Empresa Grande Logo')
            ->set('logo', $largeLogo)
            ->call('saveClient')
            ->assertHasErrors(['logo' => 'max']);
    }

    public function test_client_logo_url_returns_default_when_no_logo(): void
    {
        $user = $this->createUserWithTenant();
        $client = Client::create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Empresa Sem Logo',
            'logo_path' => null,
        ]);

        $this->assertStringContainsString('logo_oficial.png', $client->logo_url);
    }

    public function test_create_client_requires_name(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->set('name', '')
            ->call('saveClient')
            ->assertHasErrors(['name' => 'required']);
    }

    public function test_create_client_name_must_have_minimum_length(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->set('name', 'AB')
            ->call('saveClient')
            ->assertHasErrors(['name' => 'min']);
    }

    public function test_create_client_email_must_be_valid(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->set('name', 'Cliente Válido')
            ->set('email', 'email-invalido')
            ->call('saveClient')
            ->assertHasErrors(['email' => 'email']);
    }

    public function test_clear_form_resets_all_fields(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->set('name', 'Algum Nome')
            ->set('cnpj', '00.000.000/0000-00')
            ->call('clearForm')
            ->assertSet('name', null)
            ->assertSet('cnpj', null)
            ->assertSet('isEditing', false)
            ->assertSet('formTitle', 'Cadastrar Cliente');
    }

    public function test_unauthenticated_user_cannot_access_clientes_page(): void
    {
        $this->get('/app/cadastros/clientes')
            ->assertRedirect('/login');
    }

    public function test_client_is_scoped_to_current_tenant_on_save(): void
    {
        $user = $this->createUserWithTenant();
        $otherTenant = Tenant::create(['name' => 'Outra Empresa']);

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->set('name', 'Cliente Seguro')
            ->set('trading_name', 'Cliente Seguro')
            ->call('saveClient');

        $client = Client::where('name', 'Cliente Seguro')->first();

        $this->assertEquals($user->tenant_id, $client->tenant_id);
        $this->assertNotEquals($otherTenant->id, $client->tenant_id);
    }

    // =========================================================================
    // Testes de Edição
    // =========================================================================

    public function test_edit_client_loads_data_into_form(): void
    {
        $user = $this->createUserWithTenant();
        $client = Client::factory()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Cliente Original',
            'trading_name' => 'Original',
            'email' => 'original@teste.com',
        ]);

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->call('editClient', $client->id)
            ->assertSet('clientId', $client->id)
            ->assertSet('name', 'Cliente Original')
            ->assertSet('trading_name', 'Original')
            ->assertSet('email', 'original@teste.com')
            ->assertSet('isEditing', true)
            ->assertDispatched('open-client-modal');
    }

    public function test_can_update_client_data(): void
    {
        $user = $this->createUserWithTenant();
        $client = Client::factory()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Nome Antigo',
            'trading_name' => 'Fantasia Antiga',
        ]);

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->call('editClient', $client->id)
            ->set('name', 'Nome Atualizado')
            ->set('trading_name', 'Fantasia Atualizada')
            ->set('email', 'novo@email.com')
            ->call('saveClient')
            ->assertDispatched('client-updated')
            ->assertDispatched('show-client-toast', message: 'Cliente atualizado com sucesso!');

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'name' => 'Nome Atualizado',
            'trading_name' => 'Fantasia Atualizada',
            'email' => 'novo@email.com',
        ]);
    }

    public function test_can_update_client_logo(): void
    {
        Storage::fake('public');

        $user = $this->createUserWithTenant();
        $oldLogo = UploadedFile::fake()->image('old.png')->store('logos/clients', 'public');
        $client = Client::factory()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Empresa',
            'trading_name' => 'Empresa',
            'logo_path' => $oldLogo,
        ]);

        $newLogo = UploadedFile::fake()->image('new.png', 200, 200);

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->call('editClient', $client->id)
            ->set('logo', $newLogo)
            ->call('saveClient')
            ->assertDispatched('client-updated');

        $client->refresh();
        $this->assertNotEquals($oldLogo, $client->logo_path);
        Storage::disk('public')->assertExists($client->logo_path);
        Storage::disk('public')->assertMissing($oldLogo);
    }

    public function test_edit_cannot_access_other_tenant_client(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $user = $this->createUserWithTenant();
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->create(['tenant_id' => $otherTenant->id]);

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->call('editClient', $otherClient->id);
    }

    public function test_update_resets_form_after_save(): void
    {
        $user = $this->createUserWithTenant();
        $client = Client::factory()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Cliente Teste',
            'trading_name' => 'Teste',
        ]);

        Livewire::actingAs($user)
            ->test('clientes.create')
            ->call('editClient', $client->id)
            ->call('saveClient')
            ->assertSet('isEditing', false)
            ->assertSet('clientId', null)
            ->assertSet('name', null);
    }

    // =========================================================================
    // Testes de Exclusão
    // =========================================================================

    public function test_confirm_delete_sets_properties_and_dispatches_event(): void
    {
        $user = $this->createUserWithTenant();
        $client = Client::factory()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Cliente para Excluir',
        ]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->call('confirmDelete', $client->id)
            ->assertSet('confirmDeleteId', $client->id)
            ->assertSet('confirmDeleteName', 'Cliente para Excluir')
            ->assertDispatched('show-delete-modal');
    }

    public function test_can_delete_client(): void
    {
        $user = $this->createUserWithTenant();
        $client = Client::factory()->create(['tenant_id' => $user->tenant_id]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->call('confirmDelete', $client->id)
            ->call('deleteClient')
            ->assertDispatched('hide-delete-modal')
            ->assertDispatched('show-toast', message: 'Cliente excluído com sucesso!');

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_delete_removes_logo_from_storage(): void
    {
        Storage::fake('public');

        $user = $this->createUserWithTenant();
        $logoPath = UploadedFile::fake()->image('logo.png')->store('logos/clients', 'public');
        $client = Client::factory()->create([
            'tenant_id' => $user->tenant_id,
            'logo_path' => $logoPath,
        ]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->call('confirmDelete', $client->id)
            ->call('deleteClient');

        Storage::disk('public')->assertMissing($logoPath);
    }

    public function test_cancel_delete_clears_state(): void
    {
        $user = $this->createUserWithTenant();
        $client = Client::factory()->create(['tenant_id' => $user->tenant_id]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->call('confirmDelete', $client->id)
            ->call('cancelDelete')
            ->assertSet('confirmDeleteId', null)
            ->assertSet('confirmDeleteName', null)
            ->assertDispatched('hide-delete-modal');

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_delete_cannot_remove_other_tenant_client(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $user = $this->createUserWithTenant();
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->create(['tenant_id' => $otherTenant->id]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->call('confirmDelete', $otherClient->id);
    }

    // =========================================================================
    // Testes de Paginação
    // =========================================================================

    public function test_clients_list_is_paginated_with_five_per_page(): void
    {
        $user = $this->createUserWithTenant();
        Client::factory()->count(8)->create(['tenant_id' => $user->tenant_id]);

        $component = Livewire::actingAs($user)
            ->test('pages::clientes.index');

        $clients = $component->instance()->clients;

        $this->assertEquals(7, $clients->perPage());
        $this->assertEquals(8, $clients->total());
        $this->assertEquals(1, $clients->currentPage());
        $this->assertCount(7, $clients->items());
    }

    public function test_second_page_shows_remaining_client(): void
    {
        $user = $this->createUserWithTenant();
        Client::factory()->count(8)->create(['tenant_id' => $user->tenant_id]);

        $component = Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->call('gotoPage', 2);

        $clients = $component->instance()->clients;

        $this->assertEquals(2, $clients->currentPage());
        $this->assertCount(1, $clients->items());
    }

    // =========================================================================
    // Testes de Filtro/Busca
    // =========================================================================

    public function test_can_filter_clients_by_name(): void
    {
        $user = $this->createUserWithTenant();
        Client::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Incorporadora Alpha']);
        Client::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Construtora Beta']);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->set('search', 'Alpha')
            ->assertSee('Incorporadora Alpha')
            ->assertDontSee('Construtora Beta');
    }

    public function test_filter_searches_across_cnpj_email_and_phone(): void
    {
        $user = $this->createUserWithTenant();
        Client::factory()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Empresa CNPJ',
            'cnpj' => '12.345.678/0001-90',
        ]);
        Client::factory()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Empresa Email',
            'email' => 'contato@empresa.com',
        ]);
        Client::factory()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Outra Empresa',
        ]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->set('search', '12.345')
            ->assertSee('Empresa CNPJ')
            ->assertDontSee('Empresa Email')
            ->assertDontSee('Outra Empresa');

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->set('search', 'contato@')
            ->assertSee('Empresa Email')
            ->assertDontSee('Empresa CNPJ');
    }

    public function test_clear_search_shows_all_clients(): void
    {
        $user = $this->createUserWithTenant();
        Client::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Empresa Alpha']);
        Client::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Empresa Beta']);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->set('search', 'Alpha')
            ->assertSee('Empresa Alpha')
            ->assertDontSee('Empresa Beta')
            ->call('clearSearch')
            ->assertSee('Empresa Alpha')
            ->assertSee('Empresa Beta');
    }

    public function test_search_shows_empty_result_state_when_no_match(): void
    {
        $user = $this->createUserWithTenant();
        Client::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Empresa Real']);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->set('search', 'xyzinexistente')
            ->assertSee('Nenhum cliente encontrado');
    }

    public function test_search_resets_selected_ids(): void
    {
        $user = $this->createUserWithTenant();
        $client = Client::factory()->create(['tenant_id' => $user->tenant_id]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->set('selectedIds', [$client->id])
            ->set('search', 'qualquer coisa')
            ->assertSet('selectedIds', []);
    }

    // =========================================================================
    // Testes de Seleção Múltipla
    // =========================================================================

    public function test_toggle_select_all_selects_all_on_current_page(): void
    {
        $user = $this->createUserWithTenant();
        Client::factory()->count(3)->create(['tenant_id' => $user->tenant_id]);

        $component = Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->call('toggleSelectAll');

        $pageIds = $component->instance()->clients->pluck('id')->toArray();

        $this->assertEqualsCanonicalizing($pageIds, $component->get('selectedIds'));
    }

    public function test_toggle_select_all_deselects_when_all_already_selected(): void
    {
        $user = $this->createUserWithTenant();
        Client::factory()->count(3)->create(['tenant_id' => $user->tenant_id]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->call('toggleSelectAll')
            ->call('toggleSelectAll')
            ->assertSet('selectedIds', []);
    }

    // =========================================================================
    // Testes de Exclusão em Massa
    // =========================================================================

    public function test_open_bulk_delete_modal_dispatches_event(): void
    {
        $user = $this->createUserWithTenant();
        $client = Client::factory()->create(['tenant_id' => $user->tenant_id]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->set('selectedIds', [$client->id])
            ->call('openBulkDeleteModal')
            ->assertDispatched('show-bulk-delete-modal');
    }

    public function test_open_bulk_delete_modal_does_nothing_when_nothing_selected(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->call('openBulkDeleteModal')
            ->assertNotDispatched('show-bulk-delete-modal');
    }

    public function test_can_bulk_delete_selected_clients(): void
    {
        $user = $this->createUserWithTenant();
        $client1 = Client::factory()->create(['tenant_id' => $user->tenant_id]);
        $client2 = Client::factory()->create(['tenant_id' => $user->tenant_id]);
        $client3 = Client::factory()->create(['tenant_id' => $user->tenant_id]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->set('selectedIds', [$client1->id, $client2->id])
            ->call('bulkDeleteClients')
            ->assertDispatched('hide-bulk-delete-modal')
            ->assertDispatched('show-toast')
            ->assertSet('selectedIds', []);

        $this->assertDatabaseMissing('clients', ['id' => $client1->id]);
        $this->assertDatabaseMissing('clients', ['id' => $client2->id]);
        $this->assertDatabaseHas('clients', ['id' => $client3->id]);
    }

    public function test_bulk_delete_removes_logos_from_storage(): void
    {
        Storage::fake('public');

        $user = $this->createUserWithTenant();

        $logo1 = UploadedFile::fake()->image('l1.png')->store('logos/clients', 'public');
        $logo2 = UploadedFile::fake()->image('l2.png')->store('logos/clients', 'public');

        $client1 = Client::factory()->create(['tenant_id' => $user->tenant_id, 'logo_path' => $logo1]);
        $client2 = Client::factory()->create(['tenant_id' => $user->tenant_id, 'logo_path' => $logo2]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->set('selectedIds', [$client1->id, $client2->id])
            ->call('bulkDeleteClients');

        Storage::disk('public')->assertMissing($logo1);
        Storage::disk('public')->assertMissing($logo2);
    }

    public function test_bulk_delete_only_removes_own_tenant_clients(): void
    {
        $user = $this->createUserWithTenant();
        $otherTenant = Tenant::factory()->create();

        $ownClient = Client::factory()->create(['tenant_id' => $user->tenant_id]);
        $otherClient = Client::factory()->create(['tenant_id' => $otherTenant->id]);

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->set('selectedIds', [$ownClient->id, $otherClient->id])
            ->call('bulkDeleteClients');

        $this->assertDatabaseMissing('clients', ['id' => $ownClient->id]);
        $this->assertDatabaseHas('clients', ['id' => $otherClient->id]);
    }

    public function test_cancel_bulk_delete_dispatches_hide_event(): void
    {
        $user = $this->createUserWithTenant();

        Livewire::actingAs($user)
            ->test('pages::clientes.index')
            ->call('cancelBulkDelete')
            ->assertDispatched('hide-bulk-delete-modal');
    }
}
