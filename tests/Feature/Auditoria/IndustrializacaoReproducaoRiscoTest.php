<?php

namespace Tests\Feature\Auditoria;

use App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao;
use App\Actions\Estoque\CriarOrdemIndustrializacao;
use App\Actions\Estoque\EmitirOrdemIndustrializacao;
use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Actions\Estoque\RegistrarProducaoIndustrializada;
use App\Actions\Estoque\RegistrarRemessaIndustrializacao;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Actions\Suprimentos\RegistrarRecebimentoPedido;
use App\Enums\DirecaoRemessaIndustrializacao;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\TipoLocalEstoque;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\ProducaoIndustrializada;
use App\Models\RemessaIndustrializacao;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\SaldoEstoque;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A2.2, Seção 4 — REPRODUÇÃO EMPÍRICA do risco
 * histórico, executada e CONFIRMADA ANTES de qualquer correção (rodada
 * isolada, resultado documentado no relatório final): "mesma intenção de
 * retorno/produção industrializada enviada duas vezes → estoque/fato
 * físico duplicado". Os 2 testes abaixo chamam as Actions reais SEM
 * `operationId` (exatamente o que já acontecia em todo o domínio antes
 * da A2.2) duas vezes, com os MESMOS dados.
 *
 * **Continuam passando DEPOIS da correção da Seção 5, de propósito** —
 * `operationId` é OPCIONAL: sem ele, o comportamento é o mesmo de
 * sempre (nunca deduplica por dado, 2 chamadas = 2 fatos legítimos,
 * mesmo princípio de "duas operações idênticas continuam permitidas"
 * já estabelecido na A2.1). Documentam a linha de base — é exatamente
 * por isso que a UI (Seção 7) passa a gerar um `operationId` sempre que
 * a intenção nasce, para SE proteger deste comportamento quando
 * desejado. A proteção de verdade (mesma operation_id nunca duplica) é
 * testada em `tests/Feature/Auditoria/IdempotenciaIndustrializacaoTest.php`.
 */
class IndustrializacaoReproducaoRiscoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-20 12:00:00'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ]);
    }

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => $this->obra->id, 'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true,
        ]);
    }

    private function criarFornecedor(): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
    }

    private function criarLocalTerceiro(Fornecedor $fornecedor): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => $this->obra->id, 'nome' => 'Terceiro ' . uniqid(),
            'tipo' => TipoLocalEstoque::Terceiro->value, 'fornecedor_id' => $fornecedor->id, 'ativo' => true,
        ]);
    }

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade): void
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item',
            'quantidade' => 1000000, 'material_id' => $material->id,
        ]);

        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $rpItem = $rpItem->fresh();

        $pacote = \App\Models\ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem, $pacote, $quantidade);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'FornecedorCompra ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);
        (new RegistrarEntradaEstoque())->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);
    }

    public function test_reproducao_retorno_de_sobra_reenviado_duplica_estoque(): void
    {
        $materiaPrima = $this->criarMaterial();
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $this->entradaPronta($materiaPrima, $localProprio, 1000);

        $ordem = (new CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        (new AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $this->criarMaterial(), 100, $this->user);
        $ordem = (new EmitirOrdemIndustrializacao())->execute($ordem->fresh(), $this->user);

        // Envia 500 pro terceiro primeiro (pra ter sobra a devolver).
        (new RegistrarRemessaIndustrializacao())->execute(
            $ordem, $materiaPrima, 500, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user
        );

        $saldoObraAntes = SaldoEstoque::porMaterialLocal($materiaPrima, $localProprio);
        $this->assertEqualsWithDelta(500.0, $saldoObraAntes, 0.001);

        // MESMA intenção de retorno de 200 de sobra, enviada DUAS VEZES
        // (duplo-clique/retry) — código ATUAL não tem nenhuma proteção.
        (new RegistrarRemessaIndustrializacao())->execute(
            $ordem, $materiaPrima, 200, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user
        );
        (new RegistrarRemessaIndustrializacao())->execute(
            $ordem, $materiaPrima, 200, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user
        );

        $saldoObraDepois = SaldoEstoque::porMaterialLocal($materiaPrima, $localProprio);

        // PROVA DO BUG (código atual, sem idempotência): o saldo sobe
        // 400 (2x 200), não 200 — estoque fantasma real e mensurável.
        $this->assertEqualsWithDelta(900.0, $saldoObraDepois, 0.001, 'BUG CONFIRMADO: retorno duplicado inflou o saldo em 2x, sem nenhuma proteção.');
        $this->assertSame(2, RemessaIndustrializacao::where('direcao', DirecaoRemessaIndustrializacao::RetornoSobra->value)->count(), 'BUG CONFIRMADO: 2 fatos de retorno foram criados pra 1 única intenção.');
    }

    public function test_reproducao_producao_reenviada_duplica_estoque(): void
    {
        $materiaPrima = $this->criarMaterial();
        $materialProduto = $this->criarMaterial();
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $this->entradaPronta($materiaPrima, $localProprio, 1000);

        $ordem = (new CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        (new AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $materialProduto, 100, $this->user);
        $ordem = (new EmitirOrdemIndustrializacao())->execute($ordem->fresh(), $this->user);
        $produto = $ordem->fresh()->produtos->first();

        // MESMA intenção de produção de 30 unidades, enviada DUAS VEZES.
        (new RegistrarProducaoIndustrializada())->execute($produto, 30, Carbon::today(), $this->user);
        (new RegistrarProducaoIndustrializada())->execute($produto, 30, Carbon::today(), $this->user);

        $saldoTerceiro = SaldoEstoque::porMaterialLocal($materialProduto, $localTerceiro);

        // PROVA DO BUG: 60 produzidos em vez de 30 — a mesma fabricação
        // "aparece" duas vezes no estoque do terceiro.
        $this->assertEqualsWithDelta(60.0, $saldoTerceiro, 0.001, 'BUG CONFIRMADO: produção duplicada inflou o saldo em 2x, sem nenhuma proteção.');
        $this->assertSame(2, ProducaoIndustrializada::where('produto_industrializado_id', $produto->id)->count());
        $this->assertSame(2, MovimentacaoEstoque::where('material_id', $materialProduto->id)->count());
    }
}
