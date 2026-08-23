<?php

namespace Tests\Unit;

use App\Actions\Engenharia\AnexarRevisaoDocumento;
use App\Models\DocumentoEngenharia;
use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.2 — atomicidade arquivo↔banco de
 * App\Actions\Engenharia\AnexarRevisaoDocumento (lição do Ciclo 17,
 * mesmo padrão de App\Actions\Atividade\AnexarArquivoAtividade).
 */
class AnexarRevisaoDocumentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(AnexarRevisaoDocumento::DISCO);
    }

    private function criarDocumento(): DocumentoEngenharia
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        return DocumentoEngenharia::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'codigo' => 'DOC-ACTION-TEST',
            'descricao' => 'x',
        ]);
    }

    public function test_upload_com_sucesso_grava_arquivo_e_revisao(): void
    {
        $documento = $this->criarDocumento();
        $arquivo = UploadedFile::fake()->create('desenho.pdf', 100, 'application/pdf');

        $revisao = (new AnexarRevisaoDocumento())->execute(
            $documento,
            ['revisao' => 'R0', 'data_emissao' => null, 'status_documento_id' => null, 'descricao' => 'Emissão inicial'],
            $arquivo,
            null
        );

        $this->assertNotNull($revisao->anexo_path);
        Storage::disk(AnexarRevisaoDocumento::DISCO)->assertExists($revisao->anexo_path);
        $this->assertDatabaseHas('documento_engenharia_revisoes', ['id' => $revisao->id, 'revisao' => 'R0']);
    }

    public function test_revisao_sem_arquivo_funciona_normalmente(): void
    {
        $documento = $this->criarDocumento();

        $revisao = (new AnexarRevisaoDocumento())->execute(
            $documento,
            ['revisao' => 'R0', 'data_emissao' => null, 'status_documento_id' => null, 'descricao' => 'Sem anexo'],
            null,
            null
        );

        $this->assertNull($revisao->anexo_path);
    }

    /**
     * O caso mais crítico da lição do Ciclo 17: arquivo grava com sucesso,
     * mas o INSERT falha (aqui, simulado por um `revisao` duplicado — o
     * unique(documento_engenharia_id, revisao) do banco real barra o
     * segundo insert) — o arquivo já gravado não pode ficar órfão.
     */
    public function test_falha_no_insert_remove_arquivo_ja_gravado_compensacao(): void
    {
        $documento = $this->criarDocumento();

        // Revisão "R0" já existe — o próximo insert com o mesmo texto vai
        // falhar no unique(documento_engenharia_id, revisao) do banco.
        $documento->revisoes()->create(['tenant_id' => $documento->tenant_id, 'revisao' => 'R0', 'descricao' => 'já existe']);

        $arquivo = UploadedFile::fake()->create('desenho.pdf', 100, 'application/pdf');

        $excecao = null;
        try {
            (new AnexarRevisaoDocumento())->execute(
                $documento,
                ['revisao' => 'R0', 'data_emissao' => null, 'status_documento_id' => null, 'descricao' => 'Duplicada'],
                $arquivo,
                null
            );
        } catch (\Throwable $e) {
            $excecao = $e;
        }

        $this->assertNotNull($excecao, 'Esperava que o insert duplicado lançasse uma exceção.');

        // O arquivo que a Action gravou ANTES do insert falhar não pode
        // sobrar órfão no storage.
        $arquivosNoDiretorio = Storage::disk(AnexarRevisaoDocumento::DISCO)->allFiles("documentos-engenharia/{$documento->obra_id}/{$documento->id}");
        $this->assertCount(0, $arquivosNoDiretorio, 'Arquivo órfão não removido após falha no INSERT.');

        // Só a revisão original (não-duplicada) existe.
        $this->assertEquals(1, $documento->revisoes()->count());
    }

    /**
     * Etapa 18.2.HARDENING (achado B1) — quando o delete() de compensação
     * retorna `false` (falha "silenciosa" do storage, sem exceção), a
     * EXCEÇÃO ORIGINAL do INSERT continua sendo a relançada — nunca
     * substituída pelo problema secundário de cleanup.
     */
    public function test_falha_na_compensacao_delete_retorna_false_preserva_excecao_original(): void
    {
        $documento = $this->criarDocumento();
        $documento->revisoes()->create(['tenant_id' => $documento->tenant_id, 'revisao' => 'R0', 'descricao' => 'já existe']);

        // Mock parcial: delega tudo pro disco fake real, exceto delete(),
        // que é forçado a "falhar silenciosamente" (retorna false).
        $discoParcial = Mockery::mock(Storage::disk(AnexarRevisaoDocumento::DISCO))->makePartial();
        $discoParcial->shouldReceive('delete')->andReturn(false);
        Storage::set(AnexarRevisaoDocumento::DISCO, $discoParcial);

        $arquivo = UploadedFile::fake()->create('desenho.pdf', 100, 'application/pdf');

        $excecao = null;
        try {
            (new AnexarRevisaoDocumento())->execute(
                $documento,
                ['revisao' => 'R0', 'data_emissao' => null, 'status_documento_id' => null, 'descricao' => 'Duplicada'],
                $arquivo,
                null
            );
        } catch (\Throwable $e) {
            $excecao = $e;
        }

        $this->assertNotNull($excecao);
        // A exceção relançada é a do INSERT (violação de unique), NUNCA uma
        // exceção "genérica de cleanup" substituindo a causa raiz.
        $this->assertStringContainsString('Integrity constraint violation', $excecao->getMessage());

        // Arquivo órfão CONHECIDO — delete() "falhou", então o arquivo
        // permanece (comportamento esperado, não um bug desta etapa).
        $this->assertEquals(1, $documento->revisoes()->count());
    }

    /**
     * Etapa 18.2.HARDENING (achado B1) — mesma garantia quando o delete()
     * de compensação LANÇA uma exceção (em vez de retornar false).
     */
    public function test_falha_na_compensacao_delete_lanca_excecao_preserva_excecao_original(): void
    {
        $documento = $this->criarDocumento();
        $documento->revisoes()->create(['tenant_id' => $documento->tenant_id, 'revisao' => 'R0', 'descricao' => 'já existe']);

        $discoParcial = Mockery::mock(Storage::disk(AnexarRevisaoDocumento::DISCO))->makePartial();
        $discoParcial->shouldReceive('delete')->andThrow(new \RuntimeException('Falha de infraestrutura simulada no delete.'));
        Storage::set(AnexarRevisaoDocumento::DISCO, $discoParcial);

        $arquivo = UploadedFile::fake()->create('desenho.pdf', 100, 'application/pdf');

        $excecao = null;
        try {
            (new AnexarRevisaoDocumento())->execute(
                $documento,
                ['revisao' => 'R0', 'data_emissao' => null, 'status_documento_id' => null, 'descricao' => 'Duplicada'],
                $arquivo,
                null
            );
        } catch (\Throwable $e) {
            $excecao = $e;
        }

        $this->assertNotNull($excecao);
        // A exceção que sobe pro chamador é SEMPRE a original do INSERT,
        // nunca a "Falha de infraestrutura simulada no delete" do cleanup.
        $this->assertStringContainsString('Integrity constraint violation', $excecao->getMessage());
        $this->assertStringNotContainsString('Falha de infraestrutura simulada', $excecao->getMessage());

        $this->assertEquals(1, $documento->revisoes()->count());
    }
}
