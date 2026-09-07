<?php

namespace Tests\Feature;

use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\Papel;
use App\Enums\StatusCandidatoLicaoAprendida;
use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoCandidatoLicaoAprendida;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Enums\TipoLicaoAprendida;
use App\Models\CandidatoLicaoAprendida;
use App\Models\Disciplina;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaVinculo;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\LicoesAprendidas\InteligenciaLicoesQuery;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ciclo 23, Etapa 23.5.A — cobertura de `InteligenciaLicoesQuery`
 * (Decisão 13). Só o read-model é exercitado diretamente (nunca via
 * Livewire) — a UI/drill-down tem cobertura própria em
 * `InteligenciaLicoesPageTest`.
 */
class InteligenciaLicoesQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obraA;
    private Work $obraB;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obraA = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra A']);
        $this->obraB = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra B']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraA, $this->user, Papel::Admin->value);
        $this->vincularObra($this->obraB, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
    }

    private function criarLicao(Work $obra, array $overrides = []): LicaoAprendida
    {
        return LicaoAprendida::create(array_merge([
            'obra_origem_id' => $obra->id,
            'disciplina_id' => null,
            'titulo' => 'Lição '.Str::random(8),
            'situacao_observada' => 'Situação observada.',
            'recomendacao_futura' => 'Recomendação futura.',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Media->value,
            'area_funcional' => AreaFuncionalLicao::Campo->value,
            'status' => StatusLicaoAprendida::Rascunho->value,
        ], $overrides));
    }

    private function criarLicaoPublicada(Work $obra, array $overrides = []): LicaoAprendida
    {
        return $this->criarLicao($obra, array_merge([
            'status' => StatusLicaoAprendida::Publicada->value,
            'publicado_em' => now(),
        ], $overrides));
    }

    private function vincularMaterial(LicaoAprendida $licao, Material $material, bool $eOrigem = false): LicaoAprendidaVinculo
    {
        return LicaoAprendidaVinculo::create([
            'licao_aprendida_id' => $licao->id,
            'entidade_tipo' => TipoEntidadeVinculoLicao::Material->value,
            'entidade_id' => $material->id,
            'titulo_snapshot' => $material->codigo.' - '.$material->descricao,
            'e_origem' => $eOrigem,
        ]);
    }

    private function vincularOrigem(LicaoAprendida $licao, TipoEntidadeVinculoLicao $tipo = TipoEntidadeVinculoLicao::Restricao): LicaoAprendidaVinculo
    {
        return LicaoAprendidaVinculo::create([
            'licao_aprendida_id' => $licao->id,
            'entidade_tipo' => $tipo->value,
            'entidade_id' => (string) Str::ulid(),
            'titulo_snapshot' => 'Origem snapshot',
            'e_origem' => true,
        ]);
    }

    private function vincularComplementar(LicaoAprendida $licao, TipoEntidadeVinculoLicao $tipo = TipoEntidadeVinculoLicao::Atividade): LicaoAprendidaVinculo
    {
        return LicaoAprendidaVinculo::create([
            'licao_aprendida_id' => $licao->id,
            'entidade_tipo' => $tipo->value,
            'entidade_id' => (string) Str::ulid(),
            'titulo_snapshot' => 'Complementar snapshot',
            'e_origem' => false,
        ]);
    }

    private function criarCandidatoConvertido(Work $obra, LicaoAprendida $licao): CandidatoLicaoAprendida
    {
        return CandidatoLicaoAprendida::create([
            'obra_id' => $obra->id,
            'tipo' => TipoCandidatoLicaoAprendida::RestricaoRelevante->value,
            'chave_logica' => Str::random(20),
            'status' => StatusCandidatoLicaoAprendida::Convertido->value,
            'entidade_tipo' => TipoEntidadeVinculoLicao::Restricao->value,
            'entidade_id' => (string) Str::ulid(),
            'titulo' => 'Candidato',
            'descricao' => 'Descrição do candidato.',
            'dados_snapshot' => [],
            'gerado_em' => now(),
            'convertido_em' => now(),
            'licao_aprendida_id' => $licao->id,
        ]);
    }

    private ?\App\Models\UnidadeMedida $unidadeMedida = null;

    private function unidadeMedida(): \App\Models\UnidadeMedida
    {
        return $this->unidadeMedida ??= \App\Models\UnidadeMedida::create([
            'codigo' => 'UN',
            'nome' => 'Unidade',
            'ativo' => true,
        ]);
    }

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-'.Str::random(6),
            'descricao' => 'Material de teste',
            'unidade_medida_id' => $this->unidadeMedida()->id,
            'modo_rastreabilidade' => \App\Enums\ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ], $overrides));
    }

    // =========================================================================
    // A) SOMENTE PUBLICADAS ENTRAM
    // =========================================================================

    public function test_a_somente_publicadas_entram_no_resumo(): void
    {
        $this->criarLicao($this->obraA, ['status' => StatusLicaoAprendida::Rascunho->value]);
        $this->criarLicao($this->obraA, ['status' => StatusLicaoAprendida::EmValidacao->value]);
        $this->criarLicao($this->obraA, ['status' => StatusLicaoAprendida::Arquivada->value]);
        $publicada = $this->criarLicaoPublicada($this->obraA);

        $resumo = InteligenciaLicoesQuery::resumo();

        $this->assertSame(1, $resumo->totalLicoesPublicadas);
        $this->assertSame(1, $resumo->totalObrasComLicaoPublicada);
        $this->assertSame($publicada->id, LicaoAprendida::where('status', 'publicada')->first()->id);
    }

    public function test_a2_arquivada_nunca_conta_mesmo_tendo_sido_publicada_antes(): void
    {
        $licao = $this->criarLicaoPublicada($this->obraA);
        $licao->update(['status' => StatusLicaoAprendida::Arquivada->value, 'arquivado_em' => now()]);

        $resumo = InteligenciaLicoesQuery::resumo();

        $this->assertSame(0, $resumo->totalLicoesPublicadas);
    }

    // =========================================================================
    // B) OUTRO TENANT NUNCA ENTRA
    // =========================================================================

    public function test_b_outro_tenant_nunca_entra(): void
    {
        $this->criarLicaoPublicada($this->obraA);

        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $this->criarLicaoPublicada($outraObra);
            $this->criarLicaoPublicada($outraObra);
        });

        $resumo = InteligenciaLicoesQuery::resumo();

        $this->assertSame(1, $resumo->totalLicoesPublicadas, 'Lições do outro tenant nunca podem contar aqui.');
    }

    // =========================================================================
    // C) PRÓPRIA E OUTRAS OBRAS DO MESMO TENANT AGREGAM CORRETAMENTE
    // D) TOTAL DE OBRAS DISTINTAS
    // =========================================================================

    public function test_c_d_obras_do_mesmo_tenant_agregam_e_contam_distintas(): void
    {
        $this->criarLicaoPublicada($this->obraA);
        $this->criarLicaoPublicada($this->obraA);
        $this->criarLicaoPublicada($this->obraB);

        $resumo = InteligenciaLicoesQuery::resumo();

        $this->assertSame(3, $resumo->totalLicoesPublicadas);
        $this->assertSame(2, $resumo->totalObrasComLicaoPublicada);
    }

    // =========================================================================
    // E) ÁREA
    // =========================================================================

    public function test_e_distribuicao_por_area(): void
    {
        $this->criarLicaoPublicada($this->obraA, ['area_funcional' => AreaFuncionalLicao::Suprimentos->value]);
        $this->criarLicaoPublicada($this->obraA, ['area_funcional' => AreaFuncionalLicao::Suprimentos->value]);
        $this->criarLicaoPublicada($this->obraA, ['area_funcional' => AreaFuncionalLicao::Engenharia->value]);

        $distribuicao = InteligenciaLicoesQuery::distribuicaoPorArea()->keyBy('chave');

        $this->assertSame(2, $distribuicao->get('suprimentos')->quantidade);
        $this->assertSame(1, $distribuicao->get('engenharia')->quantidade);
        $this->assertSame('Suprimentos', $distribuicao->get('suprimentos')->rotulo);
    }

    // =========================================================================
    // F) DISCIPLINA
    // =========================================================================

    public function test_f_distribuicao_por_disciplina_incluindo_sem_disciplina(): void
    {
        $disciplina = Disciplina::factory()->create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);

        $this->criarLicaoPublicada($this->obraA, ['disciplina_id' => $disciplina->id]);
        $this->criarLicaoPublicada($this->obraA, ['disciplina_id' => $disciplina->id]);
        $this->criarLicaoPublicada($this->obraA, ['disciplina_id' => null]);

        $distribuicao = InteligenciaLicoesQuery::distribuicaoPorDisciplina();

        $comDisciplina = $distribuicao->firstWhere('chave', $disciplina->id);
        $semDisciplina = $distribuicao->firstWhere('chave', '');

        $this->assertSame(2, $comDisciplina->quantidade);
        $this->assertSame('Elétrica', $comDisciplina->rotulo);
        $this->assertSame(1, $semDisciplina->quantidade);
        $this->assertSame('Sem disciplina informada', $semDisciplina->rotulo);
    }

    // =========================================================================
    // G) TIPO
    // =========================================================================

    public function test_g_distribuicao_por_tipo(): void
    {
        $this->criarLicaoPublicada($this->obraA, ['tipo' => TipoLicaoAprendida::Problema->value]);
        $this->criarLicaoPublicada($this->obraA, ['tipo' => TipoLicaoAprendida::BoaPratica->value]);
        $this->criarLicaoPublicada($this->obraA, ['tipo' => TipoLicaoAprendida::BoaPratica->value]);

        $distribuicao = InteligenciaLicoesQuery::distribuicaoPorTipo()->keyBy('chave');

        $this->assertSame(1, $distribuicao->get('problema')->quantidade);
        $this->assertSame(2, $distribuicao->get('boa_pratica')->quantidade);
    }

    // =========================================================================
    // H) CRITICIDADE
    // =========================================================================

    public function test_h_distribuicao_por_criticidade(): void
    {
        $this->criarLicaoPublicada($this->obraA, ['criticidade' => CriticidadeLicao::Critica->value]);
        $this->criarLicaoPublicada($this->obraA, ['criticidade' => CriticidadeLicao::Baixa->value]);
        $this->criarLicaoPublicada($this->obraA, ['criticidade' => CriticidadeLicao::Baixa->value]);

        $distribuicao = InteligenciaLicoesQuery::distribuicaoPorCriticidade();

        $this->assertSame('critica', $distribuicao->first()->chave, 'Ordenada por peso — Crítica primeiro.');
        $this->assertSame(1, $distribuicao->firstWhere('chave', 'critica')->quantidade);
        $this->assertSame(2, $distribuicao->firstWhere('chave', 'baixa')->quantidade);
    }

    // =========================================================================
    // I) BOAS PRÁTICAS
    // =========================================================================

    public function test_i_total_boas_praticas_publicadas(): void
    {
        $this->criarLicaoPublicada($this->obraA, ['tipo' => TipoLicaoAprendida::BoaPratica->value]);
        $this->criarLicaoPublicada($this->obraA, ['tipo' => TipoLicaoAprendida::BoaPratica->value]);
        $this->criarLicaoPublicada($this->obraA, ['tipo' => TipoLicaoAprendida::Problema->value]);
        // Boa prática em rascunho não conta.
        $this->criarLicao($this->obraA, ['tipo' => TipoLicaoAprendida::BoaPratica->value]);

        $resumo = InteligenciaLicoesQuery::resumo();

        $this->assertSame(2, $resumo->totalBoasPraticasPublicadas);
    }

    // =========================================================================
    // J) EVOLUÇÃO TEMPORAL
    // =========================================================================

    public function test_j_evolucao_temporal_agrupa_por_mes_de_publicacao(): void
    {
        $this->criarLicaoPublicada($this->obraA, ['publicado_em' => '2026-06-15 10:00:00']);
        $this->criarLicaoPublicada($this->obraA, ['publicado_em' => '2026-06-20 10:00:00']);
        $this->criarLicaoPublicada($this->obraA, ['publicado_em' => '2026-07-01 10:00:00']);

        $evolucao = InteligenciaLicoesQuery::evolucaoTemporal()->keyBy('periodo');

        $this->assertSame(2, $evolucao->get('2026-06')->quantidade);
        $this->assertSame(1, $evolucao->get('2026-07')->quantidade);
        $this->assertSame(['2026-06', '2026-07'], $evolucao->keys()->all(), 'Sempre ordenado cronologicamente.');
    }

    // =========================================================================
    // K) MATERIAL EM 1 OBRA NÃO ENTRA / L) MATERIAL EM 2 OBRAS ENTRA
    // M) CONTAGEM N LIÇÕES / M OBRAS CORRETA
    // =========================================================================

    public function test_k_material_presente_em_1_obra_nunca_entra_na_presenca_cross_obra(): void
    {
        $material = $this->criarMaterial();
        $licao1 = $this->criarLicaoPublicada($this->obraA);
        $licao2 = $this->criarLicaoPublicada($this->obraA);
        $this->vincularMaterial($licao1, $material);
        $this->vincularMaterial($licao2, $material);

        $materiais = InteligenciaLicoesQuery::materiaisCrossObra();

        $this->assertTrue($materiais->firstWhere('materialId', $material->id) === null, 'Mesma obra 2x nunca é presença cross-obra.');
    }

    public function test_l_m_material_em_2_obras_entra_com_contagem_correta(): void
    {
        $material = $this->criarMaterial(['codigo' => 'AÇO-CA50', 'descricao' => 'Aço CA-50 10mm']);

        $licaoA1 = $this->criarLicaoPublicada($this->obraA, ['tipo' => TipoLicaoAprendida::BoaPratica->value]);
        $licaoA2 = $this->criarLicaoPublicada($this->obraA);
        $licaoB1 = $this->criarLicaoPublicada($this->obraB);

        $this->vincularMaterial($licaoA1, $material, eOrigem: true);
        $this->vincularMaterial($licaoA2, $material);
        $this->vincularMaterial($licaoB1, $material);

        // Lição sem vínculo ao material nunca entra na contagem.
        $this->criarLicaoPublicada($this->obraA);

        $materiais = InteligenciaLicoesQuery::materiaisCrossObra();
        $item = $materiais->firstWhere('materialId', $material->id);

        $this->assertNotNull($item);
        $this->assertSame(3, $item->quantidadeLicoes, 'N lições.');
        $this->assertSame(2, $item->quantidadeObras, 'M obras distintas.');
        $this->assertSame(1, $item->quantidadeBoasPraticas);
        $this->assertStringContainsString('AÇO-CA50', $item->titulo);
    }

    // =========================================================================
    // N) MESMA LIÇÃO NUNCA DUPLICA POR MÚLTIPLOS VÍNCULOS
    // =========================================================================

    public function test_n_mesma_licao_nunca_duplica_por_ter_outros_vinculos_alem_do_material(): void
    {
        $material = $this->criarMaterial();
        $licaoA = $this->criarLicaoPublicada($this->obraA);
        $licaoB = $this->criarLicaoPublicada($this->obraB);

        $this->vincularMaterial($licaoA, $material, eOrigem: true);
        // Vínculo complementar de OUTRO tipo na mesma lição — nunca deve
        // inflar a contagem do material.
        $this->vincularComplementar($licaoA, TipoEntidadeVinculoLicao::Atividade);
        $this->vincularMaterial($licaoB, $material);

        $materiais = InteligenciaLicoesQuery::materiaisCrossObra();
        $item = $materiais->firstWhere('materialId', $material->id);

        $this->assertSame(2, $item->quantidadeLicoes);
        $this->assertSame(2, $item->quantidadeObras);
    }

    // =========================================================================
    // O) PROVENIÊNCIA MANUAL / CONTEXTUAL / CANDIDATO
    // =========================================================================

    public function test_o_proveniencia_manual_contextual_candidato_sao_mutuamente_exclusivas(): void
    {
        $manual = $this->criarLicaoPublicada($this->obraA);

        $contextual = $this->criarLicaoPublicada($this->obraA);
        $this->vincularOrigem($contextual);

        $viaCandidato = $this->criarLicaoPublicada($this->obraA);
        $this->vincularOrigem($viaCandidato);
        $this->criarCandidatoConvertido($this->obraA, $viaCandidato);

        $proveniencia = InteligenciaLicoesQuery::proveniencia()->keyBy('chave');

        $this->assertSame(1, $proveniencia->get('manual')->quantidade);
        $this->assertSame(1, $proveniencia->get('captura_contextual')->quantidade);
        $this->assertSame(1, $proveniencia->get('candidato_convertido')->quantidade);

        $soma = $proveniencia->sum('quantidade');
        $this->assertSame(3, $soma, 'A soma das 3 categorias precisa bater com o total de publicadas — mutuamente exclusivas.');
        $this->assertSame(InteligenciaLicoesQuery::totalPublicadas(), $soma);
    }

    public function test_o2_candidato_convertido_tem_sempre_vinculo_e_origem_mas_nunca_e_contado_como_contextual(): void
    {
        // Confirma explicitamente a ordem de checagem exigida pela
        // Decisão 15: candidato PRIMEIRO, nunca em paralelo com e_origem.
        $licao = $this->criarLicaoPublicada($this->obraA);
        $this->vincularOrigem($licao);
        $this->assertTrue(LicaoAprendidaVinculo::where('licao_aprendida_id', $licao->id)->where('e_origem', true)->exists());

        $this->criarCandidatoConvertido($this->obraA, $licao);

        $proveniencia = InteligenciaLicoesQuery::proveniencia()->keyBy('chave');

        $this->assertSame(1, $proveniencia->get('candidato_convertido')->quantidade);
        $this->assertSame(0, $proveniencia->get('captura_contextual')->quantidade);
    }

    // =========================================================================
    // PRIVACIDADE — nunca observacoes_internas em nenhum DTO
    // =========================================================================

    public function test_privacidade_nenhum_dto_carrega_observacoes_internas(): void
    {
        $licao = $this->criarLicaoPublicada($this->obraA, ['observacoes_internas' => 'Segredo interno jamais exposto.']);
        $material = $this->criarMaterial();
        $this->vincularMaterial($licao, $material);
        $outraLicao = $this->criarLicaoPublicada($this->obraB);
        $this->vincularMaterial($outraLicao, $material);

        $resumo = InteligenciaLicoesQuery::resumo();

        $serializado = json_encode([
            $resumo->distribuicaoPorArea,
            $resumo->distribuicaoPorDisciplina,
            $resumo->distribuicaoPorTipo,
            $resumo->distribuicaoPorCriticidade,
            $resumo->evolucaoTemporal,
            $resumo->materiaisCrossObra,
            $resumo->proveniencia,
        ]);

        $this->assertStringNotContainsString('Segredo interno', (string) $serializado);
    }

    // =========================================================================
    // PERFORMANCE — Decisão 12 (query count fixo, não O(N))
    // =========================================================================

    private function inserirLicoesEmMassa(Work $obra, int $quantidade): void
    {
        $agora = now();
        $tipos = TipoLicaoAprendida::cases();
        $areas = AreaFuncionalLicao::cases();
        $criticidades = CriticidadeLicao::cases();
        $linhas = [];

        for ($i = 0; $i < $quantidade; $i++) {
            $linhas[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $obra->tenant_id,
                'obra_origem_id' => $obra->id,
                'disciplina_id' => null,
                'titulo' => "Lição em massa {$i}",
                'situacao_observada' => 'Situação.',
                'causa' => null,
                'impacto' => null,
                'acao_adotada' => null,
                'resultado' => null,
                'recomendacao_futura' => 'Recomendação.',
                'tipo' => $tipos[$i % count($tipos)]->value,
                'criticidade' => $criticidades[$i % count($criticidades)]->value,
                'area_funcional' => $areas[$i % count($areas)]->value,
                'status' => StatusLicaoAprendida::Publicada->value,
                'data_ocorrencia' => null,
                'data_ocorrencia_fim' => null,
                'observacoes_internas' => null,
                'created_by_id' => null,
                'enviado_validacao_em' => null,
                'publicado_por_id' => null,
                'publicado_em' => $agora->copy()->subDays($i % 90),
                'arquivado_por_id' => null,
                'arquivado_em' => null,
                'created_at' => $agora,
                'updated_at' => $agora,
                'deleted_at' => null,
            ];

            if (count($linhas) >= 500) {
                DB::table('licoes_aprendidas')->insert($linhas);
                $linhas = [];
            }
        }

        if ($linhas !== []) {
            DB::table('licoes_aprendidas')->insert($linhas);
        }
    }

    private function contarQueriesDoResumo(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        InteligenciaLicoesQuery::resumo();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }

    public function test_performance_query_count_fixo_100_vs_1000_vs_10000_licoes(): void
    {
        $this->inserirLicoesEmMassa($this->obraA, 100);
        $queries100 = $this->contarQueriesDoResumo();

        $this->inserirLicoesEmMassa($this->obraA, 900); // total 1.000
        $queries1000 = $this->contarQueriesDoResumo();

        $this->inserirLicoesEmMassa($this->obraA, 9000); // total 10.000
        $queries10000 = $this->contarQueriesDoResumo();

        $this->assertSame(10000, LicaoAprendida::count());
        $this->assertSame($queries100, $queries1000, 'Query count nunca deve crescer com N.');
        $this->assertSame($queries100, $queries10000, 'Query count nunca deve crescer com N — 10.000 lições.');

        fwrite(STDERR, "\n[23.5.A performance] resumo() query count — N=100: {$queries100} | N=1.000: {$queries1000} | N=10.000: {$queries10000}\n");
    }

    public function test_performance_materiais_cross_obra_query_count_fixo_com_muitos_vinculos(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $material = $this->criarMaterial();
            $licaoA = $this->criarLicaoPublicada($this->obraA);
            $licaoB = $this->criarLicaoPublicada($this->obraB);
            $this->vincularMaterial($licaoA, $material);
            $this->vincularMaterial($licaoB, $material);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $materiais = InteligenciaLicoesQuery::materiaisCrossObra();
        $totalQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(30, $materiais);
        $this->assertSame(2, $totalQueries, 'Sempre 2 queries — a agregação + 1 lote de Material::whereIn, nunca 1 por material.');
    }
}
