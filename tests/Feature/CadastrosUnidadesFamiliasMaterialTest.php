<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\FamiliaMaterial;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ajuste de arquitetura de navegação — CRUD de UnidadeMedida/
 * FamiliaMaterial (cadastros corporativos tenant-wide, Ciclo 19.1)
 * reposicionado de abas dentro de `Radar → Estoque` (ESCOPO_OBRA,
 * `estoque.movimentacao`) para `Configurações → Cadastros`
 * (ESCOPO_TENANT, `cadastros.unidades_medida`/`cadastros.familias_material`),
 * mesmo padrão/permissão de `⚡fluxos-suprimento.blade.php` — Papel::Admin
 * em criar/editar/excluir, mesmo tier de TODO cadastro corporativo irmão
 * (nenhuma exceção no catálogo).
 *
 * Zero mudança de model/tabela/tenant scope — `App\Models\UnidadeMedida`/
 * `App\Models\FamiliaMaterial` continuam sendo a única fonte de verdade.
 */
class CadastrosUnidadesFamiliasMaterialTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        // Admin é o tier mínimo de TODO cadastro corporativo do catálogo —
        // mesmo padrão de cadastros.fluxos_suprimento/fornecedores/etc.
        $this->vincularObra($this->obra, $this->admin, Papel::Admin->value);
        $this->actingAs($this->admin);
    }

    // =========================================================
    // Unidades de Medida
    // =========================================================

    public function test_pagina_de_unidades_renderiza(): void
    {
        Livewire::test('pages::cadastros.unidades-medida')
            ->assertOk()
            ->assertSee('Unidades de Medida');
    }

    public function test_rota_de_unidades_renderiza_200(): void
    {
        $this->get(route('cadastros.unidades-medida'))->assertOk()->assertSee('Unidades de Medida');
    }

    public function test_criar_unidade_de_medida(): void
    {
        Livewire::test('pages::cadastros.unidades-medida')
            ->call('abrirCriar')
            ->set('codigo', 'kg')
            ->set('nome', 'Quilograma')
            ->call('salvar')
            ->assertSet('modalAberto', false);

        // Normalizada (trim+maiúsculo) pelo próprio model, nunca duplicada aqui.
        $this->assertDatabaseHas('unidades_medida', ['codigo' => 'KG', 'nome' => 'Quilograma', 'tenant_id' => $this->tenant->id]);
    }

    public function test_editar_unidade_de_medida(): void
    {
        $unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);

        Livewire::test('pages::cadastros.unidades-medida')
            ->call('editar', $unidade->id)
            ->assertSet('codigo', 'UN')
            ->set('nome', 'Unidade (Peça)')
            ->call('salvar');

        $this->assertSame('Unidade (Peça)', $unidade->fresh()->nome);
    }

    public function test_ativar_inativar_unidade(): void
    {
        $unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade', 'ativo' => true]);

        Livewire::test('pages::cadastros.unidades-medida')->call('alternarStatus', $unidade->id);

        $this->assertFalse($unidade->fresh()->ativo);

        Livewire::test('pages::cadastros.unidades-medida')->call('alternarStatus', $unidade->id);

        $this->assertTrue($unidade->fresh()->ativo);
    }

    public function test_pesquisar_unidade_filtra_por_codigo_ou_nome(): void
    {
        UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'KG', 'nome' => 'Quilograma']);
        UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);

        Livewire::test('pages::cadastros.unidades-medida')
            ->set('busca', 'Quilo')
            ->assertSee('KG')
            ->assertDontSee('Metro');
    }

    public function test_codigo_duplicado_mesmo_tenant_rejeitado_com_mensagem_amigavel(): void
    {
        UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);

        Livewire::test('pages::cadastros.unidades-medida')
            ->call('abrirCriar')
            ->set('codigo', 'm')
            ->set('nome', 'Outro nome qualquer')
            ->call('salvar')
            ->assertHasErrors(['codigo']);

        $this->assertSame(1, UnidadeMedida::where('codigo', 'M')->count());
    }

    public function test_mesmo_codigo_em_tenant_diferente_permitido(): void
    {
        UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);

        $outroTenant = Tenant::factory()->create();
        $unidadeOutroTenant = TenantContext::actingAs($outroTenant, fn () => UnidadeMedida::create([
            'tenant_id' => $outroTenant->id, 'codigo' => 'M', 'nome' => 'Metro (outro tenant)',
        ]));

        $this->assertNotNull($unidadeOutroTenant->id);
        $this->assertSame(2, UnidadeMedida::withoutGlobalScopes()->where('codigo', 'M')->count());
    }

    public function test_unidade_de_outro_tenant_invisivel(): void
    {
        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'OUTRO', 'nome' => 'De outro tenant']);
        });

        Livewire::test('pages::cadastros.unidades-medida')->assertDontSee('OUTRO');
    }

    public function test_unidade_de_outro_tenant_inacessivel_para_edicao(): void
    {
        $outroTenant = Tenant::factory()->create();
        $unidadeDeOutroTenant = TenantContext::actingAs($outroTenant, fn () => UnidadeMedida::create([
            'tenant_id' => $outroTenant->id, 'codigo' => 'OUTRO', 'nome' => 'X',
        ]));

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('pages::cadastros.unidades-medida')->call('editar', $unidadeDeOutroTenant->id);
    }

    public function test_sem_permissao_admin_bloqueia_criacao(): void
    {
        $usuarioComum = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $usuarioComum, Papel::GerentePlanejamento->value);
        $this->actingAs($usuarioComum);

        Livewire::test('pages::cadastros.unidades-medida')
            ->call('abrirCriar')
            ->assertStatus(403);
    }

    // =========================================================
    // Famílias de Materiais
    // =========================================================

    public function test_pagina_de_familias_renderiza(): void
    {
        Livewire::test('pages::cadastros.familias-material')
            ->assertOk()
            ->assertSee('Famílias de Materiais');
    }

    public function test_rota_de_familias_renderiza_200(): void
    {
        $this->get(route('cadastros.familias-material'))->assertOk()->assertSee('Famílias de Materiais');
    }

    public function test_criar_familia(): void
    {
        Livewire::test('pages::cadastros.familias-material')
            ->call('abrirCriar')
            ->set('nome', 'Tubulação')
            ->call('salvar')
            ->assertSet('modalAberto', false);

        $this->assertDatabaseHas('familias_material', ['nome' => 'Tubulação', 'tenant_id' => $this->tenant->id]);
    }

    public function test_criar_familia_sem_codigo(): void
    {
        // Código é opcional em Família — nunca bloqueia o cadastro.
        Livewire::test('pages::cadastros.familias-material')
            ->call('abrirCriar')
            ->set('nome', 'Elétrica')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('familias_material', ['nome' => 'Elétrica', 'codigo' => null]);
    }

    public function test_editar_familia(): void
    {
        $familia = FamiliaMaterial::create(['tenant_id' => $this->tenant->id, 'nome' => 'Tubulação']);

        Livewire::test('pages::cadastros.familias-material')
            ->call('editar', $familia->id)
            ->set('nome', 'Tubulação e Conexões')
            ->call('salvar');

        $this->assertSame('Tubulação e Conexões', $familia->fresh()->nome);
    }

    public function test_ativar_inativar_familia(): void
    {
        $familia = FamiliaMaterial::create(['tenant_id' => $this->tenant->id, 'nome' => 'Tubulação', 'ativo' => true]);

        Livewire::test('pages::cadastros.familias-material')->call('alternarStatus', $familia->id);

        $this->assertFalse($familia->fresh()->ativo);
    }

    public function test_pesquisar_familia_filtra_por_nome(): void
    {
        FamiliaMaterial::create(['tenant_id' => $this->tenant->id, 'nome' => 'Tubulação']);
        FamiliaMaterial::create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);

        Livewire::test('pages::cadastros.familias-material')
            ->set('busca', 'Tubu')
            ->assertSee('Tubulação')
            ->assertDontSee('Elétrica');
    }

    public function test_duplicidade_de_nome_rejeitada(): void
    {
        FamiliaMaterial::create(['tenant_id' => $this->tenant->id, 'nome' => 'Tubulação']);

        Livewire::test('pages::cadastros.familias-material')
            ->call('abrirCriar')
            ->set('nome', 'Tubulação')
            ->call('salvar')
            ->assertHasErrors(['nome']);

        $this->assertSame(1, FamiliaMaterial::where('nome', 'Tubulação')->count());
    }

    public function test_familia_de_outro_tenant_invisivel(): void
    {
        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            FamiliaMaterial::create(['tenant_id' => $outroTenant->id, 'nome' => 'Só do outro tenant']);
        });

        Livewire::test('pages::cadastros.familias-material')->assertDontSee('Só do outro tenant');
    }

    public function test_familia_de_outro_tenant_inacessivel_para_edicao(): void
    {
        $outroTenant = Tenant::factory()->create();
        $familiaDeOutroTenant = TenantContext::actingAs($outroTenant, fn () => FamiliaMaterial::create([
            'tenant_id' => $outroTenant->id, 'nome' => 'X',
        ]));

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('pages::cadastros.familias-material')->call('editar', $familiaDeOutroTenant->id);
    }

    public function test_sem_permissao_admin_bloqueia_criacao_de_familia(): void
    {
        $usuarioComum = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $usuarioComum, Papel::GerentePlanejamento->value);
        $this->actingAs($usuarioComum);

        Livewire::test('pages::cadastros.familias-material')
            ->call('abrirCriar')
            ->assertStatus(403);
    }

    // ---- Nenhuma tabela/registro duplicado entre a página nova e o Material ----

    public function test_nenhuma_tabela_ou_registro_e_duplicado(): void
    {
        Livewire::test('pages::cadastros.unidades-medida')
            ->call('abrirCriar')->set('codigo', 'UN')->set('nome', 'Unidade')->call('salvar');

        Livewire::test('pages::cadastros.familias-material')
            ->call('abrirCriar')->set('nome', 'Tubulação')->call('salvar');

        $this->assertSame(1, UnidadeMedida::count());
        $this->assertSame(1, FamiliaMaterial::count());
    }
}
