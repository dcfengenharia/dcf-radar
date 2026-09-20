<?php

namespace Tests\Feature\Auditoria;

use App\Imports\Contracts\ImportadorCronograma;
use App\Models\CronogramaImportacao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A2.1, Seção 17 — achado real (não teórico):
 * `storage/app/imports/temp` acumulava o XML de toda importação
 * bem-sucedida indefinidamente (só `cancelar()`, ANTES do dispatch do
 * Job, limpava o arquivo — `ImportarCronogramaJob::handle()`/`failed()`
 * nunca limpavam nada). Confirmado em produção/dev: 8.832 arquivos /
 * 3,5 GB acumulados. Corrigido em `ImportarCronogramaJob::
 * removerArquivoTemporario()`, chamado no fim de `handle()` (sucesso) e
 * dentro de `failed()` (falha definitiva) — nunca numa tentativa
 * intermediária, que ainda precisa reler o mesmo arquivo num retry.
 */
class ImportarCronogramaJobLimpezaTempTest extends TestCase
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
        $this->vincularObra($this->obra, $this->user, \App\Enums\Papel::GerentePlanejamento->value);
    }

    private function arquivoFixture(string $name): UploadedFile
    {
        $conteudo = file_get_contents(__DIR__ . '/../../Fixtures/' . $name);

        return UploadedFile::fake()->createWithContent($name, $conteudo);
    }

    public function test_sucesso_remove_o_arquivo_temporario_apos_concluir(): void
    {
        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar');

        $caminhoArquivo = $component->get('caminhoArquivo');
        $this->assertFileExists($caminhoArquivo, 'o arquivo precisa existir logo após a prévia, antes de qualquer limpeza');

        $component->call('confirmar')->assertSet('importado', true);

        $this->assertFileDoesNotExist(
            $caminhoArquivo,
            'ImportarCronogramaJob::handle() deveria remover o XML temporário depois de uma importação bem-sucedida (Achado A2.1, Seção 17)'
        );
    }

    public function test_falha_definitiva_tambem_remove_o_arquivo_temporario(): void
    {
        $importerQueQuebra = new class implements ImportadorCronograma {
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
            ->call('analisar');

        $caminhoArquivo = $component->get('caminhoArquivo');
        $this->assertFileExists($caminhoArquivo);

        $this->app->bind(ImportadorCronograma::class, fn () => $importerQueQuebra);

        // Fila síncrona (padrão de teste) já esgota a única tentativa e
        // chama failed() dentro da mesma requisição.
        $component->call('confirmar')->assertSet('importado', false);

        $this->assertFileDoesNotExist(
            $caminhoArquivo,
            'ImportarCronogramaJob::failed() deveria remover o XML temporário depois de uma falha definitiva (Achado A2.1, Seção 17)'
        );
    }
}
