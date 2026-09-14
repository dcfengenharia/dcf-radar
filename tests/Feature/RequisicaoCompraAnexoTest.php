<?php

namespace Tests\Feature;

use App\Actions\Suprimentos\AnexarDocumentoRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\RemoverAnexoRequisicaoCompra;
use App\Enums\Papel;
use App\Enums\TipoDocumentoRequisicaoCompra;
use App\Exceptions\RequisicaoCompraAnexoInvalidoException;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraAnexo;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Etapa 2 (Dossiê Documental da RC) — cobertura da Seção 37 do pedido
 * (U-AE), mesmo padrão de storage/teste já usado em `AtividadeAnexoTest`.
 */
class RequisicaoCompraAnexoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private RequisicaoCompra $rc;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(RequisicaoCompraAnexo::DISCO);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote', 'codigo' => 'PAC-1']);
        $this->rc = (new CriarRequisicaoCompra())->execute($pacote, null, null, $this->user);
    }

    private function criarFornecedor(?Work $obra = null): Fornecedor
    {
        return Fornecedor::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Fornecedor ' . uniqid()]);
    }

    private function pdfFake(string $nome = 'proposta.pdf', int $tamanhoKb = 100): UploadedFile
    {
        return UploadedFile::fake()->create($nome, $tamanhoKb, 'application/pdf');
    }

    public function test_u_anexar_proposta(): void
    {
        $anexo = (new AnexarDocumentoRequisicaoCompra())->execute(
            $this->rc,
            ['tipo_documento' => TipoDocumentoRequisicaoCompra::Proposta->value, 'descricao' => 'Proposta comercial'],
            $this->pdfFake('proposta.pdf'),
            null,
            $this->user,
        );

        $this->assertSame(TipoDocumentoRequisicaoCompra::Proposta, $anexo->tipo_documento);
        $this->assertDatabaseHas('requisicao_compra_anexos', ['id' => $anexo->id, 'requisicao_compra_id' => $this->rc->id]);
    }

    public function test_v_anexar_contrato(): void
    {
        $anexo = (new AnexarDocumentoRequisicaoCompra())->execute(
            $this->rc,
            ['tipo_documento' => TipoDocumentoRequisicaoCompra::Contrato->value, 'descricao' => null],
            $this->pdfFake('contrato.pdf'),
            null,
            $this->user,
        );

        $this->assertSame(TipoDocumentoRequisicaoCompra::Contrato, $anexo->tipo_documento);
    }

    public function test_w_anexo_com_fornecedor_opcional(): void
    {
        $semFornecedor = (new AnexarDocumentoRequisicaoCompra())->execute(
            $this->rc, ['tipo_documento' => 'outro', 'descricao' => null], $this->pdfFake('a.pdf'), null, $this->user,
        );
        $fornecedor = $this->criarFornecedor();
        $comFornecedor = (new AnexarDocumentoRequisicaoCompra())->execute(
            $this->rc, ['tipo_documento' => 'proposta', 'descricao' => null], $this->pdfFake('b.pdf'), $fornecedor, $this->user,
        );

        $this->assertNull($semFornecedor->fornecedor_id);
        $this->assertSame($fornecedor->id, $comFornecedor->fornecedor_id);
    }

    public function test_x_multiplos_anexos_mesmo_fornecedor(): void
    {
        $fornecedor = $this->criarFornecedor();
        $action = new AnexarDocumentoRequisicaoCompra();

        $a1 = $action->execute($this->rc, ['tipo_documento' => 'proposta', 'descricao' => null], $this->pdfFake('a.pdf'), $fornecedor, $this->user);
        $a2 = $action->execute($this->rc, ['tipo_documento' => 'contrato', 'descricao' => null], $this->pdfFake('b.pdf'), $fornecedor, $this->user);
        $a3 = $action->execute($this->rc, ['tipo_documento' => 'correspondencia', 'descricao' => null], $this->pdfFake('c.pdf'), $fornecedor, $this->user);

        $this->assertSame(3, RequisicaoCompraAnexo::where('fornecedor_id', $fornecedor->id)->count());
        $this->assertNotEquals($a1->id, $a2->id);
        $this->assertNotEquals($a2->id, $a3->id);
    }

    public function test_y_substituicao_cria_nova_versao_e_preserva_anterior(): void
    {
        $action = new AnexarDocumentoRequisicaoCompra();
        $original = $action->execute($this->rc, ['tipo_documento' => 'proposta', 'descricao' => 'v1'], $this->pdfFake('v1.pdf'), null, $this->user);

        $novaVersao = $action->execute(
            $this->rc,
            ['tipo_documento' => 'proposta', 'descricao' => 'v2', 'substitui_anexo_id' => $original->id],
            $this->pdfFake('v2.pdf'),
            null,
            $this->user,
        );

        $this->assertSame($original->id, $novaVersao->substitui_anexo_id);
        $this->assertDatabaseHas('requisicao_compra_anexos', ['id' => $original->id]);
        Storage::disk(RequisicaoCompraAnexo::DISCO)->assertExists($original->caminho_arquivo);
        Storage::disk(RequisicaoCompraAnexo::DISCO)->assertExists($novaVersao->caminho_arquivo);
        $this->assertNotEquals($original->caminho_arquivo, $novaVersao->caminho_arquivo);

        // a versão anterior, agora referenciada, não pode ser excluída
        $this->expectException(RequisicaoCompraAnexoInvalidoException::class);
        (new RemoverAnexoRequisicaoCompra())->execute($original);
    }

    public function test_z_arquivo_privado_nunca_no_disco_publico(): void
    {
        $anexo = (new AnexarDocumentoRequisicaoCompra())->execute(
            $this->rc, ['tipo_documento' => 'proposta', 'descricao' => null], $this->pdfFake('a.pdf'), null, $this->user,
        );

        $this->assertSame('local', RequisicaoCompraAnexo::DISCO);
        Storage::disk('local')->assertExists($anexo->caminho_arquivo);
    }

    public function test_aa_download_cross_tenant_falha(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroUsuario = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $outroUsuario, Papel::GerentePlanejamento->value);

        $anexo = (new AnexarDocumentoRequisicaoCompra())->execute(
            $this->rc, ['tipo_documento' => 'proposta', 'descricao' => null], $this->pdfFake('a.pdf'), null, $this->user,
        );

        $this->actingAs($outroUsuario);
        // route-model-binding + BelongsToTenant já torna o anexo invisível
        // pra outro tenant — a rota resolve 404 antes mesmo da checagem de
        // permissão.
        $this->get(route('requisicao-compra-anexos.download', $anexo))->assertNotFound();
    }

    public function test_ab_rc_de_outra_obra_nao_acessa_anexo(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outroUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $outroUsuario, Papel::GerentePlanejamento->value);

        $anexo = (new AnexarDocumentoRequisicaoCompra())->execute(
            $this->rc, ['tipo_documento' => 'proposta', 'descricao' => null], $this->pdfFake('a.pdf'), null, $this->user,
        );

        $this->actingAs($outroUsuario);

        // Handler::render() intercepta 403 de navegação de página cheia e
        // redireciona pro popup interno de acesso negado (mesmo mecanismo
        // já documentado no projeto) — nunca um 403 cru nesse caminho.
        $this->get(route('requisicao-compra-anexos.download', $anexo))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_ac_tipo_mime_invalido_rejeitado(): void
    {
        $this->expectException(ValidationException::class);

        try {
            (new AnexarDocumentoRequisicaoCompra())->execute(
                $this->rc,
                ['tipo_documento' => 'proposta', 'descricao' => null],
                UploadedFile::fake()->create('malware.exe', 100),
                null,
                $this->user,
            );
        } finally {
            $this->assertEquals(0, RequisicaoCompraAnexo::count());
            $this->assertEmpty(Storage::disk(RequisicaoCompraAnexo::DISCO)->allFiles());
        }
    }

    public function test_ad_tamanho_excedido_rejeitado(): void
    {
        $tamanhoAcimaDoLimite = (RequisicaoCompraAnexo::TAMANHO_MAXIMO_KB + 1);

        $this->expectException(ValidationException::class);

        try {
            (new AnexarDocumentoRequisicaoCompra())->execute(
                $this->rc,
                ['tipo_documento' => 'proposta', 'descricao' => null],
                UploadedFile::fake()->create('grande.pdf', $tamanhoAcimaDoLimite, 'application/pdf'),
                null,
                $this->user,
            );
        } finally {
            $this->assertEquals(0, RequisicaoCompraAnexo::count());
            $this->assertEmpty(Storage::disk(RequisicaoCompraAnexo::DISCO)->allFiles());
        }
    }

    public function test_ae_tipo_invalido_e_rejeitado_antes_do_upload_zero_arquivo_orfao(): void
    {
        // Tipo de documento inválido é rejeitado ANTES de qualquer escrita
        // em storage (guard de validação roda primeiro) — nunca deixa um
        // arquivo órfão sem registro correspondente.
        $this->expectException(\InvalidArgumentException::class);

        try {
            (new AnexarDocumentoRequisicaoCompra())->execute(
                $this->rc,
                ['tipo_documento' => 'tipo_que_nao_existe', 'descricao' => null],
                $this->pdfFake('a.pdf'),
                null,
                $this->user,
            );
        } finally {
            $this->assertEquals(0, RequisicaoCompraAnexo::count());
            $this->assertEmpty(Storage::disk(RequisicaoCompraAnexo::DISCO)->allFiles());
        }
    }

    public function test_ae2_falha_de_persistencia_apos_upload_compensa_arquivo_gravado(): void
    {
        // Nome de arquivo além do limite da coluna `nome_original` (string,
        // 255) força uma falha REAL de INSERT (banco em modo estrito)
        // DEPOIS que o arquivo já foi gravado no storage — exercita a
        // compensação de verdade, não apenas um caminho de validação
        // anterior ao upload.
        $nomeMuitoLongo = str_repeat('a', 300) . '.pdf';

        try {
            (new AnexarDocumentoRequisicaoCompra())->execute(
                $this->rc,
                ['tipo_documento' => 'proposta', 'descricao' => null],
                $this->pdfFake($nomeMuitoLongo),
                null,
                $this->user,
            );
            $this->fail('Deveria ter lançado uma exceção de banco (nome_original excede o limite da coluna).');
        } catch (\Throwable $e) {
            $this->assertEquals(0, RequisicaoCompraAnexo::count());
            $this->assertEmpty(Storage::disk(RequisicaoCompraAnexo::DISCO)->allFiles());
        }
    }

    public function test_fornecedor_de_outra_obra_e_rejeitado(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $fornecedorDeOutraObra = $this->criarFornecedor($outraObra);

        $this->expectException(\InvalidArgumentException::class);
        (new AnexarDocumentoRequisicaoCompra())->execute(
            $this->rc, ['tipo_documento' => 'proposta', 'descricao' => null], $this->pdfFake('a.pdf'), $fornecedorDeOutraObra, $this->user,
        );
    }

    public function test_remover_anexo_sem_referencia_funciona_e_apaga_arquivo(): void
    {
        $anexo = (new AnexarDocumentoRequisicaoCompra())->execute(
            $this->rc, ['tipo_documento' => 'outro', 'descricao' => null], $this->pdfFake('a.pdf'), null, $this->user,
        );

        (new RemoverAnexoRequisicaoCompra())->execute($anexo);

        $this->assertDatabaseMissing('requisicao_compra_anexos', ['id' => $anexo->id]);
        Storage::disk(RequisicaoCompraAnexo::DISCO)->assertMissing($anexo->caminho_arquivo);
    }
}
