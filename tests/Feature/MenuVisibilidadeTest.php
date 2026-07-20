<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Atividade;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Visibilidade dinâmica do menu por funcionalidade (App\Support\
 * CatalogoFuncionalidades::usuarioPodeVer()) — só se aplica a itens
 * obra-scoped e só quando há uma obra ativa no ObraContext; fora de
 * contexto de obra o item continua visível como sempre foi (evita
 * esconder a própria navegação de entrada).
 */
class MenuVisibilidadeTest extends TestCase
{
    use RefreshDatabase;

    private function revogarVer(Perfil $perfil, string $funcionalidade): void
    {
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', $funcionalidade)
            ->where('acao', 'ver')
            ->delete();
    }

    public function test_esconde_pagina_sem_permissao_ver_na_obra_ativa(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $encarregado = $this->vincularObra($obra, $user, Papel::Encarregado->value);
        $this->revogarVer($encarregado, 'report.relatorios');
        Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $this->actingAs($user);
        ObraContext::set($obra);

        // Assertivo pelo href exato (com aspas), não pelo texto solto
        // "Relatórios": desde a adição do item "Relatórios de Restrições"
        // (funcionalidade restricoes.relatorios, sempre visível pra este
        // perfil neste teste), o texto "Relatórios" deixou de ser único na
        // página — o link /app/radar/relatorios" (Report) continua sendo.
        $this->get(route('radar.restricoes'))->assertOk()
            ->assertDontSee('href="/app/radar/relatorios"', false);
    }

    public function test_mostra_pagina_com_permissao_ver_na_obra_ativa(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $user, Papel::Encarregado->value);
        Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $this->actingAs($user);
        ObraContext::set($obra);

        $this->get(route('radar.restricoes'))->assertOk()
            ->assertSee('Relatórios', false);
    }

    public function test_mostra_pagina_fora_do_contexto_de_obra_mesmo_sem_obra_ativa(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $this->get(route('app.home'))->assertOk()
            ->assertSee('Relatórios', false);
    }
}
