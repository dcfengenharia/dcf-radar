<?php

namespace Tests\Feature;

use App\Enums\TipoItemTakeOff;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\TakeOff\TakeOffConsolidado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TakeOffConsolidadoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user);
    }

    private function criarLista(DocumentoEngenhariaRevisao $revisao, string $codigo, TipoItemTakeOff $tipo = TipoItemTakeOff::Material): ListaEngenharia
    {
        return ListaEngenharia::create([
            'documento_engenharia_revisao_id' => $revisao->id,
            'tipo' => $tipo->value,
            'codigo' => $codigo,
        ]);
    }

    private function criarItem(ListaEngenharia $lista, string $codigo, float $quantidade): ItemTakeOff
    {
        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id,
            'codigo' => $codigo,
            'descricao' => "Item {$codigo}",
            'quantidade' => $quantidade,
        ]);
    }

    /** Teste E — consolidado só considera revisão vigente. */
    public function test_e_itens_vigentes_ignora_lista_de_revisao_superada(): void
    {
        $documento = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-1', 'descricao' => 'X']);

        $r1 = $documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDays(10), 'descricao' => 'Emissão 1']);
        $listaR1 = $this->criarLista($r1, 'LM-001');
        $this->criarItem($listaR1, 'MAT-A', 100);

        $r2 = $documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'Emissão 2']);
        $listaR2 = $this->criarLista($r2, 'LM-001');
        $this->criarItem($listaR2, 'MAT-B', 200);

        $itens = TakeOffConsolidado::itensVigentes($this->obra->id);

        $this->assertCount(1, $itens);
        $this->assertSame('MAT-B', $itens->first()->codigo);
    }

    public function test_itens_vigentes_agrega_varias_listas_de_varios_documentos(): void
    {
        $doc1 = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-1', 'descricao' => 'X']);
        $doc2 = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-2', 'descricao' => 'Y']);

        $r1 = $doc1->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão']);
        $r2 = $doc2->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão']);
        $this->criarItem($this->criarLista($r1, 'LM-001'), 'MAT-A', 10);
        $this->criarItem($this->criarLista($r2, 'LM-001'), 'MAT-B', 20);

        $this->assertCount(2, TakeOffConsolidado::itensVigentes($this->obra->id));
    }

    public function test_itens_vigentes_filtra_por_obra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $doc1 = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-1', 'descricao' => 'X']);
        $doc2 = DocumentoEngenharia::create(['obra_id' => $outraObra->id, 'codigo' => 'DOC-2', 'descricao' => 'Y']);

        $r1 = $doc1->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão']);
        $r2 = $doc2->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão']);
        $this->criarItem($this->criarLista($r1, 'LM-001'), 'MAT-A', 10);
        $this->criarItem($this->criarLista($r2, 'LM-001'), 'MAT-B', 20);

        $itens = TakeOffConsolidado::itensVigentes($this->obra->id);
        $this->assertCount(1, $itens);
        $this->assertSame('MAT-A', $itens->first()->codigo);
    }

    public function test_itens_vigentes_filtra_por_tipo(): void
    {
        $documento = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-1', 'descricao' => 'X']);
        $r1 = $documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão']);
        $this->criarItem($this->criarLista($r1, 'LM-001', TipoItemTakeOff::Material), 'MAT-A', 10);
        $this->criarItem($this->criarLista($r1, 'LI-001', TipoItemTakeOff::Instrumento), 'INST-A', 5);

        $materiais = TakeOffConsolidado::itensVigentes($this->obra->id, TipoItemTakeOff::Material);
        $this->assertCount(1, $materiais);
        $this->assertSame('MAT-A', $materiais->first()->codigo);
    }

    /** Filtro por lista (seção 10 do pedido). */
    public function test_itens_vigentes_filtra_por_lista(): void
    {
        $documento = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-1', 'descricao' => 'X']);
        $r1 = $documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão']);
        $lm1 = $this->criarLista($r1, 'LM-001');
        $lm2 = $this->criarLista($r1, 'LM-002');
        $this->criarItem($lm1, 'A', 10);
        $this->criarItem($lm2, 'B', 20);

        $itens = TakeOffConsolidado::itensVigentes($this->obra->id, null, $lm1->id);
        $this->assertCount(1, $itens);
        $this->assertSame('A', $itens->first()->codigo);
    }

    /**
     * Teste Q — performance: consolidado com N listas/itens não escala
     * linearmente em número de queries (sem N+1).
     */
    public function test_q_performance_consolidado_sem_n_mais_1(): void
    {
        $documento = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-1', 'descricao' => 'X']);
        $r1 = $documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão']);

        for ($i = 1; $i <= 20; $i++) {
            $lista = $this->criarLista($r1, "LM-{$i}");
            $this->criarItem($lista, "ITEM-{$i}", $i);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $itens = TakeOffConsolidado::itensVigentes($this->obra->id);
        $itens->each(fn (ItemTakeOff $i) => [$i->lista->codigo, $i->lista->revisao->documento->codigo]);

        $this->assertCount(20, $itens);
        $this->assertLessThan(10, $queries, 'Consolidado com eager-load não deve escalar linearmente com o número de listas/itens.');
    }
}
