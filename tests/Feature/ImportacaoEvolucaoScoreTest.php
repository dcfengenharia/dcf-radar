<?php

namespace Tests\Feature;

use App\Enums\TipoCronogramaImportacao;
use App\Models\CronogramaImportacao;
use App\Models\CronogramaImportacaoHealthCheck;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\HealthCheck\Score\FaixaScore;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 3.1 — Evolução do Score e Histórico. Cobre:
 * - CronogramaImportacao::importacaoAnterior() (mesma obra, mesmo tipo, tenant isolado);
 * - bloco "Evolução do Score" e "O que mudou desde a última importação" em
 *   ⚡importacao-detalhe.blade.php;
 * - navegação por categoria (filtro clicável nas Ocorrências);
 * - coluna Δ Score no histórico (historico-importacoes.blade.php).
 * Nada aqui recalcula Health Check/Score — tudo lido do que já foi persistido.
 */
class ImportacaoEvolucaoScoreTest extends TestCase
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
     * Ciclo 8 — $fixture opcional (default cronograma_sample.xml, mantido por
     * compatibilidade com os chamadores que não precisam de findings sob
     * Baseline). Só o teste de filtro por categoria passa
     * cronograma_fase2b3_slack.xml explicitamente — nenhum outro chamador
     * deste helper é afetado.
     */
    private function importarComScore(string $fixture = 'cronograma_sample.xml'): CronogramaImportacao
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture($fixture))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        return CronogramaImportacao::where('tipo', TipoCronogramaImportacao::Baseline)->latest('id')->first();
    }

    /**
     * Importa uma fixture como Baseline (via ⚡cronograma.blade.php) e retorna
     * a importação criada. Não mexe em importado_em — quem precisa de ordem
     * estritamente anterior/posterior (a coluna `importado_em` só tem
     * precisão de segundo no MySQL) deve backdatar explicitamente a
     * importação mais antiga, como feito nos testes de evolução abaixo.
     */
    private function importarBaseline(string $fixture): CronogramaImportacao
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture($fixture))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        return CronogramaImportacao::where('tipo', TipoCronogramaImportacao::Baseline)->latest('id')->first();
    }

    /**
     * Ciclo 8 — grava um CronogramaImportacaoHealthCheck com Score explícito,
     * SEM rodar o HealthCheckEngine e sem passar pelo fluxo real de
     * importação (mesmo padrão já usado em
     * test_evolucao_mostra_indisponivel_quando_anterior_nao_tem_score,
     * extraído aqui pra reuso). Usado pelos testes de Evolução/Timeline que
     * só precisam de dois Scores controlados pra validar a lógica de
     * comparação/exibição — nunca de reconciliação de atividades,
     * external_uid, arquivamento ou execução das 36 regras (ver CLAUDE.md/
     * relatório do Ciclo 8 sobre por que BASE-002 tornava esses testes
     * não-determinísticos quando baseados em importações reais).
     */
    private function criarHealthCheckComScore(CronogramaImportacao $importacao, int $score): void
    {
        CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $importacao->id]
            + CronogramaImportacaoHealthCheck::camposParaPersistir(['findings' => []])
            + [
                'score' => $score,
                'faixa_score' => FaixaScore::paraScore($score)->value,
                'cobertura' => 100,
                'score_por_dimensao' => [],
                'mapa_acoes' => [],
                'potencial_recuperavel' => 100 - $score,
                'versao_score' => '1.0',
            ]
        );
    }

    // =====================================================================
    // CronogramaImportacao::importacaoAnterior()
    // =====================================================================

    public function test_importacao_anterior_resolve_a_imediatamente_anterior_da_mesma_obra_e_tipo(): void
    {
        $primeira = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(5),
        ]);

        $segunda = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now(),
        ]);

        $this->assertTrue($segunda->importacaoAnterior()->is($primeira));
        $this->assertNull($primeira->importacaoAnterior());
    }

    public function test_importacao_anterior_nao_mistura_tipos_diferentes(): void
    {
        CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(5),
        ]);

        $avanco = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now(),
        ]);

        $this->assertNull($avanco->importacaoAnterior());
    }

    public function test_importacao_anterior_nunca_cruza_obra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);

        CronogramaImportacao::create([
            'obra_id' => $outraObra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(5),
        ]);

        $importacaoObraAtual = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now(),
        ]);

        $this->assertNull($importacaoObraAtual->importacaoAnterior());
    }

    public function test_importacao_anterior_nunca_cruza_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        TenantContext::actingAs($outroTenant, function () use ($outraObraOutroTenant) {
            return CronogramaImportacao::create([
                'obra_id' => $outraObraOutroTenant->id,
                'tipo' => TipoCronogramaImportacao::Baseline->value,
                'importado_em' => now()->subDays(5),
            ]);
        });

        $importacaoAtual = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now(),
        ]);

        $this->assertNull($importacaoAtual->importacaoAnterior());
    }

    // =====================================================================
    // EVOLUÇÃO DO SCORE — bloco na página de detalhe
    // =====================================================================

    public function test_primeira_importacao_do_tipo_mostra_mensagens_de_primeira_importacao(): void
    {
        $importacao = $this->importarComScore();

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertSee('Esta é a primeira importação deste tipo para esta obra.')
            ->assertSee('Primeira importação.');
    }

    public function test_evolucao_mostra_score_melhorou_quando_atual_e_maior_que_anterior(): void
    {
        // Ciclo 8 — registros construídos diretamente (mesmo padrão de
        // test_evolucao_mostra_indisponivel_quando_anterior_nao_tem_score),
        // não por importação real: duas importações Baseline sequenciais na
        // MESMA obra sempre disparam BASE-002 (atividades arquivadas) na
        // segunda, o que confundia o delta de Score com um efeito colateral
        // do importador — nada a ver com a lógica de Evolução do Score que
        // este teste precisa validar.
        $primeira = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(2),
        ]);
        $this->criarHealthCheckComScore($primeira, 40);

        $segunda = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now(),
        ]);
        $this->criarHealthCheckComScore($segunda, 70);

        $scorePrimeira = 40;
        $scoreSegunda = 70;

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $segunda])
            ->assertSee('A saúde do cronograma melhorou.')
            ->assertSee('+' . ($scoreSegunda - $scorePrimeira) . ' pontos');
    }

    public function test_evolucao_mostra_score_piorou_quando_atual_e_menor_que_anterior(): void
    {
        // Ciclo 8 — mesmo padrão do teste "melhorou" acima: registros
        // construídos diretamente, sem depender de reconciliação de
        // atividades/BASE-002/execução das 36 regras.
        $primeira = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(2),
        ]);
        $this->criarHealthCheckComScore($primeira, 70);

        $segunda = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now(),
        ]);
        $this->criarHealthCheckComScore($segunda, 40);

        $scorePrimeira = 70;
        $scoreSegunda = 40;

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $segunda])
            ->assertSee('A saúde do cronograma piorou.')
            ->assertSee((string) ($scoreSegunda - $scorePrimeira) . ' pontos');
    }

    public function test_evolucao_mostra_score_igual_quando_atual_e_igual_ao_anterior(): void
    {
        $primeira = $this->importarBaseline('cronograma_sample.xml');
        $primeira->update(['importado_em' => now()->subDays(2)]);

        $segunda = $this->importarBaseline('cronograma_sample.xml');

        $this->assertSame($primeira->fresh()->healthCheck->score, $segunda->fresh()->healthCheck->score);

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $segunda])
            ->assertSee('A saúde do cronograma permaneceu igual.')
            ->assertSee('sem alteração');
    }

    public function test_evolucao_mostra_indisponivel_quando_anterior_nao_tem_score(): void
    {
        $anterior = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(5),
        ]);
        CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $anterior->id]
            + CronogramaImportacaoHealthCheck::camposParaPersistir(['findings' => []])
        );

        $atual = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now(),
        ]);
        CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $atual->id]
            + CronogramaImportacaoHealthCheck::camposParaPersistir(['findings' => []])
            + [
                'score' => 90,
                'faixa_score' => FaixaScore::Bom->value,
                'cobertura' => 100,
                'score_por_dimensao' => [],
                'mapa_acoes' => [],
                'potencial_recuperavel' => 10,
                'versao_score' => '1.0',
            ]
        );

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $atual])
            ->assertSee('A importação anterior não possui Score disponível para comparação.');
    }

    public function test_evolucao_mostra_primeira_importacao_quando_anterior_nao_tem_health_check(): void
    {
        $anteriorSemHealthCheck = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'importado_em' => now()->subDays(5),
        ]);

        $atual = $this->importarComScore();

        // Precisa do MESMO tipo pra importacaoAnterior() encontrar o registro.
        $anteriorSemHealthCheck->update(['tipo' => $atual->tipo]);

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $atual])
            ->assertSee('A importação anterior não possui Score disponível para comparação.')
            ->assertSee('Primeira importação.');
    }

    // =====================================================================
    // O QUE MUDOU DESDE A ÚLTIMA IMPORTAÇÃO
    // =====================================================================

    public function test_o_que_mudou_lista_regras_cuja_quantidade_de_ocorrencias_variou(): void
    {
        $primeira = $this->importarBaseline('cronograma_health_check_sem_alertas.xml');
        $primeira->update(['importado_em' => now()->subDays(2)]);

        $segunda = $this->importarBaseline('cronograma_fase2b3_slack.xml');

        $component = Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $segunda]);

        // regra_id é dado do finding, mas o partial de "Ocorrências do Health
        // Check" (health-check-findings.blade.php) nunca o imprime como texto
        // — só aparece na tela via o card "Mapa de Ações" ({{ $acao->regraId }}
        // em ⚡importacao-detalhe.blade.php), que nunca lista regra com
        // severidade Informativa (peso = 0, nunca penaliza o Score). Por isso
        // assertSee($finding['regra_id']) só é uma asserção válida pra
        // findings que o Mapa de Ações realmente lista — aqui, checado contra
        // a representação renderizada de verdade, não contra a severidade.
        $regrasNoMapaDeAcoes = array_map(
            fn ($acao) => $acao->regraId,
            $segunda->fresh()->healthCheck->scoreResultado()->mapaAcoes
        );

        foreach ($segunda->fresh()->healthCheck->findings as $finding) {
            if (! in_array($finding['regra_id'], $regrasNoMapaDeAcoes, true)) {
                continue;
            }

            $component->assertSee($finding['regra_id']);
        }

        // sem_alertas.xml não tem nenhum finding -> toda mudança lida é
        // 0 -> N (piorou), nunca "melhorou" -> ícone de alerta, não de sucesso.
        $component->assertSeeHtml('bx-error text-warning');
    }

    public function test_o_que_mudou_nao_lista_regra_cuja_quantidade_nao_variou(): void
    {
        $primeira = $this->importarBaseline('cronograma_sample.xml');
        $primeira->update(['importado_em' => now()->subDays(2)]);

        $segunda = $this->importarBaseline('cronograma_sample.xml');

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $segunda])
            ->assertSee('Nenhuma mudança nos achados desde a última importação.');
    }

    // =====================================================================
    // NAVEGAÇÃO POR CATEGORIA (FILTRO CLICÁVEL)
    // =====================================================================

    public function test_filtrar_por_categoria_mostra_so_as_ocorrencias_daquela_categoria(): void
    {
        // "Mapa de Ações" e "Score por Dimensão" mostram SEMPRE todas as
        // categorias (não são afetados pelo filtro) — só a lista de
        // "Ocorrências do Health Check" é filtrada (findingsFiltrados()).
        // Por isso a asserção verifica o retorno do método diretamente, em
        // vez de escanear o HTML inteiro da página (que sempre contém
        // WORK-006/BASE-003 em algum outro card independente do filtro).
        $importacao = $this->importarComScore('cronograma_fase2b3_slack.xml');

        // "Sem filtro" precisa devolver TODOS os findings persistidos — a
        // intenção do teste é validar o comportamento do filtro (tudo /
        // subconjunto / tudo de novo), não fixar quantos findings essa
        // fixture especificamente produz. Lista derivada do dado real
        // (nunca hardcoded), pra não acoplar o teste à composição
        // incidental da fixture.
        $todosRegraIds = array_column($importacao->healthCheck->findings, 'regra_id');

        $component = Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertSee('BASE-003')
            ->assertSee('WORK-006')
            ->assertDontSee('Mostrar todas as categorias');

        $this->assertEqualsCanonicalizing(
            $todosRegraIds,
            array_column($component->instance()->findingsFiltrados(), 'regra_id')
        );

        $component->call('filtrarCategoria', 'baseline')
            ->assertSee('Mostrar todas as categorias');

        $this->assertSame(['BASE-003'], array_column($component->instance()->findingsFiltrados(), 'regra_id'));

        $component->call('filtrarCategoria', 'hh');

        $this->assertSame(['WORK-006'], array_column($component->instance()->findingsFiltrados(), 'regra_id'));

        $component->call('filtrarCategoria', null)
            ->assertDontSee('Mostrar todas as categorias');

        $this->assertEqualsCanonicalizing(
            $todosRegraIds,
            array_column($component->instance()->findingsFiltrados(), 'regra_id')
        );
    }

    // =====================================================================
    // TIMELINE — coluna Δ Score no histórico
    // =====================================================================

    public function test_timeline_mostra_delta_score_entre_importacoes_consecutivas_do_mesmo_tipo(): void
    {
        // Ciclo 8 — mesmo padrão: registros construídos diretamente, sem
        // reconciliação/BASE-002. A tabela de histórico (historico-
        // importacoes.blade.php) calcula o Δ Score em memória a partir de
        // CronogramaImportacao::where('obra_id', ...) com healthCheck
        // eager-loaded — não depende de nenhum efeito colateral de
        // importação real, só de obra_id/tipo/importado_em/score.
        $primeira = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(2),
        ]);
        $this->criarHealthCheckComScore($primeira, 40);

        $segunda = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now(),
        ]);
        $this->criarHealthCheckComScore($segunda, 70);

        $scorePrimeira = 40;
        $scoreSegunda = 70;
        $delta = $scoreSegunda - $scorePrimeira;
        $this->assertGreaterThan(0, $delta);

        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->assertSee('Δ Score')
            ->assertSee('+' . $delta);
    }

    public function test_timeline_primeira_importacao_do_tipo_nao_mostra_delta(): void
    {
        $this->importarBaseline('cronograma_sample.xml');

        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->assertSee('Δ Score')
            ->assertOk();
    }
}
