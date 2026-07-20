<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcessoNegadoPopupTest extends TestCase
{
    use RefreshDatabase;

    public function test_navegacao_de_pagina_cheia_sem_acesso_mostra_popup_em_vez_de_403_cru(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        // Obra do mesmo tenant, mas sem vínculo em obra_user — dispara
        // o abort_unless(..., 403) de gestao.obra.show, não um 404.
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->get(route('gestao.obra.show', $obra));

        $response->assertRedirect();
        $response->assertSessionHas('flash.popup', 'acesso-negado');
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_requisicao_json_continua_recebendo_403_normal(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->getJson(route('gestao.obra.show', $obra));

        $response->assertStatus(403);
    }

    public function test_acao_livewire_sem_permissao_continua_retornando_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => now(),
        ]);

        $leitor = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        // Confirma que o novo Handler::render() não quebra o padrão
        // já usado em dezenas de testes existentes (Livewire::test
        // ignora o Handler por completo pra chamadas de componente).
        Livewire::test('pages::radar.restricoes', ['obra' => $obra])
            ->call('reabrirRestricao', $restricao->id)
            ->assertForbidden();
    }
}
