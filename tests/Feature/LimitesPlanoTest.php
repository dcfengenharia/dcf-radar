<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusAssinatura;
use App\Models\Assinatura;
use App\Models\Client;
use App\Models\Convite;
use App\Models\Perfil;
use App\Models\Plano;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;
use Tests\TestCase;

class LimitesPlanoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTenantComPlano(?int $maxObras = null, ?int $maxUsuarios = null, StatusAssinatura $status = StatusAssinatura::Ativa): Tenant
    {
        $tenant = Tenant::factory()->create();

        $plano = Plano::factory()->create(['max_obras' => $maxObras, 'max_usuarios' => $maxUsuarios]);
        Assinatura::factory()->create([
            'tenant_id' => $tenant->id,
            'plano_id' => $plano->id,
            'status' => $status->value,
        ]);

        return $tenant;
    }

    // =========================================================================
    // Limite de obras
    // =========================================================================

    public function test_criar_obra_alem_do_limite_do_plano_e_bloqueada(): void
    {
        $tenant = $this->criarTenantComPlano(maxObras: 1);
        Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->set('name', 'Segunda Obra')
            ->set('clientId', $client->id)
            ->call('saveWork')
            ->assertHasErrors(['name']);

        $this->assertSame(1, Work::where('tenant_id', $tenant->id)->count());
    }

    public function test_criar_obra_dentro_do_limite_do_plano_funciona(): void
    {
        $tenant = $this->criarTenantComPlano(maxObras: 2);
        Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)
            ->test('obras.create')
            ->set('name', 'Segunda Obra')
            ->set('clientId', $client->id)
            ->call('saveWork')
            ->assertHasNoErrors();

        $this->assertSame(2, Work::where('tenant_id', $tenant->id)->count());
    }

    public function test_tenant_sem_assinatura_nao_tem_limite_de_obras(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNull($tenant->limiteObras());
    }

    public function test_plano_sem_max_obras_definido_e_ilimitado(): void
    {
        $tenant = $this->criarTenantComPlano(maxObras: null);

        $this->assertNull($tenant->fresh()->limiteObras());
    }

    // =========================================================================
    // Limite de usuários
    // =========================================================================

    public function test_convidar_usuario_novo_alem_do_limite_e_bloqueado(): void
    {
        $tenant = $this->criarTenantComPlano(maxUsuarios: 1);
        // O próprio gerente já ocupa a única vaga do plano.
        $gerente = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $gerente, Papel::GerentePlanejamento->value);
        $this->actingAs($gerente);

        $encarregado = Perfil::porSlugPadrao($tenant, 'encarregado');

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $obra])
            ->set('emailConvite', 'novo@example.com')
            ->set('perfilConviteId', $encarregado->id)
            ->call('enviarConvite')
            ->assertHasErrors(['emailConvite']);

        $this->assertSame(0, Convite::count());
    }

    public function test_aceitar_convite_alem_do_limite_de_usuarios_e_bloqueado(): void
    {
        $tenant = $this->criarTenantComPlano(maxUsuarios: 1);
        $gerente = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $gerente, Papel::GerentePlanejamento->value);

        $convite = Convite::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'convidado_por_id' => $gerente->id,
            'email' => 'tarde-demais@example.com',
            'status' => 'pendente',
        ]);

        $response = $this->post(route('convite.aceitar', $convite->token), [
            'first_name' => 'Tarde',
            'last_name' => 'Demais',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);

        $response->assertRedirect(route('login'));
        $this->assertNull(User::where('email', 'tarde-demais@example.com')->first());
        $this->assertSame('pendente', $convite->fresh()->status);
    }

    // =========================================================================
    // Rate limiting de convite
    // =========================================================================

    public function test_envio_de_convite_e_bloqueado_apos_estourar_o_limite(): void
    {
        ['user' => $gerente, 'obra' => $obra] = $this->criarUsuarioComObra();
        $encarregado = Perfil::porSlugPadrao($gerente->tenant, 'encarregado');

        for ($i = 0; $i < 10; $i++) {
            Livewire::actingAs($gerente)
                ->test('pages::gestao.obra-detalhe', ['obra' => $obra])
                ->set('emailConvite', "convidado{$i}@example.com")
                ->set('perfilConviteId', $encarregado->id)
                ->call('enviarConvite');
        }

        Livewire::actingAs($gerente)
            ->test('pages::gestao.obra-detalhe', ['obra' => $obra])
            ->set('emailConvite', 'convidado-alem-do-limite@example.com')
            ->set('perfilConviteId', $encarregado->id)
            ->call('enviarConvite')
            ->assertHasErrors(['emailConvite']);

        $this->assertSame(10, Convite::count());
    }

    private function criarUsuarioComObra(): array
    {
        $tenant = Tenant::factory()->create();
        $gerente = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $gerente, Papel::GerentePlanejamento->value);

        return ['user' => $gerente, 'obra' => $obra];
    }

    // =========================================================================
    // Corte de acesso — tenant Inadimplente
    // =========================================================================

    public function test_usuario_de_tenant_inadimplente_perde_acesso(): void
    {
        $tenant = $this->criarTenantComPlano(status: StatusAssinatura::Inadimplente);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->get(route('app.home'))
            ->assertRedirect(route('login'));
    }

    public function test_usuario_de_tenant_com_assinatura_ativa_mantem_acesso(): void
    {
        $tenant = $this->criarTenantComPlano(status: StatusAssinatura::Ativa);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->get(route('app.home'))
            ->assertOk();
    }

    public function test_tenant_sem_assinatura_mantem_acesso(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->get(route('app.home'))
            ->assertOk();
    }

    public function test_admin_da_plataforma_ignora_o_corte_de_acesso(): void
    {
        $tenant = $this->criarTenantComPlano(status: StatusAssinatura::Inadimplente);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'is_platform_admin' => true]);

        $this->actingAs($admin)
            ->get(route('app.home'))
            ->assertOk();
    }
}
