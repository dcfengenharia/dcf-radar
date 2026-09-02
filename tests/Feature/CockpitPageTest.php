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
 * Ciclo 21, Etapa 21.5 — camada de UI/rota do Cockpit Executivo. Cobre a
 * Seção 33/34 (segurança/permissão) e o Cenário N (deep-link) do pedido
 * — os cenários A-M/O/P de LEITURA de dado já estão cobertos em
 * `CockpitObraQueryTest.php` (o read model), nunca duplicados aqui.
 */
class CockpitPageTest extends TestCase
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
        return Livewire::test('pages::radar.cockpit', ['obra' => $obra ?? $this->obra]);
    }

    private function usuarioComPapel(string $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, $papel);
        $this->actingAs($user);

        return $user;
    }

    // =========================================================
    // Seção 34 — permissão dedicada, nunca aberta por padrão
    // =========================================================

    public function test_encarregado_nao_acessa_o_cockpit(): void
    {
        $this->usuarioComPapel(Papel::Encarregado->value);

        $this->componente()->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_engenheiro_nao_acessa_o_cockpit(): void
    {
        $this->usuarioComPapel(Papel::Engenheiro->value);

        $this->componente()->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_cliente_leitura_nao_acessa_o_cockpit(): void
    {
        $this->usuarioComPapel(Papel::ClienteLeitura->value);

        $this->componente()->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_gerente_planejamento_acessa_o_cockpit(): void
    {
        $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $this->componente()->assertStatus(200)->assertSee('Cockpit Executivo');
    }

    public function test_admin_acessa_o_cockpit(): void
    {
        $this->usuarioComPapel(Papel::Admin->value);

        $this->componente()->assertStatus(200)->assertSee('Cockpit Executivo');
    }

    public function test_usuario_sem_nenhum_vinculo_com_a_obra_nao_acessa(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user);

        $this->componente()->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    // =========================================================
    // Seção 33 — segurança cross-tenant (usuário sem vínculo, tenant diferente)
    // =========================================================

    public function test_usuario_de_outro_tenant_nao_acessa_obra_deste_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($outroUser);

        $this->componente()->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    // =========================================================
    // Renderização com dado real + Cenário N (deep-link)
    // =========================================================

    public function test_pagina_renderiza_situacoes_reais_com_deep_link_valido(): void
    {
        $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $atividade = Atividade::create([
            'obra_id' => $this->obra->id, 'nome' => 'Atividade Cockpit', 'codigo_cronograma' => 'CKP-1',
            'status' => StatusAtividade::Planejado->value, 'inicio_planejado' => Carbon::today()->addDays(3),
            'data_termino' => Carbon::today()->addDays(5), 'fora_do_cronograma' => false,
        ]);
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-CKP', 'descricao' => 'Doc']);
        $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $doc->atividades()->sync([$atividade->id]);

        // Deep-link de DocumentoBloqueante aponta pra 'engenharia.pacotes'
        // (Seção 21) — a página nunca deve lançar RouteNotFoundException
        // ao montar o href, e o link real precisa bater com route().
        $esperado = route('engenharia.pacotes', ['documento' => $doc->id]);

        $this->componente()
            ->assertStatus(200)
            ->assertSee('Documento')
            ->assertSeeHtml($esperado);
    }

    public function test_horizonte_pode_ser_alterado_via_filtro(): void
    {
        $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $componente = $this->componente();
        $componente->assertSet('horizontePrincipalDias', 28);

        $componente->set('horizontePrincipalDias', 56);
        $componente->assertSet('horizontePrincipalDias', 56);
    }

    public function test_obra_saudavel_mostra_estado_vazio_amigavel(): void
    {
        $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $this->componente()
            ->assertStatus(200)
            ->assertSee('Nenhuma situação crítica ativa neste horizonte.');
    }
}
