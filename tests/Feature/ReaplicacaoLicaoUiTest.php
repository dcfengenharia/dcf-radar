<?php

namespace Tests\Feature;

use App\Actions\LicoesAprendidas\CriarLicaoAprendida;
use App\Actions\LicoesAprendidas\EnviarLicaoParaValidacao;
use App\Actions\LicoesAprendidas\PublicarLicaoAprendida;
use App\Actions\LicoesAprendidas\RegistrarReaplicacaoLicao;
use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\ResultadoAvaliacaoReaplicacao;
use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoLicaoAprendida;
use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaReaplicacao;
use App\Models\LinhaBase;
use App\Models\Material;
use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 23, Etapa 23.5.B (Decisão 27) — cobertura de UI dos 3 pontos de
 * integração (Lookahead, Estoque, Biblioteca Corporativa) + performance
 * em lote (Decisão 28). Cobertura de DOMÍNIO já exaustiva em
 * `ReaplicacaoLicaoTest`/`AvaliacaoReaplicacaoLicaoTest` — aqui só a
 * camada Livewire real.
 */
class ReaplicacaoLicaoUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private Work $obraOrigem;
    private User $user;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Atual']);
        $this->obraOrigem = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Origem']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->vincularObra($this->obraOrigem, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
        ObraContext::set($this->obra);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
    }

    private function criarLicaoPublicada(Work $obraOrigem, array $overrides = []): LicaoAprendida
    {
        $licao = app(CriarLicaoAprendida::class)->execute($obraOrigem, $this->user, array_merge([
            'titulo' => 'Lição de teste '.uniqid(),
            'situacao_observada' => 'Situação observada de teste.',
            'recomendacao_futura' => 'Recomendação futura de teste.',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Alta->value,
            'area_funcional' => AreaFuncionalLicao::Suprimentos->value,
        ], $overrides));

        app(EnviarLicaoParaValidacao::class)->execute($licao->fresh());

        return app(PublicarLicaoAprendida::class)->execute($licao->fresh(), $this->user);
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'MAT-'.uniqid(),
            'descricao' => 'Material de teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
    }

    private function darLinhaBaseAtiva(Work $obra): void
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => \App\Enums\TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now(),
        ]);
        LinhaBase::create([
            'obra_id' => $obra->id,
            'nome' => 'Linha de Base de teste',
            'cronograma_importacao_id' => $importacao->id,
            'criado_por' => $this->user->id,
        ]);
    }

    private function vincularSemAcesso(Work $obra, User $user): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Sem Acesso '.uniqid()]);
        $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);
    }

    // =========================================================================
    // A) LOOKAHEAD (popup da Atividade)
    // =========================================================================

    public function test_lookahead_registra_reaplicacao_com_contexto_de_atividade(): void
    {
        $this->darLinhaBaseAtiva($this->obra);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $licao = $this->criarLicaoPublicada($this->obraOrigem);

        $component = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id)
            ->call('registrarReaplicacaoAqui', $licao->id);

        $reaplicacao = LicaoAprendidaReaplicacao::where('licao_aprendida_id', $licao->id)->where('obra_id', $this->obra->id)->first();
        $this->assertNotNull($reaplicacao);
        $this->assertSame(1, $reaplicacao->contextos()->count());
        $this->assertSame(\App\Enums\TipoEntidadeVinculoLicao::Atividade, $reaplicacao->contextos->first()->entidade_tipo);

        $component->assertSet('reaplicacaoErro', null);
    }

    public function test_lookahead_computed_reaplicacoes_reflete_estado_apos_registro(): void
    {
        $this->darLinhaBaseAtiva($this->obra);
        // `reaplicacoesLicoesContextuais()` só checa lições que já são
        // sugestões reais do popup (`modalLicoesContextuais`) — por
        // isso a Atividade e a lição precisam compartilhar a MESMA
        // Disciplina (correspondência determinística já existente da
        // 23.4), senão o lote de checagem sai vazio por construção.
        $disciplina = \App\Models\Disciplina::factory()->create(['tenant_id' => $this->tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'disciplina_id' => $disciplina->id]);
        $licao = $this->criarLicaoPublicada($this->obraOrigem, ['disciplina_id' => $disciplina->id]);

        $component = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id);

        $this->assertSame($licao->id, $component->instance()->modalLicoesContextuais()->sole()->licaoId, 'Pré-condição: a lição precisa aparecer como sugestão.');
        $this->assertNull($component->instance()->reaplicacoesLicoesContextuais()->get($licao->id));

        $component->call('registrarReaplicacaoAqui', $licao->id);
        unset($component->instance()->reaplicacoesLicoesContextuais);

        $this->assertNotNull($component->instance()->reaplicacoesLicoesContextuais()->get($licao->id));
    }

    public function test_lookahead_usuario_sem_criar_e_bloqueado_no_backend(): void
    {
        $this->darLinhaBaseAtiva($this->obra);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $licao = $this->criarLicaoPublicada($this->obraOrigem);

        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularSemAcesso($this->obra, $semAcesso);
        $this->actingAs($semAcesso);

        Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id)
            ->call('registrarReaplicacaoAqui', $licao->id)
            ->assertSet('reaplicacaoErro', fn ($erro) => ! empty($erro));

        $this->assertSame(0, LicaoAprendidaReaplicacao::count());
    }

    public function test_lookahead_avaliar_resultado_via_componente(): void
    {
        $this->darLinhaBaseAtiva($this->obra);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $licao = $this->criarLicaoPublicada($this->obraOrigem);
        $reaplicacao = app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obra, $this->user);

        Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id)
            ->call('abrirAvaliarReaplicacao', $reaplicacao->id)
            ->assertSet('avaliarReaplicacaoId', $reaplicacao->id)
            ->set('avaliarResultado', ResultadoAvaliacaoReaplicacao::Positivo->value)
            ->set('avaliarObservacao', 'Funcionou bem por aqui também.')
            ->call('confirmarAvaliar')
            ->assertSet('avaliarReaplicacaoId', null);

        $this->assertSame(ResultadoAvaliacaoReaplicacao::Positivo, $reaplicacao->fresh()->resultadoAtual());
    }

    public function test_lookahead_usuario_sem_editar_nao_avalia(): void
    {
        $this->darLinhaBaseAtiva($this->obra);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $licao = $this->criarLicaoPublicada($this->obraOrigem);
        $reaplicacao = app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obra, $this->user);

        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);
        $this->actingAs($encarregado);

        Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id)
            ->call('abrirAvaliarReaplicacao', $reaplicacao->id)
            ->set('avaliarResultado', ResultadoAvaliacaoReaplicacao::Positivo->value)
            ->call('confirmarAvaliar');

        $this->assertNull($reaplicacao->fresh()->resultadoAtual());
    }

    // =========================================================================
    // B) ESTOQUE (modal do Material)
    // =========================================================================

    public function test_estoque_registra_reaplicacao_com_contexto_de_material(): void
    {
        $material = $this->criarMaterial();
        $licao = app(CriarLicaoAprendida::class)->execute($this->obraOrigem, $this->user, [
            'titulo' => 'Lição com material',
            'situacao_observada' => 'Situação.',
            'recomendacao_futura' => 'Recomendação.',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Alta->value,
            'area_funcional' => AreaFuncionalLicao::Suprimentos->value,
        ]);
        app(\App\Actions\LicoesAprendidas\VincularEntidadeALicao::class)->execute($licao, \App\Enums\TipoEntidadeVinculoLicao::Material, $material->id, $this->user);
        app(EnviarLicaoParaValidacao::class)->execute($licao->fresh());
        $licao = app(PublicarLicaoAprendida::class)->execute($licao->fresh(), $this->user);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirLicoesContextuaisMaterial', $material->id)
            ->call('registrarReaplicacaoAqui', $licao->id);

        $reaplicacao = LicaoAprendidaReaplicacao::where('licao_aprendida_id', $licao->id)->where('obra_id', $this->obra->id)->first();
        $this->assertNotNull($reaplicacao);
        $this->assertSame(1, $reaplicacao->contextos()->count());
        $this->assertSame(\App\Enums\TipoEntidadeVinculoLicao::Material, $reaplicacao->contextos->first()->entidade_tipo);

        $component->assertSet('reaplicacaoErro', null);
    }

    // =========================================================================
    // C) BIBLIOTECA CORPORATIVA (detalhe da lição)
    // =========================================================================

    private function componenteBiblioteca()
    {
        return Livewire::test('pages::gestao.licoes-aprendidas');
    }

    public function test_biblioteca_registra_reaplicacao_via_modal(): void
    {
        $licao = $this->criarLicaoPublicada($this->obraOrigem);

        $c = $this->componenteBiblioteca()
            ->call('abrirDetalhe', $licao->id)
            ->call('abrirModalReaplicar')
            ->assertSet('modalReaplicarAberto', true)
            ->set('reaplicarObraId', $this->obra->id)
            ->set('reaplicarObservacao', 'Adotamos por semelhança de escopo.')
            ->call('confirmarModalReaplicar')
            ->assertSet('modalReaplicarAberto', false);

        $reaplicacao = LicaoAprendidaReaplicacao::where('licao_aprendida_id', $licao->id)->first();
        $this->assertNotNull($reaplicacao);
        $this->assertSame($this->obra->id, $reaplicacao->obra_id);
        $this->assertSame('Adotamos por semelhança de escopo.', $reaplicacao->observacao_inicial);
        $this->assertCount(0, $reaplicacao->contextos);
    }

    public function test_biblioteca_obras_para_reaplicar_exclui_origem_e_obras_sem_permissao(): void
    {
        $obraSemAcesso = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Sem Acesso']);
        $this->vincularSemAcesso($obraSemAcesso, $this->user);

        $licao = $this->criarLicaoPublicada($this->obraOrigem);

        $c = $this->componenteBiblioteca()->call('abrirDetalhe', $licao->id);
        $obras = $c->instance()->obrasParaReaplicar();

        $this->assertFalse($obras->contains('id', $this->obraOrigem->id), 'Obra de origem nunca aparece.');
        $this->assertFalse($obras->contains('id', $obraSemAcesso->id), 'Obra sem permissão de criar nunca aparece.');
        $this->assertTrue($obras->contains('id', $this->obra->id));
    }

    public function test_biblioteca_usuario_sem_criar_nao_registra(): void
    {
        $licao = $this->criarLicaoPublicada($this->obraOrigem);

        // Precisa continuar enxergando a Biblioteca (`ver`, concedido a
        // todo Papel padrão) — só `criar` precisa faltar na obra
        // destino, senão `mount()` já aborta com 403 antes de chegar
        // perto do fluxo de reaplicação (achado de teste, não de
        // produção: ClienteLeitura tem `ver` mas nunca `criar`).
        $semCriar = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $semCriar, Papel::ClienteLeitura->value);
        $this->vincularObra($this->obraOrigem, $semCriar, Papel::ClienteLeitura->value);
        $this->actingAs($semCriar);

        $this->componenteBiblioteca()
            ->call('abrirDetalhe', $licao->id)
            ->call('abrirModalReaplicar')
            ->set('reaplicarObraId', $this->obra->id)
            ->call('confirmarModalReaplicar');

        $this->assertSame(0, LicaoAprendidaReaplicacao::count());
    }

    public function test_biblioteca_usuario_sem_editar_nao_avalia(): void
    {
        $licao = $this->criarLicaoPublicada($this->obraOrigem);
        $reaplicacao = app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obra, $this->user);

        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);
        $this->vincularObra($this->obraOrigem, $encarregado, Papel::Encarregado->value);
        $this->actingAs($encarregado);

        $this->componenteBiblioteca()
            ->call('abrirDetalhe', $licao->id)
            ->call('abrirAvaliarReaplicacao', $reaplicacao->id)
            ->set('avaliarResultado', ResultadoAvaliacaoReaplicacao::Positivo->value)
            ->call('confirmarAvaliar');

        $this->assertNull($reaplicacao->fresh()->resultadoAtual());
    }

    public function test_biblioteca_historico_reflete_multiplas_reaplicacoes_e_avaliacoes(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::Admin->value);

        $licao = $this->criarLicaoPublicada($this->obraOrigem);
        $r1 = app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obra, $this->user);
        app(RegistrarReaplicacaoLicao::class)->execute($licao, $obraB, $this->user);
        app(\App\Actions\LicoesAprendidas\AvaliarReaplicacaoLicao::class)->execute($r1, ResultadoAvaliacaoReaplicacao::Parcial, $this->user);

        $historico = $this->componenteBiblioteca()
            ->call('abrirDetalhe', $licao->id)
            ->instance()
            ->reaplicacoesDaLicaoAberta();

        $this->assertCount(2, $historico);
        $this->assertSame(1, $historico->firstWhere('id', $r1->id)->avaliacoes->count());
    }

    public function test_biblioteca_licao_arquivada_nunca_mostra_botao_registrar(): void
    {
        $licao = $this->criarLicaoPublicada($this->obraOrigem);
        app(\App\Actions\LicoesAprendidas\ArquivarLicaoAprendida::class)->execute($licao->fresh(), $this->user);

        $c = $this->componenteBiblioteca()->call('abrirDetalhe', $licao->id);

        // A ação de abrir o modal é sempre um no-op quando a lição não está Publicada.
        $c->call('abrirModalReaplicar')->assertSet('modalReaplicarAberto', false);
    }

    public function test_obra_de_origem_e_estruturalmente_protegida_contra_exclusao(): void
    {
        $licao = $this->criarLicaoPublicada($this->obraOrigem);

        $this->expectException(QueryException::class);
        $this->obraOrigem->delete();
    }

    // =========================================================================
    // D) PERFORMANCE EM LOTE (Decisão 28)
    // =========================================================================

    /**
     * Decisão 28 — mede `ReaplicacaoLicaoQuery::porObraELicoes()`
     * ISOLADAMENTE (nunca através do render completo da página, que
     * mistura dezenas de outros computeds/queries alheios a esta
     * feature e tornaria a comparação inútil — mesma lição já registrada
     * em `InteligenciaLicoesQueryTest`/`CockpitObraQueryTest` deste
     * projeto). Compara 2 vs. 50 IDs de lição, com metade delas já tendo
     * reaplicação registrada nesta obra.
     */
    public function test_performance_reaplicacaolicaoquery_porobraelicoes_e_fixo_independente_do_volume(): void
    {
        $licoesPequeno = collect(range(1, 2))->map(fn () => $this->criarLicaoPublicada($this->obraOrigem));
        app(RegistrarReaplicacaoLicao::class)->execute($licoesPequeno->first(), $this->obra, $this->user);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \App\Support\LicoesAprendidas\ReaplicacaoLicaoQuery::porObraELicoes($this->obra, $licoesPequeno->pluck('id'));
        $queriesPequeno = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::flushQueryLog();

        \Illuminate\Support\Facades\DB::disableQueryLog();
        $licoesGrande = collect(range(1, 50))->map(fn () => $this->criarLicaoPublicada($this->obraOrigem));
        foreach ($licoesGrande->take(25) as $licao) {
            app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obra, $this->user);
        }

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \App\Support\LicoesAprendidas\ReaplicacaoLicaoQuery::porObraELicoes($this->obra, $licoesGrande->pluck('id'));
        $queriesGrande = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame(2, $queriesPequeno, 'Sempre 2 queries — a agregação + o eager-load de avaliações.');
        $this->assertSame($queriesPequeno, $queriesGrande, 'Query count nunca deve crescer com o número de lições.');
    }
}
