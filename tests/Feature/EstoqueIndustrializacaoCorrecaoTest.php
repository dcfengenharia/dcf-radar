<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao;
use App\Actions\Estoque\CriarOrdemIndustrializacao;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\EmitirOrdemIndustrializacao;
use App\Actions\Estoque\RegistrarConsumoIndustrializacao;
use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Actions\Estoque\RegistrarRemessaIndustrializacao;
use App\Actions\Estoque\RegistrarSaidaEstoque;
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
use App\Exceptions\RemessaIndustrializacaoInvalidaException;
use App\Exceptions\ReservaEstoqueInvalidaException;
use App\Exceptions\SaidaEstoqueInvalidaException;
use App\Exceptions\SaldoFisicoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\OrdemIndustrializacao;
use App\Models\Tenant;
use App\Models\UnidadeEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\SaldoEstoque;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.5.CORREÇÃO — fecha o Achado C1 (bobina/lote
 * parcialmente remetida ao Terceiro deixava o restante inacessível na
 * obra, porque `UnidadeEstoque.local_estoque_id` era tratado como
 * localização atual única) e o Achado C2 (TypeError não tratado na UI
 * quando um ID de Ordem cross-obra é manipulado no payload). Cobertura
 * A-R (C1) e S-AA (C2), Seções 26-27 do pedido de correção. O Achado
 * B1 (ausência de chave de idempotência em entrega/retorno) foi
 * deliberadamente NÃO corrigido nesta etapa — só documentado.
 */
