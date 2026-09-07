<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 23, Etapa 23.1 — smoke test da UI
 * (`pages::gestao.licoes-aprendidas`). Não duplica a cobertura de
 * domínio já exaustiva em LicaoAprendidaTest — só garante que o
 * componente monta, autoriza, e o fluxo básico funciona através da
 * camada Livewire real (não das Actions isoladas).
 */
class LicaoAprendidaPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->admin, Papel::Admin->value);
        $this->actingAs($this->admin);
        ObraContext::set($this->obra);
    }

    private function componente()
    {
        return Livewire::test('pages::gestao.licoes-aprendidas');
    }

    public function test_pagina_renderiza_para_usuario_autorizado(): void
    {
        $this->componente()->assertStatus(200)->assertSee('Lições Aprendidas');
    }

    public function test_ver_e_aberto_mesmo_para_o_perfil_mais_restrito_com_vinculo_em_outra_obra(): void
    {
        // 'gestao.licoes-aprendidas' deliberadamente NÃO tem 'ver' gated
        // (diferente dos 3 Cockpits) — é uma biblioteca de conhecimento,
        // quanto mais gente puder consultar melhor. Prova real: mesmo o
        // perfil mais restrito do catálogo (ClienteLeitura), vinculado a
        // uma obra QUE NÃO É a ativa nesta sessão, ainda acessa a página
        // (ela é ESCOPO_TENANT — usa temPermissaoEmAlgumaObraDoTenant()).
        $outroUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $outroUser, Papel::ClienteLeitura->value);

        $this->actingAs($outroUser);

        $this->componente()->assertStatus(200);
    }

    public function test_fluxo_completo_criar_enviar_publicar_pelo_componente(): void
    {
        $c = $this->componente();

        $c->call('abrirCriar')
            ->set('formTitulo', 'Atraso recorrente de concreto')
            ->set('formSituacao', 'A usina atrasou 3 concretagens seguidas.')
            ->set('formRecomendacao', 'Qualificar uma segunda usina reserva.')
            ->set('formTipo', 'problema')
            ->set('formCriticidade', 'alta')
            ->set('formArea', 'suprimentos')
            ->call('salvarForm');

        $licao = \App\Models\LicaoAprendida::where('titulo', 'Atraso recorrente de concreto')->first();
        $this->assertNotNull($licao);
        $this->assertSame('rascunho', $licao->status->value);

        $c->call('enviarParaValidacao', $licao->id);
        $this->assertSame('em_validacao', $licao->fresh()->status->value);

        $c->call('publicar', $licao->id);
        $this->assertSame('publicada', $licao->fresh()->status->value);
        $this->assertNotNull($licao->fresh()->publicado_em);
    }

    public function test_usuario_sem_permissao_de_publicar_nao_consegue_via_componente(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);

        $licao = app(\App\Actions\LicoesAprendidas\CriarLicaoAprendida::class)->execute(
            $this->obra,
            $this->admin,
            [
                'titulo' => 'Título',
                'situacao_observada' => 'Situação',
                'recomendacao_futura' => 'Recomendação',
                'tipo' => 'problema',
                'criticidade' => 'baixa',
                'area_funcional' => 'campo',
            ]
        );
        app(\App\Actions\LicoesAprendidas\EnviarLicaoParaValidacao::class)->execute($licao);

        $this->actingAs($encarregado);
        ObraContext::set($this->obra);

        $this->componente()->call('publicar', $licao->id);

        $this->assertSame('em_validacao', $licao->fresh()->status->value, 'Encarregado não tem permissão de publicar — status não deve mudar.');
    }

    public function test_toggle_esta_obra_e_todas_as_obras(): void
    {
        $this->componente()
            ->assertSet('modoVisualizacao', 'esta_obra')
            ->set('modoVisualizacao', 'todas_obras')
            ->assertSet('modoVisualizacao', 'todas_obras');
    }

    public function test_sem_obra_ativa_forca_modo_todas_as_obras(): void
    {
        ObraContext::clear();

        $this->componente()->assertSet('modoVisualizacao', 'todas_obras');
    }

    public function test_menu_lista_licoes_aprendidas_para_usuario_autorizado(): void
    {
        $this->get(route('gestao.licoes-aprendidas'))->assertOk();
    }
}
