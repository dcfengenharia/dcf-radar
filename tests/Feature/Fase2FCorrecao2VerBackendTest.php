<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\AtribuicaoPerfilObra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FASE 2F.CORREÇÃO.2 — fecha o Achado E23 (único achado bloqueante da
 * Fase 2F.CORREÇÃO): 'ver' precisa ser reafirmada no backend ao abrir
 * diretamente `restricoes.quadro`/`restricoes.lookahead`/`suprimentos.mapa`,
 * não só condicionar o menu. Mesmo mecanismo central já aprovado
 * (`HasObraPapel::temPermissaoNaObra()`), mesmo padrão já usado pelos 3
 * Cockpits — nenhum sistema paralelo.
 *
 * Bloqueio numa navegação de página cheia usa o MESMO padrão já
 * estabelecido pelo projeto (`app/Exceptions/Handler.php::render()`
 * converte um 403 de mount() num redirect com `flash.popup =
 * 'acesso-negado'`, nunca um 403 cru — mesma asserção já usada em
 * `CockpitPageTest`), nunca um mecanismo de negação inventado.
 *
 * Seção 17 do pedido: A-F, para cada uma das 3 páginas. Usa
 * `@dataProvider` (mesmo padrão já usado em `CrossObraHardeningTest`)
 * em vez de um `foreach` manual dentro do método de teste — cada
 * combinação [página, cenário] roda como uma invocação PHPUnit
 * genuinamente isolada (própria transação de `RefreshDatabase`), nunca
 * 3 iterações compartilhando o mesmo `actingAs()`/estado de teste.
 */
class Fase2FCorrecao2VerBackendTest extends TestCase
{
    use RefreshDatabase;

    public static function paginasProvider(): array
    {
        return [
            'restricoes.quadro' => ['pages::radar.restricoes', 'restricoes.quadro'],
            'restricoes.lookahead' => ['pages::radar.lookahead', 'restricoes.lookahead'],
            'suprimentos.mapa' => ['pages::radar.suprimentos', 'suprimentos.mapa'],
        ];
    }

    private function criarObra(): Work
    {
        $tenant = Tenant::factory()->create();

        return Work::factory()->create(['tenant_id' => $tenant->id]);
    }

    /** @param array<int, array{0: string, 1: string}> $acoes */
    private function criarPerfilComAcoes(Work $obra, array $acoes, string $nome = 'Perfil de Teste'): Perfil
    {
        $perfil = Perfil::create(['tenant_id' => $obra->tenant_id, 'nome' => $nome]);

        foreach ($acoes as [$funcionalidade, $acao]) {
            PerfilPermissao::create([
                'tenant_id' => $obra->tenant_id,
                'perfil_id' => $perfil->id,
                'funcionalidade' => $funcionalidade,
                'acao' => $acao,
            ]);
        }

        return $perfil;
    }

    private function vincularComPerfil(Work $obra, User $user, Perfil $perfil): void
    {
        $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($obra, $user->id, $perfil->id);
    }

    private function assertPaginaNegada($teste): void
    {
        $teste->assertRedirect()->assertSessionHas('flash.popup', 'acesso-negado');
    }

    /**
     * A. Membro sem 'ver' (perfil customizado, zero grants relevantes) ->
     * URL/página nega.
     *
     * @dataProvider paginasProvider
     */
    public function test_a_membro_sem_ver_nega_acesso_a_pagina(string $componente, string $slug): void
    {
        $obra = $this->criarObra();
        $user = User::factory()->create(['tenant_id' => $obra->tenant_id]);
        $perfilSemVer = $this->criarPerfilComAcoes($obra, [['cadastros.clientes', 'ver']]);
        $this->vincularComPerfil($obra, $user, $perfilSemVer);

        $this->actingAs($user->fresh());

        $this->assertPaginaNegada(Livewire::test($componente, ['obra' => $obra]));
    }

    /**
     * B. Somente 'ver' -> página abre (Consulta = Consulta): visualiza,
     * mas Livewire nunca autoriza nenhuma ação de escrita a mais.
     *
     * @dataProvider paginasProvider
     */
    public function test_b_somente_ver_abre_a_pagina(string $componente, string $slug): void
    {
        $obra = $this->criarObra();
        $user = User::factory()->create(['tenant_id' => $obra->tenant_id]);
        $perfilConsulta = $this->criarPerfilComAcoes($obra, [[$slug, 'ver']]);
        $this->vincularComPerfil($obra, $user, $perfilConsulta);

        $this->actingAs($user->fresh());

        Livewire::test($componente, ['obra' => $obra])->assertOk();
    }

