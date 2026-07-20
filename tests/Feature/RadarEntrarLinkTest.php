<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RadarEntrarLinkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Engenheiro->value);
    }

    public function test_entrar_sem_responsavel_redireciona_para_restricoes_sem_query_string(): void
    {
        $this->actingAs($this->user)
            ->get(route('radar.entrar', ['obraId' => $this->obra->id]))
            ->assertRedirect(route('radar.restricoes'));

        $this->assertEquals($this->obra->id, ObraContext::currentId());
    }

    public function test_entrar_com_responsavel_redireciona_com_query_string(): void
    {
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->user)
            ->get(route('radar.entrar', ['obraId' => $this->obra->id, 'responsavel' => $responsavel->id]))
            ->assertRedirect(route('radar.restricoes', ['responsavel' => $responsavel->id]));
    }

    public function test_obra_inexistente_mostra_banner_amigavel_em_vez_de_404(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('radar.entrar', ['obraId' => 'obra-que-nao-existe']));

        $response->assertRedirect(route('gestao.minhas-obras'));
        $response->assertSessionHas('flash.banner');
        $response->assertSessionHas('flash.bannerStyle', 'danger');
    }

    public function test_usuario_sem_acesso_a_obra_mostra_banner_amigavel_em_vez_de_403(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($this->user)
            ->get(route('radar.entrar', ['obraId' => $outraObra->id]));

        $response->assertRedirect(route('gestao.minhas-obras'));
        $response->assertSessionHas('flash.banner');
        $response->assertSessionHas('flash.bannerStyle', 'danger');
    }

    public function test_usuario_deslogado_faz_login_e_cai_na_url_original_com_query_string(): void
    {
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $urlOriginal = route('radar.entrar', ['obraId' => $this->obra->id, 'responsavel' => $responsavel->id]);

        $this->get($urlOriginal)->assertRedirect(route('login'));

        $this->post('/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertRedirect($urlOriginal);
    }
}
