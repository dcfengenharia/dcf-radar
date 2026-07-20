<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Client;
use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regra de negócio: quem criou o tenant é Admin, automática e
 * imutavelmente, em TODAS as obras — inclusive obras criadas por outra
 * pessoa, inclusive obras que já existiam antes desta regra (backfill
 * na migration 2026_07_11_000001).
 */
class CriadorTenantAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $criador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->criador = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->tenant->update(['criado_por_id' => $this->criador->id]);
    }

    public function test_obra_criada_por_outra_pessoa_ja_vincula_o_criador_do_tenant_como_admin(): void
    {
        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        // O hook Work::garantirCriadorDoTenantComoAdmin() já rodou no created().

        $perfilAdmin = Perfil::porSlugPadrao($this->tenant, 'admin');
        $this->assertEquals(
            $perfilAdmin->id,
            $obra->users()->where('user_id', $this->criador->id)->first()->pivot->perfil_id
        );
    }

    public function test_criador_do_tenant_criando_sua_propria_obra_permanece_admin_nao_vira_gerente(): void
    {
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        Livewire::actingAs($this->criador)
            ->test('obras.create')
            ->set('name', 'Obra do Dono')
            ->set('clientId', $client->id)
            ->set('status', 'planejamento')
            ->call('saveWork');

        $obra = Work::where('name', 'Obra do Dono')->first();
        $perfilAdmin = Perfil::porSlugPadrao($this->tenant, 'admin');

        $this->assertEquals(
            $perfilAdmin->id,
            $obra->users()->where('user_id', $this->criador->id)->first()->pivot->perfil_id
        );
    }

    public function test_backfill_coloca_criador_como_admin_em_obras_que_ja_existiam(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        // Obra só tem outro usuário, sem o criador do tenant — simula estado pré-regra.
        $outro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $obra->users()->syncWithoutDetaching([$outro->id => ['perfil_id' => $engenheiro->id]]);
        $obra->users()->detach($this->criador->id);

        $this->assertFalse($obra->users()->where('user_id', $this->criador->id)->exists());

        $obra->garantirCriadorDoTenantComoAdmin();

        $perfilAdmin = Perfil::porSlugPadrao($this->tenant, 'admin');
        $this->assertEquals(
            $perfilAdmin->id,
            $obra->users()->where('user_id', $this->criador->id)->first()->pivot->perfil_id
        );
    }

    public function test_nao_e_possivel_alterar_nem_remover_o_criador_do_tenant_via_equipe(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obra, $gerente, Papel::GerentePlanejamento->value);
        $this->actingAs($gerente);

        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $obra])
            ->call('alterarPerfil', $this->criador->id, $engenheiro->id)
            ->assertForbidden();

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $obra])
            ->call('removerMembro', $this->criador->id)
            ->assertForbidden();

        $perfilAdmin = Perfil::porSlugPadrao($this->tenant, 'admin');
        $this->assertEquals(
            $perfilAdmin->id,
            $obra->users()->where('user_id', $this->criador->id)->first()->pivot->perfil_id
        );
    }

    public function test_perfil_admin_nao_pode_ser_excluido(): void
    {
        $this->actingAs($this->criador);
        $admin = Perfil::porSlugPadrao($this->tenant, 'admin');

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('excluirPerfil', $admin->id);

        $this->assertNotNull(Perfil::find($admin->id));
    }
}
