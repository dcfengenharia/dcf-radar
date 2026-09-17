<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Models\Atividade;
use App\Models\ObraUserPerfil;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Report;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\AtribuicaoPerfilObra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FASE 2B — CORE RBAC CONTEXTUAL PROFISSIONAL. Cobertura das Seções
 * 42-50 do pedido: multiperfil, equivalência de migração, comentários
 * independentes de editar, ações semânticas, ausência de hierarquia
 * implícita, visibilidade executiva, último Admin, convites,
 * performance.
 */
class Fase2BRbacProfissionalTest extends TestCase
{
    use RefreshDatabase;

    private function criarPerfilCustomizado(Tenant $tenant, string $nome, array $permissoes): Perfil
    {
        $perfil = Perfil::create(['tenant_id' => $tenant->id, 'nome' => $nome]);

        foreach ($permissoes as [$funcionalidade, $acao]) {
            PerfilPermissao::create([
                'tenant_id' => $tenant->id,
                'perfil_id' => $perfil->id,
                'funcionalidade' => $funcionalidade,
                'acao' => $acao,
            ]);
        }

        return $perfil;
    }

    /**
     * Atribui MAIS um perfil ao par (obra, usuário) — nunca substitui,
     * diferente de vincularObra(). Garante a MEMBRESIA em `obra_user`
     * primeiro (idempotente, `syncWithoutDetaching`) — a nova pivot
     * nunca concede nada sozinha, sem membresia (Seção 4 do pedido,
     * reforçado pelo gate em HasObraPapel::perfisIdsNaObra()).
     */
    private function atribuirPerfil(Work $obra, User $user, Perfil $perfil): void
    {
        if (! $obra->users()->where('user_id', $user->id)->exists()) {
            $obra->users()->attach($user->id, ['perfil_id' => null]);
        }

        ObraUserPerfil::create([
            'tenant_id' => $obra->tenant_id,
            'work_id' => $obra->id,
            'user_id' => $user->id,
            'perfil_id' => $perfil->id,
        ]);
    }

    // =========================================================================
    // Seção 42 — TESTES: MULTIPERFIL
    // =========================================================================

