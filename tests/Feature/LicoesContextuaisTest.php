<?php

namespace Tests\Feature;

use App\Actions\LicoesAprendidas\ArquivarLicaoAprendida;
use App\Actions\LicoesAprendidas\CriarLicaoAprendida;
use App\Actions\LicoesAprendidas\EnviarLicaoParaValidacao;
use App\Actions\LicoesAprendidas\PublicarLicaoAprendida;
use App\Actions\LicoesAprendidas\VincularEntidadeALicao;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\DTOs\LicoesAprendidas\SugestaoLicaoContextual;
use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\MotivoCorrespondenciaLicao;
use App\Enums\Papel;
use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoCronogramaImportacao;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Enums\TipoLicaoAprendida;
use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\LicaoAprendida;
use App\Models\LinhaBase;
use App\Models\ListaEngenharia;
use App\Models\Material;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\LicoesAprendidas\LicoesContextuaisQuery;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 23, Etapa 23.4 — Reutilização Contextual da Memória
 * Organizacional: `LicoesContextuaisQuery` (2 regras determinísticas,
 * MesmoMaterial/MesmaDisciplina) + os 2 pontos de UI aprovados
 * (Material em ⚡estoque.blade.php, Atividade/Lookahead em
 * ⚡lookahead.blade.php).
 */
class LicoesContextuaisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private Work $obraOrigem;
    private UnidadeMedida $unidade;

    private CriarRequisicaoPlanejamento $criarRp;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizarRp;
    private EmitirRequisicaoPlanejamento $emitirRp;
    private AlocarRequisicaoAoPacote $alocar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obraOrigem = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->vincularObra($this->obraOrigem, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);

        $this->criarRp = new CriarRequisicaoPlanejamento();
        $this->atualizarRp = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitirRp = new EmitirRequisicaoPlanejamento();
        $this->alocar = new AlocarRequisicaoAoPacote();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-'.uniqid(),
            'descricao' => 'Tubo ASTM A106 Gr.B 6"',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ], $overrides));
    }

    private function criarPacoteSimples(Work $obra): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => $obra->id, 'nome' => 'Pacote '.uniqid()]);
    }

    private function criarItemTakeOffComMaterial(Material $material, Work $obra): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'D'.uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM'.uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id,
            'codigo' => 'A'.uniqid(),
            'descricao' => 'Item',
            'quantidade' => 1000,
            'material_id' => $material->id,
        ]);
    }

    /** Vincula uma Atividade a um Material via a MESMA cadeia determinística já em produção (Pacote/RP/Alocação). */
    private function vincularAtividadeAoMaterial(Atividade $atividade, Material $material, Work $obra): void
    {
        $item = $this->criarItemTakeOffComMaterial($material, $obra);
        $rp = $this->criarRp->execute($obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, 100);
        $this->emitirRp->execute($rp->fresh(), $this->user);
        $pacote = $this->criarPacoteSimples($obra);
        $this->alocar->alocar($rpItem->fresh(), $pacote, 100);

        $atividade->itensSuprimento()->attach($pacote->id);
    }

    /**
     * Sobe uma lição até Publicada, com os Materiais informados VINCULADOS
     * ANTES da publicação — `VincularEntidadeALicao` bloqueia adicionar
     * vínculo numa lição já `estaImutavel()` (Publicada/Arquivada, Ciclo
     * 23.1), então o vínculo tem que acontecer enquanto ainda é
     * Rascunho/EmValidação.
     *
     * @param  array<int, Material>  $materiaisParaVincular
     */
    private function criarLicaoPublicada(Work $obraOrigem, array $overrides = [], array $materiaisParaVincular = []): LicaoAprendida
    {
        $licao = app(CriarLicaoAprendida::class)->execute($obraOrigem, $this->user, array_merge([
            'titulo' => 'Lição de teste '.uniqid(),
            'situacao_observada' => 'Situação observada de teste.',
            'recomendacao_futura' => 'Recomendação futura de teste.',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Alta->value,
            'area_funcional' => AreaFuncionalLicao::Suprimentos->value,
        ], $overrides));

        foreach ($materiaisParaVincular as $material) {
            $this->vincularMaterial($licao, $material);
        }

        app(EnviarLicaoParaValidacao::class)->execute($licao->fresh());

        return app(PublicarLicaoAprendida::class)->execute($licao->fresh(), $this->user);
    }

    private function vincularMaterial(LicaoAprendida $licao, Material $material): void
    {
        app(VincularEntidadeALicao::class)->execute($licao, TipoEntidadeVinculoLicao::Material, $material->id, $this->user);
    }

    /**
     * Único jeito real de construir "sem acesso a `gestao.licoes-
     * aprendidas|ver`" neste domínio: `temPermissaoEmAlgumaObraDoTenant()`
     * retorna `true` de graça pra usuário com ZERO vínculo com qualquer
     * obra (fallback "ausência de dado nunca bloqueia", achado ao ler
     * `HasObraPapel::temPermissaoEmAlgumaObraDoTenant()`), e `ver` em
     * `gestao.licoes-aprendidas` é concedido a TODO Papel padrão
     * (`Perfil::REGRAS_ESCRITA` nunca declara `'ver'` pra este slug) —
     * então só um Perfil CUSTOM, sem nenhuma linha em `PerfilPermissao`,
     * vinculado de verdade à obra, nega `ver` de fato.
     */
    private function vincularSemAcessoALicoes(Work $obra, User $user): void
    {
        $perfil = \App\Models\Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Sem Acesso a Lições '.uniqid()]);
        $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);
    }

    // =========================================================================
    // A) MesmoMaterial
    // =========================================================================

    public function test_a1_licao_publicada_de_outra_obra_com_mesmo_material_aparece(): void
    {
        $material = $this->criarMaterial();
        $licao = $this->criarLicaoPublicada($this->obraOrigem, [], [$material]);

        $sugestoes = LicoesContextuaisQuery::porMaterial($this->obra, $material);

        $sugestao = $sugestoes->sole();
        $this->assertSame($licao->id, $sugestao->licaoId);
        $this->assertSame(MotivoCorrespondenciaLicao::MesmoMaterial, $sugestao->motivos[0]['motivo']);
        $this->assertStringContainsString($material->codigo, (string) $sugestao->motivos[0]['contexto']);
        $this->assertSame($this->obraOrigem->name, $sugestao->obraOrigemNome);
    }

    public function test_a2_material_diferente_nunca_aparece(): void
    {
        $materialA = $this->criarMaterial();
        $materialB = $this->criarMaterial();
        $this->criarLicaoPublicada($this->obraOrigem, [], [$materialA]);

        $sugestoes = LicoesContextuaisQuery::porMaterial($this->obra, $materialB);

        $this->assertTrue($sugestoes->isEmpty());
    }

    public function test_a3_licao_da_propria_obra_atual_nunca_aparece(): void
    {
        $material = $this->criarMaterial();
        $this->criarLicaoPublicada($this->obra, [], [$material]); // nasceu na MESMA obra que está consultando

        $sugestoes = LicoesContextuaisQuery::porMaterial($this->obra, $material);

        $this->assertTrue($sugestoes->isEmpty());
    }

    public function test_a4_licao_de_outro_tenant_nunca_vaza(): void
    {
        $material = $this->criarMaterial();

        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        TenantContext::actingAs($outroTenant, function () use ($outroTenant, $outroUser) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $this->vincularObra($outraObra, $outroUser, Papel::Admin->value);

            $licao = app(CriarLicaoAprendida::class)->execute($outraObra, $outroUser, [
                'titulo' => 'Lição de outro tenant',
                'situacao_observada' => 'x',
                'recomendacao_futura' => 'x',
                'tipo' => TipoLicaoAprendida::Problema->value,
                'criticidade' => CriticidadeLicao::Alta->value,
                'area_funcional' => AreaFuncionalLicao::Suprimentos->value,
            ]);
            app(EnviarLicaoParaValidacao::class)->execute($licao);
            app(PublicarLicaoAprendida::class)->execute($licao->fresh(), $outroUser);
        });

        // A consulta roda autenticada como $this->user (tenant atual) — uma
        // lição Publicada de outro tenant nunca deveria aparecer, mesmo
        // existindo e mesmo sendo Publicada de verdade.
        $sugestoes = LicoesContextuaisQuery::porMaterial($this->obra, $material);

        $this->assertTrue($sugestoes->isEmpty());
        $this->assertSame(1, LicaoAprendida::withoutGlobalScope(TenantScope::class)->where('titulo', 'Lição de outro tenant')->count());
    }

    public function test_a5_status_nao_publicado_nunca_aparece(): void
    {
        $material = $this->criarMaterial();

        // Rascunho
        $rascunho = app(CriarLicaoAprendida::class)->execute($this->obraOrigem, $this->user, [
            'titulo' => 'Rascunho', 'situacao_observada' => 'x', 'recomendacao_futura' => 'x',
            'tipo' => TipoLicaoAprendida::Problema->value, 'criticidade' => CriticidadeLicao::Alta->value,
            'area_funcional' => AreaFuncionalLicao::Suprimentos->value,
        ]);
        $this->vincularMaterial($rascunho, $material);

        // Em validação
        $emValidacao = app(CriarLicaoAprendida::class)->execute($this->obraOrigem, $this->user, [
            'titulo' => 'Em validação', 'situacao_observada' => 'x', 'recomendacao_futura' => 'x',
            'tipo' => TipoLicaoAprendida::Problema->value, 'criticidade' => CriticidadeLicao::Alta->value,
            'area_funcional' => AreaFuncionalLicao::Suprimentos->value,
        ]);
        app(EnviarLicaoParaValidacao::class)->execute($emValidacao);
        $this->vincularMaterial($emValidacao->fresh(), $material);

        // Arquivada — vínculo criado ANTES da publicação, arquivada DEPOIS.
        $arquivada = $this->criarLicaoPublicada($this->obraOrigem, [], [$material]);
        app(ArquivarLicaoAprendida::class)->execute($arquivada->fresh(), $this->user);

        $sugestoes = LicoesContextuaisQuery::porMaterial($this->obra, $material);

        $this->assertTrue($sugestoes->isEmpty());
        $this->assertSame(StatusLicaoAprendida::Arquivada, $arquivada->fresh()->status);
    }

    public function test_a6_porMateriais_em_lote_bate_com_porMaterial_individual(): void
    {
        $materialA = $this->criarMaterial();
        $materialB = $this->criarMaterial();
        $licaoA = $this->criarLicaoPublicada($this->obraOrigem, [], [$materialA]);
        $licaoB = $this->criarLicaoPublicada($this->obraOrigem, [], [$materialB]);

        $lote = LicoesContextuaisQuery::porMateriais($this->obra, collect([$materialA->id, $materialB->id]));

        $this->assertSame($licaoA->id, $lote->get($materialA->id)->sole()->licaoId);
        $this->assertSame($licaoB->id, $lote->get($materialB->id)->sole()->licaoId);
    }

    // =========================================================================
    // B) MesmaDisciplina (via Atividade)
    // =========================================================================

    public function test_b1_mesma_disciplina_aparece_via_atividade(): void
    {
        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'disciplina_id' => $disciplina->id,
        ]);
        $licao = $this->criarLicaoPublicada($this->obraOrigem, ['disciplina_id' => $disciplina->id]);

        $sugestoes = LicoesContextuaisQuery::porAtividade($this->obra, $atividade);

        $sugestao = $sugestoes->sole();
        $this->assertSame($licao->id, $sugestao->licaoId);
        $this->assertSame(MotivoCorrespondenciaLicao::MesmaDisciplina, $sugestao->motivos[0]['motivo']);
        $this->assertSame('Elétrica', $sugestao->motivos[0]['contexto']);
    }

    public function test_b2_disciplina_diferente_nunca_aparece(): void
    {
        $disciplinaA = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);
        $disciplinaB = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Civil']);
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'disciplina_id' => $disciplinaA->id,
        ]);
        $this->criarLicaoPublicada($this->obraOrigem, ['disciplina_id' => $disciplinaB->id]);

        $sugestoes = LicoesContextuaisQuery::porAtividade($this->obra, $atividade);

        $this->assertTrue($sugestoes->isEmpty());
    }

    public function test_b3_atividade_sem_disciplina_nunca_compara_null_com_null(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'disciplina_id' => null,
        ]);
        // Lição TAMBÉM sem disciplina — não pode "bater" por coincidência de null.
        $this->criarLicaoPublicada($this->obraOrigem, ['disciplina_id' => null]);

        $sugestoes = LicoesContextuaisQuery::porAtividade($this->obra, $atividade);

        $this->assertTrue($sugestoes->isEmpty());
    }

    public function test_b4_disciplina_da_propria_obra_atual_nunca_aparece(): void
    {
        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'disciplina_id' => $disciplina->id,
        ]);
        $this->criarLicaoPublicada($this->obra, ['disciplina_id' => $disciplina->id]);

        $sugestoes = LicoesContextuaisQuery::porAtividade($this->obra, $atividade);

        $this->assertTrue($sugestoes->isEmpty());
    }

    public function test_b5_disciplina_com_status_nao_publicado_nunca_aparece(): void
    {
        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'disciplina_id' => $disciplina->id,
        ]);
        app(CriarLicaoAprendida::class)->execute($this->obraOrigem, $this->user, [
            'titulo' => 'x', 'situacao_observada' => 'x', 'recomendacao_futura' => 'x',
            'tipo' => TipoLicaoAprendida::Problema->value, 'criticidade' => CriticidadeLicao::Alta->value,
            'area_funcional' => AreaFuncionalLicao::Suprimentos->value, 'disciplina_id' => $disciplina->id,
        ]);

        $sugestoes = LicoesContextuaisQuery::porAtividade($this->obra, $atividade);

        $this->assertTrue($sugestoes->isEmpty());
    }

    // =========================================================================
    // C) Combinação Material + Disciplina, dedup e ordenação
    // =========================================================================

    public function test_c1_licao_que_bate_por_material_e_disciplina_aparece_uma_unica_vez_com_2_motivos(): void
    {
        $material = $this->criarMaterial();
        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'disciplina_id' => $disciplina->id,
        ]);
        $this->vincularAtividadeAoMaterial($atividade, $material, $this->obra);

        $this->criarLicaoPublicada($this->obraOrigem, ['disciplina_id' => $disciplina->id], [$material]);

        $sugestoes = LicoesContextuaisQuery::porAtividade($this->obra, $atividade);

        $this->assertCount(1, $sugestoes);
        $motivos = collect($sugestoes->first()->motivos)->pluck('motivo');
        $this->assertTrue($motivos->contains(MotivoCorrespondenciaLicao::MesmoMaterial));
        $this->assertTrue($motivos->contains(MotivoCorrespondenciaLicao::MesmaDisciplina));
    }

    public function test_c2_especificidade_material_vem_antes_de_disciplina(): void
    {
        $material = $this->criarMaterial();
        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'disciplina_id' => $disciplina->id,
        ]);
        $this->vincularAtividadeAoMaterial($atividade, $material, $this->obra);

        $licaoDisciplina = $this->criarLicaoPublicada($this->obraOrigem, ['disciplina_id' => $disciplina->id, 'criticidade' => CriticidadeLicao::Critica->value]);
        $licaoMaterial = $this->criarLicaoPublicada($this->obraOrigem, ['criticidade' => CriticidadeLicao::Baixa->value], [$material]);

        $sugestoes = LicoesContextuaisQuery::porAtividade($this->obra, $atividade)->values();

        // Material (mais específico) vem primeiro mesmo com criticidade menor.
        $this->assertSame($licaoMaterial->id, $sugestoes[0]->licaoId);
        $this->assertSame($licaoDisciplina->id, $sugestoes[1]->licaoId);
    }

    public function test_c3_desempate_por_criticidade_quando_especificidade_empata(): void
    {
        $material = $this->criarMaterial();
        $licaoBaixa = $this->criarLicaoPublicada($this->obraOrigem, ['criticidade' => CriticidadeLicao::Baixa->value], [$material]);
        $licaoCritica = $this->criarLicaoPublicada($this->obraOrigem, ['criticidade' => CriticidadeLicao::Critica->value], [$material]);

        $sugestoes = LicoesContextuaisQuery::porMaterial($this->obra, $material)->values();

        $this->assertSame($licaoCritica->id, $sugestoes[0]->licaoId);
        $this->assertSame($licaoBaixa->id, $sugestoes[1]->licaoId);
    }

    // =========================================================================
    // D) Batch (porAtividades) e performance
    // =========================================================================

    public function test_d1_atividades_diferentes_nunca_misturam_sugestoes(): void
    {
        $materialA = $this->criarMaterial();
        $materialB = $this->criarMaterial();
        $atividadeA = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $this->vincularAtividadeAoMaterial($atividadeA, $materialA, $this->obra);
        $this->vincularAtividadeAoMaterial($atividadeB, $materialB, $this->obra);

        $licaoA = $this->criarLicaoPublicada($this->obraOrigem, [], [$materialA]);
        $licaoB = $this->criarLicaoPublicada($this->obraOrigem, [], [$materialB]);

        $lote = LicoesContextuaisQuery::porAtividades($this->obra, collect([$atividadeA, $atividadeB]));

        $this->assertSame($licaoA->id, $lote->get($atividadeA->id)->sole()->licaoId);
        $this->assertSame($licaoB->id, $lote->get($atividadeB->id)->sole()->licaoId);
    }

    public function test_d2_atividade_sem_material_e_sem_disciplina_retorna_vazio_sem_erro(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'disciplina_id' => null,
        ]);

        $sugestoes = LicoesContextuaisQuery::porAtividades($this->obra, collect([$atividade]));

        $this->assertTrue($sugestoes->get($atividade->id)->isEmpty());
    }

    public function test_d3_performance_10_vs_100_atividades_sem_crescimento_n_mais_1(): void
    {
        $material = $this->criarMaterial();
        $this->criarLicaoPublicada($this->obraOrigem, [], [$material]);

        $medir = function (int $n) use ($material) {
            $atividades = collect();
            for ($i = 0; $i < $n; $i++) {
                $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
                $this->vincularAtividadeAoMaterial($atividade, $material, $this->obra);
                $atividades->push($atividade->fresh());
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            LicoesContextuaisQuery::porAtividades($this->obra, $atividades);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $queries10 = $medir(10);
        $queries100 = $medir(100);

        $this->assertSame($queries10, $queries100, "Esperado custo fixo: 10 atividades -> {$queries10} queries | 100 atividades -> {$queries100} queries");
    }

    // =========================================================================
    // E) Estrutura do DTO — nunca expõe observacoes_internas
    // =========================================================================

    public function test_e1_dto_nunca_carrega_observacoes_internas(): void
    {
        $reflection = new \ReflectionClass(SugestaoLicaoContextual::class);
        $nomes = collect($reflection->getConstructor()->getParameters())->pluck('name');

        $this->assertFalse($nomes->contains('observacoesInternas'));
        $this->assertFalse($nomes->contains('observacoes_internas'));
    }

    // =========================================================================
    // F) UI — Material (⚡estoque.blade.php)
    // =========================================================================

    public function test_f1_usuario_com_permissao_ve_badge_e_abre_modal_com_sugestoes(): void
    {
        $material = $this->criarMaterial();
        $licao = $this->criarLicaoPublicada($this->obraOrigem, [], [$material]);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra]);
        $sugestoes = $component->instance()->licoesContextuaisPorMaterial()->get($material->id, collect());
        $this->assertCount(1, $sugestoes);
        $this->assertSame($licao->id, $sugestoes->first()->licaoId);

        $component->call('abrirLicoesContextuaisMaterial', $material->id)
            ->assertSet('licoesContextuaisMaterialId', $material->id);

        $aberta = $component->instance()->licoesContextuaisMaterialAberta();
        $this->assertSame($licao->id, $aberta->sole()->licaoId);
    }

    public function test_f2_material_sem_sugestoes_retorna_colecao_vazia(): void
    {
        $material = $this->criarMaterial();

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra]);

        $this->assertTrue($component->instance()->licoesContextuaisPorMaterial()->get($material->id, collect())->isEmpty());
    }

    public function test_f3_usuario_sem_permissao_de_ver_biblioteca_nunca_ve_sugestoes(): void
    {
        $material = $this->criarMaterial();
        $this->criarLicaoPublicada($this->obraOrigem, [], [$material]);

        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularSemAcessoALicoes($this->obra, $semAcesso);
        $this->actingAs($semAcesso);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra]);

        $this->assertTrue($component->instance()->licoesContextuaisPorMaterial()->isEmpty());
    }

    // =========================================================================
    // G) UI — Atividade / Lookahead (⚡lookahead.blade.php)
    // =========================================================================

    private function darLinhaBaseAtiva(Work $obra): void
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now(),
        ]);
        LinhaBase::create([
            'obra_id' => $obra->id,
            'nome' => 'Linha de Base de teste',
            'cronograma_importacao_id' => $importacao->id,
            'criado_por' => $this->user->id,
        ]);
    }

    public function test_g1_popup_da_atividade_mostra_licoes_contextuais_correspondentes(): void
    {
        $this->darLinhaBaseAtiva($this->obra);
        $material = $this->criarMaterial();
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $this->vincularAtividadeAoMaterial($atividade, $material, $this->obra);
        $licao = $this->criarLicaoPublicada($this->obraOrigem, [], [$material]);

        $component = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id);

        $sugestoes = $component->instance()->modalLicoesContextuais();
        $this->assertSame($licao->id, $sugestoes->sole()->licaoId);
    }

    public function test_g2_atividade_sem_correspondencia_nunca_mostra_sugestao(): void
    {
        $this->darLinhaBaseAtiva($this->obra);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $component = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id);

        $this->assertTrue($component->instance()->modalLicoesContextuais()->isEmpty());
    }

    public function test_g3_usuario_sem_permissao_de_ver_biblioteca_nunca_ve_sugestao_no_popup(): void
    {
        $this->darLinhaBaseAtiva($this->obra);
        $material = $this->criarMaterial();
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $this->vincularAtividadeAoMaterial($atividade, $material, $this->obra);
        $this->criarLicaoPublicada($this->obraOrigem, [], [$material]);

        // Mesma prova de F3, aplicada ao segundo ponto de UI: um Perfil
        // CUSTOM sem nenhuma linha em PerfilPermissao é o único jeito real
        // de negar `gestao.licoes-aprendidas|ver` neste domínio.
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularSemAcessoALicoes($this->obra, $semAcesso);
        $this->actingAs($semAcesso);

        $component = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividade->id);

        $sugestoes = $component->instance()->modalLicoesContextuais();
        $this->assertTrue($sugestoes->isEmpty());
    }

    public function test_g4_trocar_de_atividade_invalida_o_computed_anterior(): void
    {
        $this->darLinhaBaseAtiva($this->obra);
        $materialA = $this->criarMaterial();
        $materialB = $this->criarMaterial();
        $atividadeA = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $this->vincularAtividadeAoMaterial($atividadeA, $materialA, $this->obra);
        $this->vincularAtividadeAoMaterial($atividadeB, $materialB, $this->obra);
        $licaoA = $this->criarLicaoPublicada($this->obraOrigem, [], [$materialA]);

        $component = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $atividadeA->id);
        $this->assertSame($licaoA->id, $component->instance()->modalLicoesContextuais()->sole()->licaoId);

        $component->call('verAtividade', $atividadeB->id);
        $this->assertTrue($component->instance()->modalLicoesContextuais()->isEmpty());
    }
}
