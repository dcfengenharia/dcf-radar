<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Atividade;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\CatalogoFuncionalidades;
use App\Support\ObraContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ajuste final pré-produção — menu composto do Estoque.
 *
 * Cobre o mecanismo genérico novo (`CatalogoFuncionalidades::
 * itemVisivelPorFuncionalidade()`, "funcionalidades" array com semântica
 * OR) e o comportamento real do item de menu "Estoque" depois de migrado
 * pra ele. Não é reabertura do RBAC — o backend de Estoque (mount()/
 * abaAutorizada()/selecionarAba()/#[Locked]) não foi tocado.
 */
class MenuFuncionalidadesCompostasTest extends TestCase
{
    use RefreshDatabase;

    private const SLUGS_ESTOQUE = [
        'estoque.movimentacao',
        'estoque.reserva',
        'estoque.conciliacao',
        'estoque.industrializacao',
        'estoque.inventario',
    ];

    private function revogarVer(Perfil $perfil, string $funcionalidade): void
    {
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', $funcionalidade)
            ->where('acao', 'ver')
            ->delete();
    }

    /**
     * Deixa o perfil com `ver` SÓ na funcionalidade informada, entre as
     * 5 do grupo Estoque (revoga as outras 4) — simula "usuário somente
     * X".
     */
    private function restringirVerASomenteEstoque(Perfil $perfil, string $funcionalidadeMantida): void
    {
        foreach (self::SLUGS_ESTOQUE as $slug) {
            if ($slug !== $funcionalidadeMantida) {
                $this->revogarVer($perfil, $slug);
            }
        }
    }

    private function revogarTodasEstoque(Perfil $perfil): void
    {
        foreach (self::SLUGS_ESTOQUE as $slug) {
            $this->revogarVer($perfil, $slug);
        }
    }

    // ------------------------------------------------------------------
    // 1-5: mecanismo genérico, testado diretamente contra o método real
    // (production code), com objetos que espelham exatamente o formato
    // decodificado do verticalMenu.json — nunca uma reimplementação
    // paralela da regra.
    // ------------------------------------------------------------------

    public function test_1_funcionalidade_string_legada_continua_funcionando(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = $this->vincularObra($obra, $user, Papel::Encarregado->value);
        $this->revogarVer($perfil, 'report.relatorios');

        $this->actingAs($user);
        ObraContext::set($obra);

        $item = json_decode(json_encode(['funcionalidade' => 'report.relatorios']));
        $this->assertFalse(CatalogoFuncionalidades::itemVisivelPorFuncionalidade($item));

        $itemVisivel = json_decode(json_encode(['funcionalidade' => 'restricoes.quadro']));
        $this->assertTrue(CatalogoFuncionalidades::itemVisivelPorFuncionalidade($itemVisivel));
    }

    public function test_2_array_com_primeiro_autorizado_mostra(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = $this->vincularObra($obra, $user, Papel::Encarregado->value);
        $this->restringirVerASomenteEstoque($perfil, 'estoque.movimentacao');

        $this->actingAs($user);
        ObraContext::set($obra);

        $item = json_decode(json_encode(['funcionalidades' => self::SLUGS_ESTOQUE]));
        $this->assertTrue(CatalogoFuncionalidades::itemVisivelPorFuncionalidade($item));
    }

    public function test_3_array_com_item_intermediario_autorizado_mostra(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = $this->vincularObra($obra, $user, Papel::Encarregado->value);
        $this->restringirVerASomenteEstoque($perfil, 'estoque.conciliacao');

        $this->actingAs($user);
        ObraContext::set($obra);

        $item = json_decode(json_encode(['funcionalidades' => self::SLUGS_ESTOQUE]));
        $this->assertTrue(CatalogoFuncionalidades::itemVisivelPorFuncionalidade($item));
    }

    public function test_4_array_com_ultimo_autorizado_mostra(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = $this->vincularObra($obra, $user, Papel::Encarregado->value);
        $this->restringirVerASomenteEstoque($perfil, 'estoque.inventario');

        $this->actingAs($user);
        ObraContext::set($obra);

        $item = json_decode(json_encode(['funcionalidades' => self::SLUGS_ESTOQUE]));
        $this->assertTrue(CatalogoFuncionalidades::itemVisivelPorFuncionalidade($item));
    }

    public function test_5_array_sem_nenhum_autorizado_esconde(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = $this->vincularObra($obra, $user, Papel::Encarregado->value);
        $this->revogarTodasEstoque($perfil);

        $this->actingAs($user);
        ObraContext::set($obra);

        $item = json_decode(json_encode(['funcionalidades' => self::SLUGS_ESTOQUE]));
        $this->assertFalse(CatalogoFuncionalidades::itemVisivelPorFuncionalidade($item));
    }

    // ------------------------------------------------------------------
    // 6-11: comportamento real do item "Estoque", via HTTP, mesma
    // técnica de tests/Feature/MenuVisibilidadeTest.php (href exato no
    // HTML renderizado, nunca texto solto).
    // ------------------------------------------------------------------

    // Fragmento de sufixo (sem o prefixo de host, que `url()` sempre
    // acrescenta neste ambiente — a exata comparação de MenuVisibilidadeTest
    // com href relativo só "funciona" pro caso dontSee, porque a
    // substring nunca aparece de qualquer forma; aqui precisamos de uma
    // asserção que também prove positivamente a presença do link).
    private const HREF_ESTOQUE = '/app/radar/estoque"';

    /**
     * Zero perfis — testado no nível do mecanismo (unit-style, mesmo
     * padrão de test_1..test_5), não via HTTP: `⚡home.blade.php::mount()`
     * já tem uma defesa PRÉ-EXISTENTE e correta (linha 47-48, não tocada
     * nesta tarefa) que limpa o `ObraContext` quando `! Auth::user()->
     * temAcessoAObra($obra)` — verdadeiro pra um usuário com membership
     * mas SEM nenhum Perfil (`temAcessoAObra() = perfisIdsNaObra() !==
     * []`). Isso é o comportamento correto e documentado de "fora do
     * contexto de obra, sem gating nenhum" se propagando — não um bug
     * desta tarefa — mas torna `app.home`/qualquer página gated por
     * `obra.context` inadequada pra ISOLAR a visibilidade do item de
     * menu nesse cenário específico (a própria página nega acesso à
     * obra antes do menu chegar a renderizar com o contexto real). Por
     * isso a asserção correta é diretamente contra `usuarioPodeVer()`/
     * `itemVisivelPorFuncionalidade()`, com `ObraContext::set()` mantido
     * de propósito (sem passar por nenhum mount() de página que o
     * limparia).
     */
    public function test_6_zero_perfis_esconde_item_estoque(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        // Vínculo com a obra sem nenhum Perfil (membership pura).
        $obra->users()->attach($user->id, ['perfil_id' => null]);

        $this->actingAs($user);
        ObraContext::set($obra);

        $this->assertFalse($user->temAcessoAObra($obra));

        foreach (self::SLUGS_ESTOQUE as $slug) {
            $this->assertFalse(CatalogoFuncionalidades::usuarioPodeVer($slug));
        }

        $item = json_decode(json_encode(['funcionalidades' => self::SLUGS_ESTOQUE]));
        $this->assertFalse(CatalogoFuncionalidades::itemVisivelPorFuncionalidade($item));
    }

    public function test_7_usuario_somente_inventario_ve_item_estoque(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = $this->vincularObra($obra, $user, Papel::Encarregado->value);
        $this->restringirVerASomenteEstoque($perfil, 'estoque.inventario');
        Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $this->actingAs($user);
        ObraContext::set($obra);

        $this->get(route('radar.restricoes'))->assertOk()
            ->assertSee(self::HREF_ESTOQUE, false);
    }

    public function test_8_usuario_somente_reserva_ve_item_estoque(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = $this->vincularObra($obra, $user, Papel::Encarregado->value);
        $this->restringirVerASomenteEstoque($perfil, 'estoque.reserva');
        Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $this->actingAs($user);
        ObraContext::set($obra);

        $this->get(route('radar.restricoes'))->assertOk()
            ->assertSee(self::HREF_ESTOQUE, false);
    }

    public function test_9_usuario_sem_nenhum_estoque_nao_ve_item(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = $this->vincularObra($obra, $user, Papel::Encarregado->value);
        $this->revogarTodasEstoque($perfil);
        Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $this->actingAs($user);
        ObraContext::set($obra);

        $this->get(route('radar.restricoes'))->assertOk()
            ->assertDontSee(self::HREF_ESTOQUE, false);
    }

    public function test_10_multiperfil_uniao_funciona(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // Perfil primário: sem NENHUM ver de Estoque.
        $perfilPrimario = $this->vincularObra($obra, $user, Papel::Encarregado->value);
        $this->revogarTodasEstoque($perfilPrimario);

        // Segundo perfil, próprio e customizado: só ver em
        // estoque.conciliacao — a permissão efetiva é a UNIÃO dos dois,
        // então o item de menu deve aparecer mesmo o primário não tendo
        // nenhum ver de Estoque.
        $perfilSecundario = Perfil::create([
            'tenant_id' => $tenant->id,
            'nome' => 'Conciliação de Estoque (custom)',
        ]);
        PerfilPermissao::create([
            'tenant_id' => $tenant->id,
            'perfil_id' => $perfilSecundario->id,
            'funcionalidade' => 'estoque.conciliacao',
            'acao' => 'ver',
        ]);
        \App\Support\AtribuicaoPerfilObra::adicionarPerfil($obra, $user->id, $perfilSecundario->id, null);

        Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $this->actingAs($user);
        ObraContext::set($obra);

        $this->get(route('radar.restricoes'))->assertOk()
            ->assertSee(self::HREF_ESTOQUE, false);
    }

    public function test_11_cross_obra_nao_contamina_visibilidade_do_menu(): void
    {
        $tenant = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // Obra A: acesso legítimo a Inventário.
        $perfilObraA = $this->vincularObra($obraA, $user, Papel::Encarregado->value);
        $this->restringirVerASomenteEstoque($perfilObraA, 'estoque.inventario');

        // Obra B: nenhum ver de Estoque.
        $perfilObraB = $this->vincularObra($obraB, $user, Papel::Encarregado->value);
        $this->revogarTodasEstoque($perfilObraB);

        Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obraB->id]);

        $this->actingAs($user);
        ObraContext::set($obraB);

        $this->get(route('radar.restricoes'))->assertOk()
            ->assertDontSee(self::HREF_ESTOQUE, false);
    }

    /**
     * Confirma que o item real de resources/menu/verticalMenu.json usa
     * os 5 slugs esperados, no formato `funcionalidades` (array) — se
     * alguém reduzir o item pra `funcionalidade` (string) de novo por
     * engano, este teste quebra.
     */
    public function test_item_estoque_real_usa_os_cinco_slugs(): void
    {
        $json = json_decode(file_get_contents(base_path('resources/menu/verticalMenu.json')));

        $itemEstoque = collect($json->menu)->first(
            fn ($item) => isset($item->slug) && $item->slug === 'radar.estoque'
        );

        $this->assertNotNull($itemEstoque, 'Item "radar.estoque" não encontrado no menu.');
        $this->assertObjectHasProperty('funcionalidades', $itemEstoque);
        $this->assertObjectNotHasProperty('funcionalidade', $itemEstoque);
        $this->assertEqualsCanonicalizing(self::SLUGS_ESTOQUE, $itemEstoque->funcionalidades);
    }
}