    public function test_a_perfil_a_concede_ver_perfil_b_nao_concede_resultado_e_ver(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $perfilVer = $this->criarPerfilCustomizado($tenant, 'Só Ver', [['restricoes.quadro', 'ver']]);
        $perfilSemNada = $this->criarPerfilCustomizado($tenant, 'Nada', []);

        $this->atribuirPerfil($obra, $user, $perfilVer);
        $this->atribuirPerfil($obra, $user, $perfilSemNada);

        $this->assertTrue($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'ver'));
        $this->assertFalse($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'editar'));
    }

    public function test_b_perfil_a_concede_ver_perfil_b_concede_comentar_resultado_e_ambos(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $perfilVer = $this->criarPerfilCustomizado($tenant, 'Consulta', [['restricoes.quadro', 'ver']]);
        $perfilComentar = $this->criarPerfilCustomizado($tenant, 'Comentários', [['restricoes.quadro', 'comentar']]);

        $this->atribuirPerfil($obra, $user, $perfilVer);
        $this->atribuirPerfil($obra, $user, $perfilComentar);

        $this->assertTrue($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'ver'));
        $this->assertTrue($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'comentar'));
        $this->assertFalse($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'editar'));
    }

    public function test_c_nenhum_perfil_concede_editar_editar_e_negado(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $perfilVer = $this->criarPerfilCustomizado($tenant, 'Consulta', [['restricoes.quadro', 'ver']]);
        $perfilComentar = $this->criarPerfilCustomizado($tenant, 'Comentários', [['restricoes.quadro', 'comentar']]);

        $this->atribuirPerfil($obra, $user, $perfilVer);
        $this->atribuirPerfil($obra, $user, $perfilComentar);

        $this->assertFalse($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'editar'));
        $this->assertFalse($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'excluir'));
    }

    public function test_d_capacidades_nao_atravessam_obras(): void
    {
        $tenant = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [
            ['planejamento.requisicoes', 'ver'],
            ['planejamento.requisicoes', 'editar'],
        ]);
        $suprimentosConsulta = $this->criarPerfilCustomizado($tenant, 'Suprimentos Consulta', [
            ['suprimentos.mapa', 'ver'],
        ]);
        $engenharia = $this->criarPerfilCustomizado($tenant, 'Engenharia', [
            ['engenharia.pacotes', 'ver'],
            ['engenharia.pacotes', 'editar'],
        ]);

        $this->atribuirPerfil($obraA, $user, $planejamento);
        $this->atribuirPerfil($obraA, $user, $suprimentosConsulta);
        $this->atribuirPerfil($obraB, $user, $engenharia);

        // Obra A: Planejamento + Suprimentos Consulta, nunca Engenharia.
        $this->assertTrue($user->temPermissaoNaObra($obraA->id, 'planejamento.requisicoes', 'editar'));
        $this->assertTrue($user->temPermissaoNaObra($obraA->id, 'suprimentos.mapa', 'ver'));
        $this->assertFalse($user->temPermissaoNaObra($obraA->id, 'engenharia.pacotes', 'editar'));

        // Obra B: só Engenharia, nunca Planejamento/Suprimentos.
        $this->assertTrue($user->temPermissaoNaObra($obraB->id, 'engenharia.pacotes', 'editar'));
        $this->assertFalse($user->temPermissaoNaObra($obraB->id, 'planejamento.requisicoes', 'editar'));
        $this->assertFalse($user->temPermissaoNaObra($obraB->id, 'suprimentos.mapa', 'ver'));
    }

    public function test_e_perfil_de_outro_tenant_nunca_e_atribuivel(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenantA->id]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);

        $perfilDeB = $this->criarPerfilCustomizado($tenantB, 'Perfil de Outro Tenant', [['restricoes.quadro', 'ver']]);

        // A FK de obra_user_perfil.perfil_id aponta pra um Perfil de OUTRO
        // tenant que a obra — nunca resolvível pelo global scope de
        // BelongsToTenant quando o resolver tenta ler Perfil::whereIn(...)
        // dentro do tenant ATUAL (tenantA). A associação em si até pode
        // existir fisicamente (nenhuma FK cross-tenant no banco), mas o
        // resolver nunca concede a permissão porque nunca resolve o
        // Perfil de outro tenant como válido dentro do contexto atual.
        \App\Models\ObraUserPerfil::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'work_id' => $obraA->id,
            'user_id' => $userA->id,
            'perfil_id' => $perfilDeB->id,
        ]);

        $this->actingAs($userA);

        $this->assertFalse($userA->temPermissaoNaObra($obraA->id, 'restricoes.quadro', 'ver'));
    }

    // =========================================================================
    // Seção 43 — TESTES: MIGRAÇÃO
    // =========================================================================

    private function rodarBackfillObraUserPerfil(): void
    {
        (require base_path('database/migrations/2026_09_14_000002_backfill_obra_user_perfil_from_legado.php'))->up();
    }

    public function test_backfill_1_perfil_legado_vira_1_associacao_equivalente(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = Perfil::porSlugPadrao($tenant, 'engenheiro');

        // Simula estado PRÉ-Fase 2B: escreve só no espelho legado, nunca
        // via AtribuicaoPerfilObra.
        $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);

        $this->assertSame(0, ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $user->id)->count());

        $this->rodarBackfillObraUserPerfil();

        $associacoes = ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $user->id)->get();
        $this->assertCount(1, $associacoes);
        $this->assertSame($perfil->id, $associacoes->first()->perfil_id);
        $this->assertSame($tenant->id, $associacoes->first()->tenant_id);
    }

    public function test_backfill_e_idempotente_rodar_duas_vezes_nao_duplica(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = Perfil::porSlugPadrao($tenant, 'engenheiro');
        $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);

        $this->rodarBackfillObraUserPerfil();
        $this->rodarBackfillObraUserPerfil();

        $this->assertSame(1, ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $user->id)->count());
    }

    public function test_backfill_contagem_antes_e_depois_consistente_para_multiplos_perfis(): void
    {
        $tenant = Tenant::factory()->create();
        $obra1 = Work::factory()->create(['tenant_id' => $tenant->id]);
        $obra2 = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user1 = User::factory()->create(['tenant_id' => $tenant->id]);
        $user2 = User::factory()->create(['tenant_id' => $tenant->id]);

        $admin = Perfil::porSlugPadrao($tenant, 'admin');
        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');

        $obra1->users()->attach($user1->id, ['perfil_id' => $admin->id]);
        $obra1->users()->attach($user2->id, ['perfil_id' => $engenheiro->id]);
        $obra2->users()->attach($user1->id, ['perfil_id' => $engenheiro->id]);

        $totalObraUserComPerfilAntes = \Illuminate\Support\Facades\DB::table('obra_user')->whereNotNull('perfil_id')->count();

        $this->rodarBackfillObraUserPerfil();

        $totalAssociacoesDepois = ObraUserPerfil::count();

        $this->assertSame($totalObraUserComPerfilAntes, $totalAssociacoesDepois);
    }

    // =========================================================================
    // Seção 44 — TESTES: COMENTÁRIOS (independentes de editar)
    // =========================================================================

    public function test_comentar_atividade_lookahead_nao_exige_editar(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $perfilComentar = $this->criarPerfilCustomizado($tenant, 'Só Comentar', [
            ['restricoes.lookahead', 'ver'],
            ['restricoes.lookahead', 'comentar'],
        ]);
        $this->atribuirPerfil($obra, $user, $perfilComentar);
        $this->actingAs($user);

        $this->assertTrue($user->can('comentar', $atividade));
        $this->assertFalse($user->can('update', $atividade));
    }

    public function test_ver_atividade_sem_comentar_nao_pode_comentar(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $perfilVer = $this->criarPerfilCustomizado($tenant, 'Só Ver', [['restricoes.lookahead', 'ver']]);
        $this->atribuirPerfil($obra, $user, $perfilVer);
        $this->actingAs($user);

        $this->assertFalse($user->can('comentar', $atividade));
    }

    public function test_comentar_restricao_nao_exige_editar_nem_criar(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);
        $restricao = Restricao::factory()->create(['tenant_id' => $tenant->id, 'atividade_id' => $atividade->id]);

        $perfilComentar = $this->criarPerfilCustomizado($tenant, 'Só Comentar Restrição', [
            ['restricoes.quadro', 'comentar'],
        ]);
        $this->atribuirPerfil($obra, $user, $perfilComentar);
        $this->actingAs($user);

        $this->assertTrue($user->can('comentar', $restricao));
        $this->assertFalse($user->can('create', [Restricao::class, $obra->id]));
    }

    public function test_comentar_report_permanece_livre_como_ver_ja_era(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $user, 'cliente_leitura');
        $importacao = \App\Models\CronogramaImportacao::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'user_id' => $user->id,
            'arquivo' => 'teste.xml',
            'data_status' => now(),
            'metodo_distribuicao' => 'linear',
            'tipo' => 'ambos',
            'criadas' => 0,
            'atualizadas' => 0,
            'removidas' => 0,
            'importado_em' => now(),
        ]);
        $report = Report::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'status' => StatusReport::Emitido,
            'cronograma_importacao_id' => $importacao->id,
        ]);

        $this->actingAs($user);

        $this->assertTrue($user->can('comentar', $report));
    }

    public function test_sem_ver_o_recurso_nao_consegue_comentar_como_canal_lateral(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        // Usuário sem NENHUM vínculo com a obra.
        $this->actingAs($user);

        $this->assertFalse($user->can('comentar', $atividade));
    }

    public function test_comentar_cross_obra_permanece_bloqueado(): void
    {
        $tenant = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obraB->id]);

        $perfilComentar = $this->criarPerfilCustomizado($tenant, 'Comentar A', [
            ['restricoes.lookahead', 'comentar'],
        ]);
        $this->atribuirPerfil($obraA, $user, $perfilComentar);
        $this->actingAs($user);

        $this->assertFalse($user->can('comentar', $atividadeB));
    }

    // =========================================================================
    // Seção 45 — TESTES: AÇÕES SEMÂNTICAS
    // =========================================================================

    public function test_editar_documento_sem_liberar_para_construcao_nao_libera(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $perfilEditar = $this->criarPerfilCustomizado($tenant, 'Só Editar Doc', [
            ['engenharia.pacotes', 'ver'],
            ['engenharia.pacotes', 'editar'],
        ]);
        $this->atribuirPerfil($obra, $user, $perfilEditar);

        $this->assertTrue($user->temPermissaoNaObra($obra->id, 'engenharia.pacotes', 'editar'));
        $this->assertFalse($user->temPermissaoNaObra($obra->id, 'engenharia.pacotes', 'liberar_para_construcao'));
    }

    public function test_perfil_com_ver_e_liberar_mas_sem_editar_pode_liberar(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $perfil = $this->criarPerfilCustomizado($tenant, 'Liberador', [
            ['engenharia.pacotes', 'ver'],
            ['engenharia.pacotes', 'liberar_para_construcao'],
        ]);
        $this->atribuirPerfil($obra, $user, $perfil);

        $this->assertTrue($user->temPermissaoNaObra($obra->id, 'engenharia.pacotes', 'liberar_para_construcao'));
        $this->assertFalse($user->temPermissaoNaObra($obra->id, 'engenharia.pacotes', 'editar'));
    }

    public function test_resolver_restricao_e_capacidade_distinta_de_editar(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);
        $restricao = Restricao::factory()->create(['tenant_id' => $tenant->id, 'atividade_id' => $atividade->id, 'responsavel_id' => null]);

        $perfilResolver = $this->criarPerfilCustomizado($tenant, 'Só Resolver', [
            ['restricoes.quadro', 'resolver'],
        ]);
        $this->atribuirPerfil($obra, $user, $perfilResolver);
        $this->actingAs($user);

        $this->assertTrue($user->can('resolver', $restricao));
        $this->assertFalse($user->can('update', $restricao));
        $this->assertFalse($user->can('reabrir', $restricao));
    }

    // =========================================================================
    // Seção 46 — NÃO CRIAR HIERARQUIA IMPLÍCITA
    // =========================================================================

    public function test_editar_sozinho_nunca_implica_resolver_ou_comentar_num_perfil_novo_isolado(): void
    {
        // Um Perfil 100% novo/customizado (nunca passou pelo backfill,
        // que só tocou perfis JÁ existentes no momento da migração) com
        // 'editar' não ganha 'resolver'/'comentar' de graça — grants são
        // sempre explícitos (Seção 46).
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $perfil = $this->criarPerfilCustomizado($tenant, 'Só Editar (novo)', [
            ['restricoes.quadro', 'editar'],
        ]);
        $this->atribuirPerfil($obra, $user, $perfil);

        $this->assertTrue($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'editar'));
        $this->assertFalse($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'resolver'));
        $this->assertFalse($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'reabrir'));
        $this->assertFalse($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'comentar'));
        $this->assertFalse($user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'excluir'));
    }

    // =========================================================================
    // Seção 47 — TESTES: EXECUTIVO
    // =========================================================================

    public function test_perfil_executivo_ve_cockpit_mas_nao_detalhe_operacional_de_suprimentos(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $perfilExecutivo = $this->criarPerfilCustomizado($tenant, 'Executivo', [
            ['gestao.cockpit', 'ver'],
            ['gestao.suprimentos', 'ver'],
        ]);
        $this->atribuirPerfil($obra, $user, $perfilExecutivo);
        $this->actingAs($user);

        // Cockpit Executivo abre normalmente.
        Livewire::test('pages::radar.cockpit', ['obra' => $obra])->assertOk();

        // Cockpit de Suprimentos abre (tem gestao.suprimentos|ver), mas
        // NUNCA vê detalhe operacional (suprimentos.mapa|ver ausente).
        $componente = Livewire::test('pages::radar.cockpit-suprimentos', ['obra' => $obra])->assertOk();
        $this->assertFalse($componente->instance()->podeVerDetalheOperacional());

        // Não pode abrir o Mapa de Suprimentos operacional em si.
        $this->assertFalse($user->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'ver'));
    }

    public function test_perfil_executivo_sem_gestao_suprimentos_nao_abre_cockpit_de_suprimentos(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $perfilExecutivo = $this->criarPerfilCustomizado($tenant, 'Executivo Restrito', [
            ['gestao.cockpit', 'ver'],
        ]);
        $this->atribuirPerfil($obra, $user, $perfilExecutivo);
        $this->actingAs($user);

        // Handler::render() intercepta o 403 de mount() (navegação de
        // página cheia, sem header X-Livewire) e converte num redirect
        // com popup de acesso negado — mesmo padrão já documentado no
        // projeto (Fase 2A, Achado 7), nunca uma exceção crua na tela.
        Livewire::test('pages::radar.cockpit-suprimentos', ['obra' => $obra])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_cockpit_executivo_redige_deep_link_de_situacao_de_suprimentos_sem_permissao_operacional(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $perfilExecutivo = $this->criarPerfilCustomizado($tenant, 'Executivo', [
            ['gestao.cockpit', 'ver'],
        ]);
        $this->atribuirPerfil($obra, $user, $perfilExecutivo);
        $this->actingAs($user);

        $componente = Livewire::test('pages::radar.cockpit', ['obra' => $obra])->assertOk();

        $this->assertFalse($componente->instance()->podeVerDetalheDominio('suprimentos'));
        $this->assertFalse($componente->instance()->podeVerDetalhePorFuncionalidade('suprimentos.mapa'));
    }

    // =========================================================================
    // Seção 48 — TESTES: ÚLTIMO ADMIN
    // =========================================================================

    private function abrirObraDetalhe(Work $obra): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test('pages::gestao.obra-detalhe', ['obra' => $obra]);
    }

    public function test_admin_com_varios_perfis_nao_pode_perder_o_unico_perfil_admin(): void
    {
        // O criador do tenant é SEMPRE Admin automaticamente
        // (Work::garantirCriadorDoTenantComoAdmin()) — pra testar "o
        // único Admin do tenant com múltiplos perfis" de forma real,
        // o cenário É o próprio criador (nunca outro membro comum, que
        // sempre teria o criador como um segundo Admin já existente).
        // Só o administrador da PLATAFORMA pode alterar o perfil do
        // criador (guard `$ehCriadorDoTenant` em alterarPerfil()).
        $tenant = Tenant::factory()->create();
        $criadorDoTenant = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criadorDoTenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]); // auto-atribui Admin ao criador
        $planejamento = Perfil::porSlugPadrao($tenant, 'gerente_planejamento');
        $this->atribuirPerfil($obra, $criadorDoTenant, $planejamento);

        // is_platform_admin sozinho não basta (achado da Etapa 2.1 de
        // Pré-produção) — exige impersonation ATIVA do tenant dono da
        // obra pra passar em WorkPolicy::update().
        $tenantDoAdmin = Tenant::factory()->create();
        $platformAdmin = User::factory()->create(['tenant_id' => $tenantDoAdmin->id, 'is_platform_admin' => true]);
        $this->actingAs($platformAdmin);
        \App\Support\ImpersonationContext::start($tenant);

        $componente = $this->abrirObraDetalhe($obra);
        $componente->call('alterarPerfil', $criadorDoTenant->id, $planejamento->id);

        // Bloqueado: $criadorDoTenant é o único Admin do tenant (mesmo
        // tendo também Planejamento — perder o perfil Admin via a UI de
        // "1 perfil" continua proibido).
        $this->assertTrue($criadorDoTenant->fresh()->temPerfilNaObra($obra, 'admin'));
    }

    public function test_com_dois_admins_um_pode_perder_o_status(): void
    {
        $tenant = Tenant::factory()->create();
        $criadorDoTenant = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criadorDoTenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $membro = User::factory()->create(['tenant_id' => $tenant->id]);

        $admin = Perfil::porSlugPadrao($tenant, 'admin');
        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');

        // Criador do tenant já é Admin automaticamente (garantirCriadorDoTenantComoAdmin).
        $obra->users()->attach($membro->id, ['perfil_id' => $admin->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($obra, $membro->id, $admin->id);

        $this->actingAs($criadorDoTenant);

        $componente = $this->abrirObraDetalhe($obra);
        $componente->call('alterarPerfil', $membro->id, $engenheiro->id);

        $this->assertFalse($membro->fresh()->temPerfilNaObra($obra, 'admin'));
        $this->assertTrue($membro->fresh()->temPerfilNaObra($obra, 'engenheiro'));
    }

    public function test_remover_perfil_secundario_nao_afeta_condicao_de_ultimo_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $criadorDoTenant = $tenant->criador;
        $membro = User::factory()->create(['tenant_id' => $tenant->id]);

        $admin = Perfil::porSlugPadrao($tenant, 'admin');
        $planejamento = Perfil::porSlugPadrao($tenant, 'gerente_planejamento');

        $obra->users()->attach($membro->id, ['perfil_id' => $admin->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($obra, $membro->id, $admin->id);
        $this->atribuirPerfil($obra, $membro, $planejamento);

        // Remover diretamente na nova pivot só o perfil SECUNDÁRIO
        // (Planejamento) — Admin permanece intocado.
        ObraUserPerfil::where('work_id', $obra->id)
            ->where('user_id', $membro->id)
            ->where('perfil_id', $planejamento->id)
            ->delete();

        $this->assertTrue($membro->fresh()->temPerfilNaObra($obra, 'admin'));
    }

    public function test_usuario_sem_admin_nao_conta_como_admin_so_por_ter_varios_perfis(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');
        $encarregado = Perfil::porSlugPadrao($tenant, 'encarregado');

        $this->atribuirPerfil($obra, $user, $engenheiro);
        $this->atribuirPerfil($obra, $user, $encarregado);

        $this->assertFalse($user->temPerfilNaObra($obra, 'admin'));
    }

    // =========================================================================
    // Seção 49 — TESTES: CONVITES
    // =========================================================================

    public function test_convite_legado_com_1_perfil_continua_funcionando_e_cria_associacao_na_nova_pivot(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $remetente = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = Perfil::porSlugPadrao($tenant, 'encarregado');

        $convite = \App\Models\Convite::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'email' => 'novo@example.com',
            'perfil_id' => $perfil->id,
            'token' => \Illuminate\Support\Str::random(64),
            'convidado_por_id' => $remetente->id,
            'expira_em' => now()->addDays(7),
        ]);

        $response = $this->post(route('convite.aceitar', $convite->token), [
            'first_name' => 'Novo',
            'last_name' => 'Usuario',
            'password' => 'senha-segura-123',
            'password_confirmation' => 'senha-segura-123',
            'terms' => true,
        ]);

        $novoUsuario = User::where('email', 'novo@example.com')->first();
        $this->assertNotNull($novoUsuario);
        $this->assertTrue($obra->users()->where('user_id', $novoUsuario->id)->exists());

        $associacao = ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $novoUsuario->id)->first();
        $this->assertNotNull($associacao);
        $this->assertSame($perfil->id, $associacao->perfil_id);
    }

    public function test_convite_nao_duplica_associacao_ao_aceitar(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $remetente = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = Perfil::porSlugPadrao($tenant, 'engenheiro');
        $usuarioExistente = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'existente@example.com']);

        $convite = \App\Models\Convite::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'email' => 'existente@example.com',
            'perfil_id' => $perfil->id,
            'token' => \Illuminate\Support\Str::random(64),
            'convidado_por_id' => $remetente->id,
            'expira_em' => now()->addDays(7),
        ]);

        $this->post(route('convite.aceitar', $convite->token), [
            'first_name' => $usuarioExistente->first_name,
            'last_name' => $usuarioExistente->last_name,
            'password' => 'senha-segura-123',
            'password_confirmation' => 'senha-segura-123',
            'terms' => true,
        ]);

        $this->assertSame(
            1,
            ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $usuarioExistente->id)->count()
        );
    }

    // =========================================================================
    // Seção 50 — PERFORMANCE
    // =========================================================================

    public function test_menu_com_usuario_de_5_perfis_nao_gera_queries_proporcionais_a_itens(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // 5 perfis customizados, cada um com 1 permissão diferente.
        foreach (range(1, 5) as $i) {
            $perfil = $this->criarPerfilCustomizado($tenant, "Perfil {$i}", [
                ['restricoes.quadro', 'ver'],
            ]);
            $this->atribuirPerfil($obra, $user, $perfil);
        }

        $this->actingAs($user);
        \App\Support\ObraContext::set($obra);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        // Simula N checagens de menu/página pra funcionalidades
        // diferentes — todas devem reaproveitar o mesmo cache de união
        // depois da primeira.
        for ($i = 0; $i < 20; $i++) {
            $user->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'ver');
            $user->temPermissaoNaObra($obra->id, 'restricoes.lookahead', 'ver');
            $user->temPermissaoNaObra($obra->id, 'suprimentos.mapa', 'ver');
        }
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // 1 query pra resolver perfis (nova pivot) + 1 pra permissões em
        // lote (whereIn) + 1 pro espelho legado (works pivot) — nunca
        // proporcional às 60 chamadas feitas acima.
        $this->assertLessThanOrEqual(5, $queries);
    }

    public function test_usuario_com_1_perfil_vs_5_perfis_tem_contagem_de_query_comparavel(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $user1Perfil = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $user1Perfil, 'engenheiro');

        $user5Perfis = User::factory()->create(['tenant_id' => $tenant->id]);
        foreach (range(1, 5) as $i) {
            $perfil = $this->criarPerfilCustomizado($tenant, "Perfil Extra {$i}", [['restricoes.quadro', 'ver']]);
            $this->atribuirPerfil($obra, $user5Perfis, $perfil);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $user1Perfil->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'ver');
        $queries1 = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::flushQueryLog();

        $user5Perfis->temPermissaoNaObra($obra->id, 'restricoes.quadro', 'ver');
        $queries5 = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Mais perfis não deveria custar mais que +2 queries (a
        // permissões-em-lote via whereIn continua sendo 1 query, não N).
        $this->assertLessThanOrEqual($queries1 + 2, $queries5);
    }
}
