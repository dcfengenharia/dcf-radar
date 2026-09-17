<?php

namespace Tests\Feature;

use App\Models\ObraUserPerfil;
use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FASE 2C, Seção 22-25/36/41-44 — Matriz de Acessos: renderização,
 * filtros, edição contextual via a mesma API multiperfil, isolamento de
 * tenant, e ausência de N+1 (2 queries batch pra qualquer volume de
 * pares obra×usuário).
 */
class Fase2CMatrizAcessosTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sem_permissao_recebe_403(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // Handler::render() intercepta 403 de navegação de página cheia e
        // redireciona pro popup interno de acesso negado (mesmo padrão já
        // documentado/testado em todo o projeto) — nunca um assertStatus(403) cru.
        $this->actingAs($user)->get(route('gestao.matriz-acessos'))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_b_criador_do_tenant_acessa_e_ve_associacoes(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $membro = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Joao', 'last_name' => 'Silva']);
        $this->vincularObra($obra, $membro, 'engenheiro');

        $this->actingAs($criador);
        Livewire::test('pages::gestao.matriz-acessos')
            ->assertSee('Joao Silva')
            ->assertSee($obra->name);
    }

    public function test_c_filtro_por_perfil_restringe_linhas(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        // Nomes deliberadamente distintos dos nomes dos PRÓPRIOS perfis
        // (que também aparecem nas <option> do filtro, indiferente da
        // seleção) — evita falso-negativo de assertDontSee.
        $engenheiroUser = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'UsuarioEngPessoa']);
        $encarregadoUser = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'UsuarioEncPessoa']);
        $this->vincularObra($obra, $engenheiroUser, 'engenheiro');
        $this->vincularObra($obra, $encarregadoUser, 'encarregado');

        $perfilEngenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');

        $this->actingAs($criador);
        Livewire::test('pages::gestao.matriz-acessos')
            ->set('filtroPerfilId', $perfilEngenheiro->id)
            ->assertSee('UsuarioEngPessoa')
            ->assertDontSee('UsuarioEncPessoa');
    }

    public function test_d_editar_perfis_via_matriz_usa_substituirperfis(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $membro = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $membro, 'cliente_leitura');

        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');
        $encarregado = Perfil::porSlugPadrao($tenant, 'encarregado');

        $this->actingAs($criador);
        Livewire::test('pages::gestao.matriz-acessos')
            ->call('abrirEdicao', $obra->id, $membro->id)
            ->set('perfisSelecionadosEdicao', [$engenheiro->id, $encarregado->id])
            ->call('salvarEdicao');

        $membro = $membro->fresh();
        $this->assertTrue($membro->temPerfilNaObra($obra, 'engenheiro'));
        $this->assertTrue($membro->temPerfilNaObra($obra, 'encarregado'));
        $this->assertFalse($membro->temPerfilNaObra($obra, 'cliente_leitura'));
    }

    public function test_e_ultimo_admin_bloqueado_via_matriz(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');

        $tenantDoAdmin = Tenant::factory()->create();
        $platformAdmin = User::factory()->create(['tenant_id' => $tenantDoAdmin->id, 'is_platform_admin' => true]);
        $this->actingAs($platformAdmin);
        \App\Support\ImpersonationContext::start($tenant);

        Livewire::test('pages::gestao.matriz-acessos')
            ->call('abrirEdicao', $obra->id, $criador->id)
            ->set('perfisSelecionadosEdicao', [$engenheiro->id])
            ->call('salvarEdicao')
            ->assertDispatched('show-toast');

        $this->assertTrue($criador->fresh()->temPerfilNaObra($obra, 'admin'));
    }

    public function test_f_isolamento_de_tenant_nunca_mostra_outra_empresa(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $criadorA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $tenantA->update(['criado_por_id' => $criadorA->id]);
        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $membroB = User::factory()->create(['tenant_id' => $tenantB->id, 'first_name' => 'ForaDoTenant']);
        $this->vincularObra($obraB, $membroB, 'engenheiro');

        $this->actingAs($criadorA);
        Livewire::test('pages::gestao.matriz-acessos')
            ->assertDontSee('ForaDoTenant')
            ->assertDontSee($obraB->name);
    }

    public function test_g_editar_obra_de_outro_tenant_via_id_manipulado_e_bloqueado(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $criadorA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $tenantA->update(['criado_por_id' => $criadorA->id]);
        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $membroB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $this->vincularObra($obraB, $membroB, 'engenheiro');

        $this->actingAs($criadorA);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('pages::gestao.matriz-acessos')
            ->call('abrirEdicao', $obraB->id, $membroB->id);
    }

    public function test_h_performance_sem_n_mais_1_independente_do_volume(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);

        // Cenário pequeno.
        $obra1 = Work::factory()->create(['tenant_id' => $tenant->id]);
        for ($i = 0; $i < 5; $i++) {
            $u = User::factory()->create(['tenant_id' => $tenant->id]);
            $this->vincularObra($obra1, $u, 'engenheiro');
        }

        $this->actingAs($criador);
        DB::enableQueryLog();
        Livewire::test('pages::gestao.matriz-acessos');
        $queriesPequeno = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Cenário maior — mais obras e usuários. Log DESLIGADO durante o
        // setup (senão as queries de criação de fixture também entram na
        // contagem, mascarando a medição real do render).
        for ($i = 0; $i < 5; $i++) {
            $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
            for ($j = 0; $j < 6; $j++) {
                $u = User::factory()->create(['tenant_id' => $tenant->id]);
                $this->vincularObra($obra, $u, 'encarregado');
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test('pages::gestao.matriz-acessos');
        $queriesGrande = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            $queriesPequeno + 3,
            $queriesGrande,
            'A contagem de queries não deveria crescer proporcionalmente ao número de pares obra×usuário.'
        );
    }

    public function test_i_perfil_de_outro_tenant_nunca_persistido_via_matriz(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $criadorA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $tenantA->update(['criado_por_id' => $criadorA->id]);
        $obraA = Work::factory()->create(['tenant_id' => $tenantA->id]);
        $membro = User::factory()->create(['tenant_id' => $tenantA->id]);
        $this->vincularObra($obraA, $membro, 'engenheiro');
        $perfilDeB = Perfil::create(['tenant_id' => $tenantB->id, 'nome' => 'De Outro Tenant']);

        $this->actingAs($criadorA);
        Livewire::test('pages::gestao.matriz-acessos')
            ->call('abrirEdicao', $obraA->id, $membro->id)
            ->set('perfisSelecionadosEdicao', [$perfilDeB->id])
            ->call('salvarEdicao');

        $this->assertSame([], ObraUserPerfil::where('work_id', $obraA->id)->where('user_id', $membro->id)->pluck('perfil_id')->all());
    }
}
