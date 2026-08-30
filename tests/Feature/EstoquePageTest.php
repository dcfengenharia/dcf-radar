<?php

namespace Tests\Feature;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\TipoLocalEstoque;
use App\Models\FamiliaMaterial;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.1 — UI/autorização/isolamento da tela de Estoque
 * (⚡estoque.blade.php). Cenários de domínio já cobertos em
 * EstoqueFundacaoTest — aqui só o componente Livewire, permissão e
 * isolamento cross-obra/cross-tenant.
 */
class EstoquePageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
    }

    public function test_pagina_renderiza(): void
    {
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee('Catálogo de Materiais');
    }

    public function test_criar_material_via_ui(): void
    {
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalMaterial')
            ->set('materialCodigo', 'CABO-UI-01')
            ->set('materialDescricao', 'Cabo de teste via UI')
            ->set('materialUnidadeMedidaId', $this->unidade->id)
            ->set('materialModoRastreabilidade', ModoRastreabilidadeMaterial::Quantitativo->value)
            ->call('salvarMaterial')
            ->assertSet('modalMaterialAberto', false);

        $this->assertDatabaseHas('materiais', ['codigo' => 'CABO-UI-01']);
    }

    public function test_criar_material_sem_unidade_falha_validacao(): void
    {
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalMaterial')
            ->set('materialCodigo', 'SEM-UNIDADE')
            ->set('materialDescricao', 'X')
            ->call('salvarMaterial')
            ->assertHasErrors(['materialUnidadeMedidaId']);
    }

    public function test_criar_local_via_ui(): void
    {
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalLocal')
            ->set('localNome', 'Almoxarifado Central')
            ->set('localTipo', TipoLocalEstoque::Almoxarifado->value)
            ->call('salvarLocal')
            ->assertSet('modalLocalAberto', false);

        $this->assertDatabaseHas('locais_estoque', ['nome' => 'Almoxarifado Central', 'obra_id' => $this->obra->id]);
    }

    // ---- AK/AL: autorização ----

    public function test_ak_sem_permissao_bloqueia_criacao(): void
    {
        $usuarioLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $usuarioLeitura, Papel::ClienteLeitura->value);
        $this->actingAs($usuarioLeitura);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalMaterial')
            ->assertStatus(403);
    }

    public function test_al_ver_nao_muta(): void
    {
        $material = Material::create([
            'codigo' => 'AL-01',
            'descricao' => 'X',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);

        $usuarioLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $usuarioLeitura, Papel::ClienteLeitura->value);
        $this->actingAs($usuarioLeitura);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee('AL-01');

        $this->assertTrue($material->fresh()->ativo);
    }

    // ---- AM: cross-obra ----

    public function test_am_local_de_outra_obra_nao_editavel(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);
        $localDeOutraObra = LocalEstoque::create([
            'obra_id' => $outraObra->id,
            'nome' => 'Local de Outra Obra',
            'tipo' => TipoLocalEstoque::Almoxarifado->value,
            'ativo' => true,
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalLocal', $localDeOutraObra->id);
    }

    // ---- AN: cross-tenant ----

    public function test_an_material_de_outro_tenant_nao_aparece(): void
    {
        $outroTenant = Tenant::factory()->create();
        \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $unidade = UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
            Material::create([
                'tenant_id' => $outroTenant->id,
                'codigo' => 'OUTRO-TENANT-MAT',
                'descricao' => 'X',
                'unidade_medida_id' => $unidade->id,
                'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
                'ativo' => true,
            ]);
        });

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->assertOk()
            ->assertDontSee('OUTRO-TENANT-MAT');
    }

    public function test_alternar_status_material(): void
    {
        $material = Material::create([
            'codigo' => 'TOGGLE-01',
            'descricao' => 'X',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('alternarStatusMaterial', $material->id);

        $this->assertFalse($material->fresh()->ativo);
    }
}
