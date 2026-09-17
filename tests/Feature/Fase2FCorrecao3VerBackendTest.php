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
 * FASE 2F.CORREÇÃO.3 — propagação completa do Achado E23 para as 10
 * superfícies remanescentes identificadas na busca curta da
 * Fase 2F.CORREÇÃO.2 (`⚡causas`, `⚡cronograma`, `⚡curvas`, `⚡dashboard`,
 * `⚡linhas-base`, `⚡matriz`, `⚡plano-semanal`, `⚡programacoes`,
 * `⚡relatorio-importar-avanco`, `⚡relatorios`). Mesmo mecanismo central
 * já aprovado (`HasObraPapel::temPermissaoNaObra()`), mesmo padrão já
 * usado pelos 3 Cockpits e por `restricoes.quadro`/`restricoes.lookahead`/
 * `suprimentos.mapa` (Fase 2F.CORREÇÃO.2) — nenhum sistema paralelo.
 *
 * Todas as 10 páginas são igualmente contextuais à obra (`mount(Work
 * $obra)`, confirmado por fresh-read individual antes da correção — ver
 * relatório), incluindo `dashboard.gerencial` (rota dentro do mesmo
 * grupo `obra.context` das demais, nunca tenant-wide como Home
 * Executiva) — por isso as 10 usam exatamente o mesmo formato de
 * `@dataProvider` já usado em `Fase2FCorrecao2VerBackendTest`, evitando
 * duplicar ~60 métodos quase idênticos.
 */
class Fase2FCorrecao3VerBackendTest extends TestCase
{
    use RefreshDatabase;

    public static function paginasProvider(): array
    {
        return [
            'restricoes.causas' => ['pages::radar.causas', 'restricoes.causas'],
            'obras.importar_cronograma' => ['pages::radar.cronograma', 'obras.importar_cronograma'],
            'obras.curvas' => ['pages::radar.curvas', 'obras.curvas'],
            'dashboard.gerencial' => ['pages::radar.dashboard', 'dashboard.gerencial'],
            'obras.linhas_base' => ['pages::radar.linhas-base', 'obras.linhas_base'],
            'restricoes.matriz' => ['pages::radar.matriz', 'restricoes.matriz'],
            'restricoes.plano_semanal' => ['pages::radar.plano-semanal', 'restricoes.plano_semanal'],
            'restricoes.minhas_programacoes' => ['pages::radar.programacoes', 'restricoes.minhas_programacoes'],
            'report.importar_avanco' => ['pages::radar.relatorio-importar-avanco', 'report.importar_avanco'],
            'report.relatorios' => ['pages::radar.relatorios', 'report.relatorios'],
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
     * C. Editar (ou outra capacidade operacional real desta funcionalidade)
     * sem 'ver' -> página nega. Prova explícita: NUNCA implementar a
     * implicação escondida "editar => ver".
     *
     * @dataProvider paginasProvider
     */
    public function test_c_editar_sem_ver_nega_acesso_a_pagina(string $componente, string $slug): void
    {
        $obra = $this->criarObra();
        $user = User::factory()->create(['tenant_id' => $obra->tenant_id]);
        // 'editar' é sempre uma ação real do catálogo (as 4 ações
        // uniformes existem pra toda funcionalidade — ver
        // CatalogoFuncionalidades) mesmo quando a página não expõe uma
        // mutação de fato por esse nome; o que importa aqui é provar que
        // NENHUMA outra ação, por si só, nunca substitui 'ver'.
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
     * Item 12 do pedido — prova E2E de menu+backend juntos, uma vez por
     * família (Restrições/Planejamento-Obras/Reports/Dashboard), usando
     * um representante de cada família.
     */
    public static function familiasProvider(): array
    {
        return [
            'Restrições (causas)' => ['pages::radar.causas', 'restricoes.causas'],
            'Planejamento/Obras (curvas)' => ['pages::radar.curvas', 'obras.curvas'],
            'Reports (relatorios)' => ['pages::radar.relatorios', 'report.relatorios'],
            'Dashboard (dashboard.gerencial)' => ['pages::radar.dashboard', 'dashboard.gerencial'],
        ];
    }

    /** @dataProvider familiasProvider */
    public function test_menu_esconde_e_backend_nega_juntos_sem_ver_por_familia(string $componente, string $slug): void
    {
        $obra = $this->criarObra();
        $user = User::factory()->create(['tenant_id' => $obra->tenant_id]);
        $perfilSemVer = $this->criarPerfilComAcoes($obra, [['cadastros.clientes', 'ver']]);
        $this->vincularComPerfil($obra, $user, $perfilSemVer);

        $this->actingAs($user->fresh());
        \App\Support\ObraContext::set($obra);

        $this->assertFalse(\App\Support\CatalogoFuncionalidades::usuarioPodeVer($slug));

        $this->assertPaginaNegada(Livewire::test($componente, ['obra' => $obra]));
    }
}