class EstoqueIndustrializacaoCorrecaoTest extends TestCase
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

    // ---- helpers (duplicados de propósito — convenção do projeto: um helper por arquivo) ----

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true,
        ]);
    }

    private function criarFornecedor(?Work $obra = null): Fornecedor
    {
        return Fornecedor::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Fornecedor ' . uniqid()]);
    }

    private function criarLocalTerceiro(Fornecedor $fornecedor, ?Work $obra = null): LocalEstoque
    {
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

    /** @return array{0: OrdemIndustrializacao, 1: Fornecedor, 2: LocalEstoque, 3: LocalEstoque} */
    private function ordemEmitidaSemProduto(): array
    {
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);

        $ordem = (new CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        (new AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $this->criarMaterial(), 10, $this->user);
        $ordem = (new EmitirOrdemIndustrializacao())->execute($ordem->fresh(), $this->user);

        return [$ordem, $fornecedor, $localProprio, $localTerceiro];
    }

    // =========================================================
    // A-D: cenário completo de bobina fracionada (Seções 9-14 do pedido)
    // =========================================================

    public function test_a_remessa_parcial_deixa_saldo_correto_nos_dois_locais(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-A');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-A')->first();

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade);

        $this->assertSame(700.0, SaldoEstoque::porMaterialLocal($material, $localProprio));
        $this->assertSame(300.0, SaldoEstoque::porMaterialLocal($material, $localTerceiro));
        $this->assertSame(700.0, SaldoEstoque::porUnidadeLocal($unidade, $localProprio));
        $this->assertSame(300.0, SaldoEstoque::porUnidadeLocal($unidade, $localTerceiro));
        $this->assertSame(1000.0, SaldoEstoque::porUnidade($unidade));
    }

    public function test_b_local_estoque_id_nunca_mais_e_reescrito_pela_remessa(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-B');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-B')->first();
        $localOrigemOriginal = $unidade->local_estoque_id;

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade);

        $this->assertSame($localOrigemOriginal, $unidade->fresh()->local_estoque_id);
    }

    public function test_c_saida_normal_do_restante_na_obra_funciona_apos_remessa_parcial(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-C');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-C')->first();

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade);

        $mov = (new RegistrarSaidaEstoque())->execute($material, $localProprio, 700, Carbon::today(), $this->user, unidade: $unidade->fresh(), retiradoPor: $this->user);

        $this->assertSame(700.0, (float) $mov->quantidade);
        $this->assertSame(0.0, SaldoEstoque::porMaterialLocal($material, $localProprio));
    }

    public function test_d_reserva_do_restante_na_obra_funciona_apos_remessa_parcial(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-D');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-D')->first();
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Reserva']);

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade);

        $reserva = (new CriarReservaEstoque())->execute($pacote, $material, $localProprio, 300, $unidade->fresh(), null, $this->user);

        $this->assertTrue($reserva->estaAtiva());
        $this->assertSame(300.0, (float) $reserva->quantidade);
    }

    // =========================================================
    // E-H: segunda remessa, retorno, consumo — cenário completo Seções 9-14
    // =========================================================

    public function test_e_segunda_remessa_soma_corretamente_saldo_no_terceiro(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-E');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-E')->first();
        $remessa = new RegistrarRemessaIndustrializacao();

        $remessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());
        $remessa->execute($ordem, $material, 200, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());

        $this->assertSame(500.0, SaldoEstoque::porMaterialLocal($material, $localProprio));
        $this->assertSame(500.0, SaldoEstoque::porMaterialLocal($material, $localTerceiro));
    }

    public function test_f_retorno_parcial_move_saldo_do_terceiro_de_volta_a_obra(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-F');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-F')->first();
        $remessa = new RegistrarRemessaIndustrializacao();

        $remessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());
        $remessa->execute($ordem, $material, 200, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());
        $remessa->execute($ordem, $material, 150, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user, $unidade->fresh());

        $this->assertSame(650.0, SaldoEstoque::porMaterialLocal($material, $localProprio));
        $this->assertSame(350.0, SaldoEstoque::porMaterialLocal($material, $localTerceiro));
        $this->assertSame(1000.0, SaldoEstoque::porUnidade($unidade));
    }

    public function test_g_consumo_no_terceiro_reduz_so_o_saldo_do_terceiro_nunca_o_da_obra(): void
    {
        $materiaPrima = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $materialProduto = $this->criarMaterial();
        $localProprio = $this->criarLocal();
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $this->entradaPronta($materiaPrima, $localProprio, 1000, null, 'BOBINA-G');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-G')->first();

        $ordem = (new CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        (new AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $materialProduto, 10, $this->user);
        $ordem = (new EmitirOrdemIndustrializacao())->execute($ordem->fresh(), $this->user);

        $remessa = new RegistrarRemessaIndustrializacao();
        $remessa->execute($ordem, $materiaPrima, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());
        $remessa->execute($ordem, $materiaPrima, 200, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());
        $remessa->execute($ordem, $materiaPrima, 150, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user, $unidade->fresh());
        $remessaEnvioRestante = \App\Models\RemessaIndustrializacao::where('direcao', DirecaoRemessaIndustrializacao::Envio->value)->latest('ocorrido_em')->first();

        (new RegistrarConsumoIndustrializacao())->execute(
            $ordem->produtos->first(), $remessaEnvioRestante, 100, Carbon::today(), $this->user
        );

        $this->assertSame(250.0, SaldoEstoque::porMaterialLocal($materiaPrima, $localTerceiro));
        $this->assertSame(650.0, SaldoEstoque::porMaterialLocal($materiaPrima, $localProprio));
    }

    public function test_h_cenario_completo_reserva_e_saida_da_parte_restante_na_obra(): void
    {
        // Seção 13 do pedido: "O bug atual bloqueia exatamente esse cenário. Teste obrigatório."
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-H');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-H')->first();
        $remessa = new RegistrarRemessaIndustrializacao();

        $remessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());
        $remessa->execute($ordem, $material, 200, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());
        $remessa->execute($ordem, $material, 150, DirecaoRemessaIndustrializacao::RetornoSobra, $localProprio, Carbon::today(), $this->user, $unidade->fresh());

        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote H']);
        $reserva = (new CriarReservaEstoque())->execute($pacote, $material, $localProprio, 300, $unidade->fresh(), null, $this->user);
        $this->assertTrue($reserva->estaAtiva());

        $mov = (new RegistrarSaidaEstoque())->execute($material, $localProprio, 100, Carbon::today(), $this->user, unidade: $unidade->fresh(), retiradoPor: $this->user);
        $this->assertSame(100.0, (float) $mov->quantidade);

        // 650 (obra após remessas+retorno) - 100 (saída) = 550.
        $this->assertSame(550.0, SaldoEstoque::porMaterialLocal($material, $localProprio));
        $this->assertSame(350.0, SaldoEstoque::porMaterialLocal($material, $localTerceiro));
    }

    // =========================================================
    // I-N: garantias de proteção (over-split, concorrência, serial, quantitativo)
    // =========================================================

    public function test_i_saldo_consolidado_nunca_ultrapassa_o_total_original(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-I');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-I')->first();

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());

        $this->assertSame(1000.0, SaldoEstoque::porUnidade($unidade));
        $this->assertSame(1000.0, SaldoEstoque::porMaterial($material));
    }

    public function test_j_remessa_alem_do_saldo_no_local_de_origem_e_bloqueada(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-J');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-J')->first();
        $remessa = new RegistrarRemessaIndustrializacao();

        $remessa->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());

        $this->expectException(RemessaIndustrializacaoInvalidaException::class);
        $remessa->execute($ordem, $material, 701, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());
    }

    public function test_k_saida_alem_do_saldo_no_local_e_bloqueada_mesmo_com_saldo_global_maior(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-K');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-K')->first();

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());

        // Saldo GLOBAL da unidade é 1000, mas na obra só restam 700 —
        // a unidade ESTÁ presente na obra (700 > 0), então o guard de
        // presença não dispara; quem bloqueia é o guard de saldo físico
        // insuficiente (mais específico), pedindo 701 > 700 disponível.
        $this->expectException(SaldoFisicoInsuficienteException::class);
        (new RegistrarSaidaEstoque())->execute($material, $localProprio, 701, Carbon::today(), $this->user, unidade: $unidade->fresh(), retiradoPor: $this->user);
    }

    public function test_k2_saida_alem_do_saldo_quando_unidade_ja_esta_100_por_cento_em_outro_local(): void
    {
        // Complementa o test_k: quando a unidade NÃO tem NENHUM saldo na
        // obra (100% remetida), quem bloqueia é o guard de presença
        // (mensagem "está em outro Local"), nunca o de saldo insuficiente.
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-K2');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-K2')->first();

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 1000, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->expectExceptionMessage('está em outro Local');
        (new RegistrarSaidaEstoque())->execute($material, $localProprio, 1, Carbon::today(), $this->user, unidade: $unidade->fresh(), retiradoPor: $this->user);
    }

    public function test_l_reserva_alem_do_saldo_no_local_e_bloqueada_mesmo_com_saldo_global_maior(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-L');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-L')->first();
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote L']);

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());

        $this->expectExceptionMessage('Não há saldo físico disponível suficiente');
        (new CriarReservaEstoque())->execute($pacote, $material, $localProprio, 701, $unidade->fresh(), null, $this->user);
    }

    public function test_m_ordem_de_lock_estrutural_local_antes_de_unidade_na_remessa(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-M');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-M')->first();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'for update')) {
                $queries[] = $query->sql;
            }
        });

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());

        $this->assertNotEmpty($queries);
        $idxLocal = null;
        $idxUnidade = null;
        foreach ($queries as $i => $sql) {
            if (str_contains($sql, 'locais_estoque') && $idxLocal === null) {
                $idxLocal = $i;
            }
            if (str_contains($sql, 'unidades_estoque') && $idxUnidade === null) {
                $idxUnidade = $i;
            }
        }
        $this->assertNotNull($idxLocal);
        $this->assertNotNull($idxUnidade);
        $this->assertLessThan($idxUnidade, $idxLocal, 'Locais devem ser travados ANTES da UnidadeEstoque.');
    }

    public function test_n_serial_permanece_indivisivel_remessa_move_o_unico_1(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1, null, null, 'SERIAL-N');
        $unidade = UnidadeEstoque::where('serial_unico', 'SERIAL-N')->first();

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 1, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade);

        $this->assertSame(0.0, SaldoEstoque::porUnidadeLocal($unidade, $localProprio));
        $this->assertSame(1.0, SaldoEstoque::porUnidadeLocal($unidade, $localTerceiro));
        $this->assertSame(1.0, SaldoEstoque::porUnidade($unidade));

        // Segunda remessa do MESMO serial (agora sem saldo na obra) é bloqueada.
        $this->expectException(RemessaIndustrializacaoInvalidaException::class);
        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 1, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());
    }

    public function test_o_material_quantitativo_nao_e_afetado_pela_correcao(): void
    {
        $material = $this->criarMaterial(); // Quantitativo (default)
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 500);

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 200, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, null);

        $this->assertSame(300.0, SaldoEstoque::porMaterialLocal($material, $localProprio));
        $this->assertSame(200.0, SaldoEstoque::porMaterialLocal($material, $localTerceiro));
    }

    public function test_p_cross_obra_continua_bloqueado_apos_correcao(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-P');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-P')->first();
        $localOutraObra = $this->criarLocal($outraObra);

        $this->expectException(RemessaIndustrializacaoInvalidaException::class);
        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 100, DirecaoRemessaIndustrializacao::Envio, $localOutraObra, Carbon::today(), $this->user, $unidade);
    }

    public function test_q_cross_tenant_continua_bloqueado_apos_correcao(): void
    {
        $outroTenant = Tenant::factory()->create();
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-Q');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-Q')->first();

        $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $localOutroTenant = LocalEstoque::create(['obra_id' => $obraOutroTenant->id, 'nome' => 'Local X', 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);

        $this->expectException(RemessaIndustrializacaoInvalidaException::class);
        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 100, DirecaoRemessaIndustrializacao::Envio, $localOutroTenant, Carbon::today(), $this->user, $unidade);
    }

    public function test_r_ui_lista_unidade_no_local_correto_apos_remessa_parcial(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        [$ordem, , $localProprio, $localTerceiro] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 1000, null, 'BOBINA-R');
        $unidade = UnidadeEstoque::where('codigo_lote', 'BOBINA-R')->first();

        (new RegistrarRemessaIndustrializacao())->execute($ordem, $material, 300, DirecaoRemessaIndustrializacao::Envio, $localProprio, Carbon::today(), $this->user, $unidade->fresh());

        $unidadesNaObra = SaldoEstoque::unidadesComPresencaNoLocal($material, $localProprio);
        $unidadesNoTerceiro = SaldoEstoque::unidadesComPresencaNoLocal($material, $localTerceiro);

        $this->assertCount(1, $unidadesNaObra);
        $this->assertCount(1, $unidadesNoTerceiro);
        $this->assertSame($unidade->id, $unidadesNaObra->first()->id);
        $this->assertSame($unidade->id, $unidadesNoTerceiro->first()->id);
    }

    // =========================================================
    // S-AA: Achado C2 — payload cross-obra na UI nunca gera TypeError/500
    // =========================================================

    private function criarOrdemDeOutraObra(): array
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value);
        $fornecedor = $this->criarFornecedor($outraObra);
        $localTerceiro = $this->criarLocalTerceiro($fornecedor, $outraObra);
        $ordem = (new CriarOrdemIndustrializacao())->execute($outraObra, $fornecedor, $localTerceiro, $this->user);

        return [$ordem, $outraObra];
    }

    public function test_s_confirmar_adicionar_produto_com_ordem_cross_obra_nao_gera_500(): void
    {
        [$ordemOutraObra] = $this->criarOrdemDeOutraObra();
        $material = $this->criarMaterial();

        $componente = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('ordemIndustrDetalheId', $ordemOutraObra->id)
            ->set('produtoIndustrMaterialId', $material->id)
            ->set('produtoIndustrQuantidadePrevista', 10)
            ->call('confirmarAdicionarProdutoIndustr');

        $componente->assertHasErrors(['produtoIndustrGeral']);
        $this->assertSame(0, \App\Models\ProdutoIndustrializado::where('ordem_industrializacao_id', $ordemOutraObra->id)->count());
    }

    public function test_t_confirmar_emitir_ordem_com_ordem_cross_obra_nao_gera_500(): void
    {
        [$ordemOutraObra] = $this->criarOrdemDeOutraObra();

        $componente = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('ordemIndustrDetalheId', $ordemOutraObra->id)
            ->call('confirmarEmitirOrdemIndustr');

        $componente->assertHasErrors(['ordemIndustrDetalheGeral']);
        $this->assertTrue($ordemOutraObra->fresh()->estaRascunho());
    }

    public function test_u_confirmar_remessa_com_ordem_cross_obra_nao_gera_500(): void
    {
        [$ordemOutraObra] = $this->criarOrdemDeOutraObra();
        $material = $this->criarMaterial();
        $localProprio = $this->criarLocal();

        $componente = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('ordemIndustrDetalheId', $ordemOutraObra->id)
            ->set('remessaIndustrMaterialId', $material->id)
            ->set('remessaIndustrLocalProprioId', $localProprio->id)
            ->set('remessaIndustrQuantidade', 10)
            ->set('remessaIndustrData', now()->toDateString())
            ->call('confirmarRemessaIndustr');

        $componente->assertHasErrors(['remessaIndustrGeral']);
        $this->assertSame(0, \App\Models\RemessaIndustrializacao::count());
    }

    public function test_v_confirmar_producao_com_produto_cross_obra_continua_seguro(): void
    {
        // Já era seguro antes desta correção (findOrFail obra-scoped) — reafirmado.
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value);
        $fornecedor = $this->criarFornecedor($outraObra);
        $localTerceiro = $this->criarLocalTerceiro($fornecedor, $outraObra);
        $ordem = (new CriarOrdemIndustrializacao())->execute($outraObra, $fornecedor, $localTerceiro, $this->user);
        (new AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $this->criarMaterial(), 10, $this->user);
        $ordem = (new EmitirOrdemIndustrializacao())->execute($ordem->fresh(), $this->user);
        $produtoOutraObra = $ordem->produtos->first();

        // ModelNotFoundException (findOrFail obra-scoped) propaga crua pro
        // harness de `Livewire::test()` (não é convertida em 404 dentro do
        // teste unitário — só numa requisição HTTP real, via o handler de
        // exceção do Laravel) — mesmo achado já documentado no projeto pra
        // este padrão. O ponto AQUI é: nunca um TypeError não tratado
        // (Achado C2) — `confirmarProducaoIndustr` já usava findOrFail
        // obra-scoped ANTES desta correção, então é só reafirmado seguro.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('producaoIndustrProdutoId', $produtoOutraObra->id)
            ->set('producaoIndustrQuantidade', 5)
            ->set('producaoIndustrData', now()->toDateString())
            ->call('confirmarProducaoIndustr');
    }

    public function test_w_zero_write_em_qualquer_tentativa_cross_obra_da_secao_s_a_u(): void
    {
        [$ordemOutraObra] = $this->criarOrdemDeOutraObra();
        $material = $this->criarMaterial();
        $localProprio = $this->criarLocal();

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('ordemIndustrDetalheId', $ordemOutraObra->id)
            ->call('confirmarEmitirOrdemIndustr');

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('ordemIndustrDetalheId', $ordemOutraObra->id)
            ->set('remessaIndustrMaterialId', $material->id)
            ->set('remessaIndustrLocalProprioId', $localProprio->id)
            ->set('remessaIndustrQuantidade', 10)
            ->set('remessaIndustrData', now()->toDateString())
            ->call('confirmarRemessaIndustr');

        $this->assertSame(0, \App\Models\MovimentacaoEstoque::count());
        $this->assertSame(0, \App\Models\RemessaIndustrializacao::count());
        $this->assertTrue($ordemOutraObra->fresh()->estaRascunho());
    }

    public function test_x_mensagem_amigavel_nunca_expoe_detalhe_tecnico(): void
    {
        [$ordemOutraObra] = $this->criarOrdemDeOutraObra();

        $componente = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('ordemIndustrDetalheId', $ordemOutraObra->id)
            ->call('confirmarEmitirOrdemIndustr');

        $erros = $componente->instance()->getErrorBag()->get('ordemIndustrDetalheGeral');
        $this->assertNotEmpty($erros);
        $this->assertStringNotContainsString('TypeError', $erros[0]);
        $this->assertStringNotContainsString('SQL', $erros[0]);
    }

    public function test_y_ordem_da_propria_obra_continua_funcionando_normalmente(): void
    {
        // Regressão: a correção nunca pode bloquear o caminho LEGÍTIMO (mesma obra).
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $ordem = (new CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        (new AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $this->criarMaterial(), 10, $this->user);

        $componente = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('ordemIndustrDetalheId', $ordem->id)
            ->call('confirmarEmitirOrdemIndustr');

        $componente->assertHasNoErrors();
        $this->assertTrue($ordem->fresh()->estaEmitida());
    }

    public function test_z_confirmar_adicionar_produto_ordem_da_propria_obra_continua_funcionando(): void
    {
        $fornecedor = $this->criarFornecedor();
        $localTerceiro = $this->criarLocalTerceiro($fornecedor);
        $ordem = (new CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        $material = $this->criarMaterial();

        $componente = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('ordemIndustrDetalheId', $ordem->id)
            ->set('produtoIndustrMaterialId', $material->id)
            ->set('produtoIndustrQuantidadePrevista', 10)
            ->call('confirmarAdicionarProdutoIndustr');

        $componente->assertHasNoErrors();
        $this->assertSame(1, $ordem->produtos()->count());
    }

    public function test_aa_confirmar_remessa_ordem_da_propria_obra_continua_funcionando(): void
    {
        $material = $this->criarMaterial();
        [$ordem, , $localProprio] = $this->ordemEmitidaSemProduto();
        $this->entradaPronta($material, $localProprio, 500);

        $componente = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('ordemIndustrDetalheId', $ordem->id)
            ->set('remessaIndustrMaterialId', $material->id)
            ->set('remessaIndustrLocalProprioId', $localProprio->id)
            ->set('remessaIndustrQuantidade', 100)
            ->set('remessaIndustrData', now()->toDateString())
            ->call('confirmarRemessaIndustr');

        $componente->assertHasNoErrors();
        $this->assertSame(1, \App\Models\RemessaIndustrializacao::count());
    }
}
