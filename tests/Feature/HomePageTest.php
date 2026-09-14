<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Home Executiva (Ciclo 25) — camada de UI/rota (`app.home` →
 * `pages::app.home`). Os cenários de LEITURA de dado (prontidão/
 * ameaças/causas/Suprimentos/Engenharia/PPC/etc.) já estão cobertos por
 * `HomeExecutivaQueryTest.php` (o read model) — esta suíte cobre só a
 * camada de página: resolução de obra, seletor, permissão implícita
 * (nenhuma — Home é acessível a todo usuário autenticado, cada bloco só
 * mostra o que os dados subjacentes já filtram por permissão/tenant/obra)
 * e o deep-link novo de atividade no Lookahead.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-14'));
        $this->tenant = Tenant::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuarioNaObra(Work $obra, string $papel = null): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obra, $user, $papel ?? Papel::GerentePlanejamento->value);

        return $user;
    }

    public function test_a_usuario_com_uma_unica_obra_e_auto_selecionado_no_mount(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = $this->usuarioNaObra($obra);
        $this->actingAs($user);

        $this->get('/app/home')->assertOk()->assertSee($obra->name);
        $this->assertSame($obra->id, ObraContext::currentId());
    }

    public function test_b_usuario_com_duas_obras_sem_contexto_ve_estado_de_selecao(): void
    {
        $obraA = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraA, $user, Papel::GerentePlanejamento->value);
        $this->vincularObra($obraB, $user, Papel::GerentePlanejamento->value);
        $this->actingAs($user);

        $this->get('/app/home')->assertOk()->assertSee('Nenhuma obra selecionada');
        $this->assertNull(ObraContext::currentId());
    }

    public function test_c_usuario_sem_nenhuma_obra_ve_cta_minhas_obras(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user);

        $this->get('/app/home')->assertOk()->assertSee('Você ainda não tem acesso a nenhuma obra');
    }

    public function test_d_obra_sem_cronograma_mostra_cta_de_importacao(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = $this->usuarioNaObra($obra);
        $this->actingAs($user);

        $this->get('/app/home')->assertOk()->assertSee('Importar Cronograma');
    }

    public function test_e_trocar_obra_atualiza_o_contexto_da_sessao_e_o_resumo_exibido(): void
    {
        $obraA = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Alpha']);
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Beta']);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraA, $user, Papel::GerentePlanejamento->value);
        $this->vincularObra($obraB, $user, Papel::GerentePlanejamento->value);
        $this->actingAs($user);

        $componente = Livewire::test('pages::app.home');
        $componente->call('trocarObra', $obraB->id);

        $this->assertSame($obraB->id, ObraContext::currentId());
    }

    public function test_f_nao_permite_trocar_para_obra_sem_acesso(): void
    {
        $obraPropria = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $obraAlheia = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = $this->usuarioNaObra($obraPropria);
        $this->actingAs($user);

        $componente = Livewire::test('pages::app.home');
        $componente->call('trocarObra', $obraAlheia->id)->assertForbidden();

        $this->assertSame($obraPropria->id, ObraContext::currentId());
    }

    public function test_g_obra_de_outro_tenant_nunca_e_selecionavel(): void
    {
        $obraPropria = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = $this->usuarioNaObra($obraPropria);
        $this->actingAs($user);

        $outroTenant = Tenant::factory()->create();
        $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        $componente = Livewire::test('pages::app.home');
        $componente->call('trocarObra', $obraOutroTenant->id)->assertForbidden();
    }

    public function test_h_contexto_de_obra_ja_revogado_e_limpo_defensivamente(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = $this->usuarioNaObra($obra);
        $this->actingAs($user);
        ObraContext::set($obra);

        // Revoga o vínculo (simula acesso removido depois do login).
        $obra->users()->detach($user->id);

        $this->get('/app/home')->assertOk()->assertSee('Nenhuma obra selecionada');
        $this->assertNull(ObraContext::currentId());
    }

    public function test_i_deep_link_de_atividade_no_lookahead_abre_o_popup_de_detalhe(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = $this->usuarioNaObra($obra);
        $this->actingAs($user);
        ObraContext::set($obra);

        $atividade = Atividade::create([
            'obra_id' => $obra->id, 'nome' => 'Atividade Deep Link',
            'codigo_cronograma' => 'DL1', 'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => Carbon::today()->addDays(3), 'fora_do_cronograma' => false,
        ]);

        // "modal fade show d-block" só é renderizado dentro de
        // `@if ($modalAtividadeId)` — marcador confiável de que o popup
        // de detalhe realmente abriu (nunca só "o nome aparece em algum
        // lugar da página", que a tabela principal já satisfaria sozinha).
        // Simula a query string real via Livewire::withQueryParams()
        // (mesmo mecanismo do #[Url]), evitando o middleware completo de
        // onboarding que um GET HTTP cru disparia.
        // Conteúdo interno do popup (Curva S/checklist/etc.) depende de
        // outro estado que só `verAtividade()` prepara (ex.: Linha de
        // Base ativa) e não faz parte desta mudança — a garantia provada
        // aqui é só a do deep-link em si: a URL abre o MESMO popup, pra
        // a MESMA atividade, sem precisar clicar em nada.
        $html = Livewire::withQueryParams(['atividade' => $atividade->id])
            ->test('pages::radar.lookahead', ['obra' => $obra])
            ->assertSet('modalAtividadeId', $atividade->id)
            ->html();

        $this->assertStringContainsString('modal fade show d-block', $html);
    }

    public function test_j_saudacao_original_e_preservada(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = $this->usuarioNaObra($obra);
        $user->forceFill(['first_name' => 'Danuzio'])->save();
        $this->actingAs($user);

        $this->get('/app/home')->assertOk()->assertSee('Fala comigo Danuzio');
    }

    // =================================================================
    // Fechamento (Ciclo 25) — "última visita" (Seções 3-7)
    // =================================================================

    public function test_k_primeiro_acesso_grava_o_cursor_e_nao_tem_visita_anterior(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = $this->usuarioNaObra($obra);
        $this->actingAs($user);
        $this->criarAtividadeParaTerCronograma($obra);

        $this->get('/app/home')->assertOk()->assertSee('Últimos acontecimentos da obra');

        $this->assertDatabaseHas('ultimo_acesso_home_por_usuario_obra', [
            'obra_id' => $obra->id, 'user_id' => $user->id,
        ]);
    }

    public function test_l_segundo_acesso_usa_o_cursor_do_primeiro_como_desde(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = $this->usuarioNaObra($obra);
        $this->actingAs($user);
        $this->criarAtividadeParaTerCronograma($obra);

        \App\Models\UltimoAcessoHome::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $obra->id, 'user_id' => $user->id,
            'ultimo_acesso_em' => Carbon::now()->subDays(4),
        ]);

        $this->get('/app/home')->assertOk()->assertSee('O que mudou desde sua última visita');

        $registro = \App\Models\UltimoAcessoHome::where('obra_id', $obra->id)->where('user_id', $user->id)->first();
        $this->assertTrue($registro->ultimo_acesso_em->gt(Carbon::now()->subMinute()), 'o cursor avança pra agora depois de capturado');
    }

    private function criarAtividadeParaTerCronograma(Work $obra): void
    {
        Atividade::create([
            'obra_id' => $obra->id, 'nome' => 'Atividade', 'codigo_cronograma' => 'A' . uniqid(),
            'status' => StatusAtividade::Planejado->value, 'inicio_planejado' => Carbon::today()->addDays(2),
            'fora_do_cronograma' => false,
        ]);
    }

    public function test_m_obra_a_e_obra_b_tem_cursores_independentes(): void
    {
        $obraA = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraA, $user, Papel::GerentePlanejamento->value);
        $this->vincularObra($obraB, $user, Papel::GerentePlanejamento->value);
        $this->actingAs($user);
        ObraContext::set($obraA);

        $this->get('/app/home')->assertOk(); // 1º acesso na obra A

        $this->assertDatabaseHas('ultimo_acesso_home_por_usuario_obra', ['obra_id' => $obraA->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('ultimo_acesso_home_por_usuario_obra', ['obra_id' => $obraB->id, 'user_id' => $user->id]);

        // `trocarObra()` faz `ObraContext::set()` + `$this->redirect(...)` —
        // dentro de `Livewire::test()`, o redirect não é seguido
        // automaticamente (diferente de um `wire:navigate` real no
        // navegador, que causaria um NOVO carregamento de página e um
        // NOVO `mount()`). Simula esse segundo carregamento real com um
        // segundo `get()`, já que `ObraContext::set()` já persistiu na
        // sessão.
        Livewire::test('pages::app.home')->call('trocarObra', $obraB->id)->assertRedirect(route('app.home'));
        $this->get('/app/home')->assertOk();

        // Abrir a Home da obra B não deve ter mexido no cursor da obra A.
        $this->assertDatabaseHas('ultimo_acesso_home_por_usuario_obra', ['obra_id' => $obraB->id, 'user_id' => $user->id]);
        $cursorA = \App\Models\UltimoAcessoHome::where('obra_id', $obraA->id)->where('user_id', $user->id)->first();
        $this->assertNotNull($cursorA);
    }

    public function test_n_usuario_a_e_usuario_b_tem_cursores_independentes(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $userA = $this->usuarioNaObra($obra);
        $userB = $this->usuarioNaObra($obra);

        $this->actingAs($userA);
        $this->get('/app/home')->assertOk();

        $this->assertDatabaseHas('ultimo_acesso_home_por_usuario_obra', ['obra_id' => $obra->id, 'user_id' => $userA->id]);
        $this->assertDatabaseMissing('ultimo_acesso_home_por_usuario_obra', ['obra_id' => $obra->id, 'user_id' => $userB->id]);
    }

    public function test_o_re_render_do_livewire_nunca_avanca_o_cursor(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = $this->usuarioNaObra($obra);
        $this->actingAs($user);

        $componente = Livewire::test('pages::app.home');

        $registroAposMount = \App\Models\UltimoAcessoHome::where('obra_id', $obra->id)->where('user_id', $user->id)->first();
        $this->assertNotNull($registroAposMount);
        $timestampAposMount = $registroAposMount->ultimo_acesso_em;

        // Um "re-render" (sem chamar mount() de novo) não deve tocar o
        // cursor — só acessa o computed `resumo` de novo.
        $componente->call('$refresh');

        $registroDepois = \App\Models\UltimoAcessoHome::where('obra_id', $obra->id)->where('user_id', $user->id)->first();
        $this->assertTrue($registroDepois->ultimo_acesso_em->eq($timestampAposMount));
    }

    // =================================================================
    // Fechamento (Ciclo 25) — permissões (Seções 24-26)
    // =================================================================

    public function test_p_usuario_sem_permissao_de_engenharia_nao_ve_codigo_de_documento(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // Perfil customizado SEM nenhuma PerfilPermissao — nunca herda o
        // "ver liberado por padrão" dos 5 perfis seedados (mesmo padrão
        // já usado em `AtividadeAnexoTest`/`GrdAceiteTest` pra provar
        // ausência real de permissão, não uma suposição sobre perfil
        // legado).
        $perfilRestrito = \App\Models\Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Sem Engenharia ' . uniqid()]);
        $obra->users()->attach($user->id, ['perfil_id' => $perfilRestrito->id]);
        $this->actingAs($user);
        ObraContext::set($obra);

        $atividade = Atividade::create([
            'obra_id' => $obra->id, 'nome' => 'Atividade Bloqueada',
            'codigo_cronograma' => 'DOC1', 'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => Carbon::today()->addDays(3), 'fora_do_cronograma' => false,
        ]);
        $doc = \App\Models\DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'DOC-SIGILOSO', 'descricao' => 'Documento']);
        $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $atividade->documentosEngenharia()->attach($doc->id);

        $this->assertFalse($user->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'ver'));

        $html = $this->get('/app/home')->assertOk()->getContent();

        $this->assertStringNotContainsString('DOC-SIGILOSO', $html);
        $this->assertStringContainsString('não tem permissão para ver o detalhamento de Engenharia', $html);
    }

    public function test_q_usuario_sem_permissao_de_suprimentos_nao_ve_bloco_detalhado(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfilRestrito = \App\Models\Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Sem Suprimentos ' . uniqid()]);
        $obra->users()->attach($user->id, ['perfil_id' => $perfilRestrito->id]);
        $this->actingAs($user);
        ObraContext::set($obra);

        Atividade::create([
            'obra_id' => $obra->id, 'nome' => 'Atividade', 'codigo_cronograma' => 'A1',
            'status' => StatusAtividade::Planejado->value, 'inicio_planejado' => Carbon::today()->addDays(2),
            'fora_do_cronograma' => false,
        ]);

        $this->assertFalse($user->temPermissaoNaObra($obra, 'suprimentos.mapa', 'ver'));

        $this->get('/app/home')->assertOk()->assertSee('não tem permissão para ver o detalhamento de Suprimentos');
    }
}
