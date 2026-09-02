<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 22, Etapa 22.2 — camada de UI/rota do Cockpit de Engenharia.
 * Cobre Seção 34 (render/permissão/filtros/contagens/buckets/estado
 * vazio/informação insuficiente/deep-links/multi-obra/tenant/troca de
 * filtro/limitação) + cenário R (permissão insuficiente).
 */
class CockpitEngenhariaPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-01'));

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
        return Livewire::test('pages::radar.cockpit-engenharia', ['obra' => $obra ?? $this->obra]);
    }

    private function usuarioComPapel(string $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, $papel);
        $this->actingAs($user);

        return $user;
    }

    // =========================================================
    // R — Permissão insuficiente
    // =========================================================

    public function test_r_engenheiro_sozinho_nao_acessa_o_cockpit_de_engenharia(): void
    {
        $this->usuarioComPapel(Papel::Engenheiro->value);

        $this->componente()->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_encarregado_nao_acessa(): void
    {
        $this->usuarioComPapel(Papel::Encarregado->value);

        $this->componente()->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_gerente_planejamento_acessa(): void
    {
        $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $this->componente()->assertStatus(200)->assertSee('Cockpit de Engenharia e Liberação para Construção');
    }

    public function test_admin_acessa(): void
    {
        $this->usuarioComPapel(Papel::Admin->value);

        $this->componente()->assertStatus(200)->assertSee('Cockpit de Engenharia e Liberação para Construção');
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
    // Estado vazio — Seção 35: dois estados NUNCA iguais
    // =========================================================

    public function test_estado_vazio_sem_bloqueio_e_diferente_de_informacao_insuficiente(): void
    {
        $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $this->componente()
            ->assertStatus(200)
            ->assertSee('Nenhuma atividade com bloqueio documental neste horizonte.')
            ->assertSee('Todas as atividades do horizonte possuem documentação suficiente para determinar a prontidão documental.');
    }

    public function test_estado_com_informacao_insuficiente_mostra_mensagem_distinta(): void
    {
        $user = $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'fora_do_cronograma' => false,
            'status' => StatusAtividade::Planejado, 'inicio_planejado' => now()->addDay(),
        ]);

        // Os dois blocos são INDEPENDENTES por design (Seção 9/35: pendência
        // documental != risco operacional) — uma atividade sem nenhum
        // documento vinculado nunca gera "ação prioritária" (não existe
        // documento bloqueante pra ela) MAS aparece no bloco de Informação
        // Insuficiente. As duas mensagens de estado vazio/insuficiente
        // coexistindo na mesma tela é o comportamento CORRETO, nunca um
        // "documento bloqueando" inventado pra essa atividade.
        $this->componente()
            ->assertStatus(200)
            ->assertSee('Nenhuma atividade com bloqueio documental neste horizonte.')
            ->assertSee('Existem atividades cuja prontidão documental não pode ser determinada')
            ->assertDontSee('Todas as atividades do horizonte possuem documentação suficiente para determinar a prontidão documental.');
    }

    // =========================================================
    // Render real com bloqueio + deep-link
    // =========================================================

    public function test_pagina_renderiza_bloqueio_real_com_deep_link_valido(): void
    {
        $user = $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $at = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'fora_do_cronograma' => false,
            'status' => StatusAtividade::Planejado, 'inicio_planejado' => now()->addDay(), 'codigo_cronograma' => '1.1',
        ]);
        $doc = DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'DOC-1', 'descricao' => 'x']);
        $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $this->componente()
            ->assertStatus(200)
            ->assertSee('DOC-1', false)
            ->assertSeeHtml(route('engenharia.pacotes', ['documento' => $doc->id]));
    }

    public function test_pagina_renderiza_documento_liberado_sem_bloqueio(): void
    {
        $user = $this->usuarioComPapel(Papel::GerentePlanejamento->value);

        $at = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'fora_do_cronograma' => false,
            'status' => StatusAtividade::Planejado, 'inicio_planejado' => now()->addDay(),
        ]);
        $doc = DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'DOC-2', 'descricao' => 'x']);
        $rev = $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
        (new AlterarLiberacaoRevisaoDocumento())->liberar($rev->fresh(), $user);
        $at->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $this->componente()
            ->assertStatus(200)
            ->assertSee('Nenhuma atividade com bloqueio documental neste horizonte.');
    }

    // =========================================================
    // Multi-obra / manipulação direta de Livewire (Seção 31)
    // =========================================================

    public function test_multiobra_dados_de_outra_obra_nunca_aparecem(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $atB = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $obraB->id, 'fora_do_cronograma' => false,
            'status' => StatusAtividade::Planejado, 'inicio_planejado' => now()->addDay(), 'codigo_cronograma' => 'B.1',
        ]);
        $docB = DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $obraB->id, 'codigo' => 'DOC-B', 'descricao' => 'x']);
        $docB->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
        $atB->documentosEngenharia()->attach($docB->id, ['tenant_id' => $this->tenant->id]);

        $this->usuarioComPapel(Papel::GerentePlanejamento->value);
        $this->vincularObra($obraB, auth()->user(), Papel::GerentePlanejamento->value);

        $this->componente($this->obra)
            ->assertStatus(200)
            ->assertDontSee('DOC-B', false);
    }
}
