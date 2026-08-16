<?php

namespace Tests\Feature;

use App\Actions\Atividade\AnexarArquivoAtividade;
use App\Actions\Atividade\RemoverAnexoAtividade;
use App\Enums\Papel;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\AtividadeAnexo;
use App\Models\PacoteTrabalho;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AtividadeAnexoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private Atividade $atividade;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AtividadeAnexo::DISCO);
        Storage::fake('public');

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Engenheiro->value);
        $this->actingAs($this->user);

        $this->atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
    }

    private function conteudoPdfValido(int $tamanhoBytes = 2048): string
    {
        $cabecalho = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\n";
        $rodape = "\ntrailer<</Size 4/Root 1 0 R>>\n%%EOF";
        $recheio = str_repeat('A', max(0, $tamanhoBytes - strlen($cabecalho) - strlen($rodape)));

        return $cabecalho . $recheio . $rodape;
    }

    private function arquivoPdfFake(string $nome = 'documento.pdf', int $tamanhoBytes = 2048): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, $this->conteudoPdfValido($tamanhoBytes));
    }

    // -------------------------------------------------------------------------
    // Upload
    // -------------------------------------------------------------------------

    public function test_upload_valido_de_pdf_cria_anexo(): void
    {
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('documento.pdf'),
            $this->user
        );

        $this->assertDatabaseHas('atividade_anexos', ['id' => $anexo->id, 'atividade_id' => $this->atividade->id]);
        Storage::disk(AtividadeAnexo::DISCO)->assertExists($anexo->caminho_arquivo);
    }

    public function test_metadata_do_anexo_e_gravada_corretamente(): void
    {
        $arquivo = $this->arquivoPdfFake('planilha-de-cargas.pdf', 4096);

        $anexo = app(AnexarArquivoAtividade::class)->execute($this->atividade, $arquivo, $this->user);

        $this->assertEquals('planilha-de-cargas.pdf', $anexo->nome_original);
        $this->assertEquals('application/pdf', $anexo->mime_type);
        $this->assertEquals(4096, $anexo->tamanho_bytes);
        $this->assertEquals($this->user->id, $anexo->enviado_por);
    }

    public function test_dois_pdfs_com_mesmo_nome_original_geram_dois_anexos_distintos(): void
    {
        $action = app(AnexarArquivoAtividade::class);

        $anexo1 = $action->execute($this->atividade, $this->arquivoPdfFake('relatorio.pdf'), $this->user);
        $anexo2 = $action->execute($this->atividade, $this->arquivoPdfFake('relatorio.pdf'), $this->user);

        $this->assertNotEquals($anexo1->id, $anexo2->id);
        $this->assertNotEquals($anexo1->caminho_arquivo, $anexo2->caminho_arquivo);
        $this->assertEquals(2, $this->atividade->anexos()->count());
        Storage::disk(AtividadeAnexo::DISCO)->assertExists($anexo1->caminho_arquivo);
        Storage::disk(AtividadeAnexo::DISCO)->assertExists($anexo2->caminho_arquivo);
    }

    public function test_arquivo_nao_pdf_e_rejeitado(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(AnexarArquivoAtividade::class)->execute(
                $this->atividade,
                UploadedFile::fake()->create('malware.exe', 100),
                $this->user
            );
        } finally {
            $this->assertEquals(0, AtividadeAnexo::count());
            $this->assertEmpty(Storage::disk(AtividadeAnexo::DISCO)->allFiles());
        }
    }

    public function test_pdf_acima_do_limite_e_rejeitado(): void
    {
        $tamanhoAcimaDoLimite = (AtividadeAnexo::TAMANHO_MAXIMO_KB + 1) * 1024;

        $this->expectException(ValidationException::class);

        try {
            app(AnexarArquivoAtividade::class)->execute(
                $this->atividade,
                $this->arquivoPdfFake('grande.pdf', $tamanhoAcimaDoLimite),
                $this->user
            );
        } finally {
            $this->assertEquals(0, AtividadeAnexo::count());
            $this->assertEmpty(Storage::disk(AtividadeAnexo::DISCO)->allFiles());
        }
    }

    public function test_storage_e_privado_nunca_usa_disco_publico(): void
    {
        $this->assertEquals('local', AtividadeAnexo::DISCO);

        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('confidencial.pdf'),
            $this->user
        );

        Storage::disk(AtividadeAnexo::DISCO)->assertExists($anexo->caminho_arquivo);
        Storage::disk('public')->assertMissing($anexo->caminho_arquivo);
    }

    // -------------------------------------------------------------------------
    // Download protegido
    // -------------------------------------------------------------------------

    public function test_download_autorizado_retorna_o_arquivo_com_nome_original(): void
    {
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('cronograma-anexo.pdf'),
            $this->user
        );

        $response = $this->get(route('atividade-anexos.download', $anexo));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('cronograma-anexo.pdf', $response->headers->get('content-disposition'));
    }

    public function test_download_negado_para_usuario_com_acesso_a_obra_mas_sem_permissao_ver_restricoes_lookahead(): void
    {
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('bug-a711.pdf'),
            $this->user
        );

        // Perfil customizado (não um dos 5 padrão, que sempre têm 'ver' em
        // tudo via Perfil::seedPadrao()) vinculado à obra, mas SEM nenhuma
        // linha PerfilPermissao — reproduz exatamente o cenário de um admin
        // desmarcando "Ver" pra Lookahead em ⚡perfis-acesso.blade.php.
        $perfilSemVer = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Sem Ver Lookahead']);
        $usuarioSemVer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra->users()->attach($usuarioSemVer->id, ['perfil_id' => $perfilSemVer->id]);

        $this->assertTrue($usuarioSemVer->temAcessoAObra($this->obra->id));
        $this->assertFalse($usuarioSemVer->temPermissaoNaObra($this->obra->id, 'restricoes.lookahead', 'ver'));

        $response = $this->actingAs($usuarioSemVer)->get(route('atividade-anexos.download', $anexo));

        $response->assertRedirect();
        $response->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_download_autorizado_prova_permissao_ver_explicita_nao_apenas_acesso_a_obra(): void
    {
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('com-ver-explicito.pdf'),
            $this->user
        );

        $perfilComVer = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Com Ver Lookahead']);
        PerfilPermissao::create([
            'tenant_id' => $this->tenant->id,
            'perfil_id' => $perfilComVer->id,
            'funcionalidade' => 'restricoes.lookahead',
            'acao' => 'ver',
        ]);
        $usuarioComVer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra->users()->attach($usuarioComVer->id, ['perfil_id' => $perfilComVer->id]);

        $this->assertTrue($usuarioComVer->temPermissaoNaObra($this->obra->id, 'restricoes.lookahead', 'ver'));

        $response = $this->actingAs($usuarioComVer)->get(route('atividade-anexos.download', $anexo));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('com-ver-explicito.pdf', $response->headers->get('content-disposition'));
    }

    public function test_download_sem_acesso_a_obra_e_negado(): void
    {
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('restrito.pdf'),
            $this->user
        );

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $usuarioSemAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $usuarioSemAcesso, Papel::Engenheiro->value);

        $response = $this->actingAs($usuarioSemAcesso)->get(route('atividade-anexos.download', $anexo));

        $response->assertRedirect();
        $response->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_download_de_outro_tenant_nao_resolve_o_anexo(): void
    {
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('sigiloso.pdf'),
            $this->user
        );

        $outroTenant = Tenant::factory()->create();
        $usuarioOutroTenant = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraObraMesmoTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObraMesmoTenant, $usuarioOutroTenant, Papel::Engenheiro->value);

        $response = $this->actingAs($usuarioOutroTenant)->get(route('atividade-anexos.download', $anexo));

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Permissões (adicionar / remover)
    // -------------------------------------------------------------------------

    public function test_adicionar_anexo_exige_permissao_editar_no_lookahead(): void
    {
        $usuarioSoLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $usuarioSoLeitura, Papel::ClienteLeitura->value);

        $this->assertFalse($usuarioSoLeitura->can('update', $this->atividade));
        $this->assertTrue($this->user->can('update', $this->atividade));
    }

    public function test_remover_anexo_sem_permissao_excluir_e_negado(): void
    {
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('nao-remover.pdf'),
            $this->user
        );

        $usuarioSoLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $usuarioSoLeitura, Papel::ClienteLeitura->value);

        $response = $this->actingAs($usuarioSoLeitura)->delete(route('atividade-anexos.destroy', $anexo));

        $response->assertRedirect();
        $response->assertSessionHas('flash.popup', 'acesso-negado');
        $this->assertDatabaseHas('atividade_anexos', ['id' => $anexo->id]);
        Storage::disk(AtividadeAnexo::DISCO)->assertExists($anexo->caminho_arquivo);
    }

    public function test_remocao_autorizada_apaga_registro_e_arquivo_fisico(): void
    {
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('pode-remover.pdf'),
            $this->user
        );
        $caminho = $anexo->caminho_arquivo;

        // 'excluir' em restricoes.lookahead exige nível GerentePlanejamento+
        // (Perfil::REGRAS_ESCRITA) — Engenheiro ($this->user) só tem
        // 'editar', então não basta pra este cenário: usuário dedicado.
        $usuarioComExcluir = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $usuarioComExcluir, Papel::GerentePlanejamento->value);

        $response = $this->actingAs($usuarioComExcluir)->delete(route('atividade-anexos.destroy', $anexo));

        $response->assertNoContent();
        $this->assertDatabaseMissing('atividade_anexos', ['id' => $anexo->id]);
        Storage::disk(AtividadeAnexo::DISCO)->assertMissing($caminho);
    }

    public function test_remover_anexo_action_isolada_apaga_registro_e_arquivo(): void
    {
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('acao-isolada.pdf'),
            $this->user
        );
        $caminho = $anexo->caminho_arquivo;

        app(RemoverAnexoAtividade::class)->execute($anexo);

        $this->assertDatabaseMissing('atividade_anexos', ['id' => $anexo->id]);
        Storage::disk(AtividadeAnexo::DISCO)->assertMissing($caminho);
    }

    // -------------------------------------------------------------------------
    // Atividade arquivada
    // -------------------------------------------------------------------------

    public function test_atividade_arquivada_continua_permitindo_download_de_anexo_existente(): void
    {
        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('atividade-arquivada.pdf'),
            $this->user
        );

        $this->atividade->update(['fora_do_cronograma' => true]);

        $response = $this->get(route('atividade-anexos.download', $anexo));

        $response->assertOk();
    }

    // -------------------------------------------------------------------------
    // Preservação — anexo pertence à Atividade, não à baseline/avanço/WBS
    // -------------------------------------------------------------------------

    public function test_anexo_permanece_apos_mudanca_de_pacote_wbs(): void
    {
        $pacoteA = PacoteTrabalho::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $pacoteB = PacoteTrabalho::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $this->atividade->update(['pacote_trabalho_id' => $pacoteA->id]);

        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $this->atividade,
            $this->arquivoPdfFake('sobrevive-wbs.pdf'),
            $this->user
        );

        // Simula reorganização de WBS (mesma PK da Atividade, novo pacote pai).
        $this->atividade->update(['pacote_trabalho_id' => $pacoteB->id]);

        $this->assertTrue($this->atividade->fresh()->anexos->contains('id', $anexo->id));
        Storage::disk(AtividadeAnexo::DISCO)->assertExists($anexo->caminho_arquivo);
    }

    public function test_anexo_permanece_apos_reimportacao_real_da_mesma_atividade(): void
    {
        $importer = app(ImportadorCronograma::class);

        // v1: importação real do XML de fixture já usado pelo resto da suíte
        // (UID '2' = "Atividade 1").
        $planoV1 = $importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $importer->aplicar($planoV1, $this->obra, $this->user->id, 'v1.xml');

        $atividadeReal = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->firstOrFail();
        $pkOriginal = $atividadeReal->id;

        $anexo = app(AnexarArquivoAtividade::class)->execute(
            $atividadeReal,
            $this->arquivoPdfFake('sobrevive-reimportacao.pdf'),
            $this->user
        );

        // v2: mesmo external_uid, dados diferentes (percentual 100%) — mesma
        // reconciliação que qualquer reimportação real usa.
        $planoV2 = $importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra);
        $importer->aplicar($planoV2, $this->obra, $this->user->id, 'v2.xml');

        $atividadeReimportada = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->firstOrFail();

        $this->assertEquals($pkOriginal, $atividadeReimportada->id);
        $this->assertTrue($atividadeReimportada->anexos->contains('id', $anexo->id));
        Storage::disk(AtividadeAnexo::DISCO)->assertExists($anexo->caminho_arquivo);
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }
}
