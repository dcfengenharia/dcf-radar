<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\FamiliaMaterial;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Tests\TestCase;

/**
 * Ajuste de arquitetura de navegação — o CRUD de UnidadeMedida/
 * FamiliaMaterial saiu das abas administrativas de `⚡estoque.blade.php`
 * (removidas) e passou a viver em `Configurações → Cadastros`
 * (`⚡unidades-medida.blade.php`/`⚡familias-material.blade.php`, cobertos
 * por `CadastrosUnidadesFamiliasMaterialTest`). Este arquivo cobre só o
 * que continua sendo responsabilidade da tela de Estoque: o Catálogo
 * Mestre de Materiais (framing visual, dropdown resolvendo o cadastro
 * central, estado vazio orientando pra onde cadastrar, importação Excel
 * e template) — nunca mais o CRUD de Unidade/Família em si.
 */
class CadastrosMestresMaterialTest extends TestCase
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
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    // ---- Abas administrativas removidas de Radar → Estoque ----

    public function test_abas_de_unidade_e_familia_nao_aparecem_mais_no_estoque(): void
    {
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->assertOk()
            ->assertDontSee('Unidades de Medida')
            ->assertDontSee('Famílias de Materiais');
    }

    public function test_metodos_de_crud_de_unidade_e_familia_nao_existem_mais_no_estoque(): void
    {
        $this->expectException(\Livewire\Exceptions\MethodNotFoundException::class);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalUnidade');
    }

    // ---- G/I: Material continua exigindo Unidade + Família continua opcional ----

    public function test_g_material_sem_unidade_selecionada_bloqueia_validacao(): void
    {
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalMaterial')
            ->set('materialCodigo', 'MAT-001')
            ->set('materialDescricao', 'X')
            ->call('salvarMaterial')
            ->assertHasErrors(['materialUnidadeMedidaId']);
    }

    public function test_i_material_sem_familia_continua_valido(): void
    {
        $unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalMaterial')
            ->set('materialCodigo', 'MAT-003')
            ->set('materialDescricao', 'X')
            ->set('materialUnidadeMedidaId', $unidade->id)
            ->call('salvarMaterial')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('materiais', ['codigo' => 'MAT-003', 'familia_material_id' => null]);
    }

    // ---- Select de Material usa exatamente o cadastro central ----

    public function test_dropdown_de_material_reflete_unidade_do_cadastro_central(): void
    {
        // Simula uma Unidade cadastrada em Configurações → Cadastros —
        // criada aqui direto no model (a mesma fonte de verdade usada
        // pela nova tela), nunca via um segundo caminho de criação.
        $unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M2', 'nome' => 'Metro quadrado']);

        $componente = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalMaterial')
            ->assertSee('M2 — Metro quadrado');

        $componente->set('materialCodigo', 'MAT-002')
            ->set('materialDescricao', 'Piso')
            ->set('materialUnidadeMedidaId', $unidade->id)
            ->call('salvarMaterial')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('materiais', ['codigo' => 'MAT-002', 'unidade_medida_id' => $unidade->id]);
        // Nenhuma segunda tabela/registro de Unidade foi criado por este fluxo.
        $this->assertSame(1, UnidadeMedida::count());
    }

    // ---- Estado vazio orienta pra onde cadastrar (Configurações → Cadastros) ----

    public function test_dropdown_vazio_orienta_para_configuracoes_cadastros(): void
    {
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalMaterial')
            ->assertSee('Nenhuma Unidade de Medida cadastrada')
            ->assertSee('Configurações')
            ->assertSee('Cadastros')
            ->assertSee(route('cadastros.unidades-medida'));
    }

    // ---- Framing visual do Catálogo Mestre ----

    public function test_titulo_do_catalogo_mestre_e_comunicado(): void
    {
        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->assertSee('Catálogo Mestre de Materiais')
            ->assertSee('Cadastro corporativo do tenant');
    }

    // ---- "Baixar modelo" — template oficial multi-aba, referências ao cadastro central ----

    public function test_baixar_modelo_gera_xlsx_com_abas_esperadas(): void
    {
        UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
        FamiliaMaterial::create(['tenant_id' => $this->tenant->id, 'nome' => 'Tubulação']);

        $response = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('baixarModeloMaterial');

        $binario = $response->effects['download']['content'] ?? null;
        $this->assertNotNull($binario, 'A resposta de download não trouxe conteúdo binário — verifique o effect do Livewire.');

        $caminho = tempnam(sys_get_temp_dir(), 'modelo_material_') . '.xlsx';
        file_put_contents($caminho, base64_decode($binario));

        $spreadsheet = (new Xlsx())->load($caminho);
        $titulos = array_map(fn ($sheet) => $sheet->getTitle(), $spreadsheet->getAllSheets());

        $this->assertSame(['Materiais', 'Unidades válidas', 'Famílias válidas', 'Instruções'], $titulos);
        $this->assertSame('UN', $spreadsheet->getSheetByName('Unidades válidas')->getCell('A2')->getValue());
        $this->assertSame('Tubulação', $spreadsheet->getSheetByName('Famílias válidas')->getCell('B2')->getValue());

        unlink($caminho);
    }

    public function test_baixar_modelo_nunca_expoe_dado_de_outro_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'SEGREDO', 'nome' => 'Não deveria aparecer']);
        });

        $response = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('baixarModeloMaterial');

        $binario = $response->effects['download']['content'] ?? null;
        $caminho = tempnam(sys_get_temp_dir(), 'modelo_material_') . '.xlsx';
        file_put_contents($caminho, base64_decode($binario));

        $texto = (new Xlsx())->load($caminho)->getSheetByName('Unidades válidas')->toArray();
        $achado = collect($texto)->flatten()->contains('SEGREDO');

        $this->assertFalse($achado);
        unlink($caminho);
    }

    // ---- Fluxo completo de importação via UI, arquivo real (.xlsx), resolvendo pelo cadastro central ----

    public function test_fluxo_completo_de_importacao_via_ui(): void
    {
        UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(\App\Imports\MaterialImporter::SHEET_NOME);
        $sheet->fromArray(['Código', 'Descrição', 'Unidade', 'Família', 'Modo de Rastreabilidade'], null, 'A1');
        $sheet->fromArray(['IMP-001', 'Item importado', 'UN', '', ''], null, 'A2');
        $sheet->fromArray(['IMP-002', 'Unidade inexistente', 'XYZ', '', ''], null, 'A3');

        $caminhoOrigem = tempnam(sys_get_temp_dir(), 'import_ui_') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($caminhoOrigem);

        // Livewire::test()->set() com WithFileUploads espera um
        // Illuminate\Http\Testing\File (com propriedade pública `name`)
        // — createWithContent() permite usar bytes REAIS de um .xlsx
        // válido, nunca o conteúdo aleatório de fake()->create().
        $arquivo = \Illuminate\Http\UploadedFile::fake()->createWithContent('materiais.xlsx', file_get_contents($caminhoOrigem));

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirImportacaoMaterial')
            ->set('arquivoImportacaoMaterial', $arquivo)
            ->call('analisarImportacaoMaterial')
            ->assertSet('previaImportacaoMaterial.resumo.validas', 1)
            ->assertSet('previaImportacaoMaterial.resumo.invalidas', 1)
            ->call('confirmarImportacaoMaterial')
            ->assertSet('importModalMaterialAberto', false);

        $this->assertDatabaseHas('materiais', ['codigo' => 'IMP-001']);
        $this->assertDatabaseMissing('materiais', ['codigo' => 'IMP-002']);
    }
}
