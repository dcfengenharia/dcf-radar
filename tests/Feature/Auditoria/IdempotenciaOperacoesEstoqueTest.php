<?php

namespace Tests\Feature\Auditoria;

use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Actions\Estoque\RegistrarSaidaEstoque;
use App\Actions\Estoque\RegistrarTransferenciaEstoque;
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
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\TipoLocalEstoque;
use App\Exceptions\OperacaoEstoqueDuplicadaException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\RecebimentoPedido;
use App\Models\Tenant;
use App\Models\TransferenciaEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A2.1, Seções 5-14 — idempotência real das 3
 * Actions físicas prioritárias de Estoque (Entrada/Saída/Transferência).
 * Princípio central testado em todo o arquivo: `operation_id` protege
 * contra a MESMA intenção reenviada (retry/double-submit), NUNCA contra
 * duas operações legítimas com dados idênticos — que continuam
 * coexistindo livremente sempre que usam operation_ids diferentes (ou
 * nenhum operation_id, comportamento herdado e inalterado).
 */
class IdempotenciaOperacoesEstoqueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidadeM;

    private CriarRequisicaoPlanejamento $criarRp;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizarRp;
    private EmitirRequisicaoPlanejamento $emitirRp;
    private AlocarRequisicaoAoPacote $alocar;
    private CriarRequisicaoCompra $criarRc;
    private AtualizarRascunhoRequisicaoCompra $atualizarRc;
    private EmitirRequisicaoCompra $emitirRc;
    private CriarPedidoCompra $criarPedido;
    private AtualizarRascunhoPedidoCompra $atualizarPedido;
    private EmitirPedidoCompra $emitirPedido;
    private RegistrarRecebimentoPedido $registrarRecebimento;
    private RegistrarEntradaEstoque $registrarEntrada;
    private RegistrarSaidaEstoque $registrarSaida;
    private RegistrarTransferenciaEstoque $registrarTransferencia;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-12-15 12:00:00'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidadeM = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);

        $this->criarRp = new CriarRequisicaoPlanejamento();
        $this->atualizarRp = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitirRp = new EmitirRequisicaoPlanejamento();
        $this->alocar = new AlocarRequisicaoAoPacote();
        $this->criarRc = new CriarRequisicaoCompra();
        $this->atualizarRc = new AtualizarRascunhoRequisicaoCompra();
        $this->emitirRc = new EmitirRequisicaoCompra();
        $this->criarPedido = new CriarPedidoCompra();
        $this->atualizarPedido = new AtualizarRascunhoPedidoCompra();
        $this->emitirPedido = new EmitirPedidoCompra();
        $this->registrarRecebimento = new RegistrarRecebimentoPedido();
        $this->registrarEntrada = new RegistrarEntradaEstoque();
        $this->registrarSaida = new RegistrarSaidaEstoque();
        $this->registrarTransferencia = new RegistrarTransferenciaEstoque();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (mesmo padrão de EstoqueFundacaoTest) ----

    private function criarPacote(string $nome = 'Pacote X'): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => $nome, 'codigo' => $nome . uniqid()]);
    }

    private function criarFornecedor(): Fornecedor
    {
        return Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor X', 'cnpj' => '00.000.000/0001-00']);
    }

    private function criarFluxo(): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        return $fluxo->fresh(['etapas']);
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidadeM->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
    }

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => $this->obra->id,
            'nome' => 'Almoxarifado ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value,
            'ativo' => true,
        ]);
    }

    private function alocacaoPronta(float $quantidadeAlocada, ItemSuprimento $pacote, Material $material): AlocacaoRequisicaoPacote
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id,
            'codigo' => 'A' . uniqid(),
            'descricao' => 'Item A',
            'quantidade' => 1000,
            'material_id' => $material->id,
        ]);
        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidadeAlocada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $this->alocar->alocar($rpItem->fresh(), $pacote, $quantidadeAlocada);
    }

    /** @return array{0: RecebimentoPedido, 1: Material} */
    private function recebimentoPronto(float $quantidade): array
    {
        $material = $this->criarMaterial();
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(max($quantidade, 100), $pacote, $material);
        $rc = $this->criarRc->execute($pacote, $this->criarFluxo(), null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $pedido = $this->criarPedido->execute($rcEmitida, $this->criarFornecedor(), '2026-12-01', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $rcItem, $quantidade);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = $this->registrarRecebimento->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);

        return [$recebimento, $material];
    }

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade): void
    {
        // Precisa usar EXATAMENTE o $material recebido (nunca o material
        // próprio que recebimentoPronto() criaria) — senão a entrada vai
        // pro Material errado e o saldo do Material do teste fica zero.
        [$recebimento] = $this->recebimentoComMaterial($quantidade, $material);
        $this->registrarEntrada->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);
    }

    // =========================================================
    // A — RegistrarEntradaEstoque
    // =========================================================

    public function test_a1_entrada_com_operation_id_e_mesmo_payload_e_idempotente(): void
    {
        [$recebimento] = $this->recebimentoPronto(100);
        $local = $this->criarLocal();
        $operationId = (string) Str::ulid();

        $mov1 = $this->registrarEntrada->execute($recebimento, $local, 40, Carbon::today(), $this->user, operationId: $operationId);
        $mov2 = $this->registrarEntrada->execute($recebimento, $local, 40, Carbon::today(), $this->user, operationId: $operationId);

        $this->assertSame($mov1->id, $mov2->id);
        $this->assertSame(1, MovimentacaoEstoque::where('operation_id', $operationId)->count());
    }

    public function test_a2_entrada_com_operation_id_e_payload_divergente_e_conflito(): void
    {
        [$recebimento] = $this->recebimentoPronto(100);
        $local = $this->criarLocal();
        $operationId = (string) Str::ulid();

        $this->registrarEntrada->execute($recebimento, $local, 40, Carbon::today(), $this->user, operationId: $operationId);

        $this->expectException(OperacaoEstoqueDuplicadaException::class);
        // MESMO operation_id, quantidade DIFERENTE — não é a mesma intenção.
        $this->registrarEntrada->execute($recebimento, $local, 60, Carbon::today(), $this->user, operationId: $operationId);
    }

    public function test_a3_entrada_com_operation_ids_diferentes_e_mesmo_payload_cria_dois_fatos_legitimos(): void
    {
        // 1000m recebidos permitem 2 entradas de 40 cada sem esgotar o
        // saldo do recebimento — a mesma quantidade, 2 intenções distintas.
        [$recebimento] = $this->recebimentoPronto(1000);
        $local = $this->criarLocal();

        $mov1 = $this->registrarEntrada->execute($recebimento, $local, 40, Carbon::today(), $this->user, operationId: (string) Str::ulid());
        $mov2 = $this->registrarEntrada->execute($recebimento, $local, 40, Carbon::today(), $this->user, operationId: (string) Str::ulid());

        $this->assertNotSame($mov1->id, $mov2->id);
        $this->assertSame(2, MovimentacaoEstoque::where('recebimento_pedido_id', $recebimento->id)->count());
    }

    public function test_a4_entrada_sem_operation_id_continua_sempre_criando_novo_fato(): void
    {
        // Comportamento herdado, nunca alterado: sem operation_id, cada
        // chamada é sempre uma nova entrada — nunca deduplicada por dado.
        [$recebimento] = $this->recebimentoPronto(1000);
        $local = $this->criarLocal();

        $mov1 = $this->registrarEntrada->execute($recebimento, $local, 40, Carbon::today(), $this->user);
        $mov2 = $this->registrarEntrada->execute($recebimento, $local, 40, Carbon::today(), $this->user);

        $this->assertNotSame($mov1->id, $mov2->id);
        $this->assertNull($mov1->operation_id);
        $this->assertNull($mov2->operation_id);
    }

    public function test_a5_retry_com_operation_id_nunca_deixa_unidade_de_lote_orfa(): void
    {
        // Achado documentado no código: se o catch de corrida ficasse
        // DENTRO da transação (sem relançar), uma UnidadeEstoque de lote
        // criada especulativamente por resolverUnidadeEstoque() commitaria
        // mesmo quando o INSERT final colide — este teste prova que isso
        // NÃO acontece: o mesmo lote não aparece duplicado no banco.
        $material = Material::create([
            'codigo' => 'LOTE-' . uniqid(),
            'descricao' => 'Bobina',
            'unidade_medida_id' => $this->unidadeM->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value,
            'ativo' => true,
        ]);
        [$recebimentoLote] = $this->recebimentoComMaterial(100, $material);
        $local = $this->criarLocal();
        $operationId = (string) Str::ulid();

        $mov1 = $this->registrarEntrada->execute($recebimentoLote, $local, 40, Carbon::today(), $this->user, codigoLote: 'B001', operationId: $operationId);
        $mov2 = $this->registrarEntrada->execute($recebimentoLote, $local, 40, Carbon::today(), $this->user, codigoLote: 'B001', operationId: $operationId);

        $this->assertSame($mov1->id, $mov2->id);
        $this->assertSame(1, \App\Models\UnidadeEstoque::where('material_id', $material->id)->where('codigo_lote', 'B001')->count());
    }

    /** @return array{0: RecebimentoPedido, 1: Material} */
    private function recebimentoComMaterial(float $quantidade, Material $material): array
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(max($quantidade, 100), $pacote, $material);
        $rc = $this->criarRc->execute($pacote, $this->criarFluxo(), null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $pedido = $this->criarPedido->execute($rcEmitida, $this->criarFornecedor(), '2026-12-01', null, null, null, $this->user);
        $pedidoItem = $this->atualizarPedido->adicionarItem($pedido, $rcItem, $quantidade);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = $this->registrarRecebimento->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);

        return [$recebimento, $material];
    }

    // =========================================================
    // B — RegistrarSaidaEstoque
    // =========================================================

    public function test_b1_saida_com_operation_id_e_mesmo_payload_e_idempotente(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);
        $operationId = (string) Str::ulid();

        $mov1 = $this->registrarSaida->execute($material, $local, 30, Carbon::today(), $this->user, retiradoPor: $this->user, operationId: $operationId);
        $mov2 = $this->registrarSaida->execute($material, $local, 30, Carbon::today(), $this->user, retiradoPor: $this->user, operationId: $operationId);

        $this->assertSame($mov1->id, $mov2->id);
        $this->assertSame(1, MovimentacaoEstoque::where('operation_id', $operationId)->count());
    }

    public function test_b2_saida_com_operation_id_e_payload_divergente_e_conflito(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);
        $operationId = (string) Str::ulid();

        $this->registrarSaida->execute($material, $local, 30, Carbon::today(), $this->user, retiradoPor: $this->user, operationId: $operationId);

        $this->expectException(OperacaoEstoqueDuplicadaException::class);
        $this->registrarSaida->execute($material, $local, 20, Carbon::today(), $this->user, retiradoPor: $this->user, operationId: $operationId);
    }

    public function test_b3_saida_sem_operation_id_continua_sempre_criando_novo_fato(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);

        $mov1 = $this->registrarSaida->execute($material, $local, 10, Carbon::today(), $this->user, retiradoPor: $this->user);
        $mov2 = $this->registrarSaida->execute($material, $local, 10, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertNotSame($mov1->id, $mov2->id);
    }

    public function test_b4_saida_conflito_de_payload_nunca_cria_segundo_fato(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);
        $operationId = (string) Str::ulid();

        $this->registrarSaida->execute($material, $local, 30, Carbon::today(), $this->user, retiradoPor: $this->user, operationId: $operationId);

        try {
            $this->registrarSaida->execute($material, $local, 20, Carbon::today(), $this->user, retiradoPor: $this->user, operationId: $operationId);
            $this->fail('esperava OperacaoEstoqueDuplicadaException');
        } catch (OperacaoEstoqueDuplicadaException $e) {
        }

        // Ledger continua com exatamente 1 Saída — a tentativa de
        // conflito nunca deixou um segundo fato parcial pra trás.
        $this->assertSame(1, MovimentacaoEstoque::where('tipo', 'saida')->count());
    }

    // =========================================================
    // C — RegistrarTransferenciaEstoque
    // =========================================================

    public function test_c1_transferencia_com_operation_id_e_mesmo_payload_e_idempotente(): void
    {
        $material = $this->criarMaterial();
        $origem = $this->criarLocal();
        $destino = $this->criarLocal();
        $this->entradaPronta($material, $origem, 100);
        $operationId = (string) Str::ulid();

        $t1 = $this->registrarTransferencia->execute($material, $origem, $destino, 40, Carbon::today(), $this->user, operationId: $operationId);
        $t2 = $this->registrarTransferencia->execute($material, $origem, $destino, 40, Carbon::today(), $this->user, operationId: $operationId);

        $this->assertSame($t1->id, $t2->id);
        $this->assertSame(1, TransferenciaEstoque::where('operation_id', $operationId)->count());
        // A dupla Saída+Entrada nunca é duplicada junto com a Transferência.
        $this->assertSame(1, MovimentacaoEstoque::where('tipo', 'saida')->where('local_estoque_id', $origem->id)->count());
        $this->assertSame(1, MovimentacaoEstoque::where('tipo', 'entrada')->where('local_estoque_id', $destino->id)->count());
    }

    public function test_c2_transferencia_com_operation_id_e_payload_divergente_e_conflito(): void
    {
        $material = $this->criarMaterial();
        $origem = $this->criarLocal();
        $destino = $this->criarLocal();
        $this->entradaPronta($material, $origem, 100);
        $operationId = (string) Str::ulid();

        $this->registrarTransferencia->execute($material, $origem, $destino, 40, Carbon::today(), $this->user, operationId: $operationId);

        $this->expectException(OperacaoEstoqueDuplicadaException::class);
        // MESMO operation_id, destino DIFERENTE.
        $outroDestino = $this->criarLocal();
        $this->registrarTransferencia->execute($material, $origem, $outroDestino, 40, Carbon::today(), $this->user, operationId: $operationId);
    }

    public function test_c3_transferencia_retry_nunca_deixa_saida_entrada_orfa_sem_correlacao(): void
    {
        // Achado documentado: se o catch de corrida ficasse DENTRO da
        // transação (nunca relançando), a Saída+Entrada especulativas da
        // tentativa perdedora commitariam SEM nenhuma TransferenciaEstoque
        // correlata — este teste prova que a contagem do ledger bate
        // EXATAMENTE com 1 operação, nunca 2 pares órfãos.
        $material = $this->criarMaterial();
        $origem = $this->criarLocal();
        $destino = $this->criarLocal();
        $this->entradaPronta($material, $origem, 100);
        $operationId = (string) Str::ulid();

        $this->registrarTransferencia->execute($material, $origem, $destino, 40, Carbon::today(), $this->user, operationId: $operationId);
        $this->registrarTransferencia->execute($material, $origem, $destino, 40, Carbon::today(), $this->user, operationId: $operationId);

        $this->assertSame(1, TransferenciaEstoque::count());
        // 1 (entradaPronta) + 2 (Saída+Entrada da ÚNICA Transferência) = 3
        // — o retry idempotente nunca soma mais 2 por cima.
        $this->assertSame(3, MovimentacaoEstoque::count());
    }

    public function test_c4_transferencia_sem_operation_id_continua_sempre_criando_novo_par(): void
    {
        $material = $this->criarMaterial();
        $origem = $this->criarLocal();
        $destino = $this->criarLocal();
        $this->entradaPronta($material, $origem, 100);

        $t1 = $this->registrarTransferencia->execute($material, $origem, $destino, 10, Carbon::today(), $this->user);
        $t2 = $this->registrarTransferencia->execute($material, $origem, $destino, 10, Carbon::today(), $this->user);

        $this->assertNotSame($t1->id, $t2->id);
        $this->assertSame(2, TransferenciaEstoque::count());
    }

    // =========================================================
    // D — garantia estrutural (UNIQUE do banco), não só exists() em PHP
    // =========================================================

    public function test_d1_unique_constraint_real_em_movimentacoes_estoque_operation_id(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);
        $operationId = (string) Str::ulid();

        // Insere a PRIMEIRA linha por baixo da Action (simula o que a
        // Action faz), depois tenta inserir uma SEGUNDA linha com o MESMO
        // (tenant_id, operation_id) diretamente via Eloquent — nunca
        // passando pelo exists() da Action, provando que a garantia REAL
        // é o índice único do banco, não uma checagem em PHP.
        MovimentacaoEstoque::create([
            'operation_id' => $operationId,
            'obra_id' => $this->obra->id,
            'tipo' => 'entrada',
            'material_id' => $material->id,
            'local_estoque_id' => $local->id,
            'quantidade' => 10,
            'ocorrido_em' => Carbon::today(),
            'registrado_por' => $this->user->id,
        ]);

        $this->expectException(QueryException::class);
        MovimentacaoEstoque::create([
            'operation_id' => $operationId,
            'obra_id' => $this->obra->id,
            'tipo' => 'saida',
            'material_id' => $material->id,
            'local_estoque_id' => $local->id,
            'quantidade' => 10,
            'ocorrido_em' => Carbon::today(),
            'registrado_por' => $this->user->id,
        ]);
    }

    public function test_d2_unique_constraint_real_em_transferencias_estoque_operation_id(): void
    {
        $material = $this->criarMaterial();
        $origem = $this->criarLocal();
        $destino = $this->criarLocal();
        $this->entradaPronta($material, $origem, 100);
        $operationId = (string) Str::ulid();

        $saida = MovimentacaoEstoque::create([
            'obra_id' => $this->obra->id, 'tipo' => 'saida', 'material_id' => $material->id,
            'local_estoque_id' => $origem->id, 'quantidade' => 10, 'ocorrido_em' => Carbon::today(),
            'registrado_por' => $this->user->id,
        ]);
        $entrada = MovimentacaoEstoque::create([
            'obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id,
            'local_estoque_id' => $destino->id, 'quantidade' => 10, 'ocorrido_em' => Carbon::today(),
            'registrado_por' => $this->user->id,
        ]);

        TransferenciaEstoque::create([
            'operation_id' => $operationId,
            'obra_id' => $this->obra->id, 'material_id' => $material->id,
            'local_origem_id' => $origem->id, 'local_destino_id' => $destino->id,
            'quantidade' => 10, 'ocorrido_em' => Carbon::today(),
            'movimentacao_saida_id' => $saida->id, 'movimentacao_entrada_id' => $entrada->id,
            'registrado_por' => $this->user->id,
        ]);

        $this->expectException(QueryException::class);
        TransferenciaEstoque::create([
            'operation_id' => $operationId,
            'obra_id' => $this->obra->id, 'material_id' => $material->id,
            'local_origem_id' => $origem->id, 'local_destino_id' => $destino->id,
            'quantidade' => 10, 'ocorrido_em' => Carbon::today(),
            'movimentacao_saida_id' => $saida->id, 'movimentacao_entrada_id' => $entrada->id,
            'registrado_por' => $this->user->id,
        ]);
    }

    public function test_d3_operation_id_null_nunca_colide_entre_multiplas_linhas(): void
    {
        // Comportamento nativo do MySQL num índice único composto: cada
        // NULL é distinto — nenhuma das linhas sem operation_id colide
        // entre si, preservando 100% o comportamento pré-existente.
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);

        for ($i = 0; $i < 5; $i++) {
            MovimentacaoEstoque::create([
                'obra_id' => $this->obra->id,
                'tipo' => 'saida',
                'material_id' => $material->id,
                'local_estoque_id' => $local->id,
                'quantidade' => 1,
                'ocorrido_em' => Carbon::today(),
                'registrado_por' => $this->user->id,
            ]);
        }

        $this->assertSame(5, MovimentacaoEstoque::whereNull('operation_id')->where('tipo', 'saida')->count());
    }

    public function test_d4_operation_id_e_escopado_por_tenant_nunca_colide_entre_tenants(): void
    {
        // Mesmo operation_id (coincidência de UUID de dois tenants
        // diferentes é matematicamente irrelevante, mas o desenho do
        // índice — UNIQUE(tenant_id, operation_id) — precisa permitir
        // isso de qualquer forma, nunca vazando isolamento entre tenants).
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);
        $operationId = (string) Str::ulid();

        MovimentacaoEstoque::create([
            'operation_id' => $operationId,
            'obra_id' => $this->obra->id, 'tipo' => 'saida', 'material_id' => $material->id,
            'local_estoque_id' => $local->id, 'quantidade' => 1, 'ocorrido_em' => Carbon::today(),
            'registrado_por' => $this->user->id,
        ]);

        $outroTenant = Tenant::factory()->create();
        \App\Support\TenantContext::actingAs($outroTenant, function () use ($operationId, $outroTenant) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $outroMaterial = Material::create([
                'codigo' => 'MAT-OUTRO-' . uniqid(), 'descricao' => 'X',
                'unidade_medida_id' => UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'UN2', 'nome' => 'Unidade'])->id,
                'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
            ]);
            $outroLocal = LocalEstoque::create(['obra_id' => $outraObra->id, 'nome' => 'L', 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);

            // Nunca lança — MESMO operation_id, TENANT diferente.
            MovimentacaoEstoque::create([
                'operation_id' => $operationId,
                'obra_id' => $outraObra->id, 'tipo' => 'saida', 'material_id' => $outroMaterial->id,
                'local_estoque_id' => $outroLocal->id, 'quantidade' => 1, 'ocorrido_em' => Carbon::today(),
                'registrado_por' => null,
            ]);
        });

        $this->assertSame(2, DB::table('movimentacoes_estoque')->where('operation_id', $operationId)->count());
    }
}
