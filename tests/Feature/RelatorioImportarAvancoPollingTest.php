<?php

namespace Tests\Feature;

use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Jobs\ImportarCronogramaJob;
use App\Models\CronogramaImportacao;
use App\Models\CronogramaImportacaoHealthCheck;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre o mesmo comportamento de tracking/polling já validado em
 * CronogramaImportacaoLivewireTest, agora replicado na tela de Realizado/
 * Tendência (⚡relatorio-importar-avanco.blade.php) — corrigido nesta fase
 * pra não apresentar sucesso falso antes do Job assíncrono terminar.
 */
class RelatorioImportarAvancoPollingTest extends TestCase
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
    }

    private function arquivoFixture(string $name): UploadedFile
    {
        $conteudo = file_get_contents(__DIR__ . '/../Fixtures/' . $name);

        return UploadedFile::fake()->createWithContent($name, $conteudo);
    }

    public function test_confirmar_em_fila_sincrona_finaliza_sem_ficar_processando(): void
    {
        $component = Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->assertSet('emPrevia', true)
            ->call('confirmar');

        $component
            ->assertSet('statusImportacao', null)
            ->assertSet('importacaoTrackingId', null)
            ->assertSet('emPrevia', false)
            ->assertSet('importado', true)
            ->assertDispatched('show-toast');
    }

    public function test_confirmar_com_fila_assincrona_mostra_processamento_e_desabilita_botoes(): void
    {
        Bus::fake();

        $component = Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar');

        Bus::assertDispatched(ImportarCronogramaJob::class);

        $component
            ->assertSet('statusImportacao', 'processando')
            ->assertSet('importado', false)
            ->assertSee('Processando')
            ->assertSee('wire:poll', false);

        $html = $component->html();
        $this->assertMatchesRegularExpression('/wire:target="confirmar"[^>]*disabled/s', $html);
        $this->assertMatchesRegularExpression('/wire:click="cancelar"[^>]*disabled/s', $html);
    }

    public function test_verificar_status_finaliza_quando_job_assincrono_marca_concluido(): void
    {
        Bus::fake();

        $component = Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar');

        $trackingId = $component->get('importacaoTrackingId');
        $this->assertNotNull($trackingId);

        // Antes do worker terminar, os dados NAO devem existir e a UI NAO deve mostrar sucesso.
        $this->assertSame(0, CronogramaImportacao::where('obra_id', $this->obra->id)->count());
        $component->assertSet('importado', false);

        // Simula o worker de fila terminando o job em background.
        Cache::put("cronograma-importacao-status:{$trackingId}", ['status' => 'concluido', 'erro' => null], now()->addMinutes(30));

        $component->call('verificarStatusImportacao')
            ->assertSet('statusImportacao', null)
            ->assertSet('importado', true)
            ->assertSet('emPrevia', false)
            ->assertDispatched('show-toast');
    }

    public function test_verificar_status_mostra_erro_amigavel_quando_job_falha(): void
    {
        Bus::fake();

        $component = Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar');

        $trackingId = $component->get('importacaoTrackingId');

        Cache::put("cronograma-importacao-status:{$trackingId}", ['status' => 'erro', 'erro' => 'Falha simulada no parsing'], now()->addMinutes(30));

        $component->call('verificarStatusImportacao')
            ->assertSet('statusImportacao', null)
            ->assertSet('importado', false)
            // Prévia continua disponível — usuário pode tentar de novo sem reenviar o arquivo.
            ->assertSet('emPrevia', true)
            ->assertSee('Falha simulada no parsing');
    }

    public function test_cancelar_e_bloqueado_enquanto_importacao_esta_em_andamento(): void
    {
        Bus::fake();

        Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->call('cancelar')
            ->assertStatus(422);
    }

    public function test_health_check_fica_associado_a_importacao_correta_apos_conclusao_real(): void
    {
        $component = Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar');

        $imp = CronogramaImportacao::where('obra_id', $this->obra->id)->where('tipo', TipoCronogramaImportacao::Avanco->value)->first();
        $this->assertNotNull($imp);

        $healthCheck = CronogramaImportacaoHealthCheck::where('cronograma_importacao_id', $imp->id)->first();
        $this->assertNotNull($healthCheck);
    }

    public function test_falha_dentro_de_aplicar_reverte_tudo_sem_health_check_orfao(): void
    {
        $importerQueQuebra = new class implements ImportadorCronograma {
            public function analisar(string $caminhoArquivo, Work $obra, TipoCronogramaImportacao $tipo = TipoCronogramaImportacao::Baseline): \App\DTOs\PlanoImportacao
            {
                return app(\App\Imports\MsProjectImporter::class)->analisar($caminhoArquivo, $obra, $tipo);
            }

            public function aplicar(\App\DTOs\PlanoImportacao $plano, Work $obra, ?string $userId, ?string $arquivo, TipoCronogramaImportacao $tipo = TipoCronogramaImportacao::Baseline): CronogramaImportacao
            {
                throw new \RuntimeException('Falha simulada dentro de aplicar()');
            }
        };

        $component = Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->assertSet('emPrevia', true);

        $this->app->bind(ImportadorCronograma::class, fn () => $importerQueQuebra);

        $component->call('confirmar');

        $component->assertSet('importado', false)
            ->assertSee('Falha simulada dentro de aplicar()');

        $this->assertSame(0, CronogramaImportacao::where('obra_id', $this->obra->id)->count());
        $this->assertSame(0, CronogramaImportacaoHealthCheck::count());
    }
}
