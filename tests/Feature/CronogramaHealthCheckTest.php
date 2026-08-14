<?php

namespace Tests\Feature;

use App\Imports\Contracts\ImportadorCronograma;
use App\Jobs\ImportarCronogramaJob;
use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\CronogramaImportacaoHealthCheck;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class CronogramaHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant     = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function arquivoFixture(string $name): UploadedFile
    {
        $conteudo = file_get_contents(__DIR__ . '/../Fixtures/' . $name);

        return UploadedFile::fake()->createWithContent($name, $conteudo);
    }

    // -------------------------------------------------------------------
    // 1) XML sem inconsistências
    // -------------------------------------------------------------------

    public function test_xml_sem_inconsistencias_mostra_mensagem_amigavel_e_nenhum_alerta(): void
    {
        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_health_check_sem_alertas.xml'))
            ->call('analisar');

        $resultado = $component->instance()->healthCheckResultado;

        $this->assertSame([], $resultado['findings']);
        $component->assertSee('Nenhuma inconsistência relevante foi encontrada');
        $component->assertSee('Confirmar importação');
        $component->assertDontSee('Importar mesmo assim');
    }

    // -------------------------------------------------------------------
    // 2) XML com alertas
    // -------------------------------------------------------------------

    public function test_xml_com_alertas_mostra_ocorrencias_e_botao_importar_mesmo_assim(): void
    {
        // Ciclo 4 — Baseline só avalia regras de Planejamento (Ciclo 2):
        // cronograma_sample.xml só dispara PROG-001/WORK-004 (Execução), que
        // deixaram de aparecer aqui de propósito. cronograma_fase2b3_slack.xml
        // dispara regras de Planejamento reais (SLACK-*/BASE-003/WORK-006/
        // CRIT-001) mesmo sob Baseline — confirmado via HealthCheckEngine::
        // avaliar($plano, Baseline) direto no console antes deste ajuste.
        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_fase2b3_slack.xml'))
            ->call('analisar');

        $resultado = $component->instance()->healthCheckResultado;
        $regraIds = array_column($resultado['findings'], 'regra_id');

        $this->assertContains('SLACK-001', $regraIds);
        $this->assertContains('BASE-003', $regraIds);
        $component->assertSee('Foram encontradas inconsistências');
        $component->assertSee('Importar mesmo assim');
    }

    // -------------------------------------------------------------------
    // 3) e 5) Usuário abortando — sem persistência parcial
    // -------------------------------------------------------------------

    public function test_abortar_apos_alertas_nao_altera_o_banco_nem_deixa_arquivo_temp(): void
    {
        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->assertSet('emPrevia', true);

        $caminhoArquivo = $component->get('caminhoArquivo');
        $this->assertFileExists($caminhoArquivo);

        $component->call('cancelar')
            ->assertSet('emPrevia', false)
            ->assertSet('healthCheckResultado', ['findings' => []]);

        $this->assertFileDoesNotExist($caminhoArquivo);
        $this->assertSame(0, Atividade::count());
        $this->assertSame(0, CronogramaImportacao::count());
        $this->assertSame(0, CronogramaImportacaoHealthCheck::count());
    }

    // -------------------------------------------------------------------
    // 4) e 8) Importar mesmo com alertas — persiste Health Check só após sucesso
    // -------------------------------------------------------------------

    public function test_confirmar_com_alertas_importa_e_persiste_health_check_exatamente_como_exibido(): void
    {
        // Ciclo 4 — mesma troca de fixture do teste anterior, mesmo motivo:
        // cronograma_sample.xml só dispara regras de Execução, filtradas na
        // Baseline (Ciclo 2). Totais abaixo conferidos via HealthCheckEngine::
        // avaliar($plano, Baseline) direto no console antes deste ajuste:
        // SLACK-001 (Alto, 2 atividades), BASE-003+SLACK-002+SLACK-005
        // (Médio, 7+3+1=11 atividades), WORK-006 (Baixo, 6 atividades),
        // CRIT-001 (Informativo, 0 — regra global sem lista de atividades).
        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_fase2b3_slack.xml'))
            ->call('analisar');

        $resultadoExibido = $component->instance()->healthCheckResultado;

        $component->call('confirmar')
            ->assertSet('importado', true);

        $this->assertSame(7, Atividade::where('obra_id', $this->obra->id)->count());
        $this->assertSame(1, CronogramaImportacao::count());
        $this->assertSame(1, CronogramaImportacaoHealthCheck::count());

        $importacao = CronogramaImportacao::first();
        $healthCheck = CronogramaImportacaoHealthCheck::first();

        $this->assertSame($importacao->id, $healthCheck->cronograma_importacao_id);
        $this->assertTrue($healthCheck->importado_com_alertas);
        $this->assertSame(2, $healthCheck->total_altos);    // SLACK-001
        $this->assertSame(11, $healthCheck->total_medios);  // BASE-003 + SLACK-002 + SLACK-005
        $this->assertSame(19, $healthCheck->total_ocorrencias);

        // O que foi persistido é EXATAMENTE o que a prévia mostrou ao usuário — nunca
        // recalculado (assertEquals, não assertSame: o round-trip pela coluna JSON do
        // MySQL não preserva ordem de chaves nem distingue float "40.0" de "40").
        $this->assertEquals($resultadoExibido['findings'], $healthCheck->findings);
    }

    // -------------------------------------------------------------------
    // 6) e 7) Falha durante o Job — rollback completo, nada persiste
    // -------------------------------------------------------------------

    public function test_falha_no_job_reverte_tudo_e_health_check_nao_fica_associado_a_importacao_incompleta(): void
    {
        $importerQueQuebraNoAplicar = new class implements ImportadorCronograma {
            public function analisar(string $caminhoArquivo, Work $obra, \App\Enums\TipoCronogramaImportacao $tipo = \App\Enums\TipoCronogramaImportacao::Baseline): \App\DTOs\PlanoImportacao
            {
                return app(\App\Imports\MsProjectImporter::class)->analisar($caminhoArquivo, $obra, $tipo);
            }

            public function aplicar(\App\DTOs\PlanoImportacao $plano, Work $obra, ?string $userId, ?string $arquivo, \App\Enums\TipoCronogramaImportacao $tipo = \App\Enums\TipoCronogramaImportacao::Baseline): CronogramaImportacao
            {
                throw new \RuntimeException('Falha simulada dentro de aplicar()');
            }
        };

        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->assertSet('emPrevia', true);

        $this->app->bind(ImportadorCronograma::class, fn () => $importerQueQuebraNoAplicar);

        $component->call('confirmar');

        // A prévia continua disponível (nada foi limpo) e o erro aparece amigável.
        $component->assertSet('importado', false)
            ->assertSee('Falha simulada dentro de aplicar()');

        $this->assertSame(0, Atividade::count());
        $this->assertSame(0, CronogramaImportacao::count());
        $this->assertSame(0, CronogramaImportacaoHealthCheck::count());
    }

    // -------------------------------------------------------------------
    // 9) Preservação do comportamento atual da importação (sem regressão)
    // -------------------------------------------------------------------

    public function test_importacao_sem_health_check_engine_continua_criando_as_mesmas_atividades_de_sempre(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        // Mesma asserção do teste pré-existente CronogramaImportacaoLivewireTest —
        // confirma que MsProjectImporter::aplicar() não foi alterado.
        $this->assertEquals(2, Atividade::where('obra_id', $this->obra->id)->count());
    }

    // -------------------------------------------------------------------
    // 10) e 12) Importação de Baseline (seção Obra) — já coberta pelos testes acima.
    // -------------------------------------------------------------------

    // -------------------------------------------------------------------
    // 11) e 12) Importação de Realizado/Tendência (seção Relatórios)
    // -------------------------------------------------------------------

    public function test_importacao_de_avanco_calcula_e_persiste_health_check(): void
    {
        // Precisa existir a Linha de Base antes — a importação de Avanço só
        // atualiza atividades já casadas por external_uid.
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $component = Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->assertSet('emPrevia', true);

        $regraIds = array_column($component->instance()->healthCheckResultado['findings'], 'regra_id');
        $this->assertContains('PROG-001', $regraIds);
        $this->assertContains('WORK-004', $regraIds);
        $component->assertSee('Importar mesmo assim');

        $component->call('confirmar')->assertSet('importado', true);

        $this->assertSame(2, CronogramaImportacao::count());
        $this->assertSame(2, CronogramaImportacaoHealthCheck::count());

        $importacaoAvanco = CronogramaImportacao::where('tipo', 'avanco')->first();
        $healthCheckAvanco = CronogramaImportacaoHealthCheck::where('cronograma_importacao_id', $importacaoAvanco->id)->first();

        $this->assertNotNull($healthCheckAvanco);
        $this->assertTrue($healthCheckAvanco->importado_com_alertas);
    }
}
