<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AnexarRevisaoDocumento;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/** Ciclo 18, Etapa 18.2 — Command de migração física de storage. Cobertura A-K (seção 22). */
class MigrarRevisoesEngenhariaStoragePrivadoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function criarDocumento(?Work $obra = null): DocumentoEngenharia
    {
        $obra ??= $this->obra;

        return DocumentoEngenharia::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'codigo' => 'DOC-' . uniqid(),
            'descricao' => 'x',
        ]);
    }

    private function criarRevisaoComArquivoNoPublico(DocumentoEngenharia $documento, string $conteudo = 'conteudo', string $sufixo = 'a.pdf'): DocumentoEngenhariaRevisao
    {
        $caminho = "documentos-engenharia/{$documento->obra_id}/{$documento->id}/" . uniqid() . '-' . $sufixo;
        Storage::disk('public')->put($caminho, $conteudo);

        return $documento->revisoes()->create([
            'tenant_id' => $documento->tenant_id,
            'revisao' => 'R' . uniqid(),
            'descricao' => 'x',
            'anexo_path' => $caminho,
            'anexo_nome_original' => $sufixo,
        ]);
    }

    // A. revisão sem arquivo não quebra.
    public function test_a_revisao_sem_arquivo_nao_quebra(): void
    {
        $documento = $this->criarDocumento();
        $documento->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R0', 'descricao' => 'sem arquivo']);

        $this->artisan('engenharia:migrar-revisoes-storage-privado')
            ->assertExitCode(0);
    }

    // B. arquivo legado existente migra.
    public function test_b_arquivo_legado_migra(): void
    {
        $documento = $this->criarDocumento();
        $revisao = $this->criarRevisaoComArquivoNoPublico($documento, 'CONTEUDO-LEGADO');

        Storage::disk(AnexarRevisaoDocumento::DISCO)->assertMissing($revisao->anexo_path);

        $this->artisan('engenharia:migrar-revisoes-storage-privado')->assertExitCode(0);

        Storage::disk(AnexarRevisaoDocumento::DISCO)->assertExists($revisao->anexo_path);
        $this->assertEquals('CONTEUDO-LEGADO', Storage::disk(AnexarRevisaoDocumento::DISCO)->get($revisao->anexo_path));
        // Origem NUNCA é apagada nesta etapa (decisão explícita).
        Storage::disk('public')->assertExists($revisao->anexo_path);
    }

    // C. "banco atualizado só após cópia" — reinterpretado: não há coluna
    // pra atualizar (anexo_path é a mesma string nos dois discos), então a
    // prova é que anexo_path NUNCA muda, antes ou depois da migração.
    public function test_c_anexo_path_nunca_e_alterado_pela_migracao(): void
    {
        $documento = $this->criarDocumento();
        $revisao = $this->criarRevisaoComArquivoNoPublico($documento);
        $pathOriginal = $revisao->anexo_path;

        $this->artisan('engenharia:migrar-revisoes-storage-privado');

        $this->assertEquals($pathOriginal, $revisao->fresh()->anexo_path);
    }

    // D. destino já existe COM O MESMO CONTEÚDO → idempotente (não tenta
    // copiar de novo). Etapa 18.2.HARDENING (B2): "já migrado" agora exige
    // que os dois discos tenham o MESMO conteúdo — este teste prova o
    // caminho feliz genuíno (conteúdos idênticos), não mais apenas
    // "o path existe" (esse cenário, com conteúdos DIFERENTES, virou o
    // teste dedicado de conflito abaixo — era exatamente o bug B2).
    public function test_d_destino_ja_existe_com_mesmo_conteudo_idempotente(): void
    {
        $documento = $this->criarDocumento();
        $revisao = $this->criarRevisaoComArquivoNoPublico($documento, 'CONTEUDO-IDENTICO');

        // Já migrado manualmente antes de rodar o Command, com o MESMO
        // conteúdo do público — cenário real de reexecução após sucesso.
        Storage::disk(AnexarRevisaoDocumento::DISCO)->put($revisao->anexo_path, 'CONTEUDO-IDENTICO');

        $this->artisan('engenharia:migrar-revisoes-storage-privado')
            ->expectsOutputToContain('Já estavam no privado (idempotente): 1')
            ->expectsOutputToContain('Conflitos: 0')
            ->assertExitCode(0);

        $this->assertEquals('CONTEUDO-IDENTICO', Storage::disk(AnexarRevisaoDocumento::DISCO)->get($revisao->anexo_path));
    }

    /**
     * Etapa 18.2.HARDENING (B2) — CASO 2: origem pública e destino privado
     * com o MESMO path mas conteúdo DIFERENTE. Antes do hardening, isso
     * era silenciosamente aceito como "já migrado" (só checava exists()).
     * Agora é detectado como CONFLITO explícito: nenhum dos dois arquivos
     * é alterado/sobrescrito, o Command reporta e sinaliza via exit code
     * não-zero — exige diagnóstico humano, nunca resolução automática.
     */
    public function test_conflito_conteudos_diferentes_nao_sobrescreve_nenhum(): void
    {
        $documento = $this->criarDocumento();
        $revisao = $this->criarRevisaoComArquivoNoPublico($documento, 'CONTEUDO-PUBLICO-CORRETO');

        Storage::disk(AnexarRevisaoDocumento::DISCO)->put($revisao->anexo_path, 'CONTEUDO-PRIVADO-DIFERENTE');

        $this->artisan('engenharia:migrar-revisoes-storage-privado')
            ->expectsOutputToContain('Conflitos: 1')
            ->assertExitCode(1);

        // Nenhum dos dois foi tocado — preservados exatamente como estavam.
        $this->assertEquals('CONTEUDO-PUBLICO-CORRETO', Storage::disk('public')->get($revisao->anexo_path));
        $this->assertEquals('CONTEUDO-PRIVADO-DIFERENTE', Storage::disk(AnexarRevisaoDocumento::DISCO)->get($revisao->anexo_path));
    }

    // E. rodar duas vezes seguidas não duplica/corrompe.
    public function test_e_rodar_duas_vezes_sem_duplicacao_ou_corrupcao(): void
    {
        $documento = $this->criarDocumento();
        $revisao = $this->criarRevisaoComArquivoNoPublico($documento, 'CONTEUDO-ESTAVEL');

        $this->artisan('engenharia:migrar-revisoes-storage-privado')->assertExitCode(0);
        $this->artisan('engenharia:migrar-revisoes-storage-privado')->assertExitCode(0);

        $this->assertEquals('CONTEUDO-ESTAVEL', Storage::disk(AnexarRevisaoDocumento::DISCO)->get($revisao->anexo_path));
        $this->assertEquals(1, DocumentoEngenhariaRevisao::where('id', $revisao->id)->count());
    }

    // F. origem ausente → reporta e mantém banco consistente (nada é alterado).
    public function test_f_origem_ausente_reporta_e_banco_consistente(): void
    {
        $documento = $this->criarDocumento();
        $revisao = $documento->revisoes()->create([
            'tenant_id' => $this->tenant->id,
            'revisao' => 'R0',
            'descricao' => 'x',
            'anexo_path' => 'documentos-engenharia/fantasma/fantasma/fantasma.pdf',
            'anexo_nome_original' => 'fantasma.pdf',
        ]);

        $this->artisan('engenharia:migrar-revisoes-storage-privado')
            ->expectsOutputToContain('Sem arquivo em nenhum disco (registro órfão): 1')
            ->assertExitCode(0); // ausência não é considerada "falha" de execução do comando

        $this->assertEquals('documentos-engenharia/fantasma/fantasma/fantasma.pdf', $revisao->fresh()->anexo_path);
        Storage::disk(AnexarRevisaoDocumento::DISCO)->assertMissing($revisao->anexo_path);
    }

    // G. falha ao gravar destino → banco não aponta para arquivo inexistente
    // (mock do disco local pra forçar put() a falhar, mantendo o público real via fake).
    public function test_g_falha_ao_gravar_destino_banco_permanece_consistente(): void
    {
        $documento = $this->criarDocumento();
        $revisao = $this->criarRevisaoComArquivoNoPublico($documento, 'CONTEUDO');
        $pathOriginal = $revisao->anexo_path;

        // Storage::set() é o MESMO mecanismo interno que Storage::fake() usa
        // pra registrar um disco substituto — troca só o disco 'local' por
        // um mock que força put() a falhar, mantendo 'public' como o fake
        // real já configurado no setUp() (nunca precisa mockar os dois).
        $discoLocalMock = Mockery::mock(Filesystem::class);
        $discoLocalMock->shouldReceive('exists')->andReturn(false); // nunca "já migrado"
        $discoLocalMock->shouldReceive('put')->andReturn(false); // força falha de escrita
        Storage::set(AnexarRevisaoDocumento::DISCO, $discoLocalMock);

        // expectsOutputToContain() não é confiável pra este cenário
        // específico (Artisan::output() fica vazio quando o disco é
        // trocado via Storage::set() em vez de Storage::fake() — quirk de
        // captura de output do harness de teste, não do Command). A prova
        // real e robusta é: exit code de falha + invariante de banco.
        $exitCode = \Illuminate\Support\Facades\Artisan::call('engenharia:migrar-revisoes-storage-privado');

        $this->assertEquals(1, $exitCode, 'Exit code deveria ser FAILURE (1) quando há falha de escrita no destino.');

        // anexo_path continua exatamente o mesmo — nunca passa a apontar
        // pra um arquivo que não existe no destino.
        $this->assertEquals($pathOriginal, $revisao->fresh()->anexo_path);

        // O destino nunca chegou a "existir de verdade" (mock nunca grava).
        $this->assertFalse(Storage::disk(AnexarRevisaoDocumento::DISCO)->exists($pathOriginal));
    }

    // H. múltiplas revisões processadas corretamente.
    public function test_h_multiplas_revisoes(): void
    {
        $documento = $this->criarDocumento();
        $revisoes = collect(range(1, 5))->map(fn ($i) => $this->criarRevisaoComArquivoNoPublico($documento, "CONTEUDO-{$i}", "arquivo{$i}.pdf"));

        $this->artisan('engenharia:migrar-revisoes-storage-privado')
            ->expectsOutputToContain('Migrados nesta execução: 5')
            ->assertExitCode(0);

        foreach ($revisoes as $i => $revisao) {
            $this->assertEquals('CONTEUDO-' . ($i + 1), Storage::disk(AnexarRevisaoDocumento::DISCO)->get($revisao->anexo_path));
        }
    }

    // I. mesmo nome original em revisões diferentes migra sem colidir.
    public function test_i_mesmo_nome_original_nao_colide_na_migracao(): void
    {
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisaoComArquivoNoPublico($documento, 'CONTEUDO-1', 'desenho.pdf');
        $r2 = $this->criarRevisaoComArquivoNoPublico($documento, 'CONTEUDO-2', 'desenho.pdf');

        $this->assertNotEquals($r1->anexo_path, $r2->anexo_path);

        $this->artisan('engenharia:migrar-revisoes-storage-privado')->assertExitCode(0);

        $this->assertEquals('CONTEUDO-1', Storage::disk(AnexarRevisaoDocumento::DISCO)->get($r1->anexo_path));
        $this->assertEquals('CONTEUDO-2', Storage::disk(AnexarRevisaoDocumento::DISCO)->get($r2->anexo_path));
    }

    // J. tenant/obra distintos — comando itera todos, sem TenantContext manual.
    public function test_j_tenant_e_obra_distintos_migrados_juntos(): void
    {
        $documentoA = $this->criarDocumento($this->obra);
        $revisaoA = $this->criarRevisaoComArquivoNoPublico($documentoA, 'CONTEUDO-A');

        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $documentoB = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outraObra) {
            return $this->criarDocumento($outraObra);
        });
        $revisaoB = \App\Support\TenantContext::actingAs($outroTenant, function () use ($documentoB) {
            return $this->criarRevisaoComArquivoNoPublico($documentoB, 'CONTEUDO-B');
        });

        $this->artisan('engenharia:migrar-revisoes-storage-privado')
            ->expectsOutputToContain('Migrados nesta execução: 2')
            ->assertExitCode(0);

        Storage::disk(AnexarRevisaoDocumento::DISCO)->assertExists($revisaoA->anexo_path);
        Storage::disk(AnexarRevisaoDocumento::DISCO)->assertExists($revisaoB->anexo_path);
    }

    // K. sem N+1 grosseiro — chunk pequeno força múltiplos chunks (30 revisões, chunk=10 → 3 chunks).
    public function test_k_multiplos_chunks_sem_n_mais_1_grosseiro(): void
    {
        $documento = $this->criarDocumento();
        collect(range(1, 30))->each(fn ($i) => $this->criarRevisaoComArquivoNoPublico($documento, "C{$i}", "f{$i}.pdf"));

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->artisan('engenharia:migrar-revisoes-storage-privado', ['--chunk' => 10])
            ->expectsOutputToContain('Migrados nesta execução: 30')
            ->assertExitCode(0);

        // 30 revisões em chunks de 10 (3 chunks) — teto generoso, mas baixo
        // o bastante pra pegar um N+1 real (ex.: 1 query extra por revisão
        // estouraria isso facilmente).
        $this->assertLessThan(60, $queries, "Comando gerou {$queries} queries pra 30 revisões em 3 chunks — suspeita de N+1.");
    }

    /**
     * Etapa 18.2.HARDENING — CASO 3: só existe no privado, público já não
     * tem mais o arquivo (cenário real pós-limpeza futura do legado, ou
     * arquivo que sempre foi só privado). Válido, sem comparação de hash
     * necessária (não há nada pra comparar) — nunca tratado como erro.
     */
    public function test_caso_3_so_existe_no_privado_e_valido(): void
    {
        $documento = $this->criarDocumento();
        $revisao = $documento->revisoes()->create([
            'tenant_id' => $this->tenant->id, 'revisao' => 'R0', 'descricao' => 'x',
            'anexo_path' => 'documentos-engenharia/x/x/so-privado.pdf', 'anexo_nome_original' => 'so-privado.pdf',
        ]);
        Storage::disk(AnexarRevisaoDocumento::DISCO)->put($revisao->anexo_path, 'SO-EXISTE-NO-PRIVADO');

        $this->artisan('engenharia:migrar-revisoes-storage-privado')
            ->expectsOutputToContain('Já estavam no privado (idempotente): 1')
            ->expectsOutputToContain('Conflitos: 0')
            ->assertExitCode(0);

        $this->assertEquals('SO-EXISTE-NO-PRIVADO', Storage::disk(AnexarRevisaoDocumento::DISCO)->get($revisao->anexo_path));
    }

    /** Uma revisão em conflito não impede o processamento das demais (seção 13-H). */
    public function test_conflito_em_uma_revisao_nao_impede_processar_as_demais(): void
    {
        $documento = $this->criarDocumento();

        $revisaoConflito = $this->criarRevisaoComArquivoNoPublico($documento, 'PUBLICO-X');
        Storage::disk(AnexarRevisaoDocumento::DISCO)->put($revisaoConflito->anexo_path, 'PRIVADO-DIFERENTE-X');

        $revisaoOk = $this->criarRevisaoComArquivoNoPublico($documento, 'CONTEUDO-OK');

        $this->artisan('engenharia:migrar-revisoes-storage-privado')
            ->expectsOutputToContain('Conflitos: 1')
            ->expectsOutputToContain('Migrados nesta execução: 1')
            ->assertExitCode(1);

        Storage::disk(AnexarRevisaoDocumento::DISCO)->assertExists($revisaoOk->anexo_path);
        $this->assertEquals('CONTEUDO-OK', Storage::disk(AnexarRevisaoDocumento::DISCO)->get($revisaoOk->anexo_path));
    }
}
