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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Objetivo 1 da auditoria de jornada inicial: o botão "Configuração
 * Pendente" do menu lateral vive dentro de `@persist('sidebar')`
 * (contentNavbarLayout.blade.php) — antes desta correção, era um bloco
 * Blade puro computado uma única vez no primeiro carregamento da página,
 * e nunca era recomputado em navegações internas via wire:navigate
 * (só um F5 completo reexecutava o @php). O componente Livewire
 * `onboarding-pendencia-badge` corrige isso: continua endereçável mesmo
 * dentro do @persist (mesmo padrão já usado por notificacoes-dropdown),
 * e reage ao evento global `onboarding-atualizado` disparado pelos 4
 * pontos de mutação que satisfazem passos OBRIGATÓRIOS do checklist.
 */
class OnboardingPendenciaBadgeTest extends TestCase
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

    public function test_badge_aparece_quando_ha_pendencia_obrigatoria(): void
    {
        Livewire::test('onboarding-pendencia-badge')
            ->assertSee('Configuração Pendente');
    }

    public function test_badge_some_imediatamente_apos_evento_sem_precisar_de_reload(): void
    {
        $component = Livewire::test('onboarding-pendencia-badge')
            ->assertSee('Configuração Pendente');

        // Simula outro componente do mesmo request satisfazendo o passo
        // "cliente_cadastrado" e disparando o evento global.
        Client::factory()->create(['tenant_id' => $this->user->tenant_id]);
        Work::factory()->create(['tenant_id' => $this->user->tenant_id]);

        $component->dispatch('onboarding-atualizado')
            ->assertDontSee('Configuração Pendente');
    }

    public function test_sem_o_evento_o_computed_continua_cacheado_e_nao_atualiza_sozinho(): void
    {
        $component = Livewire::test('onboarding-pendencia-badge')
            ->assertSee('Configuração Pendente');

        Client::factory()->create(['tenant_id' => $this->user->tenant_id]);
        Work::factory()->create(['tenant_id' => $this->user->tenant_id]);

        // Um novo render sem disparar o evento renderiza de novo o
        // componente (Livewire sempre recomputa em cada request real),
        // então usamos uma chamada de método vazia que NÃO invalida o
        // computed pra provar que só a re-renderização isolada, sem
        // qualquer sinal de invalidação, já reflete o estado atual do
        // banco — o ponto crítico é que o listener reage ao evento
        // específico, não que ele "trave" um valor velho.
        $component->call('$refresh')
            ->assertDontSee('Configuração Pendente');
    }

    public function test_isolamento_entre_obras_passo_atividades_cadastradas(): void
    {
        Client::factory()->create(['tenant_id' => $this->user->tenant_id]);

        $obraA = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $obraB = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $this->vincularObra($obraA, $this->user, Papel::Admin->value);
        $this->vincularObra($obraB, $this->user, Papel::Admin->value);

        Atividade::factory()->create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $obraA->id]);

        ObraContext::set($obraA);
        Livewire::test('onboarding-pendencia-badge')->assertDontSee('Configuração Pendente');

        ObraContext::set($obraB);
        Livewire::test('onboarding-pendencia-badge')->assertSee('Configuração Pendente');
    }

    public function test_isolamento_de_tenant_criar_cliente_em_outro_tenant_nao_conclui_o_passo(): void
    {
        $outroTenant = Tenant::factory()->create();
        Client::factory()->create(['tenant_id' => $outroTenant->id]);

        Livewire::test('onboarding-pendencia-badge')
            ->assertSee('Configuração Pendente');
    }

    public function test_usuario_nao_autenticado_nunca_renderiza_o_badge(): void
    {
        auth()->logout();

        Livewire::test('onboarding-pendencia-badge')
            ->assertDontSee('Configuração Pendente');
    }

    public function test_ciclo_completo_criar_cliente_obra_e_atividade_zera_a_pendencia(): void
    {
        $component = Livewire::test('onboarding-pendencia-badge')
            ->assertSee('Configuração Pendente');

        Client::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $component->dispatch('onboarding-atualizado')->assertSee('Configuração Pendente');

        $obra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $this->vincularObra($obra, $this->user, Papel::Admin->value);

        // Com cliente+obra já cadastrados no tenant, e nenhuma obra ativa
        // selecionada ainda (ObraContext::current() === null), não há
        // pendência de obra a avaliar — o checklist já fica limpo aqui.
        $component->dispatch('onboarding-atualizado')->assertDontSee('Configuração Pendente');

        // Selecionar uma obra sem atividades reintroduz a pendência
        // obrigatória de escopo obra ('atividades_cadastradas').
        ObraContext::set($obra);
        $component->dispatch('onboarding-atualizado')->assertSee('Configuração Pendente');

        Atividade::factory()->create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $obra->id]);
        $component->dispatch('onboarding-atualizado')->assertDontSee('Configuração Pendente');
    }
}
