<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regressão de performance: HasObraPapel não pode voltar a rodar uma
 * query nova a cada chamada. Bug real medido nesta obra: uma página com
 * ~2400 atividades chamando as checagens de acesso uma vez por linha
 * renderizada gerava centenas de queries idênticas repetidas e 11+s de
 * carregamento — corrigido com cache em memória por instância, primeiro
 * pra papel/perfil por obra, depois estendido pro mapa de permissões por
 * perfil (App\Support\CatalogoFuncionalidades).
 */
class HasObraPapelCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_perfil_na_obra_so_consulta_o_banco_uma_vez_por_obra(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = Perfil::porSlugPadrao($tenant, 'gerente_planejamento');
        $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);

        $user = User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($user->temAcessoAObra($obra));
            $this->assertTrue($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'ver'));
        }

        $this->assertSame(
            2,
            $queryCount,
            'perfilIdNaObra() consulta o banco uma vez por obra e permissoesDoPerfil() uma vez por perfil — nunca por checagem.'
        );
    }

    public function test_perfil_na_obra_cacheia_null_quando_usuario_nao_tem_acesso(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        // Nunca vinculado à obra.

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($user->temAcessoAObra($obra));
            $this->assertFalse($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'ver'));
        }

        $this->assertSame(1, $queryCount, 'O resultado negativo (sem acesso) também deve ser cacheado, não só o positivo.');
    }

    public function test_tem_permissao_na_obra_respeita_a_matriz_do_perfil(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $encarregado = Perfil::porSlugPadrao($tenant, 'encarregado');
        $obra->users()->attach($user->id, ['perfil_id' => $encarregado->id]);

        $this->assertTrue($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'criar'));
        $this->assertFalse($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'));
        $this->assertFalse($user->temPermissaoNaObra($obra, 'cadastros.clientes', 'editar'));
    }

    public function test_tem_permissao_em_alguma_obra_do_tenant_generaliza_o_padrao_admin_em_qualquer_obra(): void
    {
        $tenant = Tenant::factory()->create();
        $obraSemAdmin = Work::factory()->create(['tenant_id' => $tenant->id]);
        $obraComAdmin = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $encarregado = Perfil::porSlugPadrao($tenant, 'encarregado');
        $admin = Perfil::porSlugPadrao($tenant, 'admin');

        $obraSemAdmin->users()->attach($user->id, ['perfil_id' => $encarregado->id]);
        $this->assertFalse($user->temPermissaoEmAlgumaObraDoTenant('cadastros.categorias_restricao', 'criar'));

        $obraComAdmin->users()->attach($user->id, ['perfil_id' => $admin->id]);
        $user = User::find($user->id);
        $this->assertTrue($user->temPermissaoEmAlgumaObraDoTenant('cadastros.categorias_restricao', 'criar'));
    }
}
