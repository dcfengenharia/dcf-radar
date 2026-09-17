<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\ObraUserPerfil;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\AtribuicaoPerfilObra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FASE 2B.CORREÇÃO — FECHAMENTO ADVERSARIAL DO CORE RBAC.
 *
 * Prova, antes de tudo, o comportamento REAL da versão original da Fase
 * 2B (união permanente obra_user_perfil + obra_user.perfil_id legado) e,
 * depois da correção, confirma a nova regra de autoridade (Seção 5/6 do
 * pedido): a nova pivot, uma vez POPULADA pra um par (obra, usuário), é
 * autoridade completa — o legado só entra como fallback de
 * compatibilidade quando a pivot está totalmente vazia pra aquele par.
 *
 * Cobertura: Seções 4 (Casos A-F), 8 (semântica de "alterar perfil"
 * legado), 9 (API multiperfil nova), 10 (teste crítico de remoção), 11
 * (zero perfis determinístico), 13 (Perfil de outro tenant), 14 (último
 * Admin com a API nova), 15 (invalidação de cache no mesmo lifecycle).
 */
class Fase2BCorrecaoRbacTest extends TestCase
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

    // =========================================================================
    // Seção 4 — CASOS A-F: comportamento da coexistência legado × nova pivot
    // =========================================================================

    public function test_caso_a_legado_planejamento_mais_pivot_planejamento_concede_planejamento(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);

        $this->vincularObraComPerfil($obra, $user, $planejamento);

        $this->assertTrue($user->fresh()->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'));
    }

    public function test_caso_b_legado_planejamento_mais_pivot_planejamento_e_suprimentos_e_uniao_correta(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);
        $suprimentosConsulta = $this->criarPerfilCustomizado($tenant, 'Suprimentos Consulta', [['cadastros.categorias_restricao', 'editar']]);

        $this->vincularObraComPerfil($obra, $user, $planejamento);
        AtribuicaoPerfilObra::adicionarPerfil($obra, $user->id, $suprimentosConsulta->id);

        $user = $user->fresh();
        $this->assertTrue($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'));
        $this->assertTrue($user->temPermissaoNaObra($obra, 'cadastros.categorias_restricao', 'editar'));
    }

    /**
     * CASO C (o achado central da auditoria): remover Planejamento da
     * pivot enquanto o legado CONTINUA dizendo Planejamento — a versão
     * ORIGINAL (união permanente) concederia Planejamento pra sempre
     * ("revogação fantasma"); a versão corrigida revoga imediatamente,
     * porque a pivot (não-vazia, com Suprimentos ainda presente) é
     * autoridade completa e o legado divergente é ignorado.
     */
    public function test_caso_c_remover_da_pivot_revoga_mesmo_com_legado_divergente(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);
        $suprimentosConsulta = $this->criarPerfilCustomizado($tenant, 'Suprimentos Consulta', [['cadastros.categorias_restricao', 'editar']]);

        $this->vincularObraComPerfil($obra, $user, $planejamento);
        AtribuicaoPerfilObra::adicionarPerfil($obra, $user->id, $suprimentosConsulta->id);

        // Confirma baseline: as duas capacidades presentes.
        $user = $user->fresh();
        $this->assertTrue($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'));

        AtribuicaoPerfilObra::removerPerfil($obra, $user->id, $planejamento->id);

        $legadoLogoApos = DB::table('obra_user')->where('work_id', $obra->id)->where('user_id', $user->id)->value('perfil_id');
        $this->assertSame($suprimentosConsulta->id, $legadoLogoApos, 'removerPerfil() já reaponta o legado sozinho pro perfil remanescente (Seção 14) — comportamento melhor que simplesmente deixá-lo divergente.');

        // removerPerfil() já reaponta o legado pro perfil remanescente
        // (Seção 14 — nunca deixar "Admin fantasma"/perfil fantasma
        // pendurado), então neste ponto o legado já NÃO é mais
        // Planejamento. Pra provar literalmente o Caso C do pedido
        // ("legado continua Planejamento"), forçamos aqui uma
        // divergência bruta — exatamente o cenário de um caller/dado
        // legado que nunca passou por AtribuicaoPerfilObra — e
        // confirmamos que o resolver a ignora de qualquer forma.
        DB::table('obra_user')->where('work_id', $obra->id)->where('user_id', $user->id)->update(['perfil_id' => $planejamento->id]);
        $legadoAtual = DB::table('obra_user')->where('work_id', $obra->id)->where('user_id', $user->id)->value('perfil_id');
        $this->assertSame($planejamento->id, $legadoAtual, 'Pré-condição: o legado precisa estar de fato divergente (Planejamento) neste ponto.');

        $user = $user->fresh();
        $this->assertFalse($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'), 'Planejamento deve ter sido revogado de verdade, mesmo com o legado ainda apontando pra ele.');
        $this->assertTrue($user->temPermissaoNaObra($obra, 'cadastros.categorias_restricao', 'editar'), 'Suprimentos Consulta deve permanecer intacto.');
    }

    /**
     * CASO D — a operação legada "alterar perfil" (⚡obra-detalhe.blade.php)
     * precisa SUBSTITUIR a coleção efetiva por exatamente 1 perfil, nunca
     * somar silenciosamente ao que já existia (Seção 8).
     */
    public function test_caso_d_alterar_perfil_via_ui_antiga_substitui_a_colecao_inteira(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $membro = User::factory()->create(['tenant_id' => $tenant->id]);

        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);
        $suprimentosConsulta = $this->criarPerfilCustomizado($tenant, 'Suprimentos Consulta', [['cadastros.categorias_restricao', 'editar']]);
        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');

        $this->vincularObraComPerfil($obra, $membro, $planejamento);
        AtribuicaoPerfilObra::adicionarPerfil($obra, $membro->id, $suprimentosConsulta->id);

        $this->assertCount(2, ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $membro->id)->get());

        $this->actingAs($criador);
        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $obra])
            ->call('alterarPerfil', $membro->id, $engenheiro->id);

        $restantes = ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $membro->id)->pluck('perfil_id');
        $this->assertSame([$engenheiro->id], $restantes->all(), 'alterarPerfil() precisa substituir a coleção inteira por exatamente 1 perfil, nunca somar.');

        $legadoAtual = DB::table('obra_user')->where('work_id', $obra->id)->where('user_id', $membro->id)->value('perfil_id');
        $this->assertSame($engenheiro->id, $legadoAtual);
    }

    public function test_caso_e_remover_membro_da_obra_zera_pivot_nenhuma_associacao_orfa_concede_acesso(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);

        $this->vincularObraComPerfil($obra, $user, $planejamento);
        $this->assertTrue($user->fresh()->temAcessoAObra($obra));

        // Simula uma associação órfã: membresia removida SEM passar por
        // AtribuicaoPerfilObra::removerTodas() (dado inconsistente / bug
        // de um caller que não migrou) — a pivot nova continua com a
        // linha de Planejamento.
        DB::table('obra_user')->where('work_id', $obra->id)->where('user_id', $user->id)->delete();
        $this->assertTrue(ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $user->id)->exists(), 'Pré-condição: a pivot órfã precisa continuar existindo fisicamente.');

        $user = $user->fresh();
        $this->assertFalse($user->temAcessoAObra($obra), 'Sem membresia, a pivot órfã nunca deve conceder acesso.');
        $this->assertFalse($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'));

        // O caminho real (removerMembro()) deve, além de apagar a
        // membresia, também limpar a pivot — confirma que não sobra lixo.
        AtribuicaoPerfilObra::removerTodas($obra, $user->id);
        $this->assertFalse(ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $user->id)->exists());
    }

    public function test_caso_f_convite_legado_gera_membresia_e_pivot_coerentes(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $remetente = User::factory()->create(['tenant_id' => $tenant->id]);
        $perfil = Perfil::porSlugPadrao($tenant, 'encarregado');

        $convite = \App\Models\Convite::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'email' => 'novo@exemplo.com',
            'perfil_id' => $perfil->id,
            'token' => \Illuminate\Support\Str::random(64),
            'convidado_por_id' => $remetente->id,
            'expira_em' => now()->addDays(7),
        ]);

        $response = $this->post(route('convite.aceitar', $convite->token), [
            'first_name' => 'Novo',
            'last_name' => 'Usuario',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
            'terms' => true,
        ]);

        $novoUsuario = User::where('email', 'novo@exemplo.com')->first();
        $this->assertNotNull($novoUsuario);
        $this->assertTrue($obra->users()->where('user_id', $novoUsuario->id)->exists(), 'Membresia precisa existir.');
        $this->assertSame(
            [$perfil->id],
            ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $novoUsuario->id)->pluck('perfil_id')->all(),
            'A nova pivot precisa refletir exatamente o perfil do convite.'
        );
        $this->assertTrue($novoUsuario->fresh()->temPerfilNaObra($obra, 'encarregado'));
    }

    // =========================================================================
    // Seção 9/10 — API MULTIPERFIL: adicionarPerfil/removerPerfil/substituirPerfis
    // =========================================================================

    /**
     * TESTE CRÍTICO (Seção 10, obrigatório): usuário possui Planejamento
     * + Suprimentos Consulta; remover Planejamento faz Planejamento
     * desaparecer IMEDIATAMENTE e Suprimentos permanecer — mesmo que
     * obra_user.perfil_id ainda possua um valor legado incompatível.
     */
    public function test_secao_10_remover_um_perfil_nao_afeta_o_outro_mesmo_com_legado_incompativel(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);
        $suprimentosConsulta = $this->criarPerfilCustomizado($tenant, 'Suprimentos Consulta', [['cadastros.categorias_restricao', 'editar']]);

        $this->vincularObraComPerfil($obra, $user, $planejamento);
        AtribuicaoPerfilObra::adicionarPerfil($obra, $user->id, $suprimentosConsulta->id);

        // Divergência deliberada: legado é reescrito pra um terceiro
        // valor incompatível (nem Planejamento, nem Suprimentos) — a
        // remoção não deve se importar com isso, já que a pivot segue
        // não-vazia depois (Suprimentos continua lá).
        $terceiro = $this->criarPerfilCustomizado($tenant, 'Legado Incompatível', []);
        DB::table('obra_user')->where('work_id', $obra->id)->where('user_id', $user->id)->update(['perfil_id' => $terceiro->id]);

        AtribuicaoPerfilObra::removerPerfil($obra, $user->id, $planejamento->id);

        $user = $user->fresh();
        $this->assertFalse($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'), 'Planejamento precisa desaparecer imediatamente.');
        $this->assertTrue($user->temPermissaoNaObra($obra, 'cadastros.categorias_restricao', 'editar'), 'Suprimentos Consulta precisa permanecer.');
        $this->assertFalse($user->temPerfilNaObra($obra, 'Legado Incompatível'), 'O legado incompatível nunca deveria ter sido considerado — a pivot é autoridade.');
    }

    public function test_adicionar_perfil_nunca_duplica_se_chamado_duas_vezes(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);
        $this->vincularObraComPerfil($obra, $user, $planejamento);

        $suprimentosConsulta = $this->criarPerfilCustomizado($tenant, 'Suprimentos Consulta', [['cadastros.categorias_restricao', 'editar']]);
        AtribuicaoPerfilObra::adicionarPerfil($obra, $user->id, $suprimentosConsulta->id);
        AtribuicaoPerfilObra::adicionarPerfil($obra, $user->id, $suprimentosConsulta->id);

        $this->assertSame(1, ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $user->id)->where('perfil_id', $suprimentosConsulta->id)->count());
    }

    public function test_substituir_perfis_troca_a_colecao_inteira_por_multiplos_perfis(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);
        $this->vincularObraComPerfil($obra, $user, $planejamento);

        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');
        $encarregado = Perfil::porSlugPadrao($tenant, 'encarregado');

        AtribuicaoPerfilObra::substituirPerfis($obra, $user->id, [$engenheiro->id, $encarregado->id]);

        $restantes = ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $user->id)->pluck('perfil_id')->sort()->values();
        $this->assertSame(collect([$engenheiro->id, $encarregado->id])->sort()->values()->all(), $restantes->all());
        $this->assertFalse($user->fresh()->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'), 'Planejamento não deve mais estar presente após a substituição.');
    }

    // =========================================================================
    // Seção 11 — ZERO PERFIS: determinístico, sem ressurreição do legado
    // =========================================================================

    public function test_secao_11_zero_perfis_efetivos_e_deterministico_nao_ressuscita_legado(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);

        $this->vincularObraComPerfil($obra, $user, $planejamento);

        AtribuicaoPerfilObra::removerPerfil($obra, $user->id, $planejamento->id);

        // Determinismo: o legado precisa ter sido zerado por
        // removerPerfil(), já que essa era a última associação restante
        // — senão o fallback de compatibilidade ressuscitaria Planejamento.
        $legadoAtual = DB::table('obra_user')->where('work_id', $obra->id)->where('user_id', $user->id)->value('perfil_id');
        $this->assertNull($legadoAtual, 'O espelho legado precisa ser zerado quando a última associação da pivot é removida.');

        $user = $user->fresh();
        $this->assertSame([], $user->perfisNaObra($obra)->pluck('id')->all());
        $this->assertFalse($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'));
        // Documentado (Seção 30 da Fase 2B original, preservado por esta
        // correção): membresia sem NENHUM perfil conta como SEM
        // capability — nunca um fallback permissivo. "temAcessoAObra"
        // aqui reflete exatamente essa decisão já estabelecida, não uma
        // mudança desta correção.
        $this->assertFalse($user->temAcessoAObra($obra));
    }

    // =========================================================================
    // Seção 13 — PERFIL DE OUTRO TENANT: nenhum helper novo aceita
    // =========================================================================

    public function test_secao_13_adicionar_perfil_de_outro_tenant_e_rejeitado(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenantA->id]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $perfilDeB = $this->criarPerfilCustomizado($tenantB, 'Perfil de B', [['restricoes.quadro', 'excluir']]);

        $this->expectException(\InvalidArgumentException::class);

        AtribuicaoPerfilObra::adicionarPerfil($obraA, $userA->id, $perfilDeB->id);
    }

    public function test_secao_13_substituir_perfis_com_um_de_outro_tenant_e_rejeitado_sem_escrita_parcial(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenantA->id]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $perfilDeA = $this->criarPerfilCustomizado($tenantA, 'Perfil de A', [['restricoes.quadro', 'excluir']]);
        $perfilDeB = $this->criarPerfilCustomizado($tenantB, 'Perfil de B', [['restricoes.quadro', 'excluir']]);

        try {
            AtribuicaoPerfilObra::substituirPerfis($obraA, $userA->id, [$perfilDeA->id, $perfilDeB->id]);
            $this->fail('Deveria ter lançado InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            // esperado
        }

        $this->assertSame(0, ObraUserPerfil::where('work_id', $obraA->id)->where('user_id', $userA->id)->count(), 'Nenhuma escrita parcial deve ter acontecido.');
    }

    public function test_secao_13_definir_perfil_unico_com_perfil_de_outro_tenant_e_rejeitado(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenantA->id]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $perfilDeB = $this->criarPerfilCustomizado($tenantB, 'Perfil de B', [['restricoes.quadro', 'excluir']]);

        $this->expectException(\InvalidArgumentException::class);

        AtribuicaoPerfilObra::definirPerfilUnico($obraA, $userA->id, $perfilDeB->id);
    }

    // =========================================================================
    // Seção 14 — ÚLTIMO ADMIN: reafirmado sob a nova API multiperfil
    // =========================================================================

    /**
     * Fecha a lacuna que a própria API nova (removerPerfil) poderia abrir
     * no guard de "último Admin" de ⚡obra-detalhe.blade.php (que ainda lê
     * o legado cru via OR na query SQL): remover o perfil Admin como
     * SECUNDÁRIO (mantendo outro perfil) precisa reapontar o legado pro
     * perfil remanescente, nunca deixar um "Admin fantasma".
     */
    public function test_secao_14_remover_perfil_admin_secundario_nunca_deixa_admin_fantasma_no_legado(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $membro = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin = Perfil::porSlugPadrao($tenant, 'admin');
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);

        // $membro começa com Admin (legado sincronizado) + Planejamento (secundário).
        $this->vincularObraComPerfil($obra, $membro, $admin);
        AtribuicaoPerfilObra::adicionarPerfil($obra, $membro->id, $planejamento->id);

        AtribuicaoPerfilObra::removerPerfil($obra, $membro->id, $admin->id);

        $legadoAtual = DB::table('obra_user')->where('work_id', $obra->id)->where('user_id', $membro->id)->value('perfil_id');
        $this->assertSame($planejamento->id, $legadoAtual, 'O legado precisa reapontar pro perfil remanescente, nunca continuar em Admin.');
        $this->assertFalse($membro->fresh()->temPerfilNaObra($obra, 'admin'));
    }

    public function test_secao_14_ultimo_admin_ainda_bloqueado_via_ui_apos_correcao_do_resolver(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]); // criador já é Admin automaticamente

        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');

        $this->actingAs($criador);
        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $obra])
            ->call('alterarPerfil', $criador->id, $engenheiro->id);

        $this->assertTrue($criador->fresh()->temPerfilNaObra($obra, 'admin'), 'Único Admin do tenant nunca pode perder o status via a UI legada.');
    }

    // =========================================================================
    // Seção 15 — CACHE: invalidação dentro do mesmo lifecycle/request
    // =========================================================================

    public function test_secao_15_cache_e_invalidado_apos_alterar_perfis_na_mesma_instancia(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);

        $this->vincularObraComPerfil($obra, $user, $planejamento);

        // Consulta permissão — popula o cache em memória desta instância.
        $this->assertTrue($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'));

        // Altera perfis por fora — a MESMA instância $user não sabe disso ainda.
        AtribuicaoPerfilObra::removerPerfil($obra, $user->id, $planejamento->id);

        // Sem invalidar, a instância continuaria respondendo o valor cacheado.
        $this->assertTrue($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'), 'Pré-condição: sem invalidação explícita, o cache em memória continua stale (comportamento documentado, não um bug).');

        $user->esquecerCachePerfisNaObra($obra);

        $this->assertFalse($user->temPermissaoNaObra($obra, 'restricoes.quadro', 'excluir'), 'Depois de invalidar o cache, a MESMA instância precisa refletir o estado novo.');
    }

    public function test_secao_15_esquecer_cache_sem_argumento_limpa_todas_as_obras(): void
    {
        $tenant = Tenant::factory()->create();
        $obraA = Work::factory()->create(['tenant_id' => $tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);

        $this->vincularObraComPerfil($obraA, $user, $planejamento);
        $this->vincularObraComPerfil($obraB, $user, $planejamento);

        $this->assertTrue($user->temPermissaoNaObra($obraA, 'restricoes.quadro', 'excluir'));
        $this->assertTrue($user->temPermissaoNaObra($obraB, 'restricoes.quadro', 'excluir'));

        AtribuicaoPerfilObra::removerPerfil($obraA, $user->id, $planejamento->id);
        AtribuicaoPerfilObra::removerPerfil($obraB, $user->id, $planejamento->id);

        $user->esquecerCachePerfisNaObra();

        $this->assertFalse($user->temPermissaoNaObra($obraA, 'restricoes.quadro', 'excluir'));
        $this->assertFalse($user->temPermissaoNaObra($obraB, 'restricoes.quadro', 'excluir'));
    }

    // =========================================================================
    // temPermissaoEmAlgumaObraDoTenant() — mesma regra de autoridade
    // =========================================================================

    public function test_permissao_em_alguma_obra_do_tenant_tambem_respeita_autoridade_da_pivot_por_obra(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $planejamento = $this->criarPerfilCustomizado($tenant, 'Planejamento', [['restricoes.quadro', 'excluir']]);
        $suprimentosConsulta = $this->criarPerfilCustomizado($tenant, 'Suprimentos Consulta', [['cadastros.categorias_restricao', 'editar']]);

        $this->vincularObraComPerfil($obra, $user, $planejamento);
        AtribuicaoPerfilObra::adicionarPerfil($obra, $user->id, $suprimentosConsulta->id);

        $this->assertTrue($user->fresh()->temPermissaoEmAlgumaObraDoTenant('restricoes.quadro', 'excluir'));

        // Divergência deliberada — legado continua Planejamento mesmo
        // depois de remover Planejamento da pivot (pivot não fica vazia,
        // Suprimentos permanece) — a checagem tenant-wide também precisa
        // ignorar o legado divergente, não só a checagem por obra.
        AtribuicaoPerfilObra::removerPerfil($obra, $user->id, $planejamento->id);
        DB::table('obra_user')->where('work_id', $obra->id)->where('user_id', $user->id)->update(['perfil_id' => $planejamento->id]);

        $user = $user->fresh();
        $this->assertFalse($user->temPermissaoEmAlgumaObraDoTenant('restricoes.quadro', 'excluir'), 'Legado divergente não pode conceder via a checagem tenant-wide.');
        $this->assertTrue($user->temPermissaoEmAlgumaObraDoTenant('cadastros.categorias_restricao', 'editar'));
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * Vincula $user à $obra com $perfil, mantendo legado e nova pivot
     * coerentes desde o início — mesmo caminho que qualquer caller de
     * produção usa (attach + AtribuicaoPerfilObra::definirPerfilUnico()).
     */
    private function vincularObraComPerfil(Work $obra, User $user, Perfil $perfil): void
    {
        if (! $obra->users()->where('user_id', $user->id)->exists()) {
            $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);
        } else {
            $obra->users()->updateExistingPivot($user->id, ['perfil_id' => $perfil->id]);
        }

        AtribuicaoPerfilObra::definirPerfilUnico($obra, $user->id, $perfil->id);
    }
}
