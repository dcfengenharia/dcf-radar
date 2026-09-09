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

    /**
     * Objetivo 3 da auditoria de jornada inicial (2026-09-07): o alerta de
     * sucesso pós-importação só oferecia "Ver curvas de avanço →" — o
     * usuário podia sair da tela achando que já tinha uma Linha de Base
     * salva, sem perceber que precisa ir criá-la explicitamente em
     * Linhas de Base. Correção mínima: adiciona um 2º link contextual
     * apontando pra lá, sem alterar o link/fluxo já existente.
     */
    public function test_sucesso_da_importacao_oferece_cta_para_salvar_linha_de_base(): void
    {
        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar');

        $component
            ->assertSee('Salvar como Linha de Base')
            ->assertSee('Ver curvas de avanço');

        $this->assertStringContainsString(
            route('radar.linhas-base'),
            $component->html()
        );
    }

    public function test_historico_de_importacoes_mostra_a_importacao_com_health_check_carregado(): void
    {
        // Fase 3, Etapa 5: historicoImportacoes() foi simplificado pra usar
        // o partial compartilhado historico-importacoes.blade.php (mesma
        // apresentação em ⚡cronograma/⚡relatorio-importar-avanco/
        // ⚡obra-detalhe) — as colunas específicas de Baseline que este
        // teste verificava antes (baseline_inicio/baseline_termino via
        // withMin/withMax) saíram de propósito, trocadas por Score/Faixa/
        // Cobertura/Situação (ver tests/Feature/HistoricoImportacoesTest.php
        // e CLAUDE.md). Este teste passa a verificar só o que continua
        // sendo verdade: a importação aparece, paginada, com o Health
        // Check já carregado (evita N+1).
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar');

        $historico = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->instance()->historicoImportacoes;

        $this->assertSame(1, $historico->total());
        $this->assertTrue($historico->first()->relationLoaded('healthCheck'));
        $this->assertNotNull($historico->first()->healthCheck);
        $this->assertNotNull($historico->first()->healthCheck->score);
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
        // wire:target="confirmar" (não wire:click="confirmar") porque este fixture
        // dispara alertas do Health Check — nesse caso o botão vira
        // onclick="confirmarAcao(...)" em vez de wire:click direto (ver
        // ⚡cronograma.blade.php), mas continua tendo wire:target="confirmar" +
        // wire:loading.attr="disabled" nos dois casos.
        $this->assertMatchesRegularExpression('/wire:target="confirmar"[^>]*disabled/s', $html);
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
