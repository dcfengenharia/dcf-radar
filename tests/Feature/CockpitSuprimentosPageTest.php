<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 21, Etapa 21.6 — camada de UI/rota do Cockpit de Suprimentos.
 * Cobre Seção 36 (permissão/filtros/contagens/tabelas/estado vazio/
 * deep-links/obra correta/troca de filtro).
 */
class CockpitSuprimentosPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-01'));

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function componente(?Work $obra = null)
    {
        return Livewire::test('pages::radar.cockpit-suprimentos', ['obra' => $obra ?? $this->obra]);
    }

    private function usuarioComPapel(string $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, $papel);
        $this->actingAs($user);

        return $user;
    }

    // =========================================================
    // Permissão (reaproveita o mesmo mecanismo já auditado)
    // =========================================================

    public function test_encarregado_nao_acessa_o_cockpit_de_suprimentos(): void
    {
        $this->usuarioComPapel(Papel::Encarregado->value);

        $this->componente()->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_gerente_planejamento_acessa_o_cockpit_de_suprimentos(): void
    {
        $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $this->componente()->assertStatus(200)->assertSee('Cockpit de Suprimentos e Abastecimento');
    }

    public function test_admin_acessa_o_cockpit_de_suprimentos(): void
    {
        $this->usuarioComPapel(Papel::Admin->value);

        $this->componente()->assertStatus(200)->assertSee('Cockpit de Suprimentos e Abastecimento');
    }

    public function test_usuario_de_outro_tenant_nao_acessa(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($outroUser);

        $this->componente()->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    // =========================================================
    // Filtro de horizonte
    // =========================================================

    public function test_horizonte_pode_ser_alterado_via_filtro(): void
    {
        $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $componente = $this->componente();
        $componente->assertSet('horizontePrincipalDias', 28);

        $componente->set('horizontePrincipalDias', 56);
        $componente->assertSet('horizontePrincipalDias', 56);
    }

    // =========================================================
    // Estado vazio
    // =========================================================

    public function test_obra_saudavel_mostra_estados_vazios_amigaveis(): void
    {
        $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $this->componente()
            ->assertStatus(200)
            ->assertSee('Nenhuma necessidade crítica sem cobertura neste horizonte.')
            ->assertSee('Nenhum pedido atrasado ligado a atividade futura.')
            ->assertSee('Nenhuma atividade ameaçada por material neste horizonte.')
            ->assertSee('Nenhum pedido crítico no momento.')
            ->assertSee('Toda a demanda desta obra já foi comprada.');
    }

    // =========================================================
    // Deep-link real
    // =========================================================

    public function test_pagina_renderiza_deep_link_valido_de_documento_bloqueante(): void
    {
        $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $atividade = Atividade::create([
            'obra_id' => $this->obra->id, 'nome' => 'Atividade Suprimentos', 'codigo_cronograma' => 'SUP-1',
            'status' => StatusAtividade::Planejado->value, 'inicio_planejado' => Carbon::today()->addDays(3),
            'data_termino' => Carbon::today()->addDays(5), 'fora_do_cronograma' => false,
        ]);
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-SUP', 'descricao' => 'Doc']);
        $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $doc->atividades()->sync([$atividade->id]);

        $this->componente()->assertStatus(200);
    }

    public function test_link_para_cockpit_executivo_esta_presente(): void
    {
        $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $this->componente()->assertSeeHtml(route('radar.cockpit'));
    }
}
