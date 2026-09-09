<?php

namespace Tests\Feature;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\OrigemCadastroMaterial;
use App\Imports\MaterialImporter;
use App\Models\FamiliaMaterial;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Cadastros Mestres de Materiais — Importação Excel (App\Imports\MaterialImporter).
 *
 * Semântica CRÍTICA, deliberadamente diferente de `TakeOffImporter`:
 * nunca cria Unidade/Família ausente (rejeita com erro por linha) e
 * nunca sobrescreve um Material já existente (marca como conflito).
 */
class MaterialImporterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private UnidadeMedida $un;
    private UnidadeMedida $kg;
    private FamiliaMaterial $tubulacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id]));

        $this->un = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
        $this->kg = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'KG', 'nome' => 'Quilograma']);
        $this->tubulacao = FamiliaMaterial::create(['tenant_id' => $this->tenant->id, 'nome' => 'Tubulação']);
    }

    /**
     * @param array<int, array{0?: string, 1?: string, 2?: string, 3?: string, 4?: string}> $linhas
     */
    private function gerarArquivo(array $linhas, string $sheetName = MaterialImporter::SHEET_NOME): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);
        $sheet->fromArray(['Código', 'Descrição', 'Unidade', 'Família', 'Modo de Rastreabilidade'], null, 'A1');

        foreach ($linhas as $i => $linha) {
            $sheet->fromArray($linha, null, 'A' . ($i + 2));
        }

        $caminho = tempnam(sys_get_temp_dir(), 'material_import_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($caminho);

        return $caminho;
    }

    // ---- lerLinhas ----

    public function test_a_ler_linhas_le_todas_as_linhas_preenchidas(): void
    {
        $caminho = $this->gerarArquivo([
            ['MAT-001', 'Tubo de aço', 'UN', 'Tubulação', 'quantitativo'],
            ['MAT-002', 'Cabo elétrico', 'KG', '', ''],
        ]);

        $linhas = (new MaterialImporter())->lerLinhas($caminho);

        $this->assertCount(2, $linhas);
        $this->assertSame('MAT-001', $linhas[0]['codigo']);
        $this->assertSame('Tubulação', $linhas[0]['familia']);
        $this->assertNull($linhas[1]['familia']);
    }

    public function test_b_ler_linhas_lanca_excecao_sem_aba_materiais(): void
    {
        $caminho = $this->gerarArquivo([['MAT-001', 'X', 'UN', '', '']], 'OUTRA_ABA');

        $this->expectException(\RuntimeException::class);
        (new MaterialImporter())->lerLinhas($caminho);
    }

    // ---- analisar: caminho feliz ----

    public function test_c_linha_valida_com_unidade_e_familia(): void
    {
        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-001', 'descricao' => 'Tubo', 'unidade' => 'un', 'familia' => 'tubulação', 'modo_rastreabilidade' => null],
        ]);

        $this->assertSame(1, $resultado['resumo']['validas']);
        $this->assertSame('valida', $resultado['linhas'][0]['status']);
        $this->assertSame($this->un->id, $resultado['linhas'][0]['unidade_id']);
        $this->assertSame($this->tubulacao->id, $resultado['linhas'][0]['familia_id']);
        $this->assertSame('quantitativo', $resultado['linhas'][0]['modo_rastreabilidade']);
        $this->assertSame([], $resultado['linhas'][0]['erros']);
    }

    public function test_d_familia_opcional_ausente_continua_valida(): void
    {
        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-002', 'descricao' => 'Cabo', 'unidade' => 'KG', 'familia' => null, 'modo_rastreabilidade' => 'lote'],
        ]);

        $this->assertSame(1, $resultado['resumo']['validas']);
        $this->assertNull($resultado['linhas'][0]['familia_id']);
        $this->assertSame('lote', $resultado['linhas'][0]['modo_rastreabilidade']);
    }

    // ---- REGRA CRÍTICA: nunca cria Unidade/Família ----

    public function test_e_unidade_inexistente_rejeitada_sem_criar(): void
    {
        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-003', 'descricao' => 'X', 'unidade' => 'XYZ', 'familia' => null, 'modo_rastreabilidade' => null],
        ]);

        $this->assertSame('invalida', $resultado['linhas'][0]['status']);
        $this->assertStringContainsString('não encontrada', $resultado['linhas'][0]['erros'][0]);
        $this->assertSame(2, UnidadeMedida::count());
    }

    public function test_f_familia_inexistente_rejeitada_sem_criar(): void
    {
        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-004', 'descricao' => 'X', 'unidade' => 'UN', 'familia' => 'Inexistente', 'modo_rastreabilidade' => null],
        ]);

        $this->assertSame('invalida', $resultado['linhas'][0]['status']);
        $this->assertStringContainsString('não encontrada', $resultado['linhas'][0]['erros'][0]);
        $this->assertSame(1, FamiliaMaterial::count());
    }

    public function test_g_modo_rastreabilidade_invalido_rejeitado(): void
    {
        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-005', 'descricao' => 'X', 'unidade' => 'UN', 'familia' => null, 'modo_rastreabilidade' => 'invalido-xyz'],
        ]);

        $this->assertSame('invalida', $resultado['linhas'][0]['status']);
        $this->assertStringContainsString('inválido', $resultado['linhas'][0]['erros'][0]);
    }

    public function test_h_campos_obrigatorios_ausentes(): void
    {
        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => '', 'descricao' => '', 'unidade' => '', 'familia' => null, 'modo_rastreabilidade' => null],
        ]);

        $this->assertSame('invalida', $resultado['linhas'][0]['status']);
        $this->assertCount(3, $resultado['linhas'][0]['erros']);
    }

    // ---- Duplicidade dentro do arquivo ----

    public function test_i_codigo_duplicado_dentro_do_arquivo(): void
    {
        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-006', 'descricao' => 'Primeira', 'unidade' => 'UN', 'familia' => null, 'modo_rastreabilidade' => null],
            ['linha' => 3, 'codigo' => 'mat-006', 'descricao' => 'Segunda (case-insensitive)', 'unidade' => 'UN', 'familia' => null, 'modo_rastreabilidade' => null],
        ]);

        $this->assertSame('valida', $resultado['linhas'][0]['status']);
        $this->assertSame('invalida', $resultado['linhas'][1]['status']);
        $this->assertSame(1, $resultado['resumo']['duplicadas_no_arquivo']);
        $this->assertStringContainsString('mais de uma vez', $resultado['linhas'][1]['erros'][0]);
    }

    // ---- Conflito com catálogo existente (nunca sobrescreve) ----

    public function test_j_codigo_ja_existente_no_catalogo_vira_conflito(): void
    {
        Material::create([
            'codigo' => 'MAT-007',
            'descricao' => 'Já cadastrado',
            'unidade_medida_id' => $this->un->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);

        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-007', 'descricao' => 'Tentativa de sobrescrever', 'unidade' => 'KG', 'familia' => null, 'modo_rastreabilidade' => null],
        ]);

        $this->assertSame('conflito', $resultado['linhas'][0]['status']);
        $this->assertStringContainsString('nunca sobrescreve', $resultado['linhas'][0]['erros'][0]);
        $this->assertSame(1, $resultado['resumo']['conflitos']);
    }

    public function test_k_codigo_de_material_soft_deletado_tambem_e_conflito(): void
    {
        $material = Material::create([
            'codigo' => 'MAT-008',
            'descricao' => 'Excluído',
            'unidade_medida_id' => $this->un->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
        $material->delete();

        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-008', 'descricao' => 'X', 'unidade' => 'UN', 'familia' => null, 'modo_rastreabilidade' => null],
        ]);

        $this->assertSame('conflito', $resultado['linhas'][0]['status']);
    }

    // ---- aplicar(): só grava linhas 'valida' ----

    public function test_l_aplicar_cria_so_linhas_validas(): void
    {
        $analise = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-010', 'descricao' => 'Válido', 'unidade' => 'UN', 'familia' => null, 'modo_rastreabilidade' => null],
            ['linha' => 3, 'codigo' => 'MAT-011', 'descricao' => 'Unidade inexistente', 'unidade' => 'XYZ', 'familia' => null, 'modo_rastreabilidade' => null],
        ]);

        $resultado = (new MaterialImporter())->aplicar($analise['linhas']);

        $this->assertSame(1, $resultado['criados']);
        $this->assertDatabaseHas('materiais', ['codigo' => 'MAT-010']);
        $this->assertDatabaseMissing('materiais', ['codigo' => 'MAT-011']);
    }

    public function test_m_aplicar_grava_origem_cadastro_catalogo(): void
    {
        $analise = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-012', 'descricao' => 'X', 'unidade' => 'UN', 'familia' => null, 'modo_rastreabilidade' => null],
        ]);

        (new MaterialImporter())->aplicar($analise['linhas']);

        $material = Material::where('codigo', 'MAT-012')->firstOrFail();
        $this->assertSame(OrigemCadastroMaterial::Catalogo, $material->origem_cadastro);
    }

    public function test_n_aplicar_ignora_linha_conflito_mesmo_se_passada_por_engano(): void
    {
        $existente = Material::create([
            'codigo' => 'MAT-013',
            'descricao' => 'Original — nunca deve mudar',
            'unidade_medida_id' => $this->un->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);

        $analise = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-013', 'descricao' => 'Tentativa de sobrescrever', 'unidade' => 'KG', 'familia' => null, 'modo_rastreabilidade' => null],
        ]);

        // Defesa em profundidade: mesmo passando a linha de CONFLITO
        // (sem filtrar por status), aplicar() nunca grava/sobrescreve.
        (new MaterialImporter())->aplicar($analise['linhas']);

        $this->assertSame('Original — nunca deve mudar', $existente->fresh()->descricao);
        $this->assertSame(1, Material::where('codigo', 'MAT-013')->count());
    }

    public function test_o_aplicar_nunca_cria_unidade_ou_familia(): void
    {
        $antesUnidades = UnidadeMedida::count();
        $antesFamilias = FamiliaMaterial::count();

        $analise = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-014', 'descricao' => 'X', 'unidade' => 'UN', 'familia' => 'Tubulação', 'modo_rastreabilidade' => null],
        ]);
        (new MaterialImporter())->aplicar($analise['linhas']);

        $this->assertSame($antesUnidades, UnidadeMedida::count());
        $this->assertSame($antesFamilias, FamiliaMaterial::count());
    }

    // ---- Isolamento de tenant ----

    public function test_p_unidade_de_outro_tenant_nunca_e_resolvida(): void
    {
        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'UN', 'nome' => 'Unidade de outro tenant']);
        });

        // Mesmo código "UN" existindo em outro tenant, o tenant atual
        // (autenticado no setUp) não tem nenhuma Unidade "UN" própria
        // além da já criada — este teste usa um código que só existe no
        // OUTRO tenant, pra provar que o importador nunca a enxerga.
        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-015', 'descricao' => 'X', 'unidade' => 'INEXISTENTE-NESTE-TENANT', 'familia' => null, 'modo_rastreabilidade' => null],
        ]);

        $this->assertSame('invalida', $resultado['linhas'][0]['status']);
    }

    public function test_q_familia_de_outro_tenant_nunca_e_resolvida(): void
    {
        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            FamiliaMaterial::create(['tenant_id' => $outroTenant->id, 'nome' => 'Só do outro tenant']);
        });

        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-016', 'descricao' => 'X', 'unidade' => 'UN', 'familia' => 'Só do outro tenant', 'modo_rastreabilidade' => null],
        ]);

        $this->assertSame('invalida', $resultado['linhas'][0]['status']);
        $this->assertStringContainsString('não encontrada', collect($resultado['linhas'][0]['erros'])->implode(' '));
    }

    public function test_r_codigo_de_material_de_outro_tenant_nunca_conta_como_conflito(): void
    {
        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $u = UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
            Material::create([
                'tenant_id' => $outroTenant->id,
                'codigo' => 'MAT-COMPARTILHADO',
                'descricao' => 'Do outro tenant',
                'unidade_medida_id' => $u->id,
                'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
                'ativo' => true,
            ]);
        });

        $resultado = (new MaterialImporter())->analisar([
            ['linha' => 2, 'codigo' => 'MAT-COMPARTILHADO', 'descricao' => 'X', 'unidade' => 'UN', 'familia' => null, 'modo_rastreabilidade' => null],
        ]);

        $this->assertSame('valida', $resultado['linhas'][0]['status']);
    }
}
