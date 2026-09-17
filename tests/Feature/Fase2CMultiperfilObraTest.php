<?php

namespace Tests\Feature;

use App\Models\ObraUserPerfil;
use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FASE 2C, Seção 19-21/27/41-43 — nova UI multiperfil de
 * ⚡obra-detalhe.blade.php: abrirEdicaoPerfis()/salvarPerfisMembro()
 * sempre via a API aprovada (AtribuicaoPerfilObra::substituirPerfis()),
 * nunca escrita direta na pivot.
 */
class Fase2CMultiperfilObraTest extends TestCase
{
    use RefreshDatabase;

    private function abrirObraDetalhe(Work $obra): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test('pages::gestao.obra-detalhe', ['obra' => $obra]);
    }

    public function test_a_abrir_edicao_prepopula_perfis_efetivos_atuais(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $membro = User::factory()->create(['tenant_id' => $tenant->id]);
        $engenheiro = $this->vincularObra($obra, $membro, 'engenheiro');
        $encarregado = Perfil::porSlugPadrao($tenant, 'encarregado');
        $this->adicionarPerfilExtra($obra, $membro, 'encarregado');

        $this->actingAs($criador);
        $componente = $this->abrirObraDetalhe($obra);
        $componente->call('abrirEdicaoPerfis', $membro->id);

        $selecionados = $componente->get('perfisSelecionadosEdicao');
        sort($selecionados);
        $esperado = [$engenheiro->id, $encarregado->id];
        sort($esperado);
        $this->assertSame($esperado, $selecionados);
    }

    public function test_b_salvar_2_perfis_concede_a_uniao_das_duas_capacidades(): void
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
        $componente = $this->abrirObraDetalhe($obra);
        $componente->call('abrirEdicaoPerfis', $membro->id)
            ->set('perfisSelecionadosEdicao', [$engenheiro->id, $encarregado->id])
            ->call('salvarPerfisMembro');

        $membro = $membro->fresh();
        $this->assertTrue($membro->temPerfilNaObra($obra, 'engenheiro'));
        $this->assertTrue($membro->temPerfilNaObra($obra, 'encarregado'));
        $this->assertFalse($membro->temPerfilNaObra($obra, 'cliente_leitura'));
    }

    public function test_c_remover_1_perfil_preserva_o_outro(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $membro = User::factory()->create(['tenant_id' => $tenant->id]);
        $engenheiro = $this->vincularObra($obra, $membro, 'engenheiro');
        $encarregado = $this->adicionarPerfilExtra($obra, $membro, 'encarregado');

        $this->actingAs($criador);
        $this->abrirObraDetalhe($obra)
            ->call('abrirEdicaoPerfis', $membro->id)
            ->set('perfisSelecionadosEdicao', [$encarregado->id])
            ->call('salvarPerfisMembro');

        $membro = $membro->fresh();
        $this->assertFalse($membro->temPerfilNaObra($obra, 'engenheiro'));
        $this->assertTrue($membro->temPerfilNaObra($obra, 'encarregado'));
    }

    public function test_d_remover_todos_preserva_membership_e_avisa(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $membro = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $membro, 'engenheiro');

        $this->actingAs($criador);
        $this->abrirObraDetalhe($obra)
            ->call('abrirEdicaoPerfis', $membro->id)
            ->set('perfisSelecionadosEdicao', [])
            ->call('salvarPerfisMembro')
            ->assertDispatched('show-toast');

        $this->assertTrue($obra->users()->where('user_id', $membro->id)->exists(), 'Continua membro da equipe.');
        $this->assertSame([], $membro->fresh()->perfisNaObra($obra)->pluck('id')->all());
        $this->assertFalse($membro->fresh()->temAcessoAObra($obra));
    }

    /**
     * O criador do tenant só pode ter seus próprios perfis alterados por
     * um administrador da plataforma em impersonation ativa (mesma trava
     * já usada por alterarPerfil()/removerMembro() desde a Fase 2B —
     * reafirmada aqui, e não uma trava nova desta correção). Por isso o
     * cenário de "único Admin" usa esse mesmo padrão já validado em
     * Fase2BRbacProfissionalTest::test_admin_com_varios_perfis_nao_pode_perder_o_unico_perfil_admin.
     */
    public function test_e_ultimo_admin_bloqueado_via_nova_ui_multiperfil(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]); // criador já é Admin automático
        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');

        $tenantDoAdmin = Tenant::factory()->create();
        $platformAdmin = User::factory()->create(['tenant_id' => $tenantDoAdmin->id, 'is_platform_admin' => true]);
        $this->actingAs($platformAdmin);
        \App\Support\ImpersonationContext::start($tenant);

        $this->abrirObraDetalhe($obra)
            ->call('abrirEdicaoPerfis', $criador->id)
            ->set('perfisSelecionadosEdicao', [$engenheiro->id]) // remove Admin, mantém só Engenheiro
            ->call('salvarPerfisMembro')
            ->assertDispatched('show-toast');

        $this->assertTrue($criador->fresh()->temPerfilNaObra($obra, 'admin'), 'Único Admin nunca pode perder o status via a nova UI.');
    }

    public function test_f_admin_pode_manter_admin_mais_um_perfil_secundario_via_nova_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $admin = Perfil::porSlugPadrao($tenant, 'admin');
        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');

        $tenantDoAdmin = Tenant::factory()->create();
        $platformAdmin = User::factory()->create(['tenant_id' => $tenantDoAdmin->id, 'is_platform_admin' => true]);
        $this->actingAs($platformAdmin);
        \App\Support\ImpersonationContext::start($tenant);

        $this->abrirObraDetalhe($obra)
            ->call('abrirEdicaoPerfis', $criador->id)
            ->set('perfisSelecionadosEdicao', [$admin->id, $engenheiro->id])
            ->call('salvarPerfisMembro');

        $criador = $criador->fresh();
        $this->assertTrue($criador->temPerfilNaObra($obra, 'admin'));
        $this->assertTrue($criador->temPerfilNaObra($obra, 'engenheiro'));
    }

    public function test_g_cross_obra_sem_autoridade_na_obra_b_bloqueado(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obraA = Work::factory()->create(['tenant_id' => $tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $tenant->id]);
        $gerenteDaObraA = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obraA, $gerenteDaObraA, 'gerente_planejamento');
        // gerenteDaObraA NUNCA foi vinculado à obraB.
        $membroDaObraB = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obraB, $membroDaObraB, 'engenheiro');

        $this->actingAs($gerenteDaObraA);
        $this->abrirObraDetalhe($obraB)
            ->call('abrirEdicaoPerfis', $membroDaObraB->id)
            ->assertForbidden();
    }

    public function test_h_perfil_de_outro_tenant_manipulado_no_payload_nunca_e_aceito(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenantA->id]);
        $tenantA->update(['criado_por_id' => $criador->id]);
        $obraA = Work::factory()->create(['tenant_id' => $tenantA->id]);
        $membro = User::factory()->create(['tenant_id' => $tenantA->id]);
        $this->vincularObra($obraA, $membro, 'engenheiro');

        $perfilDeB = Perfil::create(['tenant_id' => $tenantB->id, 'nome' => 'Perfil de Outro Tenant']);

        $this->actingAs($criador);
        $this->abrirObraDetalhe($obraA)
            ->call('abrirEdicaoPerfis', $membro->id)
            ->set('perfisSelecionadosEdicao', [$perfilDeB->id])
            ->call('salvarPerfisMembro');

        $this->assertSame([], ObraUserPerfil::where('work_id', $obraA->id)->where('user_id', $membro->id)->pluck('perfil_id')->all(), 'Perfil de outro tenant nunca deveria ter sido persistido — a revalidação contra perfisDisponiveis o filtra.');
    }
}
