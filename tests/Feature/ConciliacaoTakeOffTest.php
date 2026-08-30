<?php

namespace Tests\Feature;

use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\Papel;
use App\Models\DocumentoEngenharia;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\RequisicaoPlanejamentoItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Suprimentos\ConciliacaoTakeOff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.2 — conciliação quantitativa Take Off × RP. Cobertura
 * Q-X + performance N=1000 do pedido.
 */
class ConciliacaoTakeOffTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private DocumentoEngenharia $documento;
    private CriarRequisicaoPlanejamento $criar;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizar;
    private EmitirRequisicaoPlanejamento $emitir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->documento = DocumentoEngenharia::create([
            'obra_id' => $this->obra->id, 'codigo' => 'ISO-001', 'descricao' => 'Isometrico',
        ]);

        $this->criar = new CriarRequisicaoPlanejamento();
        $this->atualizar = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitir = new EmitirRequisicaoPlanejamento();
    }

    private function novaLista(string $codigo = 'LM-001', ?string $revisaoId = null): ListaEngenharia
    {
        $revisao = $revisaoId
            ? \App\Models\DocumentoEngenhariaRevisao::find($revisaoId)
            : ($this->documento->revisaoVigente() ?? $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']));

        return ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => $codigo.'-'.uniqid()]);
    }

    private function requisitarEEmitir(ItemTakeOff $item, float $quantidade): void
    {
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $item->id, $quantidade);
        $this->emitir->execute($rp->fresh(), $this->user);
    }

    // ---- Q/R/S: conciliação por item ----

    public function test_q_item_nao_requisitado(): void
    {
        $lista = $this->novaLista();
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 100]);

        $c = ConciliacaoTakeOff::porItem($item);

        $this->assertSame(ConciliacaoTakeOff::STATUS_NAO_REQUISITADO, $c['status']);
        $this->assertSame(0.0, $c['quantidade_requisitada']);
        $this->assertSame(100.0, $c['saldo']);
    }

    public function test_r_item_parcial(): void
    {
        $lista = $this->novaLista();
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 100]);
        $this->requisitarEEmitir($item, 40);

        $c = ConciliacaoTakeOff::porItem($item);

        $this->assertSame(ConciliacaoTakeOff::STATUS_PARCIAL, $c['status']);
        $this->assertSame(40.0, $c['quantidade_requisitada']);
        $this->assertSame(60.0, $c['saldo']);
        $this->assertSame(40.0, $c['percentual_requisitado']);
    }

    public function test_s_item_completo(): void
    {
        $lista = $this->novaLista();
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 100]);
        $this->requisitarEEmitir($item, 100);

        $c = ConciliacaoTakeOff::porItem($item);

        $this->assertSame(ConciliacaoTakeOff::STATUS_COMPLETO, $c['status']);
        $this->assertSame(0.0, $c['saldo']);
        $this->assertSame(100.0, $c['percentual_requisitado']);
    }

    // ---- T/U/V: conciliação por lista ----

    public function test_t_u_v_conciliacao_por_lista(): void
    {
        $lista = $this->novaLista();
        $a = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 100]);
        $b = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'B', 'descricao' => 'B', 'quantidade' => 50]);
        $c = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'C', 'descricao' => 'C', 'quantidade' => 20]);

        $this->requisitarEEmitir($a, 100); // completo
        $this->requisitarEEmitir($b, 25);  // parcial
        // C nunca requisitado

        $resultado = ConciliacaoTakeOff::porLista($lista);

        $this->assertSame(3, $resultado['total_itens']);
        $this->assertSame(1, $resultado['itens_completos']);
        $this->assertSame(1, $resultado['itens_parciais']);
        $this->assertSame(1, $resultado['itens_nao_requisitados']);
        $this->assertEqualsWithDelta(33.33, $resultado['percentual_itens_completos'], 0.01);
    }

    public function test_u_lista_100_por_cento(): void
    {
        $lista = $this->novaLista();
        $a = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 10]);
        $b = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'B', 'descricao' => 'B', 'quantidade' => 20]);
        $this->requisitarEEmitir($a, 10);
        $this->requisitarEEmitir($b, 20);

        $resultado = ConciliacaoTakeOff::porLista($lista);

        $this->assertSame(100.0, $resultado['percentual_itens_completos']);
    }

    public function test_v_lista_0_por_cento(): void
    {
        $lista = $this->novaLista();
        ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 10]);
        ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'B', 'descricao' => 'B', 'quantidade' => 20]);

        $resultado = ConciliacaoTakeOff::porLista($lista);

        $this->assertSame(0.0, $resultado['percentual_itens_completos']);
    }

    /** Cobertura NUNCA soma quantidade entre unidades incompatíveis (kg+m+un). */
    public function test_cobertura_nunca_soma_unidades_incompativeis(): void
    {
        $lista = $this->novaLista();
        $kg = \App\Models\UnidadeMedida::create(['codigo' => 'KG', 'nome' => 'Quilograma']);
        $m = \App\Models\UnidadeMedida::create(['codigo' => 'M', 'nome' => 'Metro']);

        $itemKg = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 1000, 'unidade_medida_id' => $kg->id]);
        $itemM = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'B', 'descricao' => 'B', 'quantidade' => 5, 'unidade_medida_id' => $m->id]);

        $this->requisitarEEmitir($itemM, 5); // completa o item de 5m — nunca "5 de 1005"

        $resultado = ConciliacaoTakeOff::porLista($lista);

        // 1 de 2 itens completo (contagem de itens, nunca soma de kg+m).
        $this->assertSame(50.0, $resultado['percentual_itens_completos']);
        $this->assertSame(0.0, ConciliacaoTakeOff::porItem($itemKg->fresh())['percentual_requisitado']);
    }

    // ---- W: nova revisão não reaproveita saldo antigo ----

    public function test_w_nova_revisao_nao_reaproveita_saldo_antigo(): void
    {
        $revisaoR1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDay(), 'descricao' => 'E1']);
        $listaR1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisaoR1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $itemR1 = ItemTakeOff::create(['lista_engenharia_id' => $listaR1->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 100]);
        $this->requisitarEEmitir($itemR1, 60);

        $revisaoR2 = $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);
        $listaR2 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisaoR2->id, 'tipo' => 'material', 'codigo' => 'LM2-001']);
        $itemR2 = ItemTakeOff::create(['lista_engenharia_id' => $listaR2->id, 'codigo' => 'A2', 'descricao' => 'A2', 'quantidade' => 120]);

        // O item novo (A2, revisão R2) começa do zero — os 60 já
        // requisitados de A (revisão R1) nunca são somados/deduzidos aqui.
        $c = ConciliacaoTakeOff::porItem($itemR2->fresh());
        $this->assertSame(0.0, $c['quantidade_requisitada']);
        $this->assertSame(120.0, $c['saldo']);
    }

    // ---- X: RP histórica continua apontando pro ItemTakeOff exato de R1 ----

    public function test_x_rp_historica_continua_apontando_r1_mesmo_apos_r2(): void
    {
        $revisaoR1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDay(), 'descricao' => 'E1']);
        $listaR1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisaoR1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $itemR1 = ItemTakeOff::create(['lista_engenharia_id' => $listaR1->id, 'codigo' => 'A', 'descricao' => 'A original', 'quantidade' => 100]);

        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizar->adicionarItem($rp, $itemR1->id, 60);
        $this->emitir->execute($rp->fresh(), $this->user);

        // R2 nasce, congelando R1/LM1 (19.1.HARDENING) — a RP já emitida
        // nunca quebra, continua resolvendo o ItemTakeOff EXATO de R1.
        $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);

        $rpItem->refresh();
        $this->assertSame($itemR1->id, $rpItem->item_take_off_id);
        $this->assertSame('A original', $rpItem->descricao_snapshot); // fotografia, nunca lê o item ao vivo de novo
        $this->assertNotNull($rpItem->itemTakeOff);
        $this->assertFalse($itemR1->fresh()->lista->estaVigente());
    }

    // ---- AD: performance N=1000 ----

    public function test_ad_performance_conciliacao_por_obra_n1000(): void
    {
        $lista = $this->novaLista();
        for ($i = 0; $i < 1000; $i++) {
            ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => "ITEM-{$i}", 'descricao' => "Item {$i}", 'quantidade' => 10]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $resultado = ConciliacaoTakeOff::porObra($this->obra->id);

        $this->assertSame(1000, $resultado['total_itens']);
        $this->assertLessThan(10, $queries, 'Conciliação por obra não pode escalar com N — esperava < 10 queries pra 1000 itens.');
    }
}
