<?php

namespace Tests\Feature;

use App\Jobs\ImportarCronogramaJob;
use App\Models\Atividade;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class CronogramaImportacaoLivewireTest extends TestCase
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

    public function test_confirmar_em_fila_sincrona_importa_e_finaliza_sem_ficar_processando(): void
    {
        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
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

        $this->assertEquals(2, Atividade::where('obra_id', $this->obra->id)->count());
    }

    public function test_historico_de_importacoes_mostra_inicio_e_termino_da_linha_de_base(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar');

        $historico = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->instance()->historicoImportacoes;

        $this->assertCount(1, $historico);
        $this->assertSame('2024-01-01', $historico->first()->baseline_inicio);
        $this->assertSame('2024-02-14', $historico->first()->baseline_termino);
    }

    public function test_confirmar_desabilita_botoes_e_mostra_status_enquanto_job_nao_termina(): void
    {
        Bus::fake();

        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
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
        $this->assertMatchesRegularExpression('/wire:click="confirmar"[^>]*disabled/s', $html);
        $this->assertMatchesRegularExpression('/wire:click="cancelar"[^>]*disabled/s', $html);
    }

    public function test_verificar_status_finaliza_quando_job_assincrono_marca_concluido(): void
    {
        Bus::fake();

        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar');

        $trackingId = $component->get('importacaoTrackingId');
        $this->assertNotNull($trackingId);

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

        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
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

        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->call('cancelar')
            ->assertStatus(422);
    }
}
