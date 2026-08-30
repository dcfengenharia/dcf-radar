<?php

namespace Tests\Feature;

use App\Enums\OrigemItemTakeOff;
use App\Imports\TakeOffImporter;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\FamiliaMaterial;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.1.CORREÇÃO — o importador agora opera sobre uma
 * `ListaEngenharia` (nunca mais direto sobre a revisão) — a planilha
 * não tem mais coluna "Tipo" (implícito pela lista alvo).
 */
class TakeOffImporterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private DocumentoEngenhariaRevisao $revisao;
    private ListaEngenharia $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);

        $documento = DocumentoEngenharia::create([
            'obra_id' => $this->obra->id,
            'codigo' => 'ISO-001',
            'descricao' => 'Isometrico principal',
        ]);

        $this->revisao = $documento->revisoes()->create([
            'revisao' => 'R1',
            'data_emissao' => now()->subDays(5),
            'descricao' => 'Emissão inicial',
        ]);

        $this->lista = ListaEngenharia::create([
            'documento_engenharia_revisao_id' => $this->revisao->id,
            'tipo' => 'material',
            'codigo' => 'LM-001',
        ]);
    }

    private function caminhoFixture(): string
    {
        return base_path('tests/Fixtures/take_off.xlsx');
    }

    public function test_ler_linhas_le_todas_as_linhas_preenchidas(): void
    {
        $linhas = (new TakeOffImporter())->lerLinhas($this->caminhoFixture());

        // 7 linhas com descrição preenchida (as de quantidade inválida/
        // zero/negativa também têm descrição — são filtradas em
        // analisar(), não em lerLinhas()).
        $this->assertCount(7, $linhas);

        $primeira = $linhas[0];
        $this->assertSame('MAT-001', $primeira['codigo']);
        $this->assertSame(120.5, $primeira['quantidade']);
    }

    public function test_ler_linhas_tolera_quantidade_com_virgula_decimal_brasileira(): void
    {
        $linhas = (new TakeOffImporter())->lerLinhas($this->caminhoFixture());

        $semCodigo = collect($linhas)->firstWhere('descricao', 'Item sem código');

        $this->assertNotNull($semCodigo);
        $this->assertSame(15.75, $semCodigo['quantidade']);
    }

    public function test_analisar_deduplica_por_codigo_ultima_linha_prevalece(): void
    {
        $importador = new TakeOffImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixture());
        $previa = $importador->analisar($linhas, $this->lista);

        $mat002 = collect($previa['linhas'])->first(fn ($l) => $l['codigo'] === 'MAT-002');
        $this->assertSame(50.0, $mat002['quantidade']);
        $this->assertSame('Válvula gaveta 4" (corrigida)', $mat002['descricao']);

        $avisos = implode(' | ', $previa['avisos']);
        $this->assertStringContainsString('MAT-002', $avisos);
    }

    public function test_analisar_sinaliza_quantidade_invalida_zero_e_negativa_como_ignoradas(): void
    {
        $importador = new TakeOffImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixture());
        $previa = $importador->analisar($linhas, $this->lista);

        // 7 lidas - 1 duplicata (MAT-002 linha 3) - inválida (MAT-003) -
        // zero (MAT-004) - negativa (MAT-005) = 3.
        $this->assertSame(3, $previa['ignoradas']);
        $this->assertCount(3, $previa['linhas']);

        $avisos = implode(' | ', $previa['avisos']);
        $this->assertStringContainsString('quantidade ausente ou inválida', $avisos);
        $this->assertStringContainsString('maior que zero (recebido 0)', $avisos);
        $this->assertStringContainsString('maior que zero (recebido -5)', $avisos);
    }

    public function test_analisar_avisa_sobre_catalogos_novos_a_serem_criados(): void
    {
        $importador = new TakeOffImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixture());
        $previa = $importador->analisar($linhas, $this->lista);

        $avisos = implode(' | ', $previa['avisos']);
        $this->assertStringContainsString('Tubulacao', $avisos);
        $this->assertStringContainsString('Mecanica', $avisos);
    }

    public function test_analisar_nao_avisa_sobre_catalogo_ja_existente(): void
    {
        UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);

        $importador = new TakeOffImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixture());
        $previa = $importador->analisar($linhas, $this->lista);

        $avisos = implode(' | ', $previa['avisos']);
        $this->assertStringNotContainsString('Unidade "M"', $avisos);
    }

    public function test_aplicar_cria_itens_e_catalogos_automaticamente(): void
    {
        $importador = new TakeOffImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixture());
        $previa = $importador->analisar($linhas, $this->lista);

        $resultado = $importador->aplicar($previa['linhas'], $this->lista, $this->user->id);

        $this->assertSame(3, $resultado['novos']);
        $this->assertSame(0, $resultado['atualizados']);

        $this->assertDatabaseHas('itens_take_off', [
            'lista_engenharia_id' => $this->lista->id,
            'codigo' => 'MAT-001',
            'origem' => OrigemItemTakeOff::Importado->value,
        ]);

        $mat002 = ItemTakeOff::where('codigo', 'MAT-002')->first();
        $this->assertNotNull($mat002);
        $this->assertSame('50.000', $mat002->quantidade);

        // Unidade normalizada (trim+maiúsculo) — "M" da planilha vira "M".
        $this->assertDatabaseHas('unidades_medida', ['tenant_id' => $this->tenant->id, 'codigo' => 'M']);
        $this->assertDatabaseHas('familias_material', ['tenant_id' => $this->tenant->id, 'nome' => 'Tubulacao']);
        $this->assertDatabaseHas('disciplinas', ['tenant_id' => $this->tenant->id, 'nome' => 'Mecanica']);
    }

    public function test_reimportar_a_mesma_planilha_na_mesma_lista_atualiza_em_vez_de_duplicar(): void
    {
        $importador = new TakeOffImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixture());
        $previa1 = $importador->analisar($linhas, $this->lista);
        $importador->aplicar($previa1['linhas'], $this->lista, $this->user->id);

        // O item "sem código" nunca é reconciliável — reimportar sempre
        // cria uma linha nova pra ele; os 2 itens COM código reconciliam
        // normalmente (0 novos, 2 atualizados).
        $previa2 = $importador->analisar($linhas, $this->lista);
        $this->assertSame(1, $previa2['novos']);
        $this->assertSame(2, $previa2['atualizados']);

        $resultado2 = $importador->aplicar($previa2['linhas'], $this->lista, $this->user->id);
        $this->assertSame(1, $resultado2['novos']);
        $this->assertSame(2, $resultado2['atualizados']);

        $this->assertSame(4, ItemTakeOff::where('lista_engenharia_id', $this->lista->id)->count());
    }

    /**
     * Teste G (seção 8/18 do pedido) — importação em LM-002 nunca toca
     * itens de LM-001, mesmo com códigos coincidentes.
     */
    public function test_importar_em_outra_lista_da_mesma_revisao_nunca_altera_a_primeira(): void
    {
        $lista2 = ListaEngenharia::create([
            'documento_engenharia_revisao_id' => $this->revisao->id,
            'tipo' => 'material',
            'codigo' => 'LM-002',
        ]);

        $importador = new TakeOffImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixture());

        $previa1 = $importador->analisar($linhas, $this->lista);
        $importador->aplicar($previa1['linhas'], $this->lista, $this->user->id);

        // Mesmo arquivo (mesmos códigos MAT-001/002) importado em LM-002.
        $previa2 = $importador->analisar($linhas, $lista2);
        $this->assertSame(3, $previa2['novos']);
        $this->assertSame(0, $previa2['atualizados']); // LM-002 não vê os itens de LM-001.
        $importador->aplicar($previa2['linhas'], $lista2, $this->user->id);

        $this->assertSame(3, ItemTakeOff::where('lista_engenharia_id', $this->lista->id)->count());
        $this->assertSame(3, ItemTakeOff::where('lista_engenharia_id', $lista2->id)->count());

        // MAT-001 de LM-001 continua com a descrição original, intocado.
        $mat001Lm1 = ItemTakeOff::where('lista_engenharia_id', $this->lista->id)->where('codigo', 'MAT-001')->first();
        $this->assertSame('Tubo de aço carbono 6"', $mat001Lm1->descricao);
    }

    public function test_itens_de_uma_revisao_nunca_aparecem_em_outra_revisao_do_mesmo_documento(): void
    {
        $importador = new TakeOffImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixture());
        $previa = $importador->analisar($linhas, $this->lista);
        $importador->aplicar($previa['linhas'], $this->lista, $this->user->id);

        $revisao2 = $this->revisao->documento->revisoes()->create([
            'revisao' => 'R2',
            'data_emissao' => now(),
            'descricao' => 'Segunda emissão',
        ]);

        // R2 nasce sem nenhuma lista — nada é copiado automaticamente de
        // R1 (D1).
        $this->assertSame(0, ListaEngenharia::where('documento_engenharia_revisao_id', $revisao2->id)->count());
    }

    public function test_isolamento_de_tenant_nos_catalogos_criados_automaticamente(): void
    {
        $outroTenant = Tenant::factory()->create();

        \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            FamiliaMaterial::create(['tenant_id' => $outroTenant->id, 'nome' => 'Tubulacao']);
        });

        $importador = new TakeOffImporter();
        $linhas = $importador->lerLinhas($this->caminhoFixture());
        $previa = $importador->analisar($linhas, $this->lista);
        $importador->aplicar($previa['linhas'], $this->lista, $this->user->id);

        $this->assertSame(1, FamiliaMaterial::where('nome', 'Tubulacao')->count());
        $contagemOutroTenant = \App\Support\TenantContext::actingAs(
            $outroTenant,
            fn () => FamiliaMaterial::where('nome', 'Tubulacao')->count()
        );
        $this->assertSame(1, $contagemOutroTenant);
    }
}
