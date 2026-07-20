<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Imports\DocumentoEngenhariaImporter;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\PerfilPermissao;
use App\Models\StatusDocumento;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ImportarDocumentosEngenhariaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);

        // Disciplinas e status já conhecidos — o fixture usa "DISCIPLINA-NOVA"
        // e "STATUS-INEXISTENTE" de propósito pra testar o caminho de aviso.
        Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'CIVIL']);
        Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'MECANICA']);
        StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Pendente de Aprovação', 'codigo' => 'PA']);
        StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Aprovado', 'codigo' => 'AP', 'conclusivo' => true]);
    }

    private function caminhoFixtureLd(): string
    {
        return base_path('tests/Fixtures/lista_documentos_ld.xlsx');
    }

    private function caminhoFixtureSemLd(): string
    {
        return base_path('tests/Fixtures/lista_documentos_sem_ld.xlsx');
    }

    public function test_analisar_planilha_conta_novos_atualizados_revisoes_e_avisos(): void
    {
        $importador = new DocumentoEngenhariaImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixtureLd());

        // 6 linhas na planilha, mas DOC-005 aparece 2x -> 5 códigos distintos.
        $this->assertCount(6, $linhas);

        $previa = $importador->analisar($linhas, $this->obra->id);

        $this->assertSame(5, $previa['novos']);
        $this->assertSame(0, $previa['atualizados']);
        // DOC-001(A), DOC-002(1), DOC-003(A), DOC-005(C, dedup) têm revisão; DOC-004 não tem.
        $this->assertSame(4, $previa['novas_revisoes']);

        $avisos = implode(' | ', $previa['avisos']);
        $this->assertStringContainsString('DISCIPLINA-NOVA', $avisos);
        $this->assertStringContainsString('STATUS-INEXISTENTE', $avisos);
        $this->assertStringContainsString('DOC-005', $avisos);
    }

    public function test_aplicar_planilha_cria_documentos_disciplina_automatica_e_revisoes(): void
    {
        $importador = new DocumentoEngenhariaImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixtureLd());
        $previa = $importador->analisar($linhas, $this->obra->id);

        $resultado = $importador->aplicar($previa['linhas'], $this->obra->id, $this->user->id);

        $this->assertSame(5, $resultado['novos']);
        $this->assertSame(0, $resultado['atualizados']);
        $this->assertSame(4, $resultado['novas_revisoes']);

        $this->assertDatabaseHas('documentos_engenharia', ['obra_id' => $this->obra->id, 'codigo' => 'DOC-001']);

        // Código duplicado: a última ocorrência da planilha prevalece.
        $doc005 = DocumentoEngenharia::where('obra_id', $this->obra->id)->where('codigo', 'DOC-005')->firstOrFail();
        $this->assertSame('Código Duplicado — Segunda Ocorrência (deve prevalecer)', $doc005->descricao);
        $this->assertSame('C', $doc005->revisoes()->first()->revisao);

        // Disciplina desconhecida foi criada automaticamente.
        $doc003 = DocumentoEngenharia::where('obra_id', $this->obra->id)->where('codigo', 'DOC-003')->firstOrFail();
        $this->assertDatabaseHas('disciplinas', ['tenant_id' => $this->tenant->id, 'nome' => 'DISCIPLINA-NOVA']);
        $this->assertNotNull($doc003->disciplina_id);

        // Status desconhecido não foi inventado — documento fica sem status.
        $this->assertNull($doc003->status_documento_id);

        // Documento sem revisão na planilha não ganha revisão nenhuma.
        $doc004 = DocumentoEngenharia::where('obra_id', $this->obra->id)->where('codigo', 'DOC-004')->firstOrFail();
        $this->assertSame(0, $doc004->revisoes()->count());
    }

    public function test_reimportar_mesma_revisao_nao_duplica_historico(): void
    {
        $importador = new DocumentoEngenhariaImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixtureLd());
        $previa = $importador->analisar($linhas, $this->obra->id);
        $importador->aplicar($previa['linhas'], $this->obra->id, $this->user->id);

        // Reimporta a mesma planilha — código já existe, revisão igual.
        $previa2 = $importador->analisar($linhas, $this->obra->id);
        $this->assertSame(0, $previa2['novos']);
        $this->assertSame(5, $previa2['atualizados']);
        $this->assertSame(0, $previa2['novas_revisoes']);

        $resultado2 = $importador->aplicar($previa2['linhas'], $this->obra->id, $this->user->id);
        $this->assertSame(0, $resultado2['novas_revisoes']);

        $doc001 = DocumentoEngenharia::where('obra_id', $this->obra->id)->where('codigo', 'DOC-001')->firstOrFail();
        $this->assertSame(1, $doc001->revisoes()->count());
    }

    public function test_reimportar_com_revisao_diferente_cria_nova_revisao_sem_apagar_a_antiga(): void
    {
        $importador = new DocumentoEngenhariaImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixtureLd());
        $previa = $importador->analisar($linhas, $this->obra->id);
        $importador->aplicar($previa['linhas'], $this->obra->id, $this->user->id);

        $doc001 = DocumentoEngenharia::where('obra_id', $this->obra->id)->where('codigo', 'DOC-001')->firstOrFail();
        $this->assertSame('A', $doc001->revisoes()->first()->revisao);

        // Simula uma nova rodada de planilha com revisão bumped pra "B".
        $linhasAtualizadas = collect($previa['linhas'])->map(function ($linha) {
            if ($linha['codigo'] === 'DOC-001') {
                $linha['revisao'] = 'B';
            }
            return $linha;
        })->all();

        $resultado = $importador->aplicar($linhasAtualizadas, $this->obra->id, $this->user->id);
        $this->assertSame(1, $resultado['novas_revisoes']);

        $doc001 = $doc001->fresh(['revisoes']);
        $this->assertSame(2, $doc001->revisoes->count());
        $this->assertSame('B', $doc001->revisoes->first()->revisao);
        $this->assertTrue($doc001->revisoes->contains('revisao', 'A'));
    }

    public function test_planilha_sem_aba_ld_lanca_excecao_clara(): void
    {
        $importador = new DocumentoEngenhariaImporter();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LD');

        $importador->lerLinhas($this->caminhoFixtureSemLd());
    }

    public function test_wizard_completo_via_livewire_analisar_e_confirmar(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);

        $arquivo = UploadedFile::fake()->createWithContent(
            'lista_documentos_ld.xlsx',
            file_get_contents($this->caminhoFixtureLd())
        );

        Livewire::test('pages::engenharia.documentos-engenharia')
            ->set('obraId', $this->obra->id)
            ->call('abrirImportar')
            ->set('arquivoImportacao', $arquivo)
            ->call('analisarImportacao')
            ->assertHasNoErrors()
            ->assertSet('previaImportacao.novos', 5)
            ->call('confirmarImportacao')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('documentos_engenharia', ['obra_id' => $this->obra->id, 'codigo' => 'DOC-001']);
        $this->assertSame(5, DocumentoEngenharia::where('obra_id', $this->obra->id)->count());
    }

    public function test_perfil_sem_permissao_nao_consegue_importar(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'engenharia.pacotes')
            ->delete();

        Livewire::test('pages::engenharia.documentos-engenharia')
            ->set('obraId', $this->obra->id)
            ->call('abrirImportar')
            ->assertForbidden();
    }
}
