<?php

namespace Tests\Feature;

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
 * Fase 4.3, Etapa E — item de menu "Plano de Ação". Mesmo mecanismo de
 * visibilidade de todo item do menu (CatalogoFuncionalidades::usuarioPodeVer()),
 * nenhuma lógica nova — ver MenuVisibilidadeTest.php pro padrão geral já
 * coberto pra outros itens.
 */
class PlanoAcaoMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_com_permissao_ver_visualiza_item_plano_de_acao(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $user, 'gerente_planejamento');
        Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $this->actingAs($user);
        ObraContext::set($obra);

        // Texto renderizado, não o href cru — url() gera link absoluto
        // (http://localhost/...), então o texto do item é a asserção
        // confiável (mesmo padrão de MenuVisibilidadeTest::assertSee('Relatórios', false)).
        $this->get(route('radar.restricoes'))->assertOk()
            ->assertSee('Plano de Ação', false);
    }

    public function test_usuario_sem_permissao_ver_nao_visualiza_item_plano_de_acao(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = $this->vincularObra($obra, $user, 'encarregado');
        Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'restricoes.plano_acao')
            ->where('acao', 'ver')
            ->delete();

        $this->actingAs($user);
        ObraContext::set($obra);

        $this->get(route('radar.restricoes'))->assertOk()
            ->assertDontSee('Plano de Ação', false);
    }

    public function test_url_do_item_e_a_rota_correta(): void
    {
        $this->assertSame('/app/radar/plano-acao', route('radar.plano-acao', absolute: false));
    }
}
