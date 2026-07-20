<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ImpersonationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adaptação da regra "criador do tenant é Admin imutável": o dono da
 * plataforma (is_platform_admin) pode alterar/remover o perfil de
 * QUALQUER usuário, inclusive quem criou o tenant — mas uma trava mais
 * geral substitui o bloqueio absoluto: nenhum tenant pode ficar sem
 * NENHUM perfil Admin, e isso vale até pro dono da plataforma.
 */
class PlatformAdminPerfilOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $criador;
    private User $donoDaPlataforma;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->criador = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->tenant->update(['criado_por_id' => $this->criador->id]);

        $outroTenant = Tenant::factory()->create();
        $this->donoDaPlataforma = User::factory()->create(['tenant_id' => $outroTenant->id, 'is_platform_admin' => true]);

        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        // O hook Work::garantirCriadorDoTenantComoAdmin() já deixou $this->criador como Admin aqui.
    }

    public function test_dono_da_plataforma_altera_perfil_do_criador_do_tenant_se_houver_outro_admin(): void
    {
        $outroAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfilAdmin = Perfil::porSlugPadrao($this->tenant, 'admin');
        $this->obra->users()->syncWithoutDetaching([$outroAdmin->id => ['perfil_id' => $perfilAdmin->id]]);

        $this->actingAs($this->donoDaPlataforma);
        ImpersonationContext::start($this->tenant);

        $perfilGerente = Perfil::porSlugPadrao($this->tenant, 'gerente_planejamento');

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('alterarPerfil', $this->criador->id, $perfilGerente->id);

        $this->assertEquals(
            $perfilGerente->id,
            $this->obra->users()->where('user_id', $this->criador->id)->first()->pivot->perfil_id
        );
    }

    public function test_dono_da_plataforma_nao_consegue_deixar_o_tenant_sem_nenhum_admin(): void
    {
        // $this->criador é o ÚNICO Admin nesta obra/tenant.
        $this->actingAs($this->donoDaPlataforma);
        ImpersonationContext::start($this->tenant);

        $perfilGerente = Perfil::porSlugPadrao($this->tenant, 'gerente_planejamento');

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('alterarPerfil', $this->criador->id, $perfilGerente->id)
            ->assertDispatched('show-toast');

        $perfilAdmin = Perfil::porSlugPadrao($this->tenant, 'admin');
        $this->assertEquals(
            $perfilAdmin->id,
            $this->obra->users()->where('user_id', $this->criador->id)->first()->pivot->perfil_id
        );
    }

    public function test_usuario_comum_ainda_nao_consegue_alterar_o_criador_mesmo_com_outro_admin(): void
    {
        $outroAdmin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfilAdmin = Perfil::porSlugPadrao($this->tenant, 'admin');
        $this->obra->users()->syncWithoutDetaching([$outroAdmin->id => ['perfil_id' => $perfilAdmin->id]]);
        $this->actingAs($outroAdmin);

        $perfilGerente = Perfil::porSlugPadrao($this->tenant, 'gerente_planejamento');

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('alterarPerfil', $this->criador->id, $perfilGerente->id)
            ->assertForbidden();
    }

    public function test_remover_o_unico_admin_nao_criador_tambem_e_bloqueado(): void
    {
        // Generaliza a trava: não é só sobre o criador, é sobre "zero admins".
        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $gerente, Papel::GerentePlanejamento->value);

        // Rebaixa o criador (via dono da plataforma) e promove $gerente a Admin,
        // deixando $gerente como o ÚNICO Admin do tenant.
        $perfilAdmin = Perfil::porSlugPadrao($this->tenant, 'admin');
        $perfilGerente = Perfil::porSlugPadrao($this->tenant, 'gerente_planejamento');
        $this->obra->users()->updateExistingPivot($gerente->id, ['perfil_id' => $perfilAdmin->id]);

        $this->actingAs($this->donoDaPlataforma);
        ImpersonationContext::start($this->tenant);
        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('alterarPerfil', $this->criador->id, $perfilGerente->id);

        // Agora $gerente é o único Admin. Tentar rebaixá-lo deve ser bloqueado
        // mesmo ele NÃO sendo o criador do tenant.
        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('alterarPerfil', $gerente->id, $perfilGerente->id)
            ->assertDispatched('show-toast');

        $this->assertEquals(
            $perfilAdmin->id,
            $this->obra->users()->where('user_id', $gerente->id)->first()->pivot->perfil_id
        );
    }
}
