<?php

namespace Tests\Feature;

use App\Enums\EstadoAplicabilidadeCategoria;
use App\Enums\HealthCheckCategoria;
use App\Enums\StatusPlanoAcao;
use App\Enums\TipoCronogramaImportacao;
use App\Models\CronogramaImportacao;
use App\Models\CronogramaImportacaoHealthCheck;
use App\Models\PlanoAcao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 3, Etapa 5 — página de detalhe de uma CronogramaImportacao
 * (⚡importacao-detalhe.blade.php). Nunca recalcula Health Check nem Score —
 * só lê o que já está persistido (ver CLAUDE.md).
 */
class ImportacaoDetalheTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
    }

    private function arquivoFixture(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(__DIR__ . '/../Fixtures/' . $name));
    }

    /**
     * Ciclo 7 — $fixture opcional (default cronograma_sample.xml, mantido
     * por compatibilidade com os chamadores que não precisam de findings
     * específicos). Só os 2 testes que precisam de mapa_acoes/findings
     * não-vazios sob Baseline passam cronograma_fase2b3_slack.xml
     * explicitamente — nenhum outro teste deste arquivo é afetado.
     */
    private function importarComScore(string $fixture = 'cronograma_sample.xml'): CronogramaImportacao
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture($fixture))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        return CronogramaImportacao::first();
    }

    // =====================================================================
    // DETALHE
    // =====================================================================

    public function test_detalhe_mostra_score_faixa_cobertura_e_potencial_recuperavel(): void
    {
        $importacao = $this->importarComScore();
        $score = $importacao->healthCheck->scoreResultado();

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertSee((string) $score->score)
            ->assertSee($score->faixa->label())
            ->assertSee($score->cobertura . '%')
            ->assertSee((string) $score->potencialRecuperavel);
    }

    public function test_detalhe_mostra_cabecalho_com_identificacao_completa_da_importacao(): void
    {
        $importacao = $this->importarComScore();

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertSee($importacao->arquivo)
            ->assertSee($this->obra->name)
            ->assertSee((string) $importacao->criadas)
            ->assertSee((string) $importacao->atualizadas)
            ->assertSee((string) $importacao->removidas);
    }

    public function test_detalhe_mostra_dimensoes_dinamicamente_para_todas_as_categorias_com_ocorrencia(): void
    {
        $importacao = $this->importarComScore();
        $score = $importacao->healthCheck->scoreResultado();

        $component = Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao]);

        // Toda categoria que tem finding nesta importação precisa aparecer —
        // sem depender de uma lista fixa no Blade (ver teste de dinamismo).
        foreach ($score->porDimensao as $dimensao) {
            if ($dimensao->quantidadeOcorrencias > 0) {
                $component->assertSee($dimensao->categoria->label());
            }
        }
    }

    public function test_detalhe_mostra_mapa_de_acoes_com_recomendacao_exata_da_regra(): void
    {
        $importacao = $this->importarComScore('cronograma_fase2b3_slack.xml');
        $score = $importacao->healthCheck->scoreResultado();

        $this->assertNotEmpty($score->mapaAcoes, 'cronograma_fase2b3_slack.xml precisa disparar ao menos 1 ação penalizadora pra este teste fazer sentido');

        $component = Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao]);

        foreach ($score->mapaAcoes as $acao) {
            $component->assertSee($acao->regraId)->assertSee($acao->recomendacao);
        }
    }

    public function test_detalhe_mostra_findings_completos_via_partial_existente(): void
    {
        // Ciclo 7 — health-check-findings.blade.php nunca imprime regra_id
        // como texto (só severidade/titulo/descricao/impacto/recomendacao/
        // atividades) — assertSee($regraId) só "passava" antes por
        // coincidência de nomenclatura da fixture antiga. `titulo` é o
        // campo que o partial garante renderizar pra TODO finding,
        // independente de severidade ou de ter atividades associadas.
        $importacao = $this->importarComScore('cronograma_fase2b3_slack.xml');
        $titulos = array_unique(array_column($importacao->healthCheck->findings, 'titulo'));

        $this->assertNotEmpty($titulos);

        $component = Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao]);

        foreach ($titulos as $titulo) {
            $component->assertSee($titulo);
        }
    }

    public function test_detalhe_mostra_totais_por_severidade_persistidos_sem_recalcular(): void
    {
        $importacao = $this->importarComScore();
        $hc = $importacao->healthCheck;

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertSee((string) $hc->total_criticos)
            ->assertSee((string) $hc->total_altos)
            ->assertSee((string) $hc->total_medios)
            ->assertSee((string) $hc->total_baixos)
            ->assertSee((string) $hc->total_informativos);
    }

    // =====================================================================
    // COMPATIBILIDADE
    // =====================================================================

    public function test_importacao_sem_health_check_mostra_estado_amigavel_sem_excecao(): void
    {
        $importacao = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertOk()
            ->assertSee('Esta importação não possui uma análise de saúde registrada.');
    }

    public function test_importacao_com_health_check_mas_score_null_mostra_indisponivel_sem_excecao(): void
    {
        $importacao = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);

        CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $importacao->id]
            + CronogramaImportacaoHealthCheck::camposParaPersistir(['findings' => []])
        );

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertOk()
            ->assertSee('Score não disponível')
            ->assertDontSee('Score por Dimensão')
            ->assertDontSee('Mapa de Ações');
    }

    public function test_mapa_de_acoes_vazio_mostra_mensagem_amigavel_nao_lista_vazia_quebrada(): void
    {
        // cronograma_health_check_sem_alertas.xml não dispara nenhum finding
        // penalizador -> Score 100, mapaAcoes = [].
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_health_check_sem_alertas.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $importacao = CronogramaImportacao::first();
        $this->assertSame([], $importacao->healthCheck->mapa_acoes);

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertOk()
            ->assertSee('Nenhum problema com impacto negativo no Score foi identificado nesta análise.');
    }

    // =====================================================================
    // DINAMISMO
    // =====================================================================

    public function test_score_por_dimensao_persistido_cobre_todas_as_categorias_do_enum_atual(): void
    {
        // Confirma que o snapshot persistido (e, por extensão, o que o Blade
        // itera via HealthCheckCategoria::cases()) nunca depende de uma
        // lista fixa — toda categoria que existir no enum no momento do
        // cálculo aparece automaticamente no score_por_dimensao.
        $importacao = $this->importarComScore();

        $categoriasPersistidas = array_keys($importacao->healthCheck->score_por_dimensao);
        $categoriasDoEnum = array_map(fn ($c) => $c->value, HealthCheckCategoria::cases());

        $this->assertEqualsCanonicalizing($categoriasDoEnum, $categoriasPersistidas);
    }

    // =====================================================================
    // APLICABILIDADE POR CATEGORIA (Ciclo 10 — "Transparência Baseline")
    // =====================================================================

    public function test_categoria_avaliada_com_zero_findings_mantem_score_100_sem_badge_de_aplicabilidade(): void
    {
        $importacao = $this->importarComScore('cronograma_fase2b3_slack.xml');
        $score = $importacao->healthCheck->scoreResultado();

        // Duração é pura Planejamento (100% aplicável sob Baseline) e não
        // dispara nenhum finding nesta fixture — "Score 100 · 0 ocorrências"
        // precisa continuar aparecendo exatamente como antes do Ciclo 10.
        $duracao = $score->porDimensao[HealthCheckCategoria::Duracao->value];
        $this->assertSame(100, $duracao->score);
        $this->assertSame(0, $duracao->quantidadeOcorrencias);

        $component = Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao]);
        $aplicabilidade = $component->instance()->aplicabilidadePorCategoria[HealthCheckCategoria::Duracao->value];

        $this->assertSame(EstadoAplicabilidadeCategoria::Avaliada, $aplicabilidade->estado);
    }

    public function test_categorias_nao_totalmente_avaliadas_mostram_estado_explicito(): void
    {
        $importacao = $this->importarComScore('cronograma_fase2b3_slack.xml');

        $component = Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao]);
        $aplicabilidade = $component->instance()->aplicabilidadePorCategoria;

        $naoTotalmenteAvaliadas = array_filter(
            $aplicabilidade,
            fn (\App\Support\HealthCheck\AplicabilidadeCategoria $a) => $a->estado !== EstadoAplicabilidadeCategoria::Avaliada
        );

        // Sob Baseline, esta fixture tem exatamente 5 categorias não
        // totalmente avaliadas: Avanço Físico (não avaliada, 100% Execução)
        // + Datas/HH/Marcos/Lógica (parcialmente avaliadas, categorias
        // mistas) — ver tests/Unit/AplicabilidadeCategoriaTest.php.
        $this->assertCount(5, $naoTotalmenteAvaliadas);

        // Categoria não avaliada nunca pode parecer "Score 100 · limpa" sem
        // indicação — precisa do rótulo e da explicação amigável.
        $component->assertSee('Não avaliada nesta importação')
            ->assertSee('As regras desta dimensão dependem de dados de execução');

        // Ao menos uma categoria mista precisa mostrar a parcialidade de forma explícita.
        $component->assertSee('Parcialmente avaliada');

        foreach ($naoTotalmenteAvaliadas as $categoriaValue => $aplic) {
            $component->assertSee("{$aplic->regrasAplicaveis}/{$aplic->totalRegras} regras");
        }
    }

    public function test_categorias_ficam_avaliadas_em_importacao_de_avanco(): void
    {
        Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_fase2b3_slack.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $importacao = CronogramaImportacao::where('tipo', TipoCronogramaImportacao::Avanco)->latest('id')->first();

        $component = Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao]);
        $aplicabilidade = $component->instance()->aplicabilidadePorCategoria;

        foreach (HealthCheckCategoria::cases() as $categoria) {
            $this->assertSame(EstadoAplicabilidadeCategoria::Avaliada, $aplicabilidade[$categoria->value]->estado, $categoria->value);
        }

        // Achado ao rodar: assertDontSee('Não avaliada nesta importação') não
        // é uma checagem válida aqui — score-explicacao.blade.php (texto
        // didático ESTÁTICO, sempre incluído) contém essa mesma frase entre
        // aspas na sua própria explicação, independente do tipo da
        // importação. A checagem correta é a de valor acima (nenhuma
        // categoria com estado != Avaliada) — a badge condicional em si
        // (`@if ($aplicabilidade->estado->value !== 'avaliada')`) nunca
        // renderiza pra nenhuma categoria quando todas estão Avaliada, o que
        // a asserção de valor já garante sem ambiguidade.
    }

    // =====================================================================
    // PLANO DE AÇÃO — ações de execução aguardando Avanço (Ciclo 10)
    // =====================================================================

    public function test_banner_de_acoes_de_execucao_aparece_em_baseline_quando_ha_acoes_abertas(): void
    {
        $importacao = $this->importarComScore();

        PlanoAcao::create([
            'obra_id' => $this->obra->id,
            'cronograma_importacao_origem_id' => $importacao->id,
            'regra_id' => 'PROG-001', // Execução
            'titulo' => 'Atividade atrasada',
            'recomendacao' => 'teste',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => ['1'],
        ]);

        $component = Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao]);

        $this->assertSame(1, $component->instance()->acoesExecucaoAguardandoAvanco);
        // Achado ao rodar: a frase completa quebra linha (indentação do
        // Blade) entre "aberta(s)" e "aguardando" no HTML renderizado —
        // assertSee não normaliza espaço em branco/quebra de linha, então a
        // checagem precisa ficar dentro dos limites de cada linha fonte.
        $component->assertSee('ação(ões) de execução aberta(s)')
            ->assertSee('aguardando uma importação de Avanço para nova avaliação');
    }

    public function test_banner_de_acoes_de_execucao_nao_aparece_em_baseline_sem_acoes_abertas(): void
    {
        $importacao = $this->importarComScore();

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertDontSee('aguardando uma importação de Avanço para nova avaliação');
    }

    public function test_banner_de_acoes_de_execucao_nunca_aparece_em_importacao_de_avanco(): void
    {
        $baseline = $this->importarComScore();

        PlanoAcao::create([
            'obra_id' => $this->obra->id,
            'cronograma_importacao_origem_id' => $baseline->id,
            'regra_id' => 'PROG-001',
            'titulo' => 'Atividade atrasada',
            'recomendacao' => 'teste',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => ['1'],
        ]);

        Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $avanco = CronogramaImportacao::where('tipo', TipoCronogramaImportacao::Avanco)->latest('id')->first();

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $avanco])
            ->assertDontSee('aguardando uma importação de Avanço para nova avaliação');
    }

    // =====================================================================
    // SEGURANÇA
    // =====================================================================

    public function test_usuario_sem_permissao_na_obra_nao_acessa_a_importacao(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        // $this->user NUNCA foi vinculado a $outraObra.
        $importacaoOutraObra = CronogramaImportacao::create([
            'obra_id' => $outraObra->id,
            'importado_em' => now(),
        ]);

        // mount() sem acesso é tratado como navegação de página cheia —
        // App\Exceptions\Handler::render() redireciona com flash.popup em
        // vez do 403 cru (mesmo padrão já usado por ⚡relatorio-detalhe.blade.php
        // — ver ReportDetalheTest::test_encarregado_nao_acessa_detalhe_de_rascunho).
        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacaoOutraObra])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_usuario_nao_acessa_importacao_de_outro_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        $importacaoOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outraObra) {
            return CronogramaImportacao::create([
                'obra_id' => $outraObra->id,
                'importado_em' => now(),
            ]);
        });

        // Camada 1: o global scope de BelongsToTenant já a torna invisível
        // pra qualquer query feita sob o tenant do usuário autenticado —
        // mesma proteção que route model binding usaria na rota real.
        $this->assertNull(CronogramaImportacao::find($importacaoOutroTenant->id));

        // Camada 2: mesmo que alguém repassasse a instância diretamente
        // (bypass do scope), a Policy nega — o usuário nunca teve vínculo
        // (obra_user) com uma obra de outro tenant. Mesmo redirecionamento
        // amigável do Handler, não um 403/exceção crua.
        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacaoOutroTenant])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }
}
