<?php

namespace Tests\Feature;

use App\Actions\LicoesAprendidas\RegistrarReaplicacaoLicao;
use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\Papel;
use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Enums\TipoLicaoAprendida;
use App\Exceptions\ReaplicacaoLicaoImutavelException;
use App\Exceptions\ReaplicacaoLicaoInvalidaException;
use App\Exceptions\ReaplicacaoLicaoJaRegistradaException;
use App\Exceptions\ReaplicacaoLicaoNaoAutorizadaException;
use App\Models\Atividade;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaReaplicacao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 23, Etapa 23.5.B — cobertura de `RegistrarReaplicacaoLicao` e da
 * identidade/imutabilidade de `LicaoAprendidaReaplicacao` (Decisão 25 do
 * pedido).
 */
class ReaplicacaoLicaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obraOrigem;
    private Work $obraDestino;
    private Work $obraDestino2;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obraOrigem = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Origem']);
        $this->obraDestino = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Destino']);
        $this->obraDestino2 = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Destino 2']);
        $this->usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraOrigem, $this->usuario, Papel::Admin->value);
        $this->vincularObra($this->obraDestino, $this->usuario, Papel::Admin->value);
        $this->vincularObra($this->obraDestino2, $this->usuario, Papel::Admin->value);
        $this->actingAs($this->usuario);
    }

    private function criarLicao(array $overrides = []): LicaoAprendida
    {
        return LicaoAprendida::create(array_merge([
            'obra_origem_id' => $this->obraOrigem->id,
            'titulo' => 'Lição de teste',
            'situacao_observada' => 'Situação observada.',
            'recomendacao_futura' => 'Recomendação futura.',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Media->value,
            'area_funcional' => AreaFuncionalLicao::Campo->value,
            'status' => StatusLicaoAprendida::Rascunho->value,
        ], $overrides));
    }

    private function criarLicaoPublicada(array $overrides = []): LicaoAprendida
    {
        return $this->criarLicao(array_merge([
            'status' => StatusLicaoAprendida::Publicada->value,
            'publicado_em' => now(),
        ], $overrides));
    }

    private function registrar(LicaoAprendida $licao, Work $obra, ?string $observacao = null, array $contextos = []): LicaoAprendidaReaplicacao
    {
        return app(RegistrarReaplicacaoLicao::class)->execute($licao, $obra, $this->usuario, $observacao, $contextos);
    }

    // =========================================================================
    // ELEGIBILIDADE (Publicada pode / Rascunho, EmValidação, Arquivada não)
    // =========================================================================

    public function test_publicada_pode_ser_reaplicada(): void
    {
        $licao = $this->criarLicaoPublicada();

        $reaplicacao = $this->registrar($licao, $this->obraDestino);

        $this->assertSame($licao->id, $reaplicacao->licao_aprendida_id);
        $this->assertSame($this->obraDestino->id, $reaplicacao->obra_id);
        $this->assertSame($this->usuario->id, $reaplicacao->created_by_id);
    }

    public function test_rascunho_nunca_pode_ser_reaplicada(): void
    {
        $licao = $this->criarLicao(['status' => StatusLicaoAprendida::Rascunho->value]);

        $this->expectException(ReaplicacaoLicaoInvalidaException::class);
        $this->registrar($licao, $this->obraDestino);
    }

    public function test_em_validacao_nunca_pode_ser_reaplicada(): void
    {
        $licao = $this->criarLicao(['status' => StatusLicaoAprendida::EmValidacao->value]);

        $this->expectException(ReaplicacaoLicaoInvalidaException::class);
        $this->registrar($licao, $this->obraDestino);
    }

    public function test_arquivada_nunca_pode_ser_reaplicada(): void
    {
        $licao = $this->criarLicao(['status' => StatusLicaoAprendida::Arquivada->value, 'arquivado_em' => now()]);

        $this->expectException(ReaplicacaoLicaoInvalidaException::class);
        $this->registrar($licao, $this->obraDestino);
    }

    // =========================================================================
    // OBRA DE ORIGEM (Seção 8)
    // =========================================================================

    public function test_propria_obra_de_origem_nunca_pode_ser_reaplicada(): void
    {
        $licao = $this->criarLicaoPublicada();

        $this->expectException(ReaplicacaoLicaoInvalidaException::class);
        $this->registrar($licao, $this->obraOrigem);
    }

    public function test_outra_obra_do_mesmo_tenant_pode(): void
    {
        $licao = $this->criarLicaoPublicada();

        $reaplicacao = $this->registrar($licao, $this->obraDestino);

        $this->assertNotNull($reaplicacao->id);
    }

    // =========================================================================
    // TENANT (Seção 17)
    // =========================================================================

    public function test_obra_de_outro_tenant_nunca_e_aceita(): void
    {
        // Ciclo 23.5.B.CORREÇÃO: desde que a Action passou a checar
        // autorização PRIMEIRO (Seção 1), este caminho REAL sempre bate
        // na autorização antes do check de domínio — `$this->usuario`
        // nunca tem vínculo `obra_user` com uma obra de outro tenant, é
        // estruturalmente impossível. `ReaplicacaoLicaoInvalidaException`
        // (o check explícito de tenant) permanece como defesa em
        // profundidade — achado de auditoria confirmado no teste seguinte
        // (`test_autorizacao_e_transitivamente_protegida_contra_tenant_cruzado_mesmo_com_pivot_manipulado`):
        // sequer é POSSÍVEL construir um cenário onde a autorização passe
        // pra uma obra de outro tenant, porque `HasObraPapel::works()`
        // herda o global scope de tenant de `Work` — a query de permissão
        // em si já não encontra a obra de outro tenant, mesmo com uma
        // linha manipulada em `obra_user`.
        $licao = $this->criarLicaoPublicada();

        $outroTenant = Tenant::factory()->create();
        $obraOutroTenant = TenantContext::actingAs($outroTenant, fn () => Work::factory()->create(['tenant_id' => $outroTenant->id]));

        $this->expectException(ReaplicacaoLicaoNaoAutorizadaException::class);
        $this->registrar($licao, $obraOutroTenant);

        $this->assertSame(0, LicaoAprendidaReaplicacao::count());
    }

    /**
     * Ciclo 23.5.B.CORREÇÃO — achado de auditoria (Seção 1/4 do pedido de
     * correção): tentei construir um cenário adversarial isolando a 2ª
     * camada de defesa (o check de domínio de tenant dentro da Action),
     * forçando uma linha MANIPULADA em `obra_user` ligando
     * `$this->usuario` (tenant A) a uma obra do tenant B — a intenção era
     * provar que o check de domínio bloqueia mesmo se a autorização
     * "passasse". Na prática, a autorização NUNCA passa nesse cenário:
     * `HasObraPapel::perfilIdNaObra()` resolve via `$this->works()` —
     * relação `belongsToMany(Work::class, ...)` — e `Work` usa
     * `BelongsToTenant` (global scope), então a query já filtra pelo
     * tenant AMBIENTE (A) antes mesmo de considerar a linha do pivot;
     * uma obra do tenant B nunca é encontrada, mesmo com a linha
     * `obra_user` existindo fisicamente. `LicaoAprendidaReaplicacaoPolicy::
     * registrar()` é, portanto, TRANSITIVAMENTE protegida contra tenant
     * cruzado pelo mesmo mecanismo de isolamento de `Work` — nunca uma
     * regra própria de tenant dentro da Policy. O check de domínio de
     * tenant na Action continua existindo como defesa REALMENTE em
     * profundidade — só seria exercitado se `HasObraPapel` algum dia
     * parasse de usar uma relação tenant-scoped, o que violaria a regra
     * "nunca `withoutGlobalScope()` em código de request" do próprio
     * CLAUDE.md.
     */
    public function test_autorizacao_e_transitivamente_protegida_contra_tenant_cruzado_mesmo_com_pivot_manipulado(): void
    {
        $licao = $this->criarLicaoPublicada();

        $outroTenant = Tenant::factory()->create();
        [$obraOutroTenant, $perfilOutroTenant] = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $perfil = \App\Models\Perfil::porSlugPadrao($outroTenant, Papel::Encarregado->value);

            return [$obra, $perfil];
        });

        // Vínculo cross-tenant manipulado manualmente — nunca alcançável
        // via `vincularObra()`/UI real, só pra provar que nem isso basta.
        $obraOutroTenant->users()->attach($this->usuario->id, ['perfil_id' => $perfilOutroTenant->id]);

        $this->assertFalse(
            $this->usuario->can('registrar', [LicaoAprendidaReaplicacao::class, $obraOutroTenant]),
            'Mesmo com a linha obra_user manipulada, a Policy nunca autoriza — Work é tenant-scoped.'
        );

        $this->expectException(ReaplicacaoLicaoNaoAutorizadaException::class);
        app(RegistrarReaplicacaoLicao::class)->execute($licao, $obraOutroTenant, $this->usuario);
    }

    public function test_reaplicacao_criada_pertence_ao_tenant_da_licao(): void
    {
        $licao = $this->criarLicaoPublicada();

        $reaplicacao = $this->registrar($licao, $this->obraDestino);

        $this->assertSame($this->tenant->id, $reaplicacao->tenant_id);
    }

    // =========================================================================
    // AUTORIZAÇÃO (Seção 15) — checada EM DOBRO desde a 23.5.B.CORREÇÃO:
    // a Policy em si (testada aqui isoladamente) E, mais importante,
    // `RegistrarReaplicacaoLicao` REVALIDA a mesma Policy internamente
    // (write-path seguro por construção — ver bloco "AUTORIZAÇÃO NO
    // WRITE PATH" logo abaixo, com chamadas DIRETAS à Action, nunca
    // via componente Livewire).
    // =========================================================================

    public function test_policy_registrar_exige_criar_na_obra_destino(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraDestino, $encarregado, Papel::ClienteLeitura->value);

        $this->assertFalse($encarregado->can('registrar', [LicaoAprendidaReaplicacao::class, $this->obraDestino]));

        $engenheiro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraDestino, $engenheiro, Papel::Encarregado->value);
        $this->assertTrue($engenheiro->can('registrar', [LicaoAprendidaReaplicacao::class, $this->obraDestino]));
    }

    public function test_policy_registrar_e_por_obra_nunca_global(): void
    {
        // Usuário tem `criar` só na obraDestino, NÃO na obraDestino2.
        $usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraDestino, $usuario, Papel::Encarregado->value);
        $this->vincularObra($this->obraDestino2, $usuario, Papel::ClienteLeitura->value);

        $this->assertTrue($usuario->can('registrar', [LicaoAprendidaReaplicacao::class, $this->obraDestino]));
        $this->assertFalse($usuario->can('registrar', [LicaoAprendidaReaplicacao::class, $this->obraDestino2]));
    }

    // =========================================================================
    // Ciclo 23, Etapa 23.5.B.CORREÇÃO (Seção 1/2) — AUTORIZAÇÃO NO WRITE
    // PATH: `RegistrarReaplicacaoLicao` chamada DIRETAMENTE, SEM passar
    // por nenhum componente Livewire/trait — prova que a Action rejeita
    // sozinha uma chamada não autorizada, mesmo que um chamador futuro
    // esqueça de checar a Policy antes.
    // =========================================================================

    public function test_action_rejeita_usuario_sem_criar_na_obra_destino_mesmo_em_chamada_direta(): void
    {
        $licao = $this->criarLicaoPublicada();
        $semCriar = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraDestino, $semCriar, Papel::ClienteLeitura->value);

        $this->expectException(ReaplicacaoLicaoNaoAutorizadaException::class);
        app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obraDestino, $semCriar);
    }

    public function test_action_rejeita_sem_autorizacao_antes_mesmo_de_validar_dominio(): void
    {
        // Usuário sem NENHUMA permissão e lição AINDA em Rascunho (2
        // motivos de rejeição ao mesmo tempo) — a exceção de
        // autorização precisa vir primeiro, nunca vazar detalhe de
        // domínio (status da lição) pra quem nem deveria poder tentar.
        $licao = $this->criarLicao(['status' => StatusLicaoAprendida::Rascunho->value]);
        $semCriar = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraDestino, $semCriar, Papel::ClienteLeitura->value);

        $this->expectException(ReaplicacaoLicaoNaoAutorizadaException::class);
        app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obraDestino, $semCriar);
    }

    public function test_action_aceita_usuario_com_criar_na_obra_destino_em_chamada_direta(): void
    {
        $licao = $this->criarLicaoPublicada();
        $comCriar = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraDestino, $comCriar, Papel::Encarregado->value);

        $reaplicacao = app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obraDestino, $comCriar);

        $this->assertNotNull($reaplicacao->id);
        $this->assertSame($comCriar->id, $reaplicacao->created_by_id);
    }

    public function test_action_rejeita_permissao_apenas_na_obra_de_origem(): void
    {
        $licao = $this->criarLicaoPublicada();
        $soNaOrigem = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraOrigem, $soNaOrigem, Papel::Encarregado->value);
        // Nenhum vínculo com $this->obraDestino.

        $this->expectException(ReaplicacaoLicaoNaoAutorizadaException::class);
        app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obraDestino, $soNaOrigem);
    }

    public function test_action_rejeita_permissao_em_obra_diferente_do_destino(): void
    {
        $licao = $this->criarLicaoPublicada();
        $emOutraObra = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraDestino2, $emOutraObra, Papel::Encarregado->value);
        // `criar` só na obraDestino2 — tentativa é na obraDestino (1).

        $this->expectException(ReaplicacaoLicaoNaoAutorizadaException::class);
        app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obraDestino, $emOutraObra);
    }

    public function test_action_rejeita_usuario_de_outro_tenant_mesmo_com_criar_na_propria_obra(): void
    {
        $licao = $this->criarLicaoPublicada();

        $outroTenant = Tenant::factory()->create();
        $usuarioOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $usuario = User::factory()->create(['tenant_id' => $outroTenant->id]);
            $this->vincularObra($obraOutroTenant, $usuario, Papel::Admin->value);

            return $usuario;
        });

        // O usuário de outro tenant nunca tem vínculo (obra_user) com
        // $this->obraDestino (que pertence ao tenant A) — a MESMA
        // checagem de permissão por obra já barra o cross-tenant, sem
        // nenhuma regra especial dedicada a tenant dentro da Policy.
        $this->expectException(ReaplicacaoLicaoNaoAutorizadaException::class);
        app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obraDestino, $usuarioOutroTenant);

        $this->assertSame(0, LicaoAprendidaReaplicacao::count());
    }

    // =========================================================================
    // UNIQUE LIÇÃO×OBRA / DOUBLE-CLICK (Seção 3/19)
    // =========================================================================

    public function test_unique_constraint_bloqueia_segunda_reaplicacao_mesma_licao_mesma_obra(): void
    {
        $licao = $this->criarLicaoPublicada();
        $this->registrar($licao, $this->obraDestino);

        $this->expectException(ReaplicacaoLicaoJaRegistradaException::class);
        $this->registrar($licao, $this->obraDestino);
    }

    public function test_double_click_nao_gera_500_nem_2_linhas(): void
    {
        $licao = $this->criarLicaoPublicada();
        $this->registrar($licao, $this->obraDestino);

        try {
            $this->registrar($licao, $this->obraDestino);
            $this->fail('Deveria ter lançado ReaplicacaoLicaoJaRegistradaException.');
        } catch (ReaplicacaoLicaoJaRegistradaException $e) {
            // esperado, nunca um 500 cru
        }

        $this->assertSame(1, LicaoAprendidaReaplicacao::where('licao_aprendida_id', $licao->id)
            ->where('obra_id', $this->obraDestino->id)->count());
    }

    public function test_mesma_licao_pode_ter_reaplicacao_em_obras_diferentes(): void
    {
        $licao = $this->criarLicaoPublicada();
        $this->registrar($licao, $this->obraDestino);
        $this->registrar($licao, $this->obraDestino2);

        $this->assertSame(2, LicaoAprendidaReaplicacao::where('licao_aprendida_id', $licao->id)->count());
    }

    /**
     * Seção 4 — não contar por atividade: 2 chamadas de registro pra a
     * MESMA lição×obra, mesmo vindo de 2 "contextos" (atividades)
     * diferentes, continuam colidindo no UNIQUE — nunca 2 reaplicações.
     */
    public function test_nao_conta_por_atividade_mesmo_com_contextos_diferentes(): void
    {
        $licao = $this->criarLicaoPublicada();
        $atividade1 = Atividade::factory()->create(['obra_id' => $this->obraDestino->id]);
        $atividade2 = Atividade::factory()->create(['obra_id' => $this->obraDestino->id]);

        $this->registrar($licao, $this->obraDestino, null, [['tipo' => TipoEntidadeVinculoLicao::Atividade, 'id' => $atividade1->id]]);

        $this->expectException(ReaplicacaoLicaoJaRegistradaException::class);
        $this->registrar($licao, $this->obraDestino, null, [['tipo' => TipoEntidadeVinculoLicao::Atividade, 'id' => $atividade2->id]]);
    }

    // =========================================================================
    // IDENTIDADE IMUTÁVEL (Seção 6)
    // =========================================================================

    public function test_update_direto_na_reaplicacao_e_bloqueado(): void
    {
        $licao = $this->criarLicaoPublicada();
        $reaplicacao = $this->registrar($licao, $this->obraDestino);

        $this->expectException(ReaplicacaoLicaoImutavelException::class);
        $reaplicacao->update(['obra_id' => $this->obraDestino2->id]);
    }

    public function test_delete_direto_na_reaplicacao_e_bloqueado(): void
    {
        $licao = $this->criarLicaoPublicada();
        $reaplicacao = $this->registrar($licao, $this->obraDestino);

        $this->expectException(ReaplicacaoLicaoImutavelException::class);
        $reaplicacao->delete();
    }

    public function test_forcedelete_tambem_e_bloqueado(): void
    {
        $licao = $this->criarLicaoPublicada();
        $reaplicacao = $this->registrar($licao, $this->obraDestino);

        $this->expectException(ReaplicacaoLicaoImutavelException::class);
        $reaplicacao->forceDelete();
    }

    // =========================================================================
    // Ciclo 23, Etapa 23.5.B.CORREÇÃO (Seção 6) — IMUTABILIDADE DOS
    // CONTEXTOS. `LicaoAprendidaReaplicacaoContexto` JÁ tinha um Observer
    // dedicado desde a 23.5.B original (`LicaoAprendidaReaplicacaoContextoObserver`,
    // bloqueio incondicional de update/delete) — só nunca tinha sido
    // provado por teste explícito. Fechado aqui.
    // =========================================================================

    public function test_contexto_nao_pode_ser_atualizado(): void
    {
        $licao = $this->criarLicaoPublicada();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obraDestino->id]);
        $reaplicacao = $this->registrar($licao, $this->obraDestino, null, [
            ['tipo' => TipoEntidadeVinculoLicao::Atividade, 'id' => $atividade->id],
        ]);
        $contexto = $reaplicacao->contextos->first();

        $this->expectException(ReaplicacaoLicaoImutavelException::class);
        $contexto->update(['titulo_snapshot' => 'Reescrevendo a história depois do fato.']);
    }

    public function test_contexto_nao_pode_ser_excluido(): void
    {
        $licao = $this->criarLicaoPublicada();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obraDestino->id]);
        $reaplicacao = $this->registrar($licao, $this->obraDestino, null, [
            ['tipo' => TipoEntidadeVinculoLicao::Atividade, 'id' => $atividade->id],
        ]);
        $contexto = $reaplicacao->contextos->first();

        $this->expectException(ReaplicacaoLicaoImutavelException::class);
        $contexto->delete();
    }

    public function test_contexto_forcedelete_tambem_bloqueado(): void
    {
        $licao = $this->criarLicaoPublicada();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obraDestino->id]);
        $reaplicacao = $this->registrar($licao, $this->obraDestino, null, [
            ['tipo' => TipoEntidadeVinculoLicao::Atividade, 'id' => $atividade->id],
        ]);
        $contexto = $reaplicacao->contextos->first();

        $this->expectException(ReaplicacaoLicaoImutavelException::class);
        $contexto->forceDelete();
    }

    // =========================================================================
    // ARQUIVAMENTO POSTERIOR (Seção 9)
    // =========================================================================

    public function test_arquivamento_posterior_preserva_reaplicacao_historica(): void
    {
        $licao = $this->criarLicaoPublicada();
        $reaplicacao = $this->registrar($licao, $this->obraDestino);

        $licao->update(['status' => StatusLicaoAprendida::Arquivada->value, 'arquivado_em' => now()]);

        $this->assertNotNull($reaplicacao->fresh());
        $this->assertSame($this->obraDestino->id, $reaplicacao->fresh()->obra_id);
    }

    public function test_arquivada_bloqueia_nova_reaplicacao_em_outra_obra(): void
    {
        $licao = $this->criarLicaoPublicada();
        $this->registrar($licao, $this->obraDestino);

        $licao->update(['status' => StatusLicaoAprendida::Arquivada->value, 'arquivado_em' => now()]);

        $this->expectException(ReaplicacaoLicaoInvalidaException::class);
        $this->registrar($licao->fresh(), $this->obraDestino2);
    }

    // =========================================================================
    // CONTEXTO OPERACIONAL OPCIONAL (Seção 5)
    // =========================================================================

    public function test_registrar_sem_contexto_funciona_normalmente(): void
    {
        $licao = $this->criarLicaoPublicada();

        $reaplicacao = $this->registrar($licao, $this->obraDestino);

        $this->assertCount(0, $reaplicacao->contextos);
    }

    public function test_registrar_com_contexto_valido_congela_snapshot(): void
    {
        $licao = $this->criarLicaoPublicada();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obraDestino->id, 'nome' => 'Fundação Bloco B']);

        $reaplicacao = $this->registrar($licao, $this->obraDestino, null, [
            ['tipo' => TipoEntidadeVinculoLicao::Atividade, 'id' => $atividade->id],
        ]);

        $this->assertCount(1, $reaplicacao->contextos);
        $this->assertSame(TipoEntidadeVinculoLicao::Atividade, $reaplicacao->contextos->first()->entidade_tipo);
        $this->assertStringContainsString('Fundação Bloco B', $reaplicacao->contextos->first()->titulo_snapshot);
    }

    public function test_contexto_com_entidade_inexistente_e_ignorado_sem_falhar_o_registro(): void
    {
        $licao = $this->criarLicaoPublicada();

        $reaplicacao = $this->registrar($licao, $this->obraDestino, null, [
            ['tipo' => TipoEntidadeVinculoLicao::Atividade, 'id' => (string) \Illuminate\Support\Str::ulid()],
        ]);

        $this->assertNotNull($reaplicacao->id);
        $this->assertCount(0, $reaplicacao->contextos);
    }

    public function test_contextos_duplicados_na_mesma_chamada_nunca_derrubam_o_registro(): void
    {
        $licao = $this->criarLicaoPublicada();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obraDestino->id]);

        $reaplicacao = $this->registrar($licao, $this->obraDestino, null, [
            ['tipo' => TipoEntidadeVinculoLicao::Atividade, 'id' => $atividade->id],
            ['tipo' => TipoEntidadeVinculoLicao::Atividade, 'id' => $atividade->id],
        ]);

        $this->assertNotNull($reaplicacao->id);
        $this->assertCount(1, $reaplicacao->contextos);
    }

    public function test_contexto_de_entidade_de_outro_tenant_e_ignorado(): void
    {
        $licao = $this->criarLicaoPublicada();

        $outroTenant = Tenant::factory()->create();
        $atividadeOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            return Atividade::factory()->create(['obra_id' => $obra->id]);
        });

        $reaplicacao = $this->registrar($licao, $this->obraDestino, null, [
            ['tipo' => TipoEntidadeVinculoLicao::Atividade, 'id' => $atividadeOutroTenant->id],
        ]);

        $this->assertCount(0, $reaplicacao->contextos);
    }

    // =========================================================================
    // OBSERVAÇÃO INICIAL (Seção 6) — parte da identidade, nunca editável.
    // =========================================================================

    public function test_observacao_inicial_e_gravada_e_nunca_editavel(): void
    {
        $licao = $this->criarLicaoPublicada();
        $reaplicacao = $this->registrar($licao, $this->obraDestino, 'Adotamos porque tivemos o mesmo problema.');

        $this->assertSame('Adotamos porque tivemos o mesmo problema.', $reaplicacao->observacao_inicial);

        $this->expectException(ReaplicacaoLicaoImutavelException::class);
        $reaplicacao->update(['observacao_inicial' => 'Tentando editar depois.']);
    }
}
