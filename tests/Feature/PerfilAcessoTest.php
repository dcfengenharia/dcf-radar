<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PerfilAcessoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $criador;
    private User $outroUsuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->criador = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->tenant->update(['criado_por_id' => $this->criador->id]);

        $this->outroUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_criador_do_tenant_acessa_a_pagina(): void
    {
        $this->actingAs($this->criador);

        $this->get(route('gestao.perfis-acesso'))
            ->assertOk()
            ->assertSeeLivewire('pages::gestao.perfis-acesso');
    }

    public function test_outro_usuario_do_tenant_recebe_forbidden_mesmo_sendo_admin_numa_obra(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obra, $this->outroUsuario, Papel::Admin->value);
        $this->actingAs($this->outroUsuario);

        // Navegação de página cheia sem acesso não mostra mais 403 cru —
        // App\Exceptions\Handler::render() redireciona com flash.popup.
        $this->get(route('gestao.perfis-acesso'))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_pagina_mostra_os_5_perfis_padrao(): void
    {
        $this->actingAs($this->criador);

        Livewire::test('pages::gestao.perfis-acesso')
            ->assertSee('Administrador')
            ->assertSee('Gerente de Planejamento')
            ->assertSee('Engenheiro')
            ->assertSee('Encarregado')
            ->assertSee('Cliente (Leitura)');
    }

    public function test_renomear_perfil(): void
    {
        $this->actingAs($this->criador);
        $perfil = Perfil::porSlugPadrao($this->tenant, 'encarregado');

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->set('nomeEdit', 'Encarregado de Obra')
            ->call('salvarNome');

        $this->assertEquals('Encarregado de Obra', $perfil->fresh()->nome);
    }

    public function test_criar_e_excluir_perfil_customizado(): void
    {
        $this->actingAs($this->criador);

        $componente = Livewire::test('pages::gestao.perfis-acesso')
            ->call('novoPerfil');

        $novoPerfil = Perfil::where('tenant_id', $this->tenant->id)->where('nome', 'Novo Perfil')->first();
        $this->assertNotNull($novoPerfil);

        $componente->call('excluirPerfil', $novoPerfil->id);
        $this->assertNull(Perfil::find($novoPerfil->id));
    }

    public function test_excluir_perfil_em_uso_e_bloqueado(): void
    {
        $this->actingAs($this->criador);
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfil = $this->vincularObra($obra, $this->outroUsuario, Papel::Encarregado->value);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('excluirPerfil', $perfil->id);

        $this->assertNotNull(Perfil::find($perfil->id));
    }

    public function test_toggle_permissao_concede_e_revoga(): void
    {
        $this->actingAs($this->criador);
        $perfil = Perfil::porSlugPadrao($this->tenant, 'encarregado');

        $this->assertFalse(
            PerfilPermissao::where('perfil_id', $perfil->id)
                ->where('funcionalidade', 'restricoes.quadro')
                ->where('acao', 'excluir')
                ->exists()
        );

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->call('togglePermissao', 'restricoes.quadro', 'excluir');

        $this->assertTrue(
            PerfilPermissao::where('perfil_id', $perfil->id)
                ->where('funcionalidade', 'restricoes.quadro')
                ->where('acao', 'excluir')
                ->exists()
        );

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->call('togglePermissao', 'restricoes.quadro', 'excluir');

        $this->assertFalse(
            PerfilPermissao::where('perfil_id', $perfil->id)
                ->where('funcionalidade', 'restricoes.quadro')
                ->where('acao', 'excluir')
                ->exists()
        );
    }

    public function test_revogar_permissao_reflete_na_policy_correspondente(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $encarregado = $this->vincularObra($obra, $this->outroUsuario, Papel::Encarregado->value);

        $this->assertTrue($this->outroUsuario->can('create', [\App\Models\Restricao::class, $obra->id]));

        $this->actingAs($this->criador);
        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $encarregado->id)
            ->call('togglePermissao', 'restricoes.quadro', 'criar');

        $outroFresco = User::find($this->outroUsuario->id);
        $this->assertFalse($outroFresco->can('create', [\App\Models\Restricao::class, $obra->id]));
    }
}
