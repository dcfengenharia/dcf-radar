<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Perfis\RegistrarEventoAcesso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FASE 2D — UI da tela "Histórico de Acessos" (Seções 34-42, 54-55).
 */
class Fase2DHistoricoAcessosUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $criador;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->criador = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->criador, Papel::GerentePlanejamento->value);
        $this->tenant->update(['criado_por_id' => $this->criador->id]);
        $this->actingAs($this->criador);
    }

    public function test_criador_do_tenant_acessa_a_pagina(): void
    {
        Livewire::test('pages::gestao.historico-acessos')->assertOk();
    }

    public function test_usuario_sem_autoridade_recebe_forbidden(): void
    {
        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $membro, Papel::Admin->value);
        $this->actingAs($membro);

        // Mesmo padrão de PerfilAcessoTest — navegação de página cheia
        // sem acesso vira redirect com flash.popup, nunca 403 cru.
        $this->get(route('gestao.historico-acessos'))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_menu_so_aparece_para_quem_tem_autoridade(): void
    {
        $this->get('/app/historico-acessos')->assertOk()->assertSee('Histórico de Acessos');

        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $membro, Papel::Encarregado->value);
        $this->actingAs($membro);

        $this->get(route('gestao.minhas-obras'))->assertDontSee('Histórico de Acessos', false);
    }

    public function test_empty_state_quando_nao_ha_eventos(): void
    {
        Livewire::test('pages::gestao.historico-acessos')
            ->assertSee('Nenhum evento encontrado');
    }

    public function test_listagem_mostra_resumo_humano_nunca_json_cru(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Visível']);
        RegistrarEventoAcesso::perfilCriado($this->criador, $perfil, 'zero');

        Livewire::test('pages::gestao.historico-acessos')
            ->assertSee('criou o perfil "Visível"')
            ->assertDontSee('{"origem_criacao"', false);
    }

    public function test_filtro_por_tipo_de_evento(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'A']);
        RegistrarEventoAcesso::perfilCriado($this->criador, $perfil, 'zero');
        RegistrarEventoAcesso::perfilExcluido($this->criador, $perfil);

        $componente = Livewire::test('pages::gestao.historico-acessos')
            ->set('filtroTipo', \App\Enums\TipoEventoHistoricoAcesso::PerfilExcluido->value);

        $eventos = $componente->instance()->eventosPaginados();
        $this->assertCount(1, $eventos);
        $this->assertSame(\App\Enums\TipoEventoHistoricoAcesso::PerfilExcluido, $eventos->first()->tipo_evento);
    }

    public function test_filtro_por_obra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');

        \App\Support\AtribuicaoPerfilObra::definirPerfilUnico($this->obra, $membro->id, $engenheiro->id, $this->criador, \App\Enums\OrigemEventoHistoricoAcesso::EquipeObra);
        \App\Support\AtribuicaoPerfilObra::definirPerfilUnico($outraObra, $membro->id, $engenheiro->id, $this->criador, \App\Enums\OrigemEventoHistoricoAcesso::EquipeObra);

        $componente = Livewire::test('pages::gestao.historico-acessos')
            ->set('filtroObraId', $this->obra->id);

        $eventos = $componente->instance()->eventosPaginados();
        $this->assertCount(1, $eventos);
        $this->assertSame($this->obra->id, $eventos->first()->obra_id);
    }

    public function test_busca_textual_por_nome(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Planejamento Cliente']);
        RegistrarEventoAcesso::perfilCriado($this->criador, $perfil, 'zero');

        $componente = Livewire::test('pages::gestao.historico-acessos')
            ->set('busca', 'Planejamento Cliente');

        $this->assertCount(1, $componente->instance()->eventosPaginados());

        $componente->set('busca', 'Não Existe Nada Assim');
        $this->assertCount(0, $componente->instance()->eventosPaginados());
    }

    public function test_alternar_detalhe_expande_e_recolhe(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Detalhável']);
        $evento = RegistrarEventoAcesso::perfilCriado($this->criador, $perfil, 'zero');

        $componente = Livewire::test('pages::gestao.historico-acessos')
            ->call('alternarDetalhe', $evento->id);
        $this->assertSame($evento->id, $componente->get('eventoExpandidoId'));

        $componente->call('alternarDetalhe', $evento->id);
        $this->assertNull($componente->get('eventoExpandidoId'));
    }

    public function test_alternar_detalhe_com_id_de_outro_tenant_e_ignorado(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroCriador = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroTenant->update(['criado_por_id' => $outroCriador->id]);
        $perfilAlheio = \App\Support\TenantContext::actingAs($outroTenant, fn () => Perfil::create(['tenant_id' => $outroTenant->id, 'nome' => 'Alheio']));
        $eventoAlheio = \App\Support\TenantContext::actingAs($outroTenant, fn () => RegistrarEventoAcesso::perfilCriado($outroCriador, $perfilAlheio, 'zero'));

        $componente = Livewire::test('pages::gestao.historico-acessos')
            ->call('alternarDetalhe', $eventoAlheio->id);

        $this->assertNull($componente->get('eventoExpandidoId'));
    }

    public function test_paginacao_lista_20_por_pagina(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => "Perfil {$i}"]);
            RegistrarEventoAcesso::perfilCriado($this->criador, $perfil, 'zero');
        }

        $componente = Livewire::test('pages::gestao.historico-acessos');
        $eventos = $componente->instance()->eventosPaginados();

        $this->assertSame(25, $eventos->total());
        $this->assertCount(20, $eventos->items());
        $this->assertSame(2, $eventos->lastPage());
    }

    /**
     * Seção 55 — 100 vs 1000 eventos: a listagem nunca faz eager-load de
     * ator/usuarioAfetado/perfil/obra (tudo já denormalizado), então o
     * NÚMERO de queries pra renderizar a listagem é fixo, independente
     * do volume de eventos.
     */
    public function test_performance_query_count_nao_escala_com_volume_de_eventos(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => "Perfil {$i}"]);
            RegistrarEventoAcesso::perfilCriado($this->criador, $perfil, 'zero');
        }

        DB::enableQueryLog();
        Livewire::test('pages::gestao.historico-acessos');
        $comPoucos = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        for ($i = 0; $i < 100; $i++) {
            $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => "Perfil Extra {$i}"]);
            RegistrarEventoAcesso::perfilCriado($this->criador, $perfil, 'zero');
        }

        // Log fica desligado durante a criação dos 100 eventos extras de
        // propósito — só queremos medir o custo da LISTAGEM, nunca o
        // custo (irrelevante aqui) de popular o fixture de teste.
        DB::enableQueryLog();
        Livewire::test('pages::gestao.historico-acessos');
        $comMuitos = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($comPoucos, $comMuitos, 'Query count não pode escalar com o total de eventos (só a página atual é lida).');
    }
}
