<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Atividade;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug de teste manual, 2026-09-02 — `TypeError: App\Models\User::
 * temPermissaoNaObra(): Argument #1 ($obra) must be of type Work|string,
 * null given` ao acessar `/app/planejamento/requisicoes`.
 *
 * Causa raiz confirmada lendo `vendor/livewire/livewire/src/Drawer/
 * ImplicitRouteBinding.php`: nem `resolveMountParameters()` nem
 * `resolveComponentProps()` obtêm `obra` de nenhum lugar além dos
 * PARÂMETROS DA PRÓPRIA ROTA — e a rota `planejamento.requisicoes` (como
 * toda rota deste grupo `obra.context`) não tem segmento `{obra}`. O
 * mecanismo real que preenche `mount(Work $obra)` em toda página do Radar
 * é a tag attribute explícita `:obra="$obraAtual"` no wrapper Blade
 * (`$obraAtual` vem de `RequireObraContext::handle()`, via
 * `view()->share('obraAtual', $obra)`) — e o wrapper
 * `resources/views/app/planejamento/requisicoes-planejamento.blade.php`
 * nunca a passava. Sem esse parâmetro, o container do Laravel resolve
 * `Work $obra` sozinho instanciando um `Work` VAZIO e nunca salvo
 * (`new Work()`) — daí `$this->obra->id` virar `null`, o valor exato que
 * chega em `temPermissaoNaObra()`. Mesmo defeito encontrado (grep
 * exaustivo de todo `public Work $obra` do projeto) em
 * `resources/views/app/radar/estoque.blade.php` — os únicos 2 wrappers,
 * de 24 páginas com esse padrão, que não repassavam `:obra`.
 *
 * Correção: adicionar `:obra="$obraAtual"` aos 2 wrappers — nenhuma
 * mudança em `temPermissaoNaObra()`/`HasObraPapel`/`RequireObraContext`.
 *
 * Por que a suíte já existente (`RequisicaoPlanejamentoPageTest`/
 * `EstoquePageTest`) nunca pegou isso: os dois usam
 * `Livewire::test('pages::...', ['obra' => $obra])`, que monta o
 * componente Livewire DIRETO, passando por fora do wrapper Blade/rota
 * real — o bug só existe na camada que only a requisição HTTP completa
 * exercita. Este arquivo cobre exatamente essa camada.
 */
class ObraContextWrapperNaoRepassaObraTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);

        // RequireObraContext bloqueia qualquer rota do Radar (exceto
        // radar.cronograma) enquanto a obra não tiver ao menos 1 atividade
        // cadastrada (passo obrigatório do onboarding, App\Support\
        // Onboarding\OnboardingChecklist::passosObra()) — sem isso, mesmo
        // um contexto de obra 100% válido resultaria num redirect pro
        // onboarding, mascarando o cenário que este teste quer isolar.
        Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
    }

    // =========================================================================
    // A — obra ativa válida: página carrega normalmente (200), sem TypeError.
    // =========================================================================

    public function test_planejamento_requisicoes_com_obra_ativa_valida_carrega_200(): void
    {
        $this->actingAs($this->user);
        ObraContext::set($this->obra);

        $this->get(route('planejamento.requisicoes'))
            ->assertOk()
            ->assertSee('Requisições do Planejamento');
    }

    public function test_radar_estoque_com_obra_ativa_valida_carrega_200(): void
    {
        $this->actingAs($this->user);
        ObraContext::set($this->obra);

        $this->get(route('radar.estoque'))
            ->assertOk()
            ->assertSee('Estoque');
    }

    // =========================================================================
    // B — sem contexto de obra nenhum: fluxo seguro já existente
    // (RequireObraContext), nunca TypeError/500.
    // =========================================================================

    public function test_planejamento_requisicoes_sem_contexto_de_obra_e_redirecionado_com_seguranca(): void
    {
        $this->actingAs($this->user);
        ObraContext::clear();

        $this->get(route('planejamento.requisicoes'))
            ->assertRedirect(route('gestao.minhas-obras'))
            ->assertSessionHas('flash.banner');
    }

    public function test_radar_estoque_sem_contexto_de_obra_e_redirecionado_com_seguranca(): void
    {
        $this->actingAs($this->user);
        ObraContext::clear();

        $this->get(route('radar.estoque'))
            ->assertRedirect(route('gestao.minhas-obras'))
            ->assertSessionHas('flash.banner');
    }

    // =========================================================================
    // C — contexto de obra inválido/stale (ID de obra que não existe mais):
    // mesmo fluxo seguro, nunca TypeError/500.
    // =========================================================================

    public function test_planejamento_requisicoes_com_obra_inexistente_no_contexto_e_redirecionado(): void
    {
        $this->actingAs($this->user);
        session(['obra_context_id' => 'obra-que-nunca-existiu']);

        $this->get(route('planejamento.requisicoes'))
            ->assertRedirect(route('gestao.minhas-obras'))
            ->assertSessionHas('flash.banner');
    }

    public function test_radar_estoque_com_obra_inexistente_no_contexto_e_redirecionado(): void
    {
        $this->actingAs($this->user);
        session(['obra_context_id' => 'obra-que-nunca-existiu']);

        $this->get(route('radar.estoque'))
            ->assertRedirect(route('gestao.minhas-obras'))
            ->assertSessionHas('flash.banner');
    }

    // =========================================================================
    // D — obra de OUTRO tenant no contexto: nunca deve ser aceito
    // (cross-tenant), sempre bloqueado pelo próprio RequireObraContext.
    // =========================================================================

    public function test_planejamento_requisicoes_com_obra_de_outro_tenant_e_bloqueado(): void
    {
        $outroTenant = Tenant::factory()->create();
        $obraDeOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        $this->actingAs($this->user);
        // Manipulação direta da sessão (nunca via ObraContext::set(), que
        // exigiria o Work em mãos) — simula um contexto forjado/stale
        // apontando pra uma obra de outro tenant.
        session(['obra_context_id' => $obraDeOutroTenant->id]);

        $this->get(route('planejamento.requisicoes'))
            ->assertRedirect(route('gestao.minhas-obras'))
            ->assertSessionHas('flash.banner');

        $this->assertNull(ObraContext::currentId(), 'RequireObraContext deve limpar o contexto inválido/cross-tenant.');
    }

    public function test_radar_estoque_com_obra_de_outro_tenant_e_bloqueado(): void
    {
        $outroTenant = Tenant::factory()->create();
        $obraDeOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        $this->actingAs($this->user);
        session(['obra_context_id' => $obraDeOutroTenant->id]);

        $this->get(route('radar.estoque'))
            ->assertRedirect(route('gestao.minhas-obras'))
            ->assertSessionHas('flash.banner');

        $this->assertNull(ObraContext::currentId(), 'RequireObraContext deve limpar o contexto inválido/cross-tenant.');
    }

    // =========================================================================
    // E — usuário sem vínculo com a obra (mesmo tenant): bloqueado.
    // =========================================================================

    public function test_planejamento_requisicoes_usuario_sem_vinculo_com_a_obra_e_bloqueado(): void
    {
        $obraSemVinculo = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->user);
        session(['obra_context_id' => $obraSemVinculo->id]);

        $this->get(route('planejamento.requisicoes'))
            ->assertRedirect(route('gestao.minhas-obras'))
            ->assertSessionHas('flash.banner');
    }

    public function test_radar_estoque_usuario_sem_vinculo_com_a_obra_e_bloqueado(): void
    {
        $obraSemVinculo = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->user);
        session(['obra_context_id' => $obraSemVinculo->id]);

        $this->get(route('radar.estoque'))
            ->assertRedirect(route('gestao.minhas-obras'))
            ->assertSessionHas('flash.banner');
    }

    // =========================================================================
    // F — mount() nunca recebe/propaga null: nenhum cenário acima produz
    // TypeError/500 (todas as asserções acima já são, em si, a prova —
    // um TypeError faria o teste falhar por exceção não tratada). Prova
    // adicional, direta: sem o wrapper corrigido, o próprio acesso à
    // página quebra; com ele, a propriedade $obra do componente nunca
    // fica vazia quando o contexto é válido.
    // =========================================================================

    public function test_planejamento_requisicoes_wrapper_repassa_obra_ativa_para_o_componente(): void
    {
        $this->actingAs($this->user);
        ObraContext::set($this->obra);

        $html = $this->get(route('planejamento.requisicoes'))->assertOk()->getContent();

        $this->assertStringContainsString('wire:snapshot', $html);
        $this->assertStringNotContainsString('TypeError', $html);
    }

    public function test_radar_estoque_wrapper_repassa_obra_ativa_para_o_componente(): void
    {
        $this->actingAs($this->user);
        ObraContext::set($this->obra);

        $html = $this->get(route('radar.estoque'))->assertOk()->getContent();

        $this->assertStringContainsString('wire:snapshot', $html);
        $this->assertStringNotContainsString('TypeError', $html);
    }
}
