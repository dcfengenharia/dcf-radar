<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaPedidoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Material;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Services\SuprimentoScheduler;
use App\Support\SincronizarRestricaoSuprimento;
use App\Support\Suprimentos\DescricaoRestricaoSuprimento;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Motor Definitivo de Risco de Suprimentos V1 (Seção 24/28) — a
 * Restrição automática precisa usar quantidade/material/causa reais em
 * vez de um texto genérico, e a MESMA linha evolui a descrição conforme
 * a causa muda ao longo do processo comercial, nunca criando uma
 * segunda Restrição pro mesmo par (Atividade, Pacote).
 */
class DescricaoRestricaoSuprimentoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2027-01-01'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarAtividade(string $inicioPlanejado): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => $inicioPlanejado,
        ]);
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'CABO-70',
            'descricao' => 'Cabo 70 mm²',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
    }

    private function criarItemTakeOff(Material $material, float $quantidade): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'Documento']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Cabo',
            'unidade_medida_id' => $this->unidade->id, 'quantidade' => $quantidade, 'material_id' => $material->id,
        ]);
    }

    private function criarNecessidade(Atividade $atividade, ItemTakeOff $ito, float $quantidade): AtividadeNecessidadeMaterial
    {
        return (new AtualizarNecessidadeMaterialAtividade())->criarTakeOff($atividade, $ito, $quantidade, $this->user);
    }

    private function criarFluxo(): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        return $fluxo->fresh(['etapas']);
    }

    /** Pacote+Atividade com um Pedido Emitido, nunca recebido -> dispara Condição C na cadeia formal / risco no legado. */
    private function pacoteComPedidoPendente(Atividade $atividade, ItemTakeOff $ito, AtividadeNecessidadeMaterial $necessidade, float $quantidade, ?string $dataPrevista): array
    {
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Cabo']);
        $pacote->atividades()->sync([$atividade->id]);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);

        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $item = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($item, $necessidade, $quantidade, $this->user);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor', 'cnpj' => '00.000.000/0001-00']);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, $dataPrevista, null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidade, $quantidade, $this->user);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        // Nunca recebido -> material formal pendente.

        return [$pacote->fresh(['atividades']), $rcEmitida];
    }

    // =========================================================================
    // Descrição quantitativa precisa (unidade direto): Pedido atrasado.
    // =========================================================================
    public function test_descricao_e_quantitativa_e_precisa_para_pedido_atrasado(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 60);

        [$pacote] = $this->pacoteComPedidoPendente($atividade, $ito, $necessidade, 60, '2026-12-01');

        $descricao = DescricaoRestricaoSuprimento::paraPacoteEAtividade($atividade, $pacote, 'FALLBACK GENÉRICO');

        $this->assertStringContainsString('60', $descricao);
        $this->assertStringContainsString('Cabo 70 mm²', $descricao);
        $this->assertStringNotContainsString('FALLBACK GENÉRICO', $descricao);
    }

    // =========================================================================
    // Descrição quantitativa precisa: Pedido sem prazo informado.
    // =========================================================================
    public function test_descricao_precisa_para_pedido_sem_prazo(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 40);

        [$pacote, $rc] = $this->pacoteComPedidoPendente($atividade, $ito, $necessidade, 40, '2026-12-01');
        // Legado sem previsão — mesma técnica já usada em outros testes deste domínio.
        \App\Models\PedidoCompra::whereHas('itens', fn ($q) => $q->where('requisicao_compra_item_id', $rc->itens->first()->id))
            ->first()
            ?->forceFill(['data_prevista_entrega' => null])
            ->save();

        $descricao = DescricaoRestricaoSuprimento::paraPacoteEAtividade($atividade, $pacote, 'FALLBACK');

        $this->assertStringContainsString('40', $descricao);
        $this->assertStringContainsString('sem previsão de entrega', $descricao);
    }

    // =========================================================================
    // Sem correspondência inequívoca -> texto genérico histórico preservado.
    // =========================================================================
    public function test_sem_correspondencia_mantem_texto_generico_historico(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        // Deliberadamente NUNCA cria AtividadeNecessidadeMaterial nesta
        // Atividade -> nenhuma correspondência inequívoca.
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Genérico']);
        $pacote->atividades()->sync([$atividade->id]);

        $descricao = DescricaoRestricaoSuprimento::paraPacoteEAtividade($atividade, $pacote->fresh(['atividades']), 'TEXTO GENÉRICO HISTÓRICO');

        $this->assertSame('TEXTO GENÉRICO HISTÓRICO', $descricao);
    }

    // =========================================================================
    // Necessidade já Protegida/Disponível -> nunca compõe frase de bloqueio
    // (mesmo que o Pacote tenha material formal pendente comercialmente).
    // =========================================================================
    public function test_necessidade_ja_coberta_nunca_compoe_frase_de_bloqueio(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 60);

        [$pacote] = $this->pacoteComPedidoPendente($atividade, $ito, $necessidade, 60, '2026-12-01');

        $local = \App\Models\LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Almoxarifado', 'tipo' => \App\Enums\TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
        \App\Models\MovimentacaoEstoque::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'material_id' => $material->id,
            'local_estoque_id' => $local->id, 'tipo' => \App\Enums\TipoMovimentacaoEstoque::Entrada->value,
            'quantidade' => 60, 'ocorrido_em' => now(), 'registrado_por_id' => $this->user->id,
        ]);
        \App\Models\ReservaEstoque::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'item_suprimento_id' => $pacote->id,
            'material_id' => $material->id, 'local_estoque_id' => $local->id, 'necessidade_atividade_id' => $necessidade->id,
            'quantidade' => 60, 'status' => \App\Enums\StatusReservaEstoque::Ativa->value, 'created_by_id' => $this->user->id,
        ]);

        $descricao = DescricaoRestricaoSuprimento::paraPacoteEAtividade($atividade, $pacote, 'FALLBACK');

        // Como a única necessidade relevante já está Protegida, nenhuma
        // frase de bloqueio é composta -> cai no próprio fallback (mesmo
        // comportamento que o chamador real nunca chega a exercitar, já
        // que `estadoCobreTodasParaPacote()` já teria retornado true antes).
        $this->assertSame('FALLBACK', $descricao);
    }

    // =========================================================================
    // Mesma restrição (legado) evolui a descrição conforme a causa muda
    // ao longo do processo comercial — nunca duplica.
    // =========================================================================
    public function test_mesma_restricao_legado_evolui_descricao_conforme_causa_muda(): void
    {
        $atividade = $this->criarAtividade(Carbon::today()->addDays(3)->toDateString());
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, 100);

        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, 100);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Legado']);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 100);

        // Fluxo legado sem nenhuma etapa realizada -> comercialmente em
        // risco. Precisa de 2+ etapas: com só 1, ela É a última etapa e
        // seu próprio prazo nunca a empurra pra trás dela mesma — o
        // encadeamento retroativo sempre alinha a ÚLTIMA etapa exatamente
        // com a necessidade, então só a(s) etapa(s) ANTERIOR(ES) a ela
        // acabam no passado o suficiente pra disparar EmRisco/Atrasado.
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Legado']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Solicitação', 'prazo_dias_uteis' => 30]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 2, 'nome' => 'Pedido', 'prazo_dias_uteis' => 1]);
        $pacote->update(['fluxo_suprimento_id' => $fluxo->id]);
        $pacote->atividades()->sync([$atividade->id]);
        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($pacote->fresh());
        $scheduler->congelarPrevisto($pacote->fresh(['atividades']));

        // 1) Sem nenhuma RC ainda -> NaoContratada.
        SincronizarRestricaoSuprimento::sincronizarItem($pacote->fresh(['atividades']), $this->user->id);
        $restricao = Restricao::where('atividade_id', $atividade->id)->where('origem_suprimento_item_id', $pacote->id)->first();
        $this->assertNotNull($restricao);
        $this->assertSame(StatusRestricao::Aberta, $restricao->status);
        $descricaoInicial = $restricao->descricao;
        $this->assertStringContainsString('nenhuma Requisição de Compra emitida', $descricaoInicial);

        // 2) RC emitida com parcela detalhada, sem adjudicação -> EmProcesso.
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $item = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 100);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($item, $necessidade, 100, $this->user);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        SincronizarRestricaoSuprimento::sincronizarItem($pacote->fresh(['atividades']), $this->user->id);
        $restricao = $restricao->fresh();
        $this->assertNotNull($restricao, 'Continua a MESMA linha, nunca uma nova.');
        $this->assertStringContainsString('já estão em Requisição de Compra', $restricao->descricao);
        $this->assertNotEquals($descricaoInicial, $restricao->descricao, 'A descrição evolui junto da causa real.');

        // 3) Pedido emitido, sem previsão de entrega -> SemPrazo.
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor', 'cnpj' => '00.000.000/0001-00']);
        $pedido = (new CriarPedidoCompra())->execute($rc->fresh(), $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $item->fresh(), 100);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidade, 100, $this->user);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedido->fresh()->forceFill(['data_prevista_entrega' => null])->save();

        SincronizarRestricaoSuprimento::sincronizarItem($pacote->fresh(['atividades']), $this->user->id);
        $restricao = $restricao->fresh();
        $this->assertStringContainsString('sem previsão de entrega', $restricao->descricao);
    }
}
