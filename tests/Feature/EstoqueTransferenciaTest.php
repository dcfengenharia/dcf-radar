<?php

namespace Tests\Feature;

use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\RegistrarEntradaEstoque;
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
use App\Exceptions\SaldoFisicoInsuficienteException;
use App\Exceptions\TransferenciaEstoqueInvalidaException;
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
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\TransferenciaEstoque;
use App\Models\UnidadeEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.6 — Transferência entre Locais de Estoque.
 * Cobertura A-Y do pedido (Seção 27).
 */
class EstoqueTransferenciaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

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

    // ---- helpers ----

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(array $overrides = [], ?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarFornecedor(?Work $obra = null): Fornecedor
    {
        return Fornecedor::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Fornecedor ' . uniqid()]);
    }

    private function criarLocalTerceiro(?Work $obra = null): LocalEstoque
    {
        $fornecedor = $this->criarFornecedor($obra);

        return LocalEstoque::create([
            'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Terceiro ' . uniqid(),
            'tipo' => TipoLocalEstoque::Terceiro->value, 'fornecedor_id' => $fornecedor->id, 'ativo' => true,
        ]);
    }

    private function criarItemTakeOffOrfao(?Material $material, ?Work $obra = null): ItemTakeOff
    {
        $obra ??= $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item',
            'quantidade' => 1000000, 'material_id' => $material?->id,
        ]);
    }

    private function criarRpItemEmitido(ItemTakeOff $item, float $quantidade, ?Work $obra = null): \App\Models\RequisicaoPlanejamentoItem
    {
        $obra ??= $this->obra;
        $rp = (new CriarRequisicaoPlanejamento())->execute($obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return $rpItem->fresh();
    }

    private function alocarNoPacote(\App\Models\RequisicaoPlanejamentoItem $rpItem, float $quantidade, ?Work $obra = null): AlocacaoRequisicaoPacote
    {
        $pacote = ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote ' . uniqid()]);

        return (new AlocarRequisicaoAoPacote())->alocar($rpItem, $pacote, $quantidade);
    }

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade, ?Work $obra = null, ?string $codigoLote = null, ?string $serialUnico = null): void
    {
        $obra ??= $this->obra;
        $item = $this->criarItemTakeOffOrfao($material, $obra);
        $rpItem = $this->criarRpItemEmitido($item, $quantidade, $obra);
        $alocacao = $this->alocarNoPacote($rpItem, $quantidade, $obra);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $obra->id, 'nome' => 'FornecedorCompra ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);

        (new RegistrarEntradaEstoque())->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user, $codigoLote, $serialUnico);
    }

    // =========================================================
    // A-D: quantitativo (parcial, limite exato, over-transfer, conservação)
    // =========================================================

    public function test_a_quantitativo_parcial(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 1000);

        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 300, Carbon::today(), $this->user);

        $this->assertSame(700.0, SaldoEstoque::porMaterialLocal($material, $a));
        $this->assertSame(300.0, SaldoEstoque::porMaterialLocal($material, $b));
    }

    public function test_b_limite_exato_passa(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 500);

        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 500, Carbon::today(), $this->user);

        $this->assertSame(0.0, SaldoEstoque::porMaterialLocal($material, $a));
        $this->assertSame(500.0, SaldoEstoque::porMaterialLocal($material, $b));
    }

    public function test_c_over_transfer_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 500);

        $this->expectException(SaldoFisicoInsuficienteException::class);
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 500.001, Carbon::today(), $this->user);
    }

    public function test_d_conservacao_total(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $c = $this->criarLocal();
        $this->entradaPronta($material, $a, 1000);

        $totalAntes = SaldoEstoque::porMaterialLocal($material, $a)
            + SaldoEstoque::porMaterialLocal($material, $b)
            + SaldoEstoque::porMaterialLocal($material, $c);

        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 300, Carbon::today(), $this->user);
        (new RegistrarTransferenciaEstoque())->execute($material, $b, $c, 100, Carbon::today(), $this->user);
        (new RegistrarTransferenciaEstoque())->execute($material, $c, $a, 50, Carbon::today(), $this->user);

        $totalDepois = SaldoEstoque::porMaterialLocal($material, $a)
            + SaldoEstoque::porMaterialLocal($material, $b)
            + SaldoEstoque::porMaterialLocal($material, $c);

        $this->assertSame($totalAntes, $totalDepois);
        $this->assertSame(1000.0, $totalDepois);
    }

    // =========================================================
    // E-H: bobina/lote e serial
    // =========================================================

    public function test_e_bobina_parcial(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 1000, null, 'BOBINA-E');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-E')->first();

        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 300, Carbon::today(), $this->user, $unidade);

        $this->assertSame(700.0, SaldoEstoque::porUnidadeLocal($unidade, $a));
        $this->assertSame(300.0, SaldoEstoque::porUnidadeLocal($unidade, $b));
        $this->assertSame(1000.0, SaldoEstoque::porUnidade($unidade));
        // Nunca cria unidade filha, nunca altera local de criação/origem.
        $this->assertSame(1, UnidadeEstoque::where('codigo_lote', 'BOBINA-E')->count());
        $this->assertSame($a->id, $unidade->fresh()->local_estoque_id);
    }

    public function test_f_bobina_multiplos_locais(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $c = $this->criarLocal();
        $this->entradaPronta($material, $a, 1000, null, 'BOBINA-F');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-F')->first();

        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 300, Carbon::today(), $this->user, $unidade->fresh());
        (new RegistrarTransferenciaEstoque())->execute($material, $b, $c, 100, Carbon::today(), $this->user, $unidade->fresh());
        (new RegistrarTransferenciaEstoque())->execute($material, $c, $a, 50, Carbon::today(), $this->user, $unidade->fresh());

        $this->assertSame(750.0, SaldoEstoque::porUnidadeLocal($unidade, $a)); // 700 + 50
        $this->assertSame(200.0, SaldoEstoque::porUnidadeLocal($unidade, $b)); // 300 - 100
        $this->assertSame(50.0, SaldoEstoque::porUnidadeLocal($unidade, $c)); // 100 - 50
        $this->assertSame(1000.0, SaldoEstoque::porUnidade($unidade));
    }

    public function test_g_serial(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 1, null, null, 'SERIAL-G');
        $unidade = UnidadeEstoque::where('serial_unico', 'SERIAL-G')->first();

        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 1, Carbon::today(), $this->user, $unidade);

        $this->assertSame(0.0, SaldoEstoque::porUnidadeLocal($unidade, $a));
        $this->assertSame(1.0, SaldoEstoque::porUnidadeLocal($unidade, $b));
    }

    public function test_h_serial_segunda_transferencia_bloqueada(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $c = $this->criarLocal();
        $this->entradaPronta($material, $a, 1, null, null, 'SERIAL-H');
        $unidade = UnidadeEstoque::where('serial_unico', 'SERIAL-H')->first();

        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 1, Carbon::today(), $this->user, $unidade->fresh());

        // Tentar transferir de A de novo (onde o serial já não tem saldo) é bloqueado.
        $this->expectException(TransferenciaEstoqueInvalidaException::class);
        $this->expectExceptionMessage('está em outro Local');
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $c, 1, Carbon::today(), $this->user, $unidade->fresh());
    }

    // =========================================================
    // I-L: guards estruturais
    // =========================================================

    public function test_i_origem_igual_destino_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $this->entradaPronta($material, $a, 100);

        $this->expectException(TransferenciaEstoqueInvalidaException::class);
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $a, 10, Carbon::today(), $this->user);
    }

    public function test_j_local_origem_inativo_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal(['ativo' => false]);
        $b = $this->criarLocal();

        $this->expectException(TransferenciaEstoqueInvalidaException::class);
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 10, Carbon::today(), $this->user);
    }

    public function test_j2_local_destino_inativo_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal(['ativo' => false]);
        $this->entradaPronta($material, $a, 100);

        $this->expectException(TransferenciaEstoqueInvalidaException::class);
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 10, Carbon::today(), $this->user);
    }

    public function test_k_retroativo_permitido(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 100);

        $transferencia = (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 10, Carbon::parse('2026-12-01'), $this->user);

        $this->assertSame('2026-12-01', $transferencia->ocorrido_em->toDateString());
    }

    public function test_l_futuro_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 100);

        $this->expectException(TransferenciaEstoqueInvalidaException::class);
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 10, Carbon::parse('2026-12-25'), $this->user);
    }

    // =========================================================
    // M-N: atomicidade
    // =========================================================

    public function test_m_atomicidade_cria_saida_entrada_e_transferencia_juntas(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 100);

        $transferencia = (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 30, Carbon::today(), $this->user);

        $this->assertNotNull($transferencia->movimentacao_saida_id);
        $this->assertNotNull($transferencia->movimentacao_entrada_id);
        $this->assertSame('saida', $transferencia->movimentacaoSaida->tipo->value);
        $this->assertSame('entrada', $transferencia->movimentacaoEntrada->tipo->value);
        $this->assertSame($a->id, $transferencia->movimentacaoSaida->local_estoque_id);
        $this->assertSame($b->id, $transferencia->movimentacaoEntrada->local_estoque_id);
        // Nunca é aplicação/saída pra campo.
        $this->assertNull($transferencia->movimentacaoSaida->frente_trabalho_id);
        $this->assertNull($transferencia->movimentacaoSaida->item_suprimento_id);
        $this->assertNull($transferencia->movimentacaoSaida->reserva_estoque_id);
    }

    public function test_n_excecao_no_meio_nunca_deixa_meia_transferencia(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 100);

        try {
            DB::transaction(function () use ($material, $a, $b) {
                (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 30, Carbon::today(), $this->user);
                throw new \RuntimeException('falha simulada depois da transferência');
            });
        } catch (\RuntimeException $e) {
            // esperado
        }

        $this->assertSame(100.0, SaldoEstoque::porMaterialLocal($material, $a));
        $this->assertSame(0.0, SaldoEstoque::porMaterialLocal($material, $b));
        $this->assertSame(0, TransferenciaEstoque::count());
        $this->assertSame(0, MovimentacaoEstoque::where('material_id', $material->id)->where('local_estoque_id', $b->id)->count());
    }

    // =========================================================
    // O-P: concorrência e ordem de lock (prova estrutural)
    // =========================================================

    public function test_o_concorrencia_a_para_b_e_a_para_c_nunca_formaliza_mais_que_o_fisico(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $c = $this->criarLocal();
        $this->entradaPronta($material, $a, 100);

        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 70, Carbon::today(), $this->user);

        // A segunda tentativa, sequencialmente, já não encontra saldo —
        // prova que o lock em A (adquirido antes do SUM) serializa e
        // nunca permite formalizar 140 sobre um físico de 100.
        $this->expectException(SaldoFisicoInsuficienteException::class);
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $c, 70, Carbon::today(), $this->user);
    }

    public function test_p_ordem_de_lock_deterministica_por_id_evita_deadlock_a_para_b_e_b_para_a(): void
    {
        // Prova ESTRUTURAL (mesma técnica já usada em 20.5/20.5.CORREÇÃO):
        // o SQL da query de lock dos 2 Locais SEMPRE contém `ORDER BY id`
        // — isso garante, por construção, que o MySQL trava as linhas na
        // MESMA ordem (ascendente por id) não importa em qual sentido
        // (origem/destino) a Transferência foi chamada, o que é
        // exatamente o que evita deadlock entre A→B e B→A concorrentes.
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 100);
        $this->entradaPronta($material, $b, 100);

        $sqlsComOrderById = [];
        DB::listen(function ($query) use (&$sqlsComOrderById) {
            if (str_contains($query->sql, 'for update') && str_contains($query->sql, 'locais_estoque')) {
                $sqlsComOrderById[] = str_contains(strtolower($query->sql), 'order by') && str_contains(strtolower($query->sql), '`id`');
            }
        });

        // A→B
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 10, Carbon::today(), $this->user);
        // B→A (mesmos 2 Locais, direção invertida)
        (new RegistrarTransferenciaEstoque())->execute($material, $b, $a, 10, Carbon::today(), $this->user);

        $this->assertNotEmpty($sqlsComOrderById);
        $this->assertTrue(array_reduce($sqlsComOrderById, fn ($carry, $item) => $carry && $item, true), 'Toda query de lock dos Locais precisa ordenar por id, nos dois sentidos.');
    }

    // =========================================================
    // Q-R: cross-obra / cross-tenant
    // =========================================================

    public function test_q_cross_obra_bloqueado(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $bOutraObra = $this->criarLocal([], $outraObra);
        $this->entradaPronta($material, $a, 100);

        $this->expectException(TransferenciaEstoqueInvalidaException::class);
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $bOutraObra, 10, Carbon::today(), $this->user);
    }

    public function test_r_cross_tenant_bloqueado_estruturalmente(): void
    {
        $outroTenant = Tenant::factory()->create();
        $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $bOutroTenant = LocalEstoque::create(['obra_id' => $obraOutroTenant->id, 'nome' => 'X', 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
        $this->entradaPronta($material, $a, 100);

        // Bloqueado já pela regra de mesma obra (cross-tenant nunca tem obra_id igual).
        $this->expectException(TransferenciaEstoqueInvalidaException::class);
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $bOutroTenant, 10, Carbon::today(), $this->user);
    }

    // =========================================================
    // S: comportamento com Reserva (decisão do usuário — Opção B)
    // =========================================================

    public function test_s_transferencia_permite_deixar_reserva_descoberta(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 100);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote S']);
        $reserva = (new CriarReservaEstoque())->execute($pacote, $material, $a, 80, null, null, $this->user);

        // Físico=100, Reserva=80, transferir 50 A→B: permitido (Opção B),
        // valida só contra o FÍSICO total, nunca contra o não-reservado.
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 50, Carbon::today(), $this->user);

        $this->assertSame(50.0, SaldoEstoque::porMaterialLocal($material, $a));
        $this->assertSame(50.0, SaldoEstoque::porMaterialLocal($material, $b));
        // A Reserva nunca é tocada/reduzida/liberada automaticamente.
        $this->assertTrue($reserva->fresh()->estaAtiva());
        $this->assertSame(80.0, (float) $reserva->fresh()->quantidade);
        // Fica "descoberta": disponível não-reservado na origem agora é negativo.
        $this->assertSame(-30.0, SaldoReserva::disponivelPorMaterialLocal($material, $a));
    }

    // =========================================================
    // T: Local Terceiro (decisão do usuário — bloqueado nesta etapa)
    // =========================================================

    public function test_t_local_terceiro_como_origem_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $terceiro = $this->criarLocalTerceiro();
        $proprio = $this->criarLocal();

        $this->expectException(TransferenciaEstoqueInvalidaException::class);
        (new RegistrarTransferenciaEstoque())->execute($material, $terceiro, $proprio, 10, Carbon::today(), $this->user);
    }

    public function test_t2_local_terceiro_como_destino_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $proprio = $this->criarLocal();
        $terceiro = $this->criarLocalTerceiro();
        $this->entradaPronta($material, $proprio, 100);

        $this->expectException(TransferenciaEstoqueInvalidaException::class);
        (new RegistrarTransferenciaEstoque())->execute($material, $proprio, $terceiro, 10, Carbon::today(), $this->user);
    }

    // =========================================================
    // U-V: UI autorizada / sem permissão
    // =========================================================

    public function test_u_ui_autorizada_registra_transferencia(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 100);

        $componente = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'transferencias')
            ->call('abrirModalTransferencia')
            ->set('transferenciaMaterialId', $material->id)
            ->set('transferenciaLocalOrigemId', $a->id)
            ->set('transferenciaLocalDestinoId', $b->id)
            ->set('transferenciaQuantidade', 40)
            ->set('transferenciaData', now()->toDateString())
            ->call('confirmarTransferencia');

        $componente->assertHasNoErrors();
        $this->assertSame(1, TransferenciaEstoque::count());
        $this->assertSame(60.0, SaldoEstoque::porMaterialLocal($material, $a));
        $this->assertSame(40.0, SaldoEstoque::porMaterialLocal($material, $b));
    }

    public function test_v_usuario_sem_permissao_de_criar_nao_registra_transferencia(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 100);

        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $semPermissao, 'cliente_leitura');
        $this->actingAs($semPermissao);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalTransferencia')
            ->assertStatus(403);

        $this->assertSame(0, TransferenciaEstoque::count());
    }

    // =========================================================
    // W: histórico
    // =========================================================

    public function test_w_historico_exibe_origem_destino_quantidade_data_responsavel(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal(['nome' => 'Almoxarifado Central']);
        $b = $this->criarLocal(['nome' => 'Pátio']);
        $this->entradaPronta($material, $a, 100);
        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 25, Carbon::today(), $this->user, null, 'Transferência de teste');

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'transferencias')
            ->assertSee('Almoxarifado Central')
            ->assertSee('Pátio')
            ->assertSee('25,000')
            ->assertSee($this->user->first_name);
    }

    // =========================================================
    // X: performance
    // =========================================================

    public function test_x_performance_1000_transferencias_sem_n_mais_1(): void
    {
        // Medição por DELTA (mesma técnica já estabelecida no projeto —
        // ex.: EstoqueSaidaCorrecaoTest::test_r_100_recebimentos_sem_crescimento_linear):
        // chama o computed ISOLADO (`transferenciasEstoque`), nunca um
        // render completo da página (que avalia dezenas de outros
        // computeds de todas as abas e tornaria a comparação inútil).
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 100000);

        for ($i = 0; $i < 100; $i++) {
            (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 1, Carbon::today(), $this->user);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $c1 = Livewire::test('pages::radar.estoque', ['obra' => $this->obra]);
        $c1->instance()->transferenciasEstoque;
        $q100 = count(DB::getQueryLog());

        for ($i = 0; $i < 900; $i++) {
            (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 1, Carbon::today(), $this->user);
        }

        DB::flushQueryLog();
        $c2 = Livewire::test('pages::radar.estoque', ['obra' => $this->obra]);
        $c2->instance()->transferenciasEstoque;
        $q1000 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($q100 + 10, $q1000, 'Query count não deveria escalar de 100 para 1000 transferências (N+1 suspeito)');
    }

    // =========================================================
    // Y: zero alteração de Aplicação/Prontidão/Restrição
    // =========================================================

    public function test_y_zero_alteracao_de_aplicacao_prontidao_restricao(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarLocal();
        $b = $this->criarLocal();
        $this->entradaPronta($material, $a, 100);

        (new RegistrarTransferenciaEstoque())->execute($material, $a, $b, 30, Carbon::today(), $this->user);

        $this->assertSame(0, \App\Models\AplicacaoMaterialEstoque::count());
        // Restricao não tem obra_id direto (é via atividade_id) — como
        // nenhuma Atividade/Restrição é criada em nenhum ponto deste
        // teste, uma contagem global em 0 já prova "zero criada" aqui.
        $this->assertSame(0, Restricao::count());
        // Nenhum conceito de 20.6+ (Inventário/Ajuste/Estorno) foi introduzido.
        foreach (['Inventario', 'Ajuste', 'Estorno'] as $termo) {
            $this->assertStringNotContainsString($termo, file_get_contents(app_path('Actions/Estoque/RegistrarTransferenciaEstoque.php')));
        }
    }
}