    /**
     * C. Editar sem 'ver' -> página nega. Prova explícita, pedida pelo
     * Item 12: NUNCA implementar a implicação escondida "editar => ver".
     *
     * @dataProvider paginasProvider
     */
    public function test_c_editar_sem_ver_nega_acesso_a_pagina(string $componente, string $slug): void
    {
        $obra = $this->criarObra();
        $user = User::factory()->create(['tenant_id' => $obra->tenant_id]);
        $perfilEditarSemVer = $this->criarPerfilComAcoes($obra, [[$slug, 'editar']]);
        $this->vincularComPerfil($obra, $user, $perfilEditarSemVer);

        $this->actingAs($user->fresh());

        $this->assertPaginaNegada(Livewire::test($componente, ['obra' => $obra]));
    }

    /**
     * D. Multiperfil: Perfil A sem 'ver' nesta funcionalidade (tem outra
     * capacidade qualquer) + Perfil B só com 'ver' -> união concede
     * acesso (mesmo mecanismo de união já aprovado na Fase 2B, nunca um
     * gate paralelo).
     *
     * @dataProvider paginasProvider
     */
    public function test_d_multiperfil_com_ver_no_segundo_perfil_abre(string $componente, string $slug): void
    {
        $obra = $this->criarObra();
        $user = User::factory()->create(['tenant_id' => $obra->tenant_id]);

        $perfilA = $this->criarPerfilComAcoes($obra, [['cadastros.clientes', 'ver']], 'Perfil A');
        $perfilB = $this->criarPerfilComAcoes($obra, [[$slug, 'ver']], 'Perfil B');

        $this->vincularComPerfil($obra, $user, $perfilA);
        AtribuicaoPerfilObra::adicionarPerfil($obra, $user->id, $perfilB->id);

        $this->actingAs($user->fresh());

        Livewire::test($componente, ['obra' => $obra])->assertOk();
    }

    /**
     * E. Membership pura (obra_user) + zero Perfis -> nega. Nunca
     * ressuscita autoridade legada (Fase 2B.CORREÇÃO).
     *
     * @dataProvider paginasProvider
     */
    public function test_e_zero_perfis_nega_acesso_a_pagina(string $componente, string $slug): void
    {
        $obra = $this->criarObra();
        $user = User::factory()->create(['tenant_id' => $obra->tenant_id]);
        $obra->users()->attach($user->id, ['perfil_id' => null]);

        $this->actingAs($user->fresh());

        $this->assertPaginaNegada(Livewire::test($componente, ['obra' => $obra]));
    }

    /**
     * F. Cross-work: usuário tem 'ver' na Obra A (mesmo tenant), mas na
     * Obra B só tem um Perfil sem 'ver' para esta funcionalidade ->
     * acessar a página com o contexto/obra da Obra B nega, mesmo o
     * usuário tendo autoridade legítima na Obra A. Autoridade de A nunca
     * contamina B.
     *
     * @dataProvider paginasProvider
     */
    public function test_f_cross_work_ver_na_obra_a_nao_contamina_obra_b(string $componente, string $slug): void
    {
        $tenant = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $perfilVerA = $this->criarPerfilComAcoes($obraA, [[$slug, 'ver']], 'Ver na Obra A');
        $this->vincularComPerfil($obraA, $user, $perfilVerA);

        $perfilSemVerB = $this->criarPerfilComAcoes($obraB, [['cadastros.clientes', 'ver']], 'Sem ver na Obra B');
        $this->vincularComPerfil($obraB, $user, $perfilSemVerB);

        $user = $user->fresh();
        $this->actingAs($user);

        $this->assertTrue($user->temPermissaoNaObra($obraA, $slug, 'ver'));
        $this->assertFalse($user->temPermissaoNaObra($obraB, $slug, 'ver'));

        $this->assertPaginaNegada(Livewire::test($componente, ['obra' => $obraB]));
    }

    /**
     * Item 18 — prova E2E explícita de menu + backend juntos: sem 'ver',
     * o menu não mostra o item E a URL nega. Usa 'restricoes.quadro'
     * como representante (mesmo mecanismo `CatalogoFuncionalidades::
     * usuarioPodeVer()` já usado por todo o menu).
     */
    public function test_menu_esconde_e_backend_nega_juntos_sem_ver(): void
    {
        $obra = $this->criarObra();
        $user = User::factory()->create(['tenant_id' => $obra->tenant_id]);
        $perfilSemVer = $this->criarPerfilComAcoes($obra, [['cadastros.clientes', 'ver']]);
        $this->vincularComPerfil($obra, $user, $perfilSemVer);

        $this->actingAs($user->fresh());
        \App\Support\ObraContext::set($obra);

        $this->assertFalse(\App\Support\CatalogoFuncionalidades::usuarioPodeVer('restricoes.quadro'));

        $this->assertPaginaNegada(Livewire::test('pages::radar.restricoes', ['obra' => $obra]));
    }
}
