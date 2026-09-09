<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Exports\MaterialImportTemplateExport;
use App\Imports\MaterialImporter;
use App\Models\FamiliaMaterial;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * BUG TARGETED — o template oficial gerado pelo botão "Baixar modelo"
 * nunca era aceito pelo próprio "Importar Excel": `MaterialImporter::
 * SHEET_NOME` era `'MATERIAIS'` (caixa alta) enquanto
 * `MaterialImportTemplateExport` sempre gerou a aba como `'Materiais'`
 * (Título) — `PhpSpreadsheet::getSheetByName()` é case-sensitive.
 * Reproduzido byte-a-byte gerando o arquivo real (bytes da aba
 * conferidos com `bin2hex()`) antes de qualquer correção.
 *
 * Corrigido tornando `MaterialImporter::SHEET_NOME` a ÚNICA autoridade —
 * `MaterialImportTemplateExport` agora referencia essa constante
 * diretamente, nunca um literal duplicado.
 *
 * ESTE ARQUIVO nunca constrói o .xlsx manualmente com um nome de aba
 * escolhido a dedo (isso é exatamente o que os testes anteriores de
 * `MaterialImporterTest`/`CadastrosMestresMaterialTest` faziam, cada um
 * isoladamente "certo" mas nunca provando que os dois lados eram
 * compatíveis entre si) — sempre baixa o template REAL via o mesmo
 * caminho do botão "Baixar modelo" (`⚡estoque.blade.php::
 * baixarModeloMaterial()`), escreve uma linha válida na aba real
 * devolvida, e alimenta ESSE MESMO arquivo de volta pro fluxo real de
 * "Importar Excel".
 */
class MaterialImportTemplateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $und;
    private FamiliaMaterial $tubulacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->und = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UND', 'nome' => 'Unidade']);
        $this->tubulacao = FamiliaMaterial::create(['tenant_id' => $this->tenant->id, 'nome' => 'TUBULAÇÃO']);
    }

    /**
     * Baixa o template REAL pelo mesmo caminho do botão "Baixar modelo"
     * (efeito de download do Livewire — nunca `new MaterialImportTemplateExport()`
     * chamada isolada, que provaria só "o exporter roda", não "o botão
     * real da tela devolve isto").
     */
    private function baixarTemplateReal(): string
    {
        $response = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('baixarModeloMaterial');

        $binario = base64_decode($response->effects['download']['content']);
        $caminho = tempnam(sys_get_temp_dir(), 'roundtrip_modelo_') . '.xlsx';
        file_put_contents($caminho, $binario);

        return $caminho;
    }

    /**
     * Escreve uma linha na aba de Materiais do arquivo REAL já baixado —
     * nunca cria um arquivo novo do zero, sempre edita o que veio do
     * exportador (prova que a estrutura/aba/cabeçalho gerados pelo
     * sistema são o que o usuário de fato preenche no Excel).
     */
    private function escreverLinhaNoTemplateReal(string $caminho, array $linha, int $linhaExcel = 3): string
    {
        $spreadsheet = (new XlsxReader())->load($caminho);
        $sheet = $spreadsheet->getSheetByName(MaterialImporter::SHEET_NOME);
        $this->assertNotNull($sheet, 'A aba "' . MaterialImporter::SHEET_NOME . '" não foi encontrada no template real — round-trip já quebra aqui.');

        $sheet->fromArray($linha, null, 'A' . $linhaExcel);

        $caminhoFinal = tempnam(sys_get_temp_dir(), 'roundtrip_preenchido_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($caminhoFinal);

        return $caminhoFinal;
    }

    /**
     * O TESTE OBRIGATÓRIO — nunca teria passado antes da correção
     * (falharia com a mesma RuntimeException vista no navegador).
     */
    public function test_round_trip_export_preencher_e_importar_o_mesmo_arquivo_real(): void
    {
        $caminhoBaixado = $this->baixarTemplateReal();

        // Confirma, com o arquivo REAL, exatamente o sintoma relatado no
        // navegador — sem esta correção, esta chamada lançaria
        // RuntimeException("A planilha não tem uma aba chamada...").
        $linhas = (new MaterialImporter())->lerLinhas($caminhoBaixado);
        $this->assertNotEmpty($linhas, 'lerLinhas() no template real baixado não encontrou nem a linha de exemplo — a aba não foi reconhecida.');

        $caminhoPreenchido = $this->escreverLinhaNoTemplateReal($caminhoBaixado, [
            'MAT-TESTE-001', 'Material teste importação', 'UND', 'TUBULAÇÃO', 'quantitativo',
        ]);

        $importador = new MaterialImporter();
        $linhasLidas = $importador->lerLinhas($caminhoPreenchido);
        $minhaLinha = collect($linhasLidas)->firstWhere('codigo', 'MAT-TESTE-001');
        $this->assertNotNull($minhaLinha, 'A linha preenchida no arquivo real não foi lida pelo importador.');

        $analise = $importador->analisar($linhasLidas);
        $linhaAnalisada = collect($analise['linhas'])->firstWhere('codigo', 'MAT-TESTE-001');

        $this->assertSame('valida', $linhaAnalisada['status']);
        $this->assertSame($this->und->id, $linhaAnalisada['unidade_id']);
        $this->assertSame($this->tubulacao->id, $linhaAnalisada['familia_id']);
        $this->assertSame('quantitativo', $linhaAnalisada['modo_rastreabilidade']);
        $this->assertSame([], $linhaAnalisada['erros']);

        // Análise nunca persiste — nem o exemplo do template, nem a linha preenchida.
        $this->assertSame(0, Material::count());

        $resultado = $importador->aplicar($analise['linhas']);
        $this->assertSame(1, $resultado['criados']);

        $material = Material::where('codigo', 'MAT-TESTE-001')->firstOrFail();
        $this->assertSame($this->und->id, $material->unidade_medida_id);
        $this->assertSame($this->tubulacao->id, $material->familia_material_id);
        $this->assertSame($this->tenant->id, $material->tenant_id);

        // Nunca cria Unidade/Família implicitamente durante o round-trip.
        $this->assertSame(1, UnidadeMedida::count());
        $this->assertSame(1, FamiliaMaterial::count());

        @unlink($caminhoBaixado);
        @unlink($caminhoPreenchido);
    }

    /**
     * Mesmo cenário, mas ponta-a-ponta pelo componente Livewire real
     * (upload → analisar → prévia → confirmar) — o caminho que o
     * navegador de fato exercita, não só as duas fases isoladas.
     */
    public function test_round_trip_fluxo_completo_via_ui_confirmando_a_importacao(): void
    {
        $caminhoBaixado = $this->baixarTemplateReal();
        $caminhoPreenchido = $this->escreverLinhaNoTemplateReal($caminhoBaixado, [
            'MAT-TESTE-002', 'Segundo material via UI', 'UND', '', 'quantitativo',
        ]);

        $arquivo = UploadedFile::fake()->createWithContent('materiais-preenchido.xlsx', file_get_contents($caminhoPreenchido));

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirImportacaoMaterial')
            ->set('arquivoImportacaoMaterial', $arquivo)
            ->call('analisarImportacaoMaterial')
            ->assertHasNoErrors('arquivoImportacaoMaterial')
            // A linha de exemplo EX-001 do template usa "UN" como placeholder
            // de Unidade (App\Exports\MaterialImportTemplateExport::folhaMateriais()) —
            // este tenant só tem "UND" cadastrada, então EX-001 fica
            // corretamente INVÁLIDA (unidade não encontrada, nunca aceita
            // silenciosamente); só MAT-TESTE-002 (que usa "UND", a unidade
            // real do tenant) é válida.
            ->assertSet('previaImportacaoMaterial.resumo.validas', 1)
            ->assertSet('previaImportacaoMaterial.resumo.invalidas', 1)
            ->call('confirmarImportacaoMaterial')
            ->assertSet('importModalMaterialAberto', false);

        $material = Material::where('codigo', 'MAT-TESTE-002')->first();
        $this->assertNotNull($material, 'Material da linha preenchida no template real não foi criado pela UI.');
        $this->assertSame($this->und->id, $material->unidade_medida_id);
        $this->assertNull($material->familia_material_id);

        @unlink($caminhoBaixado);
        @unlink($caminhoPreenchido);
    }

    /**
     * Confirma o conteúdo do template oficial (Seção 5 do pedido):
     * Unidades válidas/Famílias válidas refletem o tenant ATUAL, e a aba
     * Materiais tem exatamente os cabeçalhos que o importador espera.
     */
    public function test_template_real_contem_as_4_abas_com_dados_do_tenant_atual(): void
    {
        $caminho = $this->baixarTemplateReal();
        $spreadsheet = (new XlsxReader())->load($caminho);

        $titulos = array_map(fn ($s) => $s->getTitle(), $spreadsheet->getAllSheets());
        $this->assertSame([MaterialImporter::SHEET_NOME, 'Unidades válidas', 'Famílias válidas', 'Instruções'], $titulos);

        $cabecalhoMateriais = $spreadsheet->getSheetByName(MaterialImporter::SHEET_NOME)
            ->rangeToArray('A1:E1')[0];
        $this->assertSame(['Código', 'Descrição', 'Unidade', 'Família', 'Modo de Rastreabilidade'], $cabecalhoMateriais);

        $unidadesValidas = $spreadsheet->getSheetByName('Unidades válidas')->toArray();
        $this->assertContains('UND', collect($unidadesValidas)->flatten()->all());

        $familiasValidas = $spreadsheet->getSheetByName('Famílias válidas')->toArray();
        $this->assertContains('TUBULAÇÃO', collect($familiasValidas)->flatten()->all());

        @unlink($caminho);
    }

    /**
     * Testes negativos (Seção 7) — sempre sobre o arquivo REAL baixado,
     * nunca um .xlsx construído do zero, pra continuar provando
     * compatibilidade real entre exporter e importer em cada cenário.
     */
    public function test_negativo_unidade_inexistente_no_arquivo_real(): void
    {
        $caminho = $this->baixarTemplateReal();
        $preenchido = $this->escreverLinhaNoTemplateReal($caminho, ['MAT-N1', 'X', 'INEXISTENTE', '', '']);

        $importador = new MaterialImporter();
        $analise = $importador->analisar($importador->lerLinhas($preenchido));
        $linha = collect($analise['linhas'])->firstWhere('codigo', 'MAT-N1');

        $this->assertSame('invalida', $linha['status']);
        $this->assertStringContainsString('não encontrada', $linha['erros'][0]);

        @unlink($caminho);
        @unlink($preenchido);
    }

    public function test_negativo_familia_inexistente_no_arquivo_real(): void
    {
        $caminho = $this->baixarTemplateReal();
        $preenchido = $this->escreverLinhaNoTemplateReal($caminho, ['MAT-N2', 'X', 'UND', 'FAMILIA-QUE-NAO-EXISTE', '']);

        $importador = new MaterialImporter();
        $analise = $importador->analisar($importador->lerLinhas($preenchido));
        $linha = collect($analise['linhas'])->firstWhere('codigo', 'MAT-N2');

        $this->assertSame('invalida', $linha['status']);

        @unlink($caminho);
        @unlink($preenchido);
    }

    public function test_negativo_familia_vazia_e_permitida_no_arquivo_real(): void
    {
        $caminho = $this->baixarTemplateReal();
        $preenchido = $this->escreverLinhaNoTemplateReal($caminho, ['MAT-N3', 'X', 'UND', '', '']);

        $importador = new MaterialImporter();
        $analise = $importador->analisar($importador->lerLinhas($preenchido));
        $linha = collect($analise['linhas'])->firstWhere('codigo', 'MAT-N3');

        $this->assertSame('valida', $linha['status']);
        $this->assertNull($linha['familia_id']);

        @unlink($caminho);
        @unlink($preenchido);
    }

    public function test_negativo_codigo_duplicado_no_arquivo_real(): void
    {
        $caminho = $this->baixarTemplateReal();
        $spreadsheet = (new XlsxReader())->load($caminho);
        $sheet = $spreadsheet->getSheetByName(MaterialImporter::SHEET_NOME);
        $sheet->fromArray(['MAT-N4', 'Primeira', 'UND', '', ''], null, 'A3');
        $sheet->fromArray(['MAT-N4', 'Segunda (duplicada)', 'UND', '', ''], null, 'A4');
        $preenchido = tempnam(sys_get_temp_dir(), 'roundtrip_dup_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($preenchido);

        $importador = new MaterialImporter();
        $analise = $importador->analisar($importador->lerLinhas($preenchido));

        $this->assertSame(1, $analise['resumo']['duplicadas_no_arquivo']);

        @unlink($caminho);
        @unlink($preenchido);
    }

    public function test_negativo_codigo_ja_existente_no_catalogo_vira_conflito_no_arquivo_real(): void
    {
        Material::create([
            'codigo' => 'MAT-N5',
            'descricao' => 'Já cadastrado',
            'unidade_medida_id' => $this->und->id,
            'modo_rastreabilidade' => \App\Enums\ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);

        $caminho = $this->baixarTemplateReal();
        $preenchido = $this->escreverLinhaNoTemplateReal($caminho, ['MAT-N5', 'Tentativa de sobrescrever', 'UND', '', '']);

        $importador = new MaterialImporter();
        $analise = $importador->analisar($importador->lerLinhas($preenchido));
        $linha = collect($analise['linhas'])->firstWhere('codigo', 'MAT-N5');

        $this->assertSame('conflito', $linha['status']);

        @unlink($caminho);
        @unlink($preenchido);
    }

    public function test_negativo_modo_rastreabilidade_invalido_no_arquivo_real(): void
    {
        $caminho = $this->baixarTemplateReal();
        $preenchido = $this->escreverLinhaNoTemplateReal($caminho, ['MAT-N6', 'X', 'UND', '', 'modo-que-nao-existe']);

        $importador = new MaterialImporter();
        $analise = $importador->analisar($importador->lerLinhas($preenchido));
        $linha = collect($analise['linhas'])->firstWhere('codigo', 'MAT-N6');

        $this->assertSame('invalida', $linha['status']);

        @unlink($caminho);
        @unlink($preenchido);
    }

    public function test_negativo_arquivo_sem_aba_materiais_continua_erro_estrutural_amigavel(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('ABA_ERRADA');
        $caminho = tempnam(sys_get_temp_dir(), 'sem_aba_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($caminho);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A planilha não tem uma aba chamada "' . MaterialImporter::SHEET_NOME . '"');

        (new MaterialImporter())->lerLinhas($caminho);

        @unlink($caminho);
    }

    public function test_negativo_analise_nunca_persiste_mesmo_com_linhas_mistas_validas_e_invalidas(): void
    {
        $caminho = $this->baixarTemplateReal();
        $spreadsheet = (new XlsxReader())->load($caminho);
        $sheet = $spreadsheet->getSheetByName(MaterialImporter::SHEET_NOME);
        $sheet->fromArray(['MAT-N7', 'Válida', 'UND', '', ''], null, 'A3');
        $sheet->fromArray(['MAT-N8', 'Inválida', 'INEXISTENTE', '', ''], null, 'A4');
        $preenchido = tempnam(sys_get_temp_dir(), 'roundtrip_misto_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($preenchido);

        $importador = new MaterialImporter();
        $importador->analisar($importador->lerLinhas($preenchido));

        $this->assertSame(0, Material::count(), 'analisar() nunca deve escrever no banco, mesmo com linhas válidas presentes.');

        @unlink($caminho);
        @unlink($preenchido);
    }

    /**
     * UX após erro estrutural (Seção 4) — mostra mensagem clara via
     * addError(), nunca quebra a página, nunca persiste nada.
     */
    public function test_ui_mostra_erro_amigavel_para_arquivo_sem_aba_materiais_sem_persistir_nada(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('ABA_ERRADA');
        $caminho = tempnam(sys_get_temp_dir(), 'sem_aba_ui_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($caminho);

        $arquivo = UploadedFile::fake()->createWithContent('arquivo-errado.xlsx', file_get_contents($caminho));

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirImportacaoMaterial')
            ->set('arquivoImportacaoMaterial', $arquivo)
            ->call('analisarImportacaoMaterial')
            ->assertHasErrors(['arquivoImportacaoMaterial'])
            ->assertSet('previaImportacaoMaterial', null);

        $this->assertSame(0, Material::count());

        @unlink($caminho);
    }
}
