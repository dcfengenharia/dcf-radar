<?php

namespace Tests\Feature;

use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\Papel;
use App\Models\DocumentoEngenharia;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Suprimentos\ConciliacaoAlocacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.3 — conciliação RequisicaoPlanejamentoItem × Pacote.
 * Cobertura AA-AF da matriz obrigatória (não alocado/parcial/completo,
 * pacote sem demanda, performance N=100/1000).
 */
class ConciliacaoAlocacaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private DocumentoEngenharia $documento;
    private ListaEngenharia $lista;

    private CriarRequisicaoPlanejamento $criarRp;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizarRp;
    private EmitirRequisicaoPlanejamento $emitirRp;
    private AlocarRequisicaoAoPacote $alocar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->documento = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'ISO-001', 'descricao' => 'Isometrico']);
        $revisao = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $this->lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $this->criarRp = new CriarRequisicaoPlanejamento();
        $this->atualizarRp = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitirRp = new EmitirRequisicaoPlanejamento();
        $this->alocar = new AlocarRequisicaoAoPacote();
    }

    private function rpItemEmitido(float $quantidadeRequisitada, string $codigoItem, float $quantidadePrevista = 1000): \App\Models\RequisicaoPlanejamentoItem
    {
        $item = ItemTakeOff::create(['lista_engenharia_id' => $this->lista->id, 'codigo' => $codigoItem, 'descricao' => "Item {$codigoItem}", 'quantidade' => $quantidadePrevista]);
        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidadeRequisitada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $rpItem->fresh();
    }

    private function criarPacote(string $nome = 'Pacote'): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => $nome, 'codigo' => $nome]);
    }

    // ---- AA: não alocado ----

    public function test_aa_item_nao_alocado(): void
    {
        $rpItem = $this->rpItemEmitido(100, 'A');

        $c = ConciliacaoAlocacao::porRequisicaoItem($rpItem);

        $this->assertSame(ConciliacaoAlocacao::STATUS_NAO_ALOCADO, $c['status']);
        $this->assertSame(0.0, $c['quantidade_alocada']);
        $this->assertSame(100.0, $c['saldo']);
    }

    // ---- AB: parcial ----

    public function test_ab_item_parcial(): void
    {
        $rpItem = $this->rpItemEmitido(100, 'A');
        $this->alocar->alocar($rpItem, $this->criarPacote(), 40);

        $c = ConciliacaoAlocacao::porRequisicaoItem($rpItem->fresh());

        $this->assertSame(ConciliacaoAlocacao::STATUS_PARCIAL, $c['status']);
        $this->assertSame(40.0, $c['quantidade_alocada']);
        $this->assertSame(60.0, $c['saldo']);
    }

    // ---- AC: completo ----

    public function test_ac_item_completo(): void
    {
        $rpItem = $this->rpItemEmitido(100, 'A');
        $this->alocar->alocar($rpItem, $this->criarPacote(), 100);

        $c = ConciliacaoAlocacao::porRequisicaoItem($rpItem->fresh());

        $this->assertSame(ConciliacaoAlocacao::STATUS_COMPLETO, $c['status']);
        $this->assertSame(0.0, $c['saldo']);
    }

    /** Conciliação por RP inteira, cobertura por CONTAGEM de itens. */
    public function test_conciliacao_por_requisicao(): void
    {
        $item1 = ItemTakeOff::create(['lista_engenharia_id' => $this->lista->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 100]);
        $item2 = ItemTakeOff::create(['lista_engenharia_id' => $this->lista->id, 'codigo' => 'B', 'descricao' => 'B', 'quantidade' => 50]);
        $item3 = ItemTakeOff::create(['lista_engenharia_id' => $this->lista->id, 'codigo' => 'C', 'descricao' => 'C', 'quantidade' => 20]);

        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItem1 = $this->atualizarRp->adicionarItem($rp, $item1->id, 100);
        $rpItem2 = $this->atualizarRp->adicionarItem($rp, $item2->id, 50);
        $this->atualizarRp->adicionarItem($rp, $item3->id, 20);
        $rpEmitida = $this->emitirRp->execute($rp->fresh(), $this->user);

        $pacote = $this->criarPacote();
        $this->alocar->alocar($rpItem1->fresh(), $pacote, 100); // completo
        $this->alocar->alocar($rpItem2->fresh(), $pacote, 25);  // parcial
        // item3 nunca alocado

        $resultado = ConciliacaoAlocacao::porRequisicao($rpEmitida);

        $this->assertSame(3, $resultado['total_itens']);
        $this->assertSame(1, $resultado['itens_completos']);
        $this->assertSame(1, $resultado['itens_parciais']);
        $this->assertSame(1, $resultado['itens_nao_alocados']);
    }

    // ---- AD: Pacote sem demanda ----

    public function test_ad_pacote_sem_demanda(): void
    {
        $pacote = $this->criarPacote();

        $resumo = \App\Support\Suprimentos\ConciliacaoAlocacao::porPacote($pacote);

        $this->assertSame(0, $resumo['total_rps']);
        $this->assertSame(0, $resumo['total_rp_itens']);
        $this->assertSame([], $resumo['quantidade_por_unidade']);
        $this->assertNull($resumo['necessidade']);
    }

    /** Conciliação por Pacote nunca soma unidades incompatíveis. */
    public function test_conciliacao_por_pacote_nao_soma_unidades_incompativeis(): void
    {
        $kg = \App\Models\UnidadeMedida::create(['codigo' => 'KG', 'nome' => 'Quilograma']);
        $m = \App\Models\UnidadeMedida::create(['codigo' => 'M', 'nome' => 'Metro']);

        $itemKg = ItemTakeOff::create(['lista_engenharia_id' => $this->lista->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 1000, 'unidade_medida_id' => $kg->id]);
        $itemM = ItemTakeOff::create(['lista_engenharia_id' => $this->lista->id, 'codigo' => 'B', 'descricao' => 'B', 'quantidade' => 50, 'unidade_medida_id' => $m->id]);

        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItemKg = $this->atualizarRp->adicionarItem($rp, $itemKg->id, 500);
        $rpItemM = $this->atualizarRp->adicionarItem($rp, $itemM->id, 30);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        $pacote = $this->criarPacote();
        $this->alocar->alocar($rpItemKg->fresh(), $pacote, 500);
        $this->alocar->alocar($rpItemM->fresh(), $pacote, 30);

        $resumo = ConciliacaoAlocacao::porPacote($pacote);

        $this->assertSame(500.0, $resumo['quantidade_por_unidade']['KG']);
        $this->assertSame(30.0, $resumo['quantidade_por_unidade']['M']);
    }

    // ---- AE/AF: performance N=100/N=1000 ----

    public function test_ae_performance_conciliacao_n100(): void
    {
        $rpItens = collect();
        for ($i = 0; $i < 100; $i++) {
            $rpItens->push($this->rpItemEmitido(10, "ITEM-{$i}"));
        }

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $conciliacao = ConciliacaoAlocacao::porRequisicaoItens($rpItens);

        $this->assertSame(100, $conciliacao->count());
        $this->assertLessThan(5, $queries, 'Conciliação de alocação não pode escalar com N — esperava < 5 queries pra 100 RPItens.');
    }

    public function test_af_performance_conciliacao_n1000(): void
    {
        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItens = collect();
        for ($i = 0; $i < 1000; $i++) {
            $item = ItemTakeOff::create(['lista_engenharia_id' => $this->lista->id, 'codigo' => "I-{$i}", 'descricao' => "I-{$i}", 'quantidade' => 10]);
            $rpItens->push($this->atualizarRp->adicionarItem($rp, $item->id, 5));
        }
        $this->emitirRp->execute($rp->fresh(), $this->user);
        $rpItens = $rpItens->map(fn ($i) => $i->fresh());

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $conciliacao = ConciliacaoAlocacao::porRequisicaoItens($rpItens);

        $this->assertSame(1000, $conciliacao->count());
        $this->assertLessThan(5, $queries, 'Conciliação de alocação não pode escalar com N — esperava < 5 queries pra 1000 RPItens.');
    }
}
