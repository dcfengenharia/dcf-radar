<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Atividade;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingPopupTest extends TestCase
{
    use RefreshDatabase;

    public function test_popup_aparece_apos_login_quando_ha_pendencia_obrigatoria(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->get(route('app.home'))
            ->assertOk()
            ->assertSee('Bem-vindo(a) ao DCF Radar')
            ->assertSee('Cadastrar 1º Cliente');
    }

    public function test_popup_nao_aparece_quando_nao_ha_pendencia_obrigatoria(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);
        Client::factory()->create(['tenant_id' => $tenant->id]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->get(route('app.home'))
            ->assertOk()
            ->assertDontSee('Bem-vindo(a) ao DCF Radar');
    }

    public function test_popup_nao_reaparece_em_navegacao_seguinte_no_mesmo_login(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->get(route('app.home'))->assertSee('Bem-vindo(a) ao DCF Radar');
        $this->get(route('app.home'))->assertDontSee('Bem-vindo(a) ao DCF Radar');
    }

    public function test_popup_nao_aparece_sem_login_via_actingas(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $this->get(route('app.home'))
            ->assertOk()
            ->assertDontSee('Bem-vindo(a) ao DCF Radar');
    }

    public function test_popup_lista_pendencia_da_obra_atual_alem_da_do_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        Client::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $user, Papel::Admin->value);
        ObraContext::set($obra);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->get(route('app.home'))
            ->assertOk()
            ->assertSee('Bem-vindo(a) ao DCF Radar')
            ->assertDontSee('Cadastrar 1º Cliente')
            ->assertSee('Cadastrar Atividades');
    }
}
