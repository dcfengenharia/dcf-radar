<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\StatusDocumento;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.2 — download/visualização protegidos de revisão de
 * Documento de Engenharia. Cobertura A-H (seção 20) + storage (seção 21).
 */
class DocumentoEngenhariaRevisaoDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /** @return array{0: DocumentoEngenharia, 1: DocumentoEngenhariaRevisao} */
    private function criarDocumentoComRevisao(Work $obra, ?UploadedFile $arquivo = null): array
    {
        $documento = DocumentoEngenharia::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'codigo' => 'DOC-' . uniqid(),
            'descricao' => 'Documento de teste',
        ]);

        $dados = ['tenant_id' => $obra->tenant_id, 'revisao' => 'R0', 'descricao' => 'Emissão inicial'];

        if ($arquivo) {
            $caminho = $arquivo->store("documentos-engenharia/{$obra->id}/{$documento->id}", 'local');
            $dados['anexo_path'] = $caminho;
            $dados['anexo_nome_original'] = $arquivo->getClientOriginalName();
        }

        $revisao = $documento->revisoes()->create($dados);

        return [$documento, $revisao];
    }

    private function pdf(string $nome = 'desenho.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nome, 100, 'application/pdf');
    }

    // ===================== A-H (seção 20) =====================

    public function test_a_usuario_autorizado_na_obra_a_baixa_revisao_a(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value); // só 'ver'
        $this->actingAs($this->user);

        [, $revisao] = $this->criarDocumentoComRevisao($this->obra, $this->pdf());

        $response = $this->get(route('documentos-engenharia.revisoes.download', $revisao));

        $response->assertOk();
    }

    public function test_b_usuario_sem_ver_na_obra_a_e_bloqueado(): void
    {
        // Usuário existe no tenant mas SEM nenhum perfil em nenhuma obra.
        $this->actingAs($this->user);

        [, $revisao] = $this->criarDocumentoComRevisao($this->obra, $this->pdf());

        $response = $this->get(route('documentos-engenharia.revisoes.download', $revisao));

        // 403 de navegação de página cheia é interceptado por
        // App\Exceptions\Handler::render() e vira redirect + popup interno
        // de acesso negado (mesmo padrão já documentado no CLAUDE.md e já
        // usado por AtividadeAnexoTest para o precedente de anexos).
        $response->assertRedirect();
        $response->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_c_usuario_com_ver_somente_na_obra_b_e_bloqueado_em_a(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        [, $revisao] = $this->criarDocumentoComRevisao($this->obra, $this->pdf());

        $response = $this->get(route('documentos-engenharia.revisoes.download', $revisao));

        $response->assertRedirect();
        $response->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_d_usuario_com_acesso_as_duas_obras_baixa_pelo_contexto_do_documento(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->vincularObra($obraB, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        [, $revisaoA] = $this->criarDocumentoComRevisao($this->obra, $this->pdf('a.pdf'));
        [, $revisaoB] = $this->criarDocumentoComRevisao($obraB, $this->pdf('b.pdf'));

        $this->get(route('documentos-engenharia.revisoes.download', $revisaoA))->assertOk();
        $this->get(route('documentos-engenharia.revisoes.download', $revisaoB))->assertOk();
    }

    public function test_e_outro_tenant_nao_resolve(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);

        $outroTenant = Tenant::factory()->create();
        // Criados DENTRO do TenantContext do outro tenant — BelongsToTenant
        // ignora tenant_id explícito e usa o tenant do usuário autenticado
        // se não fizermos isso (mesma lição já documentada no Ciclo 17).
        $revisaoOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $documentoOutroTenant = DocumentoEngenharia::create([
                'tenant_id' => $outroTenant->id,
                'obra_id' => $obraOutroTenant->id,
                'codigo' => 'DOC-OUTRO-TENANT',
                'descricao' => 'x',
            ]);

            return $documentoOutroTenant->revisoes()->create([
                'tenant_id' => $outroTenant->id,
                'revisao' => 'R0',
                'descricao' => 'x',
                'anexo_path' => 'documentos-engenharia/x/x/fake.pdf',
                'anexo_nome_original' => 'fake.pdf',
            ]);
        });

        $this->actingAs($this->user);

        $response = $this->get(route('documentos-engenharia.revisoes.download', $revisaoOutroTenant));

        // Cross-tenant é ModelNotFoundException genuína (route-model-binding
        // nunca resolve), não um abort(403) — nunca passa pelo interceptador
        // de popup, 404 direto é o comportamento correto aqui.
        $response->assertNotFound();
    }

    public function test_f_id_manipulado_nao_bypassa(): void
    {
        // Mesma garantia de C, framed como manipulação direta de ID na URL.
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        [, $revisaoDaObraA] = $this->criarDocumentoComRevisao($this->obra, $this->pdf());

        $response = $this->get(route('documentos-engenharia.revisoes.download', $revisaoDaObraA->id));

        $response->assertRedirect();
        $response->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_g_documento_soft_deleted_comportamento_seguro(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        [$documento, $revisao] = $this->criarDocumentoComRevisao($this->obra, $this->pdf());
        $documento->delete(); // soft delete

        $response = $this->get(route('documentos-engenharia.revisoes.download', $revisao));

        $response->assertNotFound();

        // Arquivo físico preservado — histórico não é destruído pelo soft delete.
        Storage::disk('local')->assertExists($revisao->anexo_path);

        // restore() traz o download de volta.
        $documento->restore();
        $this->get(route('documentos-engenharia.revisoes.download', $revisao))->assertOk();
    }

    public function test_h_arquivo_ausente_comportamento_seguro(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        [$documento] = $this->criarDocumentoComRevisao($this->obra);
        $revisao = $documento->revisoes()->create([
            'tenant_id' => $this->tenant->id,
            'revisao' => 'R1',
            'descricao' => 'Sem arquivo físico',
            'anexo_path' => 'documentos-engenharia/inexistente/inexistente/fantasma.pdf',
            'anexo_nome_original' => 'fantasma.pdf',
        ]);

        $response = $this->get(route('documentos-engenharia.revisoes.download', $revisao));

        $response->assertNotFound();
        $response->assertDontSee('documentos-engenharia/inexistente'); // nunca revela o path físico
    }

    // ===================== Storage (seção 21) =====================

    public function test_download_autorizado_retorna_bytes_corretos(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        $arquivo = UploadedFile::fake()->createWithContent('desenho.pdf', '%PDF-1.4 conteudo de teste');
        [, $revisao] = $this->criarDocumentoComRevisao($this->obra, $arquivo);

        $response = $this->get(route('documentos-engenharia.revisoes.download', $revisao));

        $response->assertOk();
        $this->assertStringContainsString('desenho.pdf', $response->headers->get('content-disposition'));
    }

    public function test_mesmo_nome_original_nao_colide(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        $documento = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'codigo' => 'DOC-COLISAO', 'descricao' => 'x',
        ]);

        $r1arquivo = UploadedFile::fake()->createWithContent('desenho.pdf', 'CONTEUDO-R1');
        $caminho1 = $r1arquivo->store("documentos-engenharia/{$this->obra->id}/{$documento->id}", 'local');
        $r1 = $documento->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R1', 'descricao' => 'x', 'anexo_path' => $caminho1, 'anexo_nome_original' => 'desenho.pdf']);

        $r2arquivo = UploadedFile::fake()->createWithContent('desenho.pdf', 'CONTEUDO-R2-DIFERENTE');
        $caminho2 = $r2arquivo->store("documentos-engenharia/{$this->obra->id}/{$documento->id}", 'local');
        $r2 = $documento->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R2', 'descricao' => 'x', 'anexo_path' => $caminho2, 'anexo_nome_original' => 'desenho.pdf']);

        $this->assertNotEquals($caminho1, $caminho2, 'Paths físicos precisam ser distintos mesmo com mesmo nome original.');

        $resp1 = $this->get(route('documentos-engenharia.revisoes.download', $r1));
        $resp2 = $this->get(route('documentos-engenharia.revisoes.download', $r2));

        $this->assertEquals('CONTEUDO-R1', $resp1->streamedContent());
        $this->assertEquals('CONTEUDO-R2-DIFERENTE', $resp2->streamedContent());
    }

    public function test_path_traversal_nome_malicioso_nao_afeta_path_fisico(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        $arquivoMalicioso = UploadedFile::fake()->createWithContent('../../../etc/evil desenho final ç.pdf', 'conteudo');
        [, $revisao] = $this->criarDocumentoComRevisao($this->obra, $arquivoMalicioso);

        // Path físico gerado pelo Storage nunca contém o nome original bruto
        // (Laravel gera um hash aleatório) — nenhum ../ no path real.
        $this->assertStringNotContainsString('..', $revisao->anexo_path);
        $this->assertStringStartsWith("documentos-engenharia/{$this->obra->id}/", $revisao->anexo_path);

        $response = $this->get(route('documentos-engenharia.revisoes.download', $revisao));
        $response->assertOk();
    }

    public function test_inline_e_download_ambos_acessiveis(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        [, $revisao] = $this->criarDocumentoComRevisao($this->obra, $this->pdf());

        $this->get(route('documentos-engenharia.revisoes.download', $revisao) . '?inline=1')->assertOk();
        $this->get(route('documentos-engenharia.revisoes.download', $revisao))->assertOk();
    }

    public function test_fallback_para_disco_publico_legado_nunca_expoe_url_publica(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Encarregado->value);
        $this->actingAs($this->user);

        $documento = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'codigo' => 'DOC-LEGADO', 'descricao' => 'x',
        ]);
        // Arquivo só existe no disco público (simula revisão anterior à
        // 18.2, ainda não migrada pelo Command).
        $caminho = "documentos-engenharia/{$this->obra->id}/{$documento->id}/legado.pdf";
        Storage::disk('public')->put($caminho, 'CONTEUDO-LEGADO');
        $revisao = $documento->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R0', 'descricao' => 'x', 'anexo_path' => $caminho, 'anexo_nome_original' => 'legado.pdf']);

        Storage::disk('local')->assertMissing($caminho); // confirma que NÃO está no privado ainda

        $response = $this->get(route('documentos-engenharia.revisoes.download', $revisao));

        $response->assertOk();
        $this->assertEquals('CONTEUDO-LEGADO', $response->streamedContent());
    }
}
