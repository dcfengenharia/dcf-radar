<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\Client;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use App\Support\Onboarding\OnboardingChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
    }

    private function chaves(array $passos): array
    {
        return array_map(fn ($passo) => $passo->chave, $passos);
    }

    public function test_passos_tenant_ficam_pendentes_sem_cliente_e_obra(): void
    {
        $pendentes = $this->chaves(OnboardingChecklist::pendentesObrigatorios(OnboardingChecklist::passosTenant()));

        $this->assertContains('cliente_cadastrado', $pendentes);
        $this->assertContains('obra_cadastrada', $pendentes);
    }

    public function test_passo_cliente_cadastrado_conclui_apos_criar_cliente(): void
    {
        Client::factory()->create(['tenant_id' => $this->user->tenant_id]);

        $pendentes = $this->chaves(OnboardingChecklist::pendentesObrigatorios(OnboardingChecklist::passosTenant()));

        $this->assertNotContains('cliente_cadastrado', $pendentes);
    }

    public function test_passo_obra_cadastrada_conclui_apos_criar_obra(): void
    {
        Work::factory()->create(['tenant_id' => $this->user->tenant_id]);

        $pendentes = $this->chaves(OnboardingChecklist::pendentesObrigatorios(OnboardingChecklist::passosTenant()));

        $this->assertNotContains('obra_cadastrada', $pendentes);
    }

    public function test_passo_categoria_restricao_e_recomendado_nao_obrigatorio(): void
    {
        $passos = OnboardingChecklist::passosTenant();
        $categoria = collect($passos)->firstWhere('chave', 'categoria_restricao_cadastrada');

        $this->assertFalse($categoria->obrigatorio);

        CategoriaRestricao::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $this->assertTrue($categoria->estaConcluido());
    }

    public function test_passo_atividades_cadastradas_fica_pendente_sem_atividade_na_obra(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);

        $pendentes = $this->chaves(OnboardingChecklist::pendentesObrigatorios(OnboardingChecklist::passosObra($obra)));

        $this->assertContains('atividades_cadastradas', $pendentes);
    }

    public function test_passo_atividades_cadastradas_conclui_apos_criar_atividade_manual(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        Atividade::factory()->create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $obra->id]);

        $pendentes = $this->chaves(OnboardingChecklist::pendentesObrigatorios(OnboardingChecklist::passosObra($obra)));

        $this->assertNotContains('atividades_cadastradas', $pendentes);
    }

    public function test_acesso_ao_radar_redireciona_para_onboarding_quando_obra_sem_atividades(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $this->vincularObra($obra, $this->user, Papel::Admin->value);
        ObraContext::set($obra);

        $this->get(route('radar.restricoes'))
            ->assertRedirect(route('app.onboarding'));
    }

    public function test_pagina_de_onboarding_lista_os_passos_pendentes(): void
    {
        $this->get(route('app.onboarding'))
            ->assertOk()
            ->assertSee('Cadastrar 1º Cliente')
            ->assertSee('Cadastrar 1ª Obra');
    }

    public function test_botao_de_pendencia_aparece_no_menu_quando_ha_pendencia_obrigatoria(): void
    {
        $this->get(route('app.home'))
            ->assertSee('Configuração Pendente');
    }

    public function test_botao_de_pendencia_nao_aparece_quando_tudo_esta_configurado(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $obra->id]);
        Restricao::factory()->create(['tenant_id' => $this->user->tenant_id, 'atividade_id' => $atividade->id]);

        $this->get(route('app.home'))
            ->assertDontSee('Configuração Pendente');
    }

    public function test_passo_restricao_e_recomendado_nao_obrigatorio(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $obra->id]);

        $passos = OnboardingChecklist::passosObra($obra);
        $restricao = collect($passos)->firstWhere('chave', 'restricao_cadastrada');

        $this->assertFalse($restricao->obrigatorio);
        $this->assertFalse($restricao->estaConcluido());

        Restricao::factory()->create(['tenant_id' => $this->user->tenant_id, 'atividade_id' => $atividade->id]);
        $this->assertTrue($restricao->estaConcluido());
    }

    public function test_quadro_de_restricoes_fica_acessivel_mesmo_com_restricao_cadastrada_pendente(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $this->vincularObra($obra, $this->user, Papel::Admin->value);
        Atividade::factory()->create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $obra->id]);
        ObraContext::set($obra);

        // Atividade existe mas nenhuma restrição ainda — sem o exemption
        // dedicado, o middleware travaria justamente a página que o
        // usuário precisa visitar pra concluir o passo.
        $this->get(route('radar.restricoes'))
            ->assertOk();
    }

    public function test_tela_de_cronograma_continua_acessivel_mesmo_com_obra_sem_atividades(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $this->vincularObra($obra, $this->user, Papel::Admin->value);
        ObraContext::set($obra);

        $this->get(route('radar.cronograma'))
            ->assertOk();
    }

    public function test_radar_libera_normalmente_quando_obra_ja_tem_atividade(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $this->vincularObra($obra, $this->user, Papel::Admin->value);
        Atividade::factory()->create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $obra->id]);
        ObraContext::set($obra);

        $this->get(route('radar.restricoes'))
            ->assertOk();
    }
}
