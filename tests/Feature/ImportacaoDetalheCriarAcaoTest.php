<?php

namespace Tests\Feature;

use App\Enums\StatusPlanoAcao;
use App\Models\CronogramaImportacao;
use App\Models\PlanoAcao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 4.2, Parte 6 — botão "Criar Ação" no card Mapa de Ações de
 * ⚡importacao-detalhe.blade.php. `cronograma_sample.xml` dispara PROG-001
 * (Alto) e WORK-004 (Médio), ambos com findingIndex definido (finding
 * calculado por um ScoreCalculator já com a Fase 4.2 aplicada).
 */
class ImportacaoDetalheCriarAcaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function arquivoFixture(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(__DIR__ . '/../Fixtures/' . $name));
    }

    private function importarComScore(): CronogramaImportacao
    {
        // Ciclo 6 — Baseline não avalia PROG-001/WORK-004 (ambas Execução,
        // Ciclo 2). Baseline primeiro só pra criar as atividades (casadas
        // por external_uid); a mesma fixture reimportada como Avanço, logo
        // abaixo, atualiza essas atividades e avalia as 36 regras — mesmo
        // padrão já usado em CronogramaHealthCheckTest::
        // test_importacao_de_avanco_calcula_e_persiste_health_check().
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        return CronogramaImportacao::where('tipo', 'avanco')->latest('id')->first();
    }

    // =====================================================================
    // PERMISSÃO
    // =====================================================================

    public function test_botao_criar_acao_aparece_para_quem_tem_permissao(): void
    {
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $importacao = $this->importarComScore();

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertSee('Criar Ação');
    }

    public function test_botao_criar_acao_nao_aparece_para_quem_nao_tem_permissao(): void
    {
        $this->vincularObra($this->obra, $this->user, 'cliente_leitura');
        $importacao = $this->importarComScore();

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertDontSee('Criar Ação');
    }

    // =====================================================================
    // CRIAÇÃO
    // =====================================================================

    public function test_criar_acao_a_partir_do_mapa_de_acoes_usa_findingindex_correto(): void
    {
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $responsavel, 'engenheiro');

        $importacao = $this->importarComScore();
        $scoreResultado = $importacao->healthCheck->scoreResultado();
        $primeiraAcaoDoMapa = $scoreResultado->mapaAcoes[0];
        $this->assertNotNull($primeiraAcaoDoMapa->findingIndex, 'esta importação foi calculada já com findingIndex — Fase 4.2');

        $findingOriginal = $importacao->healthCheck->resultado()->findings[$primeiraAcaoDoMapa->findingIndex];
        $uidsEsperados = \App\Support\HealthCheck\PlanoAcao\UidExtractor::extrair($findingOriginal->atividades);

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->call('abrirModalCriarAcao', $primeiraAcaoDoMapa->findingIndex)
            ->set('novaAcaoResponsavelId', $responsavel->id)
            ->set('novaAcaoPrazo', '2026-12-31')
            ->call('confirmarCriarAcao')
            ->assertSet('indiceParaCriarAcao', null)
            ->assertDispatched('show-toast');

        $this->assertSame(1, PlanoAcao::count());
        $acao = PlanoAcao::first();
        $this->assertSame($primeiraAcaoDoMapa->regraId, $acao->regra_id);
        $this->assertSame($primeiraAcaoDoMapa->titulo, $acao->titulo);
        $this->assertEqualsCanonicalizing($uidsEsperados, $acao->uids_referencia);
        $this->assertTrue($acao->responsavel->is($responsavel));
        $this->assertSame('2026-12-31', $acao->prazo->toDateString());
        $this->assertTrue($acao->autor->is($this->user));
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status);
        $this->assertSame($this->obra->id, $acao->obra_id);
    }

    public function test_duplicacao_mostra_mensagem_amigavel_sem_quebrar_a_tela(): void
    {
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $importacao = $this->importarComScore();
        $primeiraAcaoDoMapa = $importacao->healthCheck->scoreResultado()->mapaAcoes[0];

        // Já existe uma ação aberta pra este mesmo finding.
        $findingOriginal = $importacao->healthCheck->resultado()->findings[$primeiraAcaoDoMapa->findingIndex];
        PlanoAcao::criarDeFinding($findingOriginal, $importacao, $this->obra->id);

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->call('abrirModalCriarAcao', $primeiraAcaoDoMapa->findingIndex)
            ->call('confirmarCriarAcao')
            ->assertOk()
            ->assertSee('Já existe uma ação aberta para este problema');

        $this->assertSame(1, PlanoAcao::count(), 'não deve ter criado uma segunda ação duplicada');
    }

    public function test_responsavel_sem_acesso_a_obra_mostra_mensagem_amigavel(): void
    {
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $importacao = $this->importarComScore();
        $primeiraAcaoDoMapa = $importacao->healthCheck->scoreResultado()->mapaAcoes[0];

        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // $semAcesso NUNCA foi vinculado à obra.

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->call('abrirModalCriarAcao', $primeiraAcaoDoMapa->findingIndex)
            ->set('novaAcaoResponsavelId', $semAcesso->id)
            ->call('confirmarCriarAcao')
            ->assertOk()
            ->assertSee('não tem acesso a esta obra');

        $this->assertSame(0, PlanoAcao::count());
    }

    public function test_badge_de_acoes_abertas_aparece_quando_ja_existe_acao(): void
    {
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $importacao = $this->importarComScore();
        $primeiraAcaoDoMapa = $importacao->healthCheck->scoreResultado()->mapaAcoes[0];
        $findingOriginal = $importacao->healthCheck->resultado()->findings[$primeiraAcaoDoMapa->findingIndex];

        PlanoAcao::criarDeFinding($findingOriginal, $importacao, $this->obra->id);

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertSee('1 ação(ões) aberta(s)');
    }

    public function test_cancelar_fecha_o_modal_sem_criar_nada(): void
    {
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $importacao = $this->importarComScore();
        $primeiraAcaoDoMapa = $importacao->healthCheck->scoreResultado()->mapaAcoes[0];

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->call('abrirModalCriarAcao', $primeiraAcaoDoMapa->findingIndex)
            ->call('fecharModalCriarAcao')
            ->assertSet('indiceParaCriarAcao', null);

        $this->assertSame(0, PlanoAcao::count());
    }

    // =====================================================================
    // BADGE CLICÁVEL → PLANO DE AÇÃO (Fase 4.3, Etapa E)
    // =====================================================================

    public function test_badge_vira_link_para_plano_de_acao_quando_ha_acoes_abertas(): void
    {
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $importacao = $this->importarComScore();
        $primeiraAcaoDoMapa = $importacao->healthCheck->scoreResultado()->mapaAcoes[0];
        $findingOriginal = $importacao->healthCheck->resultado()->findings[$primeiraAcaoDoMapa->findingIndex];

        PlanoAcao::criarDeFinding($findingOriginal, $importacao, $this->obra->id);

        $esperado = route('radar.plano-acao', ['regra' => $primeiraAcaoDoMapa->regraId]);

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertSeeHtml('href="' . $esperado . '"')
            ->assertSee('1 ação(ões) aberta(s)');
    }

    public function test_badge_nao_e_link_quando_nao_ha_acoes_abertas(): void
    {
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $importacao = $this->importarComScore();

        // Nenhum PlanoAcao criado — nenhuma ação aberta pra nenhum finding.
        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertDontSee('ação(ões) aberta(s)')
            ->assertDontSeeHtml('route(\'radar.plano-acao\'');
    }

    public function test_link_do_badge_transmite_a_regra_correta(): void
    {
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $importacao = $this->importarComScore();

        // cronograma_sample.xml dispara PROG-001 e WORK-004 — cria ação aberta
        // só pra WORK-004, confirma que o link gerado é o da regra certa.
        $findingWork004 = collect($importacao->healthCheck->resultado()->findings)
            ->first(fn ($f) => $f->regraId === 'WORK-004');
        PlanoAcao::criarDeFinding($findingWork004, $importacao, $this->obra->id);

        $linkErrado = route('radar.plano-acao', ['regra' => 'PROG-001']);
        $linkCerto = route('radar.plano-acao', ['regra' => 'WORK-004']);

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertSeeHtml('href="' . $linkCerto . '"')
            ->assertDontSeeHtml('href="' . $linkErrado . '"');
    }

    // =====================================================================
    // N+1 do Mapa de Ações (Fase 4.3, Etapa E)
    // =====================================================================

    public function test_contagem_de_acoes_abertas_do_mapa_usa_uma_unica_query_batch(): void
    {
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $importacao = $this->importarComScore();

        // Cria uma ação aberta para CADA finding do fixture (PROG-001 e
        // WORK-004), pra garantir que contarAcoesAbertasParaFinding() seja
        // efetivamente exercitado nos dois itens do Mapa de Ações.
        foreach ($importacao->healthCheck->resultado()->findings as $finding) {
            PlanoAcao::criarDeFinding($finding, $importacao, $this->obra->id);
        }

        $queriesPlanosAcao = 0;
        DB::listen(function ($query) use (&$queriesPlanosAcao) {
            if (str_contains($query->sql, 'planos_acao')) {
                $queriesPlanosAcao++;
            }
        });

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertOk();

        // Antes da Etapa E: 1 query em `planos_acao` POR finding exibido no
        // Mapa de Ações (aqui, 2). Depois: 1 única query batch
        // (acoesAbertasDaObra(), #[Computed], reaproveitada em memória pelos
        // 2 findings) — nunca escala com o tamanho do Mapa de Ações.
        $this->assertSame(
            1,
            $queriesPlanosAcao,
            "esperava exatamente 1 query em planos_acao (batch), obtido: {$queriesPlanosAcao}"
        );
    }

    public function test_contagem_de_acoes_abertas_permanece_correta_apos_a_otimizacao(): void
    {
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $importacao = $this->importarComScore();

        $findingProg001 = collect($importacao->healthCheck->resultado()->findings)
            ->first(fn ($f) => $f->regraId === 'PROG-001');
        // 2 ações abertas pra PROG-001, nenhuma pra WORK-004.
        PlanoAcao::criarDeFinding($findingProg001, $importacao, $this->obra->id);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraImportacao = CronogramaImportacao::create(['obra_id' => $outraObra->id, 'importado_em' => now()]);
        // Ação de OUTRA obra com a mesma regra_id e os MESMOS uids — nunca deve contar aqui.
        PlanoAcao::create([
            'obra_id' => $outraObra->id,
            'cronograma_importacao_origem_id' => $outraImportacao->id,
            'regra_id' => 'PROG-001',
            'titulo' => 'de outra obra',
            'recomendacao' => 'teste',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => \App\Support\HealthCheck\PlanoAcao\UidExtractor::extrair($findingProg001->atividades),
        ]);

        Livewire::test('pages::radar.importacao-detalhe', ['importacao' => $importacao])
            ->assertSee('1 ação(ões) aberta(s)')
            ->assertDontSee('2 ação(ões) aberta(s)');
    }
}
