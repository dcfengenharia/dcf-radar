<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG TARGETED — "Unidades de Medida"/"Famílias de Materiais" não
 * apareciam no menu real (Configurações → Cadastros) mesmo pro
 * Administrador, apesar de rota/view/CatalogoFuncionalidades estarem
 * corretos em runtime.
 *
 * CAUSA RAIZ: `App\Support\CatalogoFuncionalidades::usuarioPodeVer()` é
 * baseado em PRESENÇA de linha em `perfil_permissoes` (nunca "aberto por
 * padrão sem linha") — `Perfil::seedPadrao()` só roda na CRIAÇÃO do
 * tenant. Um slug adicionado ao catálogo DEPOIS que um tenant/perfil já
 * existe nunca ganha essas linhas sozinho.
 *
 * LACUNA DE TESTE QUE ESCONDEU O BUG: toda a suíte anterior
 * (`CadastrosUnidadesFamiliasMaterialTest`/`CadastrosMestresMaterialTest`)
 * usa `RefreshDatabase` + `Tenant::factory()->create()` — que SEMPRE roda
 * `Perfil::seedPadrao()` com o catálogo JÁ ATUALIZADO (os 2 slugs novos
 * já existiam no código quando o tenant de teste nasce), então o cenário
 * real do bug (perfil que já existia ANTES do slug ser adicionado ao
 * catálogo) nunca foi reproduzido. Nenhum teste anterior também
 * renderizava a camada real de menu (`layouts.sections.menu.
 * verticalMenu.blade.php`/`submenu.blade.php`) — só testava a página
 * Livewire do cadastro diretamente (`Livewire::test(...)`) ou a
 * existência da rota, nenhum dos dois passa pelo filtro de menu.
 *
 * Este arquivo cobre as duas lacunas: (1) prova que o mecanismo REAL de
 * menu (HTTP completo, sidebar renderizada) mostra os 2 itens pra um
 * perfil seedado normalmente; (2) reproduz o bug histórico (perfil sem
 * as linhas) e prova que a migration de backfill
 * (`2026_09_09_000001_backfill_perfil_permissoes_cadastros_unidade_familia`)
 * corrige exatamente esse cenário, chamando a migration real (nunca uma
 * reimplementação da lógica).
 */
class MenuCadastrosUnidadeFamiliaVisibilidadeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Work $obra;
    private Perfil $perfilAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->perfilAdmin = $this->vincularObra($this->obra, $this->admin, Papel::Admin->value);
        $this->actingAs($this->admin);
    }

    /**
     * Caminho feliz — mecanismo REAL de menu (HTTP completo, layout com
     * sidebar), nunca só o componente Livewire do cadastro nem a rota
     * isolada. Prova que, pra um perfil seedado normalmente hoje, os 2
     * itens aparecem via a mesma cadeia real que o navegador usa:
     * verticalMenu.json → verticalMenu.blade.php → submenu.blade.php →
     * CatalogoFuncionalidades::usuarioPodeVer().
     */
    public function test_menu_real_mostra_unidades_e_familias_para_perfil_seedado_normalmente(): void
    {
        $this->get(route('app.home'))
            ->assertOk()
            ->assertSee('Unidades de Medida', false)
            ->assertSee('Famílias de Materiais', false);
    }

    /**
     * Reproduz o bug histórico: um perfil cujas linhas de
     * `perfil_permissoes` pra estes 2 slugs nunca existiram (mesmo
     * estado de um tenant/perfil criado ANTES do slug entrar no
     * catálogo) — deletadas aqui pra simular exatamente essa condição.
     * Sem a migration de backfill, o item NUNCA aparece, mesmo sendo o
     * próprio perfil Admin — reproduz fielmente o sintoma relatado.
     */
    public function test_menu_real_esconde_quando_perfil_pre_existente_nao_tem_as_linhas(): void
    {
        PerfilPermissao::where('perfil_id', $this->perfilAdmin->id)
            ->whereIn('funcionalidade', ['cadastros.unidades_medida', 'cadastros.familias_material'])
            ->delete();

        $this->get(route('app.home'))
            ->assertOk()
            ->assertDontSee('Unidades de Medida', false)
            ->assertDontSee('Famílias de Materiais', false);
    }

    /**
     * Prova que a MIGRATION REAL de backfill (nunca uma reimplementação
     * da lógica) corrige o cenário do teste anterior — chama o arquivo
     * de migration diretamente, exatamente como `php artisan migrate` o
     * chamaria.
     */
    public function test_migracao_de_backfill_corrige_visibilidade_no_menu_real(): void
    {
        PerfilPermissao::where('perfil_id', $this->perfilAdmin->id)
            ->whereIn('funcionalidade', ['cadastros.unidades_medida', 'cadastros.familias_material'])
            ->delete();

        // Confirma que o cenário está de fato quebrado antes da correção.
        // Achado de teste (não de produção, já documentado no CLAUDE.md):
        // `actingAs()` reaproveita o MESMO objeto User em memória durante
        // todo o teste, e `HasObraPapel` cacheia permissão POR INSTÂNCIA
        // — reautenticar com um User recém-buscado do banco (linha abaixo)
        // é o que força uma leitura fresca após a migration escrever no
        // banco, exatamente como um request HTTP real (processo novo)
        // sempre faria sozinho, sem esse cuidado.
        $this->get(route('app.home'))->assertDontSee('Unidades de Medida', false);

        $migration = require database_path('migrations/2026_09_09_000001_backfill_perfil_permissoes_cadastros_unidade_familia.php');
        $migration->up();

        $this->actingAs(User::find($this->admin->id));

        $this->get(route('app.home'))
            ->assertOk()
            ->assertSee('Unidades de Medida', false)
            ->assertSee('Famílias de Materiais', false);
    }

    /**
     * Regra exata do backfill, espelhando `Perfil::seedPadrao()`: `ver`
     * pra TODO perfil do tenant (inclusive ClienteLeitura, que nunca tem
     * criar/editar/excluir em nada); `criar`/`editar`/`excluir` só pro
     * perfil Admin.
     */
    public function test_migracao_de_backfill_segue_a_mesma_regra_do_seedpadrao(): void
    {
        PerfilPermissao::where('tenant_id', $this->tenant->id)
            ->whereIn('funcionalidade', ['cadastros.unidades_medida', 'cadastros.familias_material'])
            ->delete();

        $migration = require database_path('migrations/2026_09_09_000001_backfill_perfil_permissoes_cadastros_unidade_familia.php');
        $migration->up();

        $clienteLeitura = Perfil::porSlugPadrao($this->tenant, 'cliente_leitura');

        $this->assertTrue($clienteLeitura->permissoes()->where('funcionalidade', 'cadastros.unidades_medida')->where('acao', 'ver')->exists());
        $this->assertFalse($clienteLeitura->permissoes()->where('funcionalidade', 'cadastros.unidades_medida')->where('acao', 'criar')->exists());

        $this->assertTrue($this->perfilAdmin->permissoes()->where('funcionalidade', 'cadastros.unidades_medida')->where('acao', 'criar')->exists());
        $this->assertTrue($this->perfilAdmin->permissoes()->where('funcionalidade', 'cadastros.familias_material')->where('acao', 'excluir')->exists());
    }

    /**
     * Idempotência — rodar a migration 2x nunca duplica linha (respeita
     * a checagem de existência antes de criar), nem quebra por violar
     * unique constraint.
     */
    public function test_migracao_de_backfill_e_idempotente(): void
    {
        $migration = require database_path('migrations/2026_09_09_000001_backfill_perfil_permissoes_cadastros_unidade_familia.php');
        $migration->up();
        $migration->up();

        $total = PerfilPermissao::where('perfil_id', $this->perfilAdmin->id)
            ->where('funcionalidade', 'cadastros.unidades_medida')
            ->where('acao', 'ver')
            ->count();

        $this->assertSame(1, $total);
    }

    /**
     * Isolamento de tenant — a migration nunca vaza linha de um tenant
     * pra outro; cada perfil recebe permissão só no seu próprio tenant_id.
     */
    public function test_migracao_de_backfill_preserva_isolamento_de_tenant(): void
    {
        // Achado de teste (não de produção): $this->admin (tenant original)
        // já está autenticado desde o setUp() — BelongsToTenant::creating()
        // sobrescreveria silenciosamente qualquer tenant_id explícito se
        // esta criação não rodasse dentro de TenantContext::actingAs($outroTenant, ...),
        // mesmo cuidado já documentado/usado em CadastrosUnidadesFamiliasMaterialTest.
        $outroTenant = Tenant::factory()->create();
        $outroAdminUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroPerfilAdmin = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant, $outroAdminUser) {
            $obra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $perfil = $this->vincularObra($obra, $outroAdminUser, Papel::Admin->value);

            // Deleta dentro do MESMO contexto de tenant — a leitura/escrita
            // de PerfilPermissao também é filtrada pelo TenantScope, então
            // rodar isto fora do actingAs($outroTenant, ...) só apagaria
            // as linhas do tenant ORIGINALMENTE autenticado ($this->admin).
            PerfilPermissao::where('perfil_id', $perfil->id)
                ->whereIn('funcionalidade', ['cadastros.unidades_medida', 'cadastros.familias_material'])
                ->delete();

            return $perfil;
        });

        PerfilPermissao::where('perfil_id', $this->perfilAdmin->id)
            ->whereIn('funcionalidade', ['cadastros.unidades_medida', 'cadastros.familias_material'])
            ->delete();

        $migration = require database_path('migrations/2026_09_09_000001_backfill_perfil_permissoes_cadastros_unidade_familia.php');
        $migration->up();

        $linhaDoOutroPerfil = PerfilPermissao::withoutGlobalScopes()
            ->where('perfil_id', $outroPerfilAdmin->id)
            ->where('funcionalidade', 'cadastros.unidades_medida')
            ->where('acao', 'ver')
            ->first();

        $this->assertNotNull($linhaDoOutroPerfil);
        $this->assertSame($outroTenant->id, $linhaDoOutroPerfil->tenant_id);
    }
}
