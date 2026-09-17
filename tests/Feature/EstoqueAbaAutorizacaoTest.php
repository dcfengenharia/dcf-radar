<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\AtribuicaoPerfilObra;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FASE 2F.CORREÇÃO — Achado E23 em ⚡estoque.blade.php: a página nunca
 * checava 'ver' em mount() (apesar das 5 funcionalidades — movimentacao/
 * reserva/conciliacao/industrializacao/inventario — já terem gate real
 * nas Actions desde o Ciclo 20). Estes testes cobrem exclusivamente essa
 * correção: entrada, visibilidade por aba, aba forjada, editar sem ver,
 * multiperfil (Fase 2B, `obra_user_perfil` — união, nunca substituição),
 * zero perfis, cross-obra/cross-tenant, ver != mutação.
 *
 * Mesma convenção já estabelecida no projeto pra 403 numa navegação de
 * página cheia (Livewire::test() de um componente `pages::*`):
 * App\Exceptions\Handler::render() converte pra redirect +
 * session('flash.popup', 'acesso-negado') — nunca uma exceção crua
 * observável no teste.
 */
class EstoqueAbaAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function assertPaginaNegada($componente): void
    {
        $componente->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    /** Cria um Perfil sem nenhuma concessão automática (não é um dos 5 padrão), com 'ver' só nos $slugs indicados, e vincula à obra (membership + espelho legado + nova pivot multiperfil). */
    private function usuarioComSlugsVer(array $slugs): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Custom ' . uniqid()]);
        foreach ($slugs as $slug) {
            PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => $slug, 'acao' => 'ver']);
        }
        $this->obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($this->obra, $user->id, $perfil->id);
        $this->actingAs($user);

        return $user;
    }

    // A — nenhum 'ver' nos 5 slugs -> nega
    public function test_a_zero_ver_nos_cinco_slugs_nega(): void
    {
        $this->usuarioComSlugsVer([]);

        $this->assertPaginaNegada(Livewire::test('pages::radar.estoque', ['obra' => $this->obra]));
    }

    // B — um único slug 'ver' -> abre, na aba correspondente
    public function test_b_somente_estoque_inventario_ver_abre_a_pagina(): void
    {
        $this->usuarioComSlugsVer(['estoque.inventario']);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->assertOk()
            ->assertSet('abaAtiva', 'inventario');
    }

    // C/D — só abas autorizadas aparecem; conteúdo de aba não autorizada não é renderizado
    public function test_cd_somente_a_aba_autorizada_e_visivel_e_renderizada(): void
    {
        $this->usuarioComSlugsVer(['estoque.reserva']);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->assertOk()
            ->assertSet('abaAtiva', 'planejamento')
            ->assertSee('Planejamento / Reservas')
            ->assertDontSee('Catálogo Mestre de Materiais')
            ->assertDontSee('Industrialização em Terceiros')
            ->assertDontSee('Inventário');
    }

    // E — editar sem ver não concede consulta
    public function test_e_editar_sem_ver_nao_concede_acesso(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Editar sem ver ' . uniqid()]);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'estoque.inventario', 'acao' => 'editar']);
        $this->obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($this->obra, $user->id, $perfil->id);
        $this->actingAs($user);

        $this->assertPaginaNegada(Livewire::test('pages::radar.estoque', ['obra' => $this->obra]));
    }

    // F — multiperfil (Fase 2B, obra_user_perfil): 2 Perfis, cada um com 'ver' num slug diferente, somam as 2 abas
    public function test_f_multiperfil_soma_abas_autorizadas(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $perfilA = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'A ' . uniqid()]);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfilA->id, 'funcionalidade' => 'estoque.inventario', 'acao' => 'ver']);

        $perfilB = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'B ' . uniqid()]);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfilB->id, 'funcionalidade' => 'estoque.reserva', 'acao' => 'ver']);

        $this->obra->users()->attach($user->id, ['perfil_id' => $perfilA->id]);
        AtribuicaoPerfilObra::adicionarPerfil($this->obra, $user->id, $perfilA->id);
        AtribuicaoPerfilObra::adicionarPerfil($this->obra, $user->id, $perfilB->id);
        $this->actingAs($user);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee('Inventário')
            ->assertSee('Planejamento / Reservas')
            ->assertDontSee('Catálogo Mestre de Materiais')
            ->assertDontSee('Industrialização em Terceiros');
    }

    // G — zero Perfis (sem vínculo nenhum na obra) -> nega
    public function test_g_sem_nenhum_perfil_na_obra_nega(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user);

        $this->assertPaginaNegada(Livewire::test('pages::radar.estoque', ['obra' => $this->obra]));
    }

    // H — cross-work: 'ver' concedido só na obra B nunca autoriza a obra A
    public function test_h_cross_work_nega(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'CrossWork ' . uniqid()]);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'estoque.inventario', 'acao' => 'ver']);
        $obraB->users()->attach($user->id, ['perfil_id' => $perfil->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($obraB, $user->id, $perfil->id);
        $this->actingAs($user);

        $this->assertPaginaNegada(Livewire::test('pages::radar.estoque', ['obra' => $this->obra]));
    }

    // I — aba forjada: chamar selecionarAba() direto com uma aba não autorizada nunca expõe o conteúdo
    public function test_i_aba_forjada_via_selecionaraba_nao_expoe_conteudo(): void
    {
        $this->usuarioComSlugsVer(['estoque.inventario']);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('selecionarAba', 'industrializacao')
            ->assertSet('abaAtiva', 'inventario')
            ->assertDontSee('Industrialização em Terceiros');
    }

    // J — ver não implica mutação: usuário só com 'ver' vê a página, mas Actions continuam exigindo 'criar'/'editar'/'excluir'
    public function test_j_ver_nunca_implica_mutacao(): void
    {
        $this->usuarioComSlugsVer(['estoque.movimentacao']);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->assertOk()
            ->call('abrirModalMaterial')
            ->assertStatus(403);
    }

    // Cross-tenant: 'ver' de outro tenant nunca autoriza este tenant
    public function test_cross_tenant_nega(): void
    {
        $outroTenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $perfil = TenantContext::actingAs(
            $outroTenant,
            fn () => Perfil::create(['tenant_id' => $outroTenant->id, 'nome' => 'OutroTenant ' . uniqid()])
        );
        PerfilPermissao::create(['tenant_id' => $outroTenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'estoque.inventario', 'acao' => 'ver']);
        $this->actingAs($user);

        // $user (outroTenant) nunca tem vínculo em obra_user com $this->obra (deste tenant).
        $this->assertPaginaNegada(Livewire::test('pages::radar.estoque', ['obra' => $this->obra]));
    }

    // Regressão: perfil padrão seedado continua abrindo todas as abas, exatamente como antes desta correção.
    public function test_perfil_padrao_seedado_continua_vendo_todas_as_abas(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, Papel::GerentePlanejamento->value);
        $this->actingAs($user);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->assertOk()
            ->assertSet('abaAtiva', 'materiais')
            ->assertSee('Catálogo Mestre de Materiais')
            ->assertSee('Locais de Estoque')
            ->assertSee('Planejamento / Reservas')
            ->assertSee('Conciliação / Aplicação')
            ->assertSee('Industrialização em Terceiros')
            ->assertSee('Inventário');
    }
}
