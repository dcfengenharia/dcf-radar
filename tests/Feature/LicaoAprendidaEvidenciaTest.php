<?php

namespace Tests\Feature;

use App\Actions\LicoesAprendidas\AnexarEvidenciaALicao;
use App\Actions\LicoesAprendidas\CriarLicaoAprendida;
use App\Actions\LicoesAprendidas\EnviarLicaoParaValidacao;
use App\Actions\LicoesAprendidas\PublicarLicaoAprendida;
use App\Actions\LicoesAprendidas\RemoverEvidenciaDaLicao;
use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\Papel;
use App\Enums\TipoLicaoAprendida;
use App\Exceptions\LicaoAprendidaImutavelException;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaEvidencia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ciclo 23, Etapa 23.2 (Seções 23-28) — evidências/anexos de uma lição:
 * upload/download/remoção protegidos, disco privado, imutabilidade após
 * publicação, política de conhecimento corporativo (Publicada é
 * visível/baixável tenant-wide, sem exigir acesso à obra de origem).
 */
class LicaoAprendidaEvidenciaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->admin, Papel::Admin->value);
        $this->actingAs($this->admin);
    }

    private function licao(): LicaoAprendida
    {
        return app(CriarLicaoAprendida::class)->execute($this->obra, $this->admin, [
            'titulo' => 'Título',
            'situacao_observada' => 'Situação',
            'recomendacao_futura' => 'Recomendação',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Alta->value,
            'area_funcional' => AreaFuncionalLicao::Suprimentos->value,
        ]);
    }

    private function pdf(string $nome = 'foto.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nome, 100, 'application/pdf');
    }

    private function jpg(string $nome = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($nome, 100, 'image/jpeg');
    }

    // =========================================================================
    // UPLOAD
    // =========================================================================

    public function test_upload_pdf_valido_e_aceito(): void
    {
        $evidencia = app(AnexarEvidenciaALicao::class)->execute($this->licao(), $this->pdf(), $this->admin);

        $this->assertInstanceOf(LicaoAprendidaEvidencia::class, $evidencia);
        Storage::disk(LicaoAprendidaEvidencia::DISCO)->assertExists($evidencia->caminho_arquivo);
        $this->assertSame('foto.pdf', $evidencia->nome_original);
        $this->assertSame($this->admin->id, $evidencia->enviado_por);
        $this->assertSame($this->tenant->id, $evidencia->tenant_id);
    }

    public function test_upload_jpg_valido_e_aceito(): void
    {
        $evidencia = app(AnexarEvidenciaALicao::class)->execute($this->licao(), $this->jpg(), $this->admin);

        Storage::disk(LicaoAprendidaEvidencia::DISCO)->assertExists($evidencia->caminho_arquivo);
    }

    public function test_upload_com_extensao_nao_permitida_e_rejeitado(): void
    {
        $licao = $this->licao();
        $exe = UploadedFile::fake()->create('malicioso.exe', 100, 'application/x-msdownload');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(AnexarEvidenciaALicao::class)->execute($licao, $exe, $this->admin);

        $this->assertSame(0, LicaoAprendidaEvidencia::count());
    }

    public function test_upload_acima_do_limite_e_rejeitado(): void
    {
        $licao = $this->licao();
        $grande = UploadedFile::fake()->create('grande.pdf', LicaoAprendidaEvidencia::TAMANHO_MAXIMO_KB + 100, 'application/pdf');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(AnexarEvidenciaALicao::class)->execute($licao, $grande, $this->admin);
    }

    public function test_upload_bloqueado_em_licao_publicada(): void
    {
        $licao = $this->licao();
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->expectException(LicaoAprendidaImutavelException::class);

        app(AnexarEvidenciaALicao::class)->execute($licao, $this->pdf(), $this->admin);

        $this->assertSame(0, LicaoAprendidaEvidencia::count());
    }

    public function test_upload_falho_nunca_deixa_arquivo_orfao_no_disco(): void
    {
        $licao = $this->licao();
        $arquivo = $this->pdf();

        // Simula o INSERT falhando depois do arquivo já salvo no disco —
        // mesma filosofia de compensação já validada em
        // AnexarArquivoAtividade/AnexarRevisaoDocumento. Listener de
        // model event removido no final (flushEventListeners()) pra
        // nunca vazar pros testes seguintes do mesmo processo.
        LicaoAprendidaEvidencia::creating(function () {
            throw new \RuntimeException('Falha simulada de banco.');
        });

        try {
            $excecao = null;
            try {
                app(AnexarEvidenciaALicao::class)->execute($licao, $arquivo, $this->admin);
            } catch (\RuntimeException $e) {
                $excecao = $e;
            }
            $this->assertNotNull($excecao, 'Esperava que a falha simulada de criação lançasse uma exceção.');
        } finally {
            LicaoAprendidaEvidencia::flushEventListeners();
        }

        $this->assertSame(0, LicaoAprendidaEvidencia::count());

        // Nenhum arquivo deve sobrar — a Action removeu o arquivo já
        // salvo assim que o INSERT falhou (compensação).
        $arquivos = Storage::disk(LicaoAprendidaEvidencia::DISCO)->allFiles("licoes-aprendidas-evidencias/{$this->tenant->id}/{$licao->id}");
        $this->assertEmpty($arquivos, 'Nenhum arquivo órfão deve sobrar quando o registro falha ao ser criado.');
    }

    // =========================================================================
    // DOWNLOAD — autorização
    // =========================================================================

    public function test_download_autorizado_para_quem_tem_acesso_a_obra_de_origem_rascunho(): void
    {
        $licao = $this->licao();
        $evidencia = app(AnexarEvidenciaALicao::class)->execute($licao, $this->pdf(), $this->admin);

        $response = $this->get(route('licao-evidencias.download', $evidencia));

        $response->assertOk();
    }

    public function test_download_bloqueado_para_quem_nao_tem_acesso_a_obra_de_origem_rascunho(): void
    {
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $licao = $this->licao();
        $evidencia = app(AnexarEvidenciaALicao::class)->execute($licao, $this->pdf(), $this->admin);

        $this->actingAs($semAcesso);
        $response = $this->get(route('licao-evidencias.download', $evidencia));

        $response->assertRedirect();
        $response->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_download_de_evidencia_de_licao_publicada_e_acessivel_tenant_wide(): void
    {
        $outroUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $outroUser, Papel::Admin->value);
        // outroUser NUNCA tem acesso a $this->obra — só a $outraObra.

        $licao = $this->licao();
        $evidencia = app(AnexarEvidenciaALicao::class)->execute($licao, $this->pdf(), $this->admin);
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->actingAs($outroUser);
        $response = $this->get(route('licao-evidencias.download', $evidencia));

        $response->assertOk();
    }

    public function test_download_de_evidencia_de_rascunho_nunca_e_corporativo_mesmo_com_permissao_em_outra_obra(): void
    {
        // Seção 26: rascunho/em validação nunca é tratado como
        // corporativo, mesmo que o usuário tenha 'ver' em OUTRA obra.
        $outroUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $outroUser, Papel::Admin->value);

        $licao = $this->licao();
        $evidencia = app(AnexarEvidenciaALicao::class)->execute($licao, $this->pdf(), $this->admin);

        $this->actingAs($outroUser);
        $response = $this->get(route('licao-evidencias.download', $evidencia));

        $response->assertRedirect();
        $response->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_download_cross_tenant_e_bloqueado_como_404(): void
    {
        $outroTenant = Tenant::factory()->create();
        $evidenciaOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $userOutroTenant = User::factory()->create(['tenant_id' => $outroTenant->id]);
            $licaoOutroTenant = app(CriarLicaoAprendida::class)->execute($obraOutroTenant, $userOutroTenant, [
                'titulo' => 'x',
                'situacao_observada' => 'x',
                'recomendacao_futura' => 'x',
                'tipo' => TipoLicaoAprendida::Problema->value,
                'criticidade' => CriticidadeLicao::Alta->value,
                'area_funcional' => AreaFuncionalLicao::Suprimentos->value,
            ]);

            return app(AnexarEvidenciaALicao::class)->execute($licaoOutroTenant, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), $userOutroTenant);
        });

        $response = $this->get(route('licao-evidencias.download', $evidenciaOutroTenant));

        $response->assertNotFound();
    }

    public function test_download_de_evidencia_inexistente_e_404(): void
    {
        $response = $this->get(route('licao-evidencias.download', (string) \Illuminate\Support\Str::ulid()));

        $response->assertNotFound();
    }

    // =========================================================================
    // REMOÇÃO — regras por status
    // =========================================================================

    public function test_remocao_permitida_em_rascunho_remove_registro_e_arquivo(): void
    {
        $licao = $this->licao();
        $evidencia = app(AnexarEvidenciaALicao::class)->execute($licao, $this->pdf(), $this->admin);
        $caminho = $evidencia->caminho_arquivo;

        app(RemoverEvidenciaDaLicao::class)->execute($evidencia);

        $this->assertSame(0, LicaoAprendidaEvidencia::count());
        Storage::disk(LicaoAprendidaEvidencia::DISCO)->assertMissing($caminho);
    }

    public function test_remocao_bloqueada_em_licao_publicada(): void
    {
        $licao = $this->licao();
        $evidencia = app(AnexarEvidenciaALicao::class)->execute($licao, $this->pdf(), $this->admin);
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->expectException(LicaoAprendidaImutavelException::class);

        app(RemoverEvidenciaDaLicao::class)->execute($evidencia->fresh());

        $this->assertSame(1, LicaoAprendidaEvidencia::count());
        Storage::disk(LicaoAprendidaEvidencia::DISCO)->assertExists($evidencia->caminho_arquivo);
    }

    public function test_remocao_bloqueada_em_licao_arquivada(): void
    {
        $licao = $this->licao();
        $evidencia = app(AnexarEvidenciaALicao::class)->execute($licao, $this->pdf(), $this->admin);
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);
        app(\App\Actions\LicoesAprendidas\ArquivarLicaoAprendida::class)->execute($licao, $this->admin);

        $this->expectException(LicaoAprendidaImutavelException::class);

        app(RemoverEvidenciaDaLicao::class)->execute($evidencia->fresh());
    }

    public function test_route_destroy_bloqueia_via_http_para_usuario_sem_permissao(): void
    {
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $licao = $this->licao();
        $evidencia = app(AnexarEvidenciaALicao::class)->execute($licao, $this->pdf(), $this->admin);

        $this->actingAs($semAcesso);
        $response = $this->delete(route('licao-evidencias.destroy', $evidencia));

        // Mesmo padrão já documentado (AtividadeAnexoController): 403 de
        // navegação de página cheia é interceptado por Handler::render()
        // e vira redirect + popup interno de acesso negado.
        $response->assertRedirect();
        $response->assertSessionHas('flash.popup', 'acesso-negado');
        $this->assertSame(1, LicaoAprendidaEvidencia::count());
    }

    public function test_route_destroy_funciona_para_usuario_autorizado(): void
    {
        $licao = $this->licao();
        $evidencia = app(AnexarEvidenciaALicao::class)->execute($licao, $this->pdf(), $this->admin);

        $response = $this->delete(route('licao-evidencias.destroy', $evidencia));

        $response->assertNoContent();
        $this->assertSame(0, LicaoAprendidaEvidencia::count());
    }

    // =========================================================================
    // ZERO EFEITO COLATERAL / STORAGE
    // =========================================================================

    public function test_disco_e_sempre_privado_local_nunca_public(): void
    {
        $this->assertSame('local', LicaoAprendidaEvidencia::DISCO);
    }

    public function test_upload_e_remocao_nunca_alteram_dominio_da_licao(): void
    {
        $licao = $this->licao();
        $tituloOriginal = $licao->titulo;
        $statusOriginal = $licao->status;

        $evidencia = app(AnexarEvidenciaALicao::class)->execute($licao, $this->pdf(), $this->admin);
        app(RemoverEvidenciaDaLicao::class)->execute($evidencia);

        $licao->refresh();
        $this->assertSame($tituloOriginal, $licao->titulo);
        $this->assertSame($statusOriginal, $licao->status);
        $this->assertSame(0, $licao->vinculos()->count());
    }
}
