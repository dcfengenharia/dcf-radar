<?php

namespace Tests\Feature;

use App\Actions\LicoesAprendidas\ArquivarLicaoAprendida;
use App\Actions\LicoesAprendidas\AtualizarLicaoAprendida;
use App\Actions\LicoesAprendidas\CriarLicaoAprendida;
use App\Actions\LicoesAprendidas\DevolverLicaoParaRascunho;
use App\Actions\LicoesAprendidas\EnviarLicaoParaValidacao;
use App\Actions\LicoesAprendidas\PublicarLicaoAprendida;
use App\Actions\LicoesAprendidas\RemoverVinculoDaLicao;
use App\Actions\LicoesAprendidas\VincularEntidadeALicao;
use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Enums\TipoLicaoAprendida;
use App\Exceptions\LicaoAprendidaImutavelException;
use App\Exceptions\LicaoAprendidaIncompletaException;
use App\Exceptions\LicaoAprendidaTransicaoInvalidaException;
use App\Exceptions\VinculoLicaoInvalidoException;
use App\Models\Atividade;
use App\Models\Disciplina;
use App\Models\Fornecedor;
use App\Models\LicaoAprendida;
use App\Models\Material;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 23, Etapa 23.1 — núcleo, governança e biblioteca corporativa de
 * Lições Aprendidas. Cobertura de comportamento (nunca "existe a
 * string X no Blade"): workflow real via Actions, isolamento de tenant
 * comprovado com dado real de outro tenant, permissão enforçada em
 * backend (nunca só a UI escondendo botão).
 */
class LicaoAprendidaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->admin, Papel::Admin->value);
        $this->actingAs($this->admin);
    }

    private function dadosMinimos(array $overrides = []): array
    {
        return array_merge([
            'titulo' => 'Atraso na entrega de aço estrutural',
            'situacao_observada' => 'O fornecedor atrasou 15 dias a entrega do aço.',
            'recomendacao_futura' => 'Antecipar o pedido de aço em pelo menos 45 dias.',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Alta->value,
            'area_funcional' => AreaFuncionalLicao::Suprimentos->value,
        ], $overrides);
    }

    // =========================================================================
    // CRIAÇÃO
    // =========================================================================

    public function test_cria_rascunho_com_campos_minimos(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());

        $this->assertSame(StatusLicaoAprendida::Rascunho, $licao->status);
        $this->assertSame($this->obra->id, $licao->obra_origem_id);
        $this->assertSame($this->admin->id, $licao->created_by_id);
        $this->assertSame($this->tenant->id, $licao->tenant_id);
        $this->assertNull($licao->publicado_em);
    }

    public function test_licao_criada_pertence_ao_tenant_do_usuario_autenticado_mesmo_se_outro_id_for_tentado(): void
    {
        $outroTenant = Tenant::factory()->create();

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());

        // BelongsToTenant carimba o tenant do usuário autenticado sempre —
        // nunca aceita um tenant_id alternativo, nem que a Action tentasse.
        $this->assertSame($this->tenant->id, $licao->tenant_id);
        $this->assertNotSame($outroTenant->id, $licao->tenant_id);
    }

    // =========================================================================
    // WORKFLOW
    // =========================================================================

    public function test_fluxo_completo_rascunho_ate_publicada(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());

        app(EnviarLicaoParaValidacao::class)->execute($licao);
        $licao->refresh();
        $this->assertSame(StatusLicaoAprendida::EmValidacao, $licao->status);
        $this->assertNotNull($licao->enviado_validacao_em);

        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);
        $licao->refresh();
        $this->assertSame(StatusLicaoAprendida::Publicada, $licao->status);
        $this->assertSame($this->admin->id, $licao->publicado_por_id);
        $this->assertNotNull($licao->publicado_em);
    }

    public function test_devolver_para_rascunho(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);

        app(DevolverLicaoParaRascunho::class)->execute($licao);
        $licao->refresh();

        $this->assertSame(StatusLicaoAprendida::Rascunho, $licao->status);
        $this->assertNull($licao->enviado_validacao_em);
    }

    public function test_publicacao_incompleta_e_bloqueada(): void
    {
        $licao = LicaoAprendida::create([
            'obra_origem_id' => $this->obra->id,
            'titulo' => 'Título',
            'situacao_observada' => 'Situação',
            'recomendacao_futura' => '',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Baixa->value,
            'area_funcional' => AreaFuncionalLicao::Campo->value,
            'status' => StatusLicaoAprendida::EmValidacao->value,
            'created_by_id' => $this->admin->id,
        ]);

        $this->expectException(LicaoAprendidaIncompletaException::class);

        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);
    }

    public function test_publicacao_incompleta_lista_exatamente_os_campos_faltantes(): void
    {
        $licao = LicaoAprendida::create([
            'obra_origem_id' => $this->obra->id,
            'titulo' => 'Título',
            'situacao_observada' => 'Situação',
            'recomendacao_futura' => '',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Baixa->value,
            'area_funcional' => AreaFuncionalLicao::Campo->value,
            'status' => StatusLicaoAprendida::EmValidacao->value,
            'created_by_id' => $this->admin->id,
        ]);

        try {
            app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);
            $this->fail('Esperava LicaoAprendidaIncompletaException');
        } catch (LicaoAprendidaIncompletaException $e) {
            $this->assertSame(['recomendacao_futura'], $e->camposFaltantes);
        }
    }

    public function test_boa_pratica_sem_causa_impacto_resultado_pode_ser_publicada(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos([
            'tipo' => TipoLicaoAprendida::BoaPratica->value,
            'causa' => null,
            'impacto' => null,
            'resultado' => null,
        ]));

        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->assertSame(StatusLicaoAprendida::Publicada, $licao->fresh()->status);
    }

    public function test_transicao_invalida_publicar_rascunho_diretamente_bloqueada(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());

        $this->expectException(LicaoAprendidaTransicaoInvalidaException::class);

        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);
    }

    public function test_transicao_invalida_arquivar_rascunho_bloqueada(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());

        $this->expectException(LicaoAprendidaTransicaoInvalidaException::class);

        app(ArquivarLicaoAprendida::class)->execute($licao, $this->admin);
    }

    public function test_arquivamento(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        app(ArquivarLicaoAprendida::class)->execute($licao, $this->admin);
        $licao->refresh();

        $this->assertSame(StatusLicaoAprendida::Arquivada, $licao->status);
        $this->assertSame($this->admin->id, $licao->arquivado_por_id);
        $this->assertNotNull($licao->arquivado_em);
    }

    public function test_licao_publicada_e_imutavel_para_conteudo(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->expectException(LicaoAprendidaImutavelException::class);

        $licao->update(['titulo' => 'Tentando reescrever']);
    }

    public function test_atualizar_action_bloqueia_edicao_fora_do_rascunho(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);

        $this->expectException(LicaoAprendidaTransicaoInvalidaException::class);

        app(AtualizarLicaoAprendida::class)->execute($licao, ['titulo' => 'Novo título']);
    }

    public function test_licao_publicada_nao_pode_ser_excluida(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->expectException(LicaoAprendidaImutavelException::class);

        $licao->delete();
    }

    public function test_licao_arquivada_nao_pode_ser_excluida(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);
        app(ArquivarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->expectException(LicaoAprendidaImutavelException::class);

        $licao->delete();
    }

    public function test_rascunho_pode_ser_excluido_livremente(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());

        $licao->delete();

        $this->assertSoftDeleted('licoes_aprendidas', ['id' => $licao->id]);
    }

    // =========================================================================
    // BIBLIOTECA CORPORATIVA / VISIBILIDADE
    // =========================================================================

    public function test_licao_publicada_de_uma_obra_e_visivel_para_usuario_sem_acesso_aquela_obra_mas_com_acesso_a_outra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $usuarioOutraObra = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $usuarioOutraObra, Papel::GerentePlanejamento->value);

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->actingAs($usuarioOutraObra);

        $this->assertTrue($usuarioOutraObra->can('view', $licao));
    }

    public function test_licao_em_rascunho_de_outra_obra_nao_e_visivel_para_usuario_sem_acesso(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $usuarioOutraObra = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $usuarioOutraObra, Papel::GerentePlanejamento->value);

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());

        $this->actingAs($usuarioOutraObra);

        $this->assertFalse($usuarioOutraObra->can('view', $licao));
    }

    public function test_licao_arquivada_de_outra_obra_nao_e_visivel_para_usuario_sem_acesso(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $usuarioOutraObra = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $usuarioOutraObra, Papel::GerentePlanejamento->value);

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);
        app(ArquivarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->actingAs($usuarioOutraObra);

        $this->assertFalse($usuarioOutraObra->can('view', $licao));
    }

    public function test_query_todas_as_obras_traz_publicada_de_outra_obra_e_nunca_rascunho_de_obra_sem_acesso(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $publicadaOutraObra = app(CriarLicaoAprendida::class)->execute($outraObra, $this->admin, $this->dadosMinimos(['titulo' => 'Publicada de outra obra']));
        app(EnviarLicaoParaValidacao::class)->execute($publicadaOutraObra);
        app(PublicarLicaoAprendida::class)->execute($publicadaOutraObra, $this->admin);

        $rascunhoOutraObra = app(CriarLicaoAprendida::class)->execute($outraObra, $this->admin, $this->dadosMinimos(['titulo' => 'Rascunho de outra obra']));

        $usuarioSoObraA = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $usuarioSoObraA, Papel::GerentePlanejamento->value);
        $this->actingAs($usuarioSoObraA);

        $this->assertTrue($usuarioSoObraA->can('view', $publicadaOutraObra));
        $this->assertFalse($usuarioSoObraA->can('view', $rascunhoOutraObra));
    }

    public function test_arquivada_nao_aparece_na_consulta_padrao_de_esta_obra(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);
        app(ArquivarLicaoAprendida::class)->execute($licao, $this->admin);

        $padrao = LicaoAprendida::where('obra_origem_id', $this->obra->id)
            ->where('status', '!=', StatusLicaoAprendida::Arquivada->value)
            ->get();

        $this->assertCount(0, $padrao);
        $this->assertSame(1, LicaoAprendida::where('obra_origem_id', $this->obra->id)->count());
    }

    // =========================================================================
    // PERMISSÕES
    // =========================================================================

    public function test_ver_e_aberto_a_qualquer_perfil_vinculado_a_obra(): void
    {
        $clienteLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $clienteLeitura, Papel::ClienteLeitura->value);

        $this->assertTrue($clienteLeitura->temPermissaoNaObra($this->obra->id, 'gestao.licoes-aprendidas', 'ver'));
    }

    public function test_criar_exige_permissao_minima(): void
    {
        $clienteLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $clienteLeitura, Papel::ClienteLeitura->value);

        $this->assertFalse($clienteLeitura->can('create', [LicaoAprendida::class, $this->obra]));

        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);

        $this->assertTrue($encarregado->can('create', [LicaoAprendida::class, $this->obra]));
    }

    public function test_editar_exige_permissao_minima_de_engenheiro(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());

        $this->assertFalse($encarregado->can('update', $licao));

        $engenheiro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $engenheiro, Papel::Engenheiro->value);

        $this->assertTrue($engenheiro->can('update', $licao));
    }

    public function test_publicar_exige_permissao_de_gerente_planejamento(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);

        $engenheiro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $engenheiro, Papel::Engenheiro->value);

        $this->assertFalse($engenheiro->can('publicar', $licao));

        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $gerente, Papel::GerentePlanejamento->value);

        $this->assertTrue($gerente->can('publicar', $licao));
    }

    public function test_arquivar_exige_permissao_de_gerente_planejamento(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $engenheiro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $engenheiro, Papel::Engenheiro->value);

        $this->assertFalse($engenheiro->can('arquivar', $licao));
    }

    // =========================================================================
    // TENANT ISOLATION
    // =========================================================================

    public function test_leitura_cross_tenant_bloqueada(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroAdmin = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $outroAdmin, Papel::Admin->value);

        $licaoDoOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outraObra, $outroAdmin) {
            return app(CriarLicaoAprendida::class)->execute($outraObra, $outroAdmin, $this->dadosMinimos());
        });

        // Usuário do tenant A tentando resolver por ID direto — o global
        // scope de BelongsToTenant já garante que a query nem encontra.
        $encontrada = LicaoAprendida::find($licaoDoOutroTenant->id);

        $this->assertNull($encontrada, 'Uma lição de outro tenant nunca deve ser encontrável via find() no tenant atual.');
    }

    public function test_edicao_cross_tenant_bloqueada(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroAdmin = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $outroAdmin, Papel::Admin->value);

        $licaoDoOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outraObra, $outroAdmin) {
            return app(CriarLicaoAprendida::class)->execute($outraObra, $outroAdmin, $this->dadosMinimos());
        });

        // Mesmo com o objeto em mãos (bypassando o find() do tenant
        // atual), a Policy nunca autoriza porque o usuário nunca tem
        // vínculo (perfil) na obra de outro tenant.
        $this->assertFalse($this->admin->can('update', $licaoDoOutroTenant));
    }

    public function test_publicacao_cross_tenant_bloqueada(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroAdmin = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $outroAdmin, Papel::Admin->value);

        $licaoDoOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outraObra, $outroAdmin) {
            $licao = app(CriarLicaoAprendida::class)->execute($outraObra, $outroAdmin, $this->dadosMinimos());
            app(EnviarLicaoParaValidacao::class)->execute($licao);

            return $licao;
        });

        $this->assertFalse($this->admin->can('publicar', $licaoDoOutroTenant));
    }

    public function test_vinculo_cross_tenant_bloqueado(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        $atividadeDeOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outraObra) {
            return Atividade::factory()->create(['tenant_id' => $outraObra->tenant_id, 'obra_id' => $outraObra->id]);
        });

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());

        $this->expectException(VinculoLicaoInvalidoException::class);

        app(VincularEntidadeALicao::class)->execute($licao, TipoEntidadeVinculoLicao::Atividade, $atividadeDeOutroTenant->id, $this->admin);
    }

    public function test_filtro_por_obra_no_modo_todas_as_obras_nunca_traz_dado_de_outro_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroAdmin = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObraOutroTenant, $outroAdmin, Papel::Admin->value);

        TenantContext::actingAs($outroTenant, function () use ($outraObraOutroTenant, $outroAdmin) {
            $licao = app(CriarLicaoAprendida::class)->execute($outraObraOutroTenant, $outroAdmin, $this->dadosMinimos());
            app(EnviarLicaoParaValidacao::class)->execute($licao);
            app(PublicarLicaoAprendida::class)->execute($licao, $outroAdmin);
        });

        $todasDoTenantAtual = LicaoAprendida::where('status', StatusLicaoAprendida::Publicada->value)->get();

        $this->assertCount(0, $todasDoTenantAtual, 'O global scope de tenant nunca deve deixar uma lição de outro tenant aparecer na listagem.');
    }

    // =========================================================================
    // HISTÓRICO / GOVERNANÇA
    // =========================================================================

    public function test_autor_preservado_mesmo_apos_usuario_ser_removido(): void
    {
        $autor = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $autor, Papel::Encarregado->value);

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $autor, $this->dadosMinimos());

        $autor->forceDelete();
        $licao->refresh();

        $this->assertNull($licao->created_by_id, 'FK nullOnDelete — id vira null, mas a lição em si sobrevive.');
        $this->assertNotNull(LicaoAprendida::find($licao->id));
    }

    public function test_publicado_por_e_publicado_em_sao_registrados(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);

        $antes = now();
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);
        $licao->refresh();

        $this->assertSame($this->admin->id, $licao->publicado_por_id);
        $this->assertTrue($licao->publicado_em->greaterThanOrEqualTo($antes->subSecond()));
    }

    // =========================================================================
    // VÍNCULOS CONTEXTUAIS
    // =========================================================================

    public function test_vincular_atividade_congela_snapshot(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Fundação Bloco B',
            'codigo_cronograma' => '5.2',
        ]);

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());

        $vinculo = app(VincularEntidadeALicao::class)->execute($licao, TipoEntidadeVinculoLicao::Atividade, $atividade->id, $this->admin);

        $this->assertSame('5.2 - Fundação Bloco B', $vinculo->titulo_snapshot);
        $this->assertSame(TipoEntidadeVinculoLicao::Atividade, $vinculo->entidade_tipo);
        $this->assertSame($atividade->id, $vinculo->entidade_id);
    }

    public function test_vinculo_sobrevive_a_mudanca_posterior_da_entidade(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Nome Original',
            'codigo_cronograma' => '1.1',
        ]);

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        $vinculo = app(VincularEntidadeALicao::class)->execute($licao, TipoEntidadeVinculoLicao::Atividade, $atividade->id, $this->admin);

        $atividade->update(['nome' => 'Nome Alterado Depois']);

        $this->assertSame('1.1 - Nome Original', $vinculo->fresh()->titulo_snapshot, 'O snapshot nunca é recalculado — preserva o que era verdade na época do vínculo.');
    }

    public function test_vincular_restricao_via_atividade(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $restricao = Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $atividade->id, 'descricao' => 'Aço não entregue no prazo']);

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        $vinculo = app(VincularEntidadeALicao::class)->execute($licao, TipoEntidadeVinculoLicao::Restricao, $restricao->id, $this->admin);

        $this->assertStringContainsString('Aço não entregue', $vinculo->titulo_snapshot);
    }

    public function test_vincular_material_tenant_wide_sem_obra(): void
    {
        $unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
        $material = Material::create([
            'tenant_id' => $this->tenant->id,
            'codigo' => 'MAT-001',
            'descricao' => 'Parafuso Sextavado',
            'unidade_medida_id' => $unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
        ]);

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        $vinculo = app(VincularEntidadeALicao::class)->execute($licao, TipoEntidadeVinculoLicao::Material, $material->id, $this->admin);

        $this->assertSame('MAT-001 - Parafuso Sextavado', $vinculo->titulo_snapshot);
    }

    public function test_vincular_fornecedor(): void
    {
        $fornecedor = Fornecedor::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Aços do Brasil Ltda',
        ]);

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        $vinculo = app(VincularEntidadeALicao::class)->execute($licao, TipoEntidadeVinculoLicao::Fornecedor, $fornecedor->id, $this->admin);

        $this->assertSame('Aços do Brasil Ltda', $vinculo->titulo_snapshot);
    }

    public function test_vinculo_com_entidade_inexistente_e_bloqueado(): void
    {
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());

        $this->expectException(VinculoLicaoInvalidoException::class);

        app(VincularEntidadeALicao::class)->execute($licao, TipoEntidadeVinculoLicao::Atividade, (string) \Illuminate\Support\Str::ulid(), $this->admin);
    }

    public function test_vinculo_bloqueado_apos_publicacao(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->expectException(LicaoAprendidaImutavelException::class);

        app(VincularEntidadeALicao::class)->execute($licao, TipoEntidadeVinculoLicao::Atividade, $atividade->id, $this->admin);
    }

    public function test_remover_vinculo(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        $vinculo = app(VincularEntidadeALicao::class)->execute($licao, TipoEntidadeVinculoLicao::Atividade, $atividade->id, $this->admin);

        app(RemoverVinculoDaLicao::class)->execute($vinculo);

        $this->assertDatabaseMissing('licao_aprendida_vinculos', ['id' => $vinculo->id]);
    }

    public function test_remover_vinculo_bloqueado_apos_publicacao(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos());
        $vinculo = app(VincularEntidadeALicao::class)->execute($licao, TipoEntidadeVinculoLicao::Atividade, $atividade->id, $this->admin);
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->expectException(LicaoAprendidaImutavelException::class);

        app(RemoverVinculoDaLicao::class)->execute($vinculo);
    }

    public function test_disciplina_reutilizada_nunca_texto_livre(): void
    {
        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Estrutural']);

        $licao = app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, $this->dadosMinimos(['disciplina_id' => $disciplina->id]));

        $this->assertSame($disciplina->id, $licao->disciplina_id);
        $this->assertSame('Estrutural', $licao->disciplina->nome);
    }
}
