<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaPedidoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Actions\Suprimentos\RegistrarRecebimentoPedido;
use App\Enums\EstadoGerencialNecessidade;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusReservaEstoque;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoMovimentacaoEstoque;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\ReservaEstoque;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\CoberturaNecessidadeAtividadeQuery;
use App\Support\Gestao\HomeExecutivaQuery;
use App\Support\Suprimentos\EstadoAtendimentoNecessidadeMaterialQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * CORREÇÃO PRÉ-PRODUÇÃO P1 — N+1 na Home Executiva.
 *
 * Prova, permanentemente, que a API batch nova
 * (`EstadoAtendimentoNecessidadeMaterialQuery::porAtividades()` /
 * `CoberturaNecessidadeAtividadeQuery::porAtividades()`) produz
 * EXATAMENTE o mesmo resultado que as chamadas individuais
 * pré-existentes (`porAtividade()`), cobre os 10 estados gerenciais do
 * Motor V1, garante isolamento entre atividades/materiais/obras/tenants,
 * e que o custo em queries deixou de crescer linearmente com o número
 * de atividades — sem alterar nenhuma regra de negócio (`montarLinha()`
 * permanece intocado).
 */
class EstadoAtendimentoNecessidadeMaterialBatchTest extends TestCase
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

    // ---- helpers (mesmo padrão de EstadoAtendimentoNecessidadeMaterialTest / DecomposicaoQuantitativaNecessidadeTest) ----

    private function criarAtividade(?string $inicioPlanejado = '2027-01-20', ?Work $obra = null): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'inicio_planejado' => $inicioPlanejado,
        ]);
    }

    private function criarMaterial(?UnidadeMedida $unidade = null): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Material de teste',
            'unidade_medida_id' => ($unidade ?? $this->unidade)->id,
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

    private function alocacaoPronta(ItemTakeOff $ito, float $quantidade): AlocacaoRequisicaoPacote
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);
    }

    private function criarFluxo(): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        return $fluxo->fresh(['etapas']);
    }

    private function rcComParcela(ItemTakeOff $ito, AtividadeNecessidadeMaterial $necessidade, float $quantidadeRc, float $quantidadeParcela): array
    {
        $alocacao = $this->alocacaoPronta($ito, $quantidadeRc);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $this->criarFluxo(), null, $this->user);
        $item = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidadeRc);
        $parcela = (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($item, $necessidade, $quantidadeParcela, $this->user);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        return [$rc->fresh(), $item->fresh(), $parcela->fresh()];
    }

    private function fornecedor(string $nome = 'Fornecedor'): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => $nome . ' ' . uniqid(), 'cnpj' => '00.000.000/0001-00']);
    }

    private function pedidoParaParcela($rc, $rcItem, AtividadeNecessidadeMaterial $necessidade, Fornecedor $fornecedor, float $quantidade, ?string $data): \App\Models\PedidoCompra
    {
        $pedido = (new CriarPedidoCompra())->execute($rc->fresh(), $fornecedor, $data, null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem->fresh(), $quantidade);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidade, $quantidade, $this->user);

        return (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
    }

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Almoxarifado ' . uniqid(), 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
    }

    private function entradaEstoque(Material $material, LocalEstoque $local, float $quantidade): void
    {
        MovimentacaoEstoque::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'material_id' => $material->id,
            'local_estoque_id' => $local->id, 'tipo' => TipoMovimentacaoEstoque::Entrada->value,
            'quantidade' => $quantidade, 'ocorrido_em' => now(), 'registrado_por_id' => $this->user->id,
        ]);
    }

    private function reservar(ItemSuprimento $pacote, Material $material, LocalEstoque $local, float $quantidade, AtividadeNecessidadeMaterial $necessidade): ReservaEstoque
    {
        return ReservaEstoque::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'item_suprimento_id' => $pacote->id,
            'material_id' => $material->id, 'local_estoque_id' => $local->id, 'necessidade_atividade_id' => $necessidade->id,
            'quantidade' => $quantidade, 'status' => StatusReservaEstoque::Ativa->value, 'created_by_id' => $this->user->id,
        ]);
    }

    /** @return array{0: Atividade, 1: AtividadeNecessidadeMaterial, 2: Material} */
    private function cenarioComEstoque(float $necessario, float $reservado, float $livreExtra): array
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = $this->criarNecessidade($atividade, $ito, $necessario);

        $local = $this->criarLocal();
        $totalFisico = $reservado + $livreExtra;
        if ($totalFisico > 0) {
            $this->entradaEstoque($material, $local, $totalFisico);
        }
        if ($reservado > 0) {
            $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
            $this->reservar($pacote, $material, $local, $reservado, $necessidade);
        }

        return [$atividade, $necessidade, $material];
    }

    // =========================================================================
    // A — Equivalência: batch cobrindo os 10 estados gerenciais == chamadas individuais
    // =========================================================================

    public function test_a_equivalencia_batch_cobre_os_10_estados_gerenciais_e_bate_com_chamadas_individuais(): void
    {
        $cenarios = [];

        // 1) Protegida — reserva integral.
        [$atProtegida, $necProtegida] = $this->cenarioComEstoque(100, 100, 0);
        $cenarios[$atProtegida->id] = ['nec' => $necProtegida, 'estado' => EstadoGerencialNecessidade::Protegida];

        // 2) DisponivelNaoReservada — estoque livre, sem reserva.
        [$atDisponivel, $necDisponivel] = $this->cenarioComEstoque(100, 0, 100);
        $cenarios[$atDisponivel->id] = ['nec' => $necDisponivel, 'estado' => EstadoGerencialNecessidade::DisponivelNaoReservada];

        // 3) RecebidaAguardandoDisponibilizacao — recebimento comercial integral, sem "Dar entrada" no estoque.
        $atRecebida = $this->criarAtividade();
        $matRecebida = $this->criarMaterial();
        $itoRecebida = $this->criarItemTakeOff($matRecebida, 1000);
        $necRecebida = $this->criarNecessidade($atRecebida, $itoRecebida, 100);
        [$rcRec, $itemRec] = $this->rcComParcela($itoRecebida, $necRecebida, 100, 100);
        $pedidoRec = $this->pedidoParaParcela($rcRec, $itemRec, $necRecebida, $this->fornecedor(), 100, '2027-01-10');
        $pedidoItemRec = $pedidoRec->fresh(['itens'])->itens->first();
        (new RegistrarRecebimentoPedido())->execute($pedidoItemRec, 100, now()->subDay(), $this->user, null, null);
        $cenarios[$atRecebida->id] = ['nec' => $necRecebida, 'estado' => EstadoGerencialNecessidade::RecebidaAguardandoDisponibilizacao];

        // 4) DependenteFornecimentoNoPrazo — pedido antes da necessidade.
        $atNoPrazo = $this->criarAtividade('2027-01-20');
        $matNoPrazo = $this->criarMaterial();
        $itoNoPrazo = $this->criarItemTakeOff($matNoPrazo, 1000);
        $necNoPrazo = $this->criarNecessidade($atNoPrazo, $itoNoPrazo, 100);
        [$rcNoPrazo, $itemNoPrazo] = $this->rcComParcela($itoNoPrazo, $necNoPrazo, 100, 100);
        $this->pedidoParaParcela($rcNoPrazo, $itemNoPrazo, $necNoPrazo, $this->fornecedor(), 100, '2027-01-10');
        $cenarios[$atNoPrazo->id] = ['nec' => $necNoPrazo, 'estado' => EstadoGerencialNecessidade::DependenteFornecimentoNoPrazo];

        // 5) DependenteFornecimentoAtrasado — pedido depois da necessidade.
        $atAtrasado = $this->criarAtividade('2027-01-20');
        $matAtrasado = $this->criarMaterial();
        $itoAtrasado = $this->criarItemTakeOff($matAtrasado, 1000);
        $necAtrasado = $this->criarNecessidade($atAtrasado, $itoAtrasado, 100);
        [$rcAtrasado, $itemAtrasado] = $this->rcComParcela($itoAtrasado, $necAtrasado, 100, 100);
        $this->pedidoParaParcela($rcAtrasado, $itemAtrasado, $necAtrasado, $this->fornecedor(), 100, '2027-01-30');
        $cenarios[$atAtrasado->id] = ['nec' => $necAtrasado, 'estado' => EstadoGerencialNecessidade::DependenteFornecimentoAtrasado];

        // 6) SemPrazo — Pedido Emitido legado sem data_prevista_entrega.
        $atSemPrazo = $this->criarAtividade('2027-01-20');
        $matSemPrazo = $this->criarMaterial();
        $itoSemPrazo = $this->criarItemTakeOff($matSemPrazo, 1000);
        $necSemPrazo = $this->criarNecessidade($atSemPrazo, $itoSemPrazo, 100);
        [$rcSemPrazo, $itemSemPrazo] = $this->rcComParcela($itoSemPrazo, $necSemPrazo, 100, 100);
        $pedidoSemPrazo = $this->pedidoParaParcela($rcSemPrazo, $itemSemPrazo, $necSemPrazo, $this->fornecedor(), 100, '2027-01-10');
        $pedidoSemPrazo->forceFill(['data_prevista_entrega' => null])->save();
        $cenarios[$atSemPrazo->id] = ['nec' => $necSemPrazo, 'estado' => EstadoGerencialNecessidade::SemPrazo];

        // 7) PrePedido — adjudicado sem Pedido.
        $atAdjudicada = $this->criarAtividade('2027-01-20');
        $matAdjudicada = $this->criarMaterial();
        $itoAdjudicada = $this->criarItemTakeOff($matAdjudicada, 1000);
        $necAdjudicada = $this->criarNecessidade($atAdjudicada, $itoAdjudicada, 100);
        [$rcAdjudicada, $itemAdjudicada, $parcelaAdjudicada] = $this->rcComParcela($itoAdjudicada, $necAdjudicada, 100, 100);
        $adjudicacao = (new CriarAdjudicacaoRequisicaoCompra())->execute($rcAdjudicada->fresh(), $this->fornecedor(), 'Decisão', null, null, $this->user);
        (new AtualizarAdjudicacaoRequisicaoCompra())->adicionarItem($adjudicacao, $itemAdjudicada->fresh(), $parcelaAdjudicada->fresh(), 100, $this->user);
        $cenarios[$atAdjudicada->id] = ['nec' => $necAdjudicada, 'estado' => EstadoGerencialNecessidade::PrePedido];

        // 8) EmProcesso — RC Emitida, sem adjudicação.
        $atEmProcesso = $this->criarAtividade('2027-01-20');
        $matEmProcesso = $this->criarMaterial();
        $itoEmProcesso = $this->criarItemTakeOff($matEmProcesso, 1000);
        $necEmProcesso = $this->criarNecessidade($atEmProcesso, $itoEmProcesso, 100);
        [$rcEmProcesso] = $this->rcComParcela($itoEmProcesso, $necEmProcesso, 100, 100);
        $cenarios[$atEmProcesso->id] = ['nec' => $necEmProcesso, 'estado' => EstadoGerencialNecessidade::EmProcesso];

        // 9) NaoContratada — sem nenhum vínculo comercial.
        $atSemCobertura = $this->criarAtividade('2027-01-20');
        $matSemCobertura = $this->criarMaterial();
        $itoSemCobertura = $this->criarItemTakeOff($matSemCobertura, 1000);
        $necSemCobertura = $this->criarNecessidade($atSemCobertura, $itoSemCobertura, 100);
        $cenarios[$atSemCobertura->id] = ['nec' => $necSemCobertura, 'estado' => EstadoGerencialNecessidade::NaoContratada];

        // 10) InformacaoInsuficiente — unidade incompatível.
        $outraUnidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'KG', 'nome' => 'Quilograma']);
        $matIncompativel = $this->criarMaterial($outraUnidade); // Material em KG
        $atIncompativel = $this->criarAtividade('2027-01-20');
        $itoIncompativel = $this->criarItemTakeOff($matIncompativel, 1000); // necessidade nasce em UN (padrão do helper)
        $necIncompativel = $this->criarNecessidade($atIncompativel, $itoIncompativel, 100);
        $cenarios[$atIncompativel->id] = ['nec' => $necIncompativel, 'estado' => EstadoGerencialNecessidade::InformacaoInsuficiente];

        $this->assertCount(10, $cenarios, 'Sanity check: os 10 estados gerenciais precisam de 10 atividades distintas.');

        $atividades = Atividade::whereIn('id', array_keys($cenarios))->get();
        $this->assertCount(10, $atividades);

        $batch = EstadoAtendimentoNecessidadeMaterialQuery::porAtividades($atividades);

        foreach ($cenarios as $atividadeId => $c) {
            $linhaBatch = $batch->get($atividadeId, collect())->firstWhere('necessidade.id', $c['nec']->id);
            $this->assertNotNull($linhaBatch, "Atividade {$atividadeId} ausente do resultado batch.");

            // 1) O batch reproduz o estado gerencial ESPERADO (fonte da
            // semântica = os mesmos cenários já provados corretos pelos
            // testes individuais existentes, nunca um valor novo inventado).
            $this->assertSame(
                $c['estado'],
                $linhaBatch['estado_gerencial'],
                "Estado gerencial incorreto pra atividade {$atividadeId} (esperado {$c['estado']->value})."
            );

            // 2) O batch bate EXATAMENTE com a chamada individual pré-existente
            // pra essa MESMA atividade, isolada (prova de equivalência real).
            $linhaIndividual = EstadoAtendimentoNecessidadeMaterialQuery::porAtividade(
                Atividade::find($atividadeId)
            )->firstWhere('necessidade.id', $c['nec']->id);

            $this->assertSame($linhaIndividual['estado_gerencial'], $linhaBatch['estado_gerencial']);
            $this->assertSame($linhaIndividual['estado_comercial'], $linhaBatch['estado_comercial']);
            $this->assertSame($linhaIndividual['estado_compromisso'], $linhaBatch['estado_compromisso']);
            $this->assertSame($linhaIndividual['estado_fisico'], $linhaBatch['estado_fisico']);
            $this->assertEquals($linhaIndividual['quantidade_necessaria'], $linhaBatch['quantidade_necessaria']);
            $this->assertEquals($linhaIndividual['quantidade_em_rc'], $linhaBatch['quantidade_em_rc']);
            $this->assertEquals($linhaIndividual['quantidade_adjudicada'], $linhaBatch['quantidade_adjudicada']);
            $this->assertEquals($linhaIndividual['quantidade_pedida'], $linhaBatch['quantidade_pedida']);
            $this->assertEquals($linhaIndividual['quantidade_recebida'], $linhaBatch['quantidade_recebida']);
            $this->assertEquals($linhaIndividual['folga_dias'], $linhaBatch['folga_dias']);
            $this->assertEquals($linhaIndividual['qualidade_informacao'], $linhaBatch['qualidade_informacao']);
            foreach ([
                'decomposicao_reservado', 'decomposicao_disponivel',
                'decomposicao_recebida_aguardando_disponibilizacao',
                'decomposicao_dependente_no_prazo', 'decomposicao_dependente_atrasado',
                'decomposicao_pedida_sem_prazo', 'decomposicao_adjudicada_sem_pedido',
                'decomposicao_em_processo', 'decomposicao_sem_cobertura',
                'decomposicao_informacao_insuficiente',
            ] as $chave) {
                $this->assertEquals($linhaIndividual[$chave], $linhaBatch[$chave], "Divergência em {$chave} pra atividade {$atividadeId}.");
            }
        }
    }

    // =========================================================================
    // B — Isolamento: concorrência pelo mesmo estoque livre nunca vira garantia dupla (mesmo cenário J já provado, agora via batch)
    // =========================================================================

    public function test_b_isolamento_concorrencia_pelo_mesmo_estoque_livre_no_batch(): void
    {
        $material = $this->criarMaterial();

        $atividadeA = $this->criarAtividade('2027-01-20');
        $itoA = $this->criarItemTakeOff($material, 1000);
        $necessidadeA = $this->criarNecessidade($atividadeA, $itoA, 80);

        $atividadeB = $this->criarAtividade('2027-01-20');
        $itoB = $this->criarItemTakeOff($material, 1000);
        $necessidadeB = $this->criarNecessidade($atividadeB, $itoB, 80);

        $local = $this->criarLocal();
        $this->entradaEstoque($material, $local, 100); // só 100 livres, pra duas necessidades de 80 cada

        $batch = EstadoAtendimentoNecessidadeMaterialQuery::porAtividades(
            Atividade::whereIn('id', [$atividadeA->id, $atividadeB->id])->get()
        );

        $linhaA = $batch->get($atividadeA->id)->firstWhere('necessidade.id', $necessidadeA->id);
        $linhaB = $batch->get($atividadeB->id)->firstWhere('necessidade.id', $necessidadeB->id);

        // Cada uma, isoladamente, vê os mesmos 100 livres — o batch nunca
        // decrementa o pool entre atividades (mesma garantia de sempre).
        $this->assertEquals(80.0, $linhaA['decomposicao_disponivel']);
        $this->assertEquals(80.0, $linhaB['decomposicao_disponivel']);
    }

    // =========================================================================
    // C — Isolamento: Pedido/RC de A nunca vaza pra B, mesmo Material, no batch
    // =========================================================================

    public function test_c_isolamento_pedido_de_uma_atividade_nunca_vaza_para_outra_no_batch(): void
    {
        $atividadeA = $this->criarAtividade('2027-01-20');
        $atividadeB = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();

        $itoA = $this->criarItemTakeOff($material, 1000);
        $necessidadeA = $this->criarNecessidade($atividadeA, $itoA, 100);
        [$rcA, $itemA] = $this->rcComParcela($itoA, $necessidadeA, 100, 100);
        $this->pedidoParaParcela($rcA, $itemA, $necessidadeA, $this->fornecedor(), 100, '2027-01-10');

        // Necessidade B, MESMO Material, sem nenhuma cadeia própria de RC/Pedido.
        $itoB = $this->criarItemTakeOff($material, 1000);
        $necessidadeB = $this->criarNecessidade($atividadeB, $itoB, 50);

        $batch = EstadoAtendimentoNecessidadeMaterialQuery::porAtividades(
            Atividade::whereIn('id', [$atividadeA->id, $atividadeB->id])->get()
        );

        $linhaA = $batch->get($atividadeA->id)->firstWhere('necessidade.id', $necessidadeA->id);
        $linhaB = $batch->get($atividadeB->id)->firstWhere('necessidade.id', $necessidadeB->id);

        $this->assertEquals(100.0, $linhaA['quantidade_pedida']);
        $this->assertEquals(0.0, $linhaB['quantidade_pedida'], 'O Pedido de A nunca pode vazar pra B só por compartilhar o Material.');
        $this->assertSame(EstadoGerencialNecessidade::DependenteFornecimentoNoPrazo, $linhaA['estado_gerencial']);
        $this->assertSame(EstadoGerencialNecessidade::NaoContratada, $linhaB['estado_gerencial']);
    }

    // =========================================================================
    // D — Isolamento: duas necessidades do MESMO material na MESMA atividade
    // nunca se misturam entre si dentro do batch
    // =========================================================================

    public function test_d_duas_necessidades_mesmo_material_mesma_atividade_permanecem_distintas(): void
    {
        $atividade = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();

        $ito1 = $this->criarItemTakeOff($material, 1000);
        $necessidade1 = $this->criarNecessidade($atividade, $ito1, 100);
        [$rc1, $item1] = $this->rcComParcela($ito1, $necessidade1, 100, 100);
        $this->pedidoParaParcela($rc1, $item1, $necessidade1, $this->fornecedor(), 100, '2027-01-10');

        $ito2 = $this->criarItemTakeOff($material, 1000);
        $necessidade2 = $this->criarNecessidade($atividade, $ito2, 60); // sem cobertura comercial própria

        $outraAtividade = $this->criarAtividade('2027-01-20');

        $batch = EstadoAtendimentoNecessidadeMaterialQuery::porAtividades(
            Atividade::whereIn('id', [$atividade->id, $outraAtividade->id])->get()
        );

        $linhaGrupo = $batch->get($atividade->id);
        $this->assertCount(2, $linhaGrupo, 'As duas necessidades da mesma atividade devem aparecer, cada uma com seu próprio estado.');

        $linha1 = $linhaGrupo->firstWhere('necessidade.id', $necessidade1->id);
        $linha2 = $linhaGrupo->firstWhere('necessidade.id', $necessidade2->id);

        $this->assertEquals(100.0, $linha1['quantidade_pedida']);
        $this->assertEquals(0.0, $linha2['quantidade_pedida']);
        $this->assertSame(EstadoGerencialNecessidade::NaoContratada, $linha2['estado_gerencial']);
    }

    // =========================================================================
    // E — Isolamento: outra obra do MESMO tenant é rejeitada deterministicamente
    // =========================================================================

    public function test_e_lote_com_atividades_de_obras_diferentes_e_rejeitado_deterministicamente(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);

        $atividadeObraA = $this->criarAtividade('2027-01-20', $this->obra);
        $atividadeObraB = $this->criarAtividade('2027-01-20', $outraObra);

        $this->expectException(\InvalidArgumentException::class);

        EstadoAtendimentoNecessidadeMaterialQuery::porAtividades(
            collect([$atividadeObraA, $atividadeObraB])
        );
    }

    /** Mesma garantia, na camada de cobertura física (delegada, não duplicada). */
    public function test_e2_cobertura_com_atividades_de_obras_diferentes_e_rejeitada_deterministicamente(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);

        $atividadeObraA = $this->criarAtividade('2027-01-20', $this->obra);
        $atividadeObraB = $this->criarAtividade('2027-01-20', $outraObra);

        $this->expectException(\InvalidArgumentException::class);

        CoberturaNecessidadeAtividadeQuery::porAtividades(collect([$atividadeObraA, $atividadeObraB]));
    }

    // =========================================================================
    // F — Isolamento: outro tenant nunca vaza via batch (global scope estrutural)
    // =========================================================================

    public function test_f_atividade_de_outro_tenant_nunca_aparece_no_resultado_do_batch(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        $atividadeDoTenantAtual = $this->criarAtividade('2027-01-20');
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $this->criarNecessidade($atividadeDoTenantAtual, $ito, 100);

        // Atividade de OUTRO tenant, criada dentro do contexto correto dele
        // (nunca confiando em tenant_id explícito ignorado pelo BelongsToTenant).
        $atividadeOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outraObraOutroTenant) {
            return Atividade::factory()->create([
                'tenant_id' => $outraObraOutroTenant->tenant_id,
                'obra_id' => $outraObraOutroTenant->id,
                'inicio_planejado' => '2027-01-20',
            ]);
        });

        // Ainda autenticado no tenant ATUAL: passar o ID de uma atividade de
        // outro tenant nunca deveria conseguir ler nada dela (global scope).
        $atividadesMisturadas = collect([
            $atividadeDoTenantAtual,
            Atividade::withoutGlobalScopes()->find($atividadeOutroTenant->id),
        ]);

        // Como as duas "atividades" resolvidas têm obra_id diferentes
        // (obras de tenants diferentes), o guard de obra única já rejeita
        // — prova, por outro caminho, que misturar tenants nunca é uma
        // operação silenciosa: ou é rejeitada (obra diferente), ou o
        // global scope já a torna invisível antes mesmo de chegar aqui.
        $this->expectException(\InvalidArgumentException::class);
        EstadoAtendimentoNecessidadeMaterialQuery::porAtividades($atividadesMisturadas);
    }

    // =========================================================================
    // G — Performance estrutural: query count NÃO cresce linearmente (20 -> 80 atividades)
    // =========================================================================

    private function montarNecessidadesComEstadosVariados(int $quantidadeComNecessidade): Collection
    {
        $atividades = collect();
        $material = $this->criarMaterial();

        for ($i = 0; $i < $quantidadeComNecessidade; $i++) {
            $atividade = $this->criarAtividade('2027-01-20');
            $ito = $this->criarItemTakeOff($material, 1000);
            $this->criarNecessidade($atividade, $ito, 10.0);
            $atividades->push($atividade);
        }

        return $atividades;
    }

    public function test_g_query_count_do_batch_nao_cresce_linearmente_com_o_numero_de_atividades(): void
    {
        $atividades20 = $this->montarNecessidadesComEstadosVariados(20);

        DB::flushQueryLog();
        DB::enableQueryLog();
        EstadoAtendimentoNecessidadeMaterialQuery::porAtividades($atividades20);
        $queries20 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $atividades80 = $atividades20->merge($this->montarNecessidadesComEstadosVariados(60)); // total 80

        DB::flushQueryLog();
        DB::enableQueryLog();
        EstadoAtendimentoNecessidadeMaterialQuery::porAtividades($atividades80);
        $queries80 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Crescimento aceitável: só a diferença fixa de setup/overhead de
        // conexão, NUNCA proporcional ao número de atividades (que
        // quadruplicou, de 20 pra 80). Antes da correção, essa mesma
        // medição (via HomeExecutivaQuery) ia de 74 pra 228 queries.
        $this->assertLessThan(
            $queries20 + 10,
            $queries80,
            "Query count cresceu de forma linear: {$queries20} -> {$queries80} (esperado O(1), não O(N))."
        );
    }

    // =========================================================================
    // H — Performance estrutural end-to-end: HomeExecutivaQuery::resumo()
    // =========================================================================

    public function test_h_home_executiva_query_count_nao_cresce_linearmente_apos_a_correcao(): void
    {
        $this->montarNecessidadesComEstadosVariados(20);

        DB::flushQueryLog();
        DB::enableQueryLog();
        HomeExecutivaQuery::resumo($this->obra);
        $queries20 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->montarNecessidadesComEstadosVariados(60); // total 80

        DB::flushQueryLog();
        DB::enableQueryLog();
        HomeExecutivaQuery::resumo($this->obra);
        $queries80 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Antes da correção: 74 -> 228 (+154). Depois: crescimento deve
        // ficar restrito a uma diferença fixa e pequena.
        $this->assertLessThan(
            $queries20 + 20,
            $queries80,
            "Home Executiva ainda escala linearmente: {$queries20} -> {$queries80}."
        );
    }
}
