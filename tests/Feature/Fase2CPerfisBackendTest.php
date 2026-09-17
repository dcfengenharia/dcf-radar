<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Perfis\CapabilidadeCatalogo;
use App\Support\Perfis\ImpactoPerfilCalculator;
use App\Support\Perfis\ResolverPerfisEfetivos;
use App\Support\Perfis\TemplatesEspecialistas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FASE 2C — camada de backend da administração profissional de perfis:
 * acoesReaisPara/slugsComVerGated (Perfil), criarComCapacidades/duplicar
 * (Perfil), os 4 templates especialistas (TemplatesEspecialistas), os
 * presets do Modo Simples (CapabilidadeCatalogo) e os resolvers em lote
 * (ResolverPerfisEfetivos/ImpactoPerfilCalculator) — sempre verificados
 * contra o resolver central já aprovado (HasObraPapel), nunca só por
 * inspeção.
 */
class Fase2CPerfisBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_acoes_reais_para_gestao_cockpit_e_somente_ver(): void
    {
        $this->assertSame(['ver'], Perfil::acoesReaisPara('gestao.cockpit'));
    }

    public function test_b_acoes_reais_para_restricoes_quadro_inclui_capacidades_semanticas(): void
    {
        $acoes = Perfil::acoesReaisPara('restricoes.quadro');
        sort($acoes);
        $this->assertSame(['comentar', 'criar', 'editar', 'excluir', 'reabrir', 'resolver', 'ver'], $acoes);
    }

    public function test_c_slugs_com_ver_gated_sao_exatamente_os_3_cockpits(): void
    {
        $gated = Perfil::slugsComVerGated();
        sort($gated);
        $this->assertSame(['gestao.cockpit', 'gestao.engenharia', 'gestao.suprimentos'], $gated);
    }

    public function test_d_criar_com_capacidades_concede_ver_livre_exceto_gated(): void
    {
        $tenant = Tenant::factory()->create();

        $perfil = Perfil::criarComCapacidades($tenant, 'Teste', 'teste_slug', [], [
            ['restricoes.quadro', 'editar'],
        ]);

        $this->assertTrue(PerfilPermissao::where('perfil_id', $perfil->id)->where('funcionalidade', 'restricoes.quadro')->where('acao', 'ver')->exists());
        $this->assertTrue(PerfilPermissao::where('perfil_id', $perfil->id)->where('funcionalidade', 'restricoes.quadro')->where('acao', 'editar')->exists());
        $this->assertFalse(PerfilPermissao::where('perfil_id', $perfil->id)->where('funcionalidade', 'gestao.cockpit')->exists(), 'Gated sem ser explicitamente pedido nunca ganha nem "ver".');
        $this->assertSame('teste_slug', $perfil->fresh()->slug_padrao);
        $this->assertTrue($perfil->fresh()->ehPadrao());
    }

    public function test_e_criar_com_capacidades_concede_ver_gated_quando_pedido(): void
    {
        $tenant = Tenant::factory()->create();

        $perfil = Perfil::criarComCapacidades($tenant, 'Executivo Teste', 'exec_teste', ['gestao.cockpit'], []);

        $this->assertTrue(PerfilPermissao::where('perfil_id', $perfil->id)->where('funcionalidade', 'gestao.cockpit')->where('acao', 'ver')->exists());
        $this->assertFalse(PerfilPermissao::where('perfil_id', $perfil->id)->where('funcionalidade', 'gestao.suprimentos')->exists());
    }

    public function test_f_duplicar_copia_permissoes_mas_nunca_slug_padrao_nem_atribuicoes(): void
    {
        $tenant = Tenant::factory()->create();
        $original = Perfil::porSlugPadrao($tenant, 'engenheiro');
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($obra, $user, 'engenheiro');

        $copia = $original->duplicar('Engenharia Cliente');

        $this->assertNull($copia->slug_padrao);
        $this->assertFalse($copia->ehPadrao());
        $this->assertSame(
            $original->permissoes()->count(),
            $copia->permissoes()->count()
        );
        // Nenhuma atribuição copiada — a cópia não tem nenhum usuário/obra.
        $impacto = ImpactoPerfilCalculator::calcular($tenant->id, $copia->id);
        $this->assertSame(0, $impacto['usuarios']);
        $this->assertSame(0, $impacto['obras']);
    }

    public function test_g_templates_especialistas_sao_criaveis_e_distintos(): void
    {
        $tenant = Tenant::factory()->create();

        foreach (['planejamento', 'suprimentos', 'almoxarifado', 'executivo'] as $chave) {
            $perfil = TemplatesEspecialistas::criar($tenant, $chave);
            $this->assertSame('especialista_'.$chave, $perfil->slug_padrao);
            $this->assertGreaterThan(0, $perfil->permissoes()->count());
        }
    }

    public function test_h_template_executivo_nunca_concede_operacional(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $executivo = TemplatesEspecialistas::criar($tenant, 'executivo');
        $obra->users()->attach($user->id, ['perfil_id' => $executivo->id]);
        \App\Support\AtribuicaoPerfilObra::definirPerfilUnico($obra, $user->id, $executivo->id);

        $user = $user->fresh();
        $this->assertTrue($user->temPermissaoNaObra($obra, 'gestao.cockpit', 'ver'));
        $this->assertTrue($user->temPermissaoNaObra($obra, 'gestao.suprimentos', 'ver'));
        $this->assertTrue($user->temPermissaoNaObra($obra, 'gestao.engenharia', 'ver'));
        $this->assertFalse($user->temPermissaoNaObra($obra, 'suprimentos.mapa', 'ver'));
        $this->assertFalse($user->temPermissaoNaObra($obra, 'estoque.movimentacao', 'ver'));
        $this->assertFalse($user->temPermissaoNaObra($obra, 'engenharia.pacotes', 'ver'));
        $this->assertFalse($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'editar'));
    }

    public function test_i_template_almoxarifado_nunca_edita_suprimentos(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $almoxarifado = TemplatesEspecialistas::criar($tenant, 'almoxarifado');
        $obra->users()->attach($user->id, ['perfil_id' => $almoxarifado->id]);
        \App\Support\AtribuicaoPerfilObra::definirPerfilUnico($obra, $user->id, $almoxarifado->id);

        $user = $user->fresh();
        $this->assertTrue($user->temPermissaoNaObra($obra, 'estoque.movimentacao', 'criar'));
        $this->assertTrue($user->temPermissaoNaObra($obra, 'suprimentos.mapa', 'ver'));
        $this->assertFalse($user->temPermissaoNaObra($obra, 'suprimentos.mapa', 'editar'));
    }

    public function test_j_presets_sem_comentario_nao_oferece_colaboracao(): void
    {
        $presets = CapabilidadeCatalogo::presetsPara('engenharia.pacotes');
        $chaves = array_column($presets, 'chave');
        $this->assertNotContains('colaboracao', $chaves);
    }

    public function test_k_presets_com_comentario_oferece_consulta_mais_comentarios(): void
    {
        $presets = CapabilidadeCatalogo::presetsPara('restricoes.quadro');
        $colaboracao = collect($presets)->firstWhere('chave', 'colaboracao');
        $this->assertNotNull($colaboracao);
        $this->assertSame(['ver', 'comentar'], $colaboracao['acoes']);
    }

    public function test_l_preset_gestao_so_existe_quando_ha_excluir_real(): void
    {
        $presetsSemExcluir = CapabilidadeCatalogo::presetsPara('estoque.reserva'); // só 'editar' em REGRAS_ESCRITA
        $this->assertNotContains('gestao', array_column($presetsSemExcluir, 'chave'));

        $presetsComExcluir = CapabilidadeCatalogo::presetsPara('restricoes.quadro');
        $this->assertContains('gestao', array_column($presetsComExcluir, 'chave'));
    }

    public function test_m_preset_ativo_identifica_combinacao_exata_e_null_para_personalizado(): void
    {
        $this->assertSame('consulta', CapabilidadeCatalogo::presetAtivo('restricoes.quadro', ['ver']));
        $this->assertSame('colaboracao', CapabilidadeCatalogo::presetAtivo('restricoes.quadro', ['comentar', 'ver']));
        $this->assertNull(CapabilidadeCatalogo::presetAtivo('restricoes.quadro', ['ver', 'excluir']), 'Ver+excluir sem os intermediários não bate com nenhum preset — personalizado.');
    }

    public function test_n_funcionalidades_por_dominio_nunca_inventa_dominio_vazio(): void
    {
        $dominios = CapabilidadeCatalogo::funcionalidadesPorDominio();

        foreach ($dominios as $nome => $itens) {
            $this->assertNotEmpty($itens, "Domínio '{$nome}' não deveria existir vazio.");
        }

        $this->assertArrayNotHasKey('Administração', $dominios, 'Nenhuma funcionalidade do catálogo mapeia pra esse domínio hoje.');
    }

    public function test_o_resolver_perfis_efetivos_consistente_com_hasobrapapel(): void
    {
        $tenant = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $tenant->id]);
        $userA = User::factory()->create(['tenant_id' => $tenant->id]);
        $userB = User::factory()->create(['tenant_id' => $tenant->id]);

        $planejamento = Perfil::porSlugPadrao($tenant, 'gerente_planejamento');
        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');
        $encarregado = Perfil::porSlugPadrao($tenant, 'encarregado');

        // userA: multiperfil em obraA (Planejamento + Engenheiro), legado divergente forçado.
        $obraA->users()->attach($userA->id, ['perfil_id' => $planejamento->id]);
        \App\Support\AtribuicaoPerfilObra::definirPerfilUnico($obraA, $userA->id, $planejamento->id);
        \App\Support\AtribuicaoPerfilObra::adicionarPerfil($obraA, $userA->id, $engenheiro->id);

        // userB: só legado (nunca migrado pra nova pivot) em obraB.
        $obraB->users()->attach($userB->id, ['perfil_id' => $encarregado->id]);

        $pares = ResolverPerfisEfetivos::paraTenant($tenant->id)->keyBy(fn ($p) => $p->work_id.'|'.$p->user_id);

        $userAFresh = $userA->fresh();
        $userBFresh = $userB->fresh();

        $esperadoA = collect($userAFresh->perfisNaObra($obraA))->pluck('id')->sort()->values()->all();
        $obtidoA = collect($pares->get($obraA->id.'|'.$userA->id)->perfil_ids)->sort()->values()->all();
        $this->assertSame($esperadoA, $obtidoA);

        $esperadoB = collect($userBFresh->perfisNaObra($obraB))->pluck('id')->sort()->values()->all();
        $obtidoB = collect($pares->get($obraB->id.'|'.$userB->id)->perfil_ids)->sort()->values()->all();
        $this->assertSame($esperadoB, $obtidoB);
    }

    public function test_p_impacto_conta_usuarios_e_obras_unicos_sem_duplicar(): void
    {
        $tenant = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user1 = User::factory()->create(['tenant_id' => $tenant->id]);
        $user2 = User::factory()->create(['tenant_id' => $tenant->id]);

        $planejamento = Perfil::porSlugPadrao($tenant, 'gerente_planejamento');
        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');

        // user1 tem Planejamento em DUAS obras — conta como 1 usuário, 2 obras.
        $this->vincularObra($obraA, $user1, 'gerente_planejamento');
        $this->vincularObra($obraB, $user1, 'gerente_planejamento');
        // user2 tem Planejamento + Engenheiro na MESMA obra — conta como 1 usuário só pra Planejamento.
        $obraA->users()->attach($user2->id, ['perfil_id' => $planejamento->id]);
        \App\Support\AtribuicaoPerfilObra::definirPerfilUnico($obraA, $user2->id, $planejamento->id);
        \App\Support\AtribuicaoPerfilObra::adicionarPerfil($obraA, $user2->id, $engenheiro->id);

        $impacto = ImpactoPerfilCalculator::calcular($tenant->id, $planejamento->id);

        $this->assertSame(2, $impacto['usuarios'], 'user1 e user2 — nunca duplicado por estar em 2 obras.');
        $this->assertSame(2, $impacto['obras'], 'obraA e obraB.');
    }

    public function test_q_impacto_isola_por_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenantA->id]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $perfilA = Perfil::porSlugPadrao($tenantA, 'engenheiro');
        $this->vincularObra($obraA, $userA, 'engenheiro');

        $impactoB = ImpactoPerfilCalculator::calcular($tenantB->id, $perfilA->id);

        $this->assertSame(0, $impactoB['usuarios']);
        $this->assertSame(0, $impactoB['obras']);
    }

    /**
     * FASE 2C, fechamento adversarial, Seção 15/26 — "Operação" NUNCA
     * concede uma ação de autoridade formal elevada (hoje só
     * `liberar_para_construcao`) de brinde; ela só aparece via "Gestão
     * completa" (ou marcada manualmente no Modo Avançado).
     */
    public function test_q_preset_operacao_nunca_inclui_acao_elevada(): void
    {
        $presets = CapabilidadeCatalogo::presetsPara('engenharia.pacotes');
        $operacao = collect($presets)->firstWhere('chave', 'operacao');
        $gestao = collect($presets)->firstWhere('chave', 'gestao');

        $this->assertNotNull($operacao);
        $this->assertNotContains('liberar_para_construcao', $operacao['acoes']);
        $this->assertContains('criar', $operacao['acoes']);
        $this->assertContains('editar', $operacao['acoes']);

        $this->assertNotNull($gestao, '"Gestão completa" precisa existir pra a ação elevada ser alcançável fora do Modo Avançado.');
        $this->assertContains('liberar_para_construcao', $gestao['acoes']);
    }

    public function test_r_acoes_elevadas_reais_expoe_apenas_liberar_para_construcao(): void
    {
        $this->assertSame(['liberar_para_construcao'], CapabilidadeCatalogo::acoesElevadasReais('engenharia.pacotes'));
        $this->assertSame([], CapabilidadeCatalogo::acoesElevadasReais('restricoes.quadro'));
    }
}
