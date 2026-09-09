<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\LiberarReservaEstoque;
use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Exceptions\ReservaEstoqueInvalidaException;
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
use App\Enums\EstadoNecessidadeMaterialAtividade;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\TipoLocalEstoque;
use App\Exceptions\NecessidadeMaterialAtividadeInvalidaException;
use App\Exceptions\SaldoNecessidadeInsuficienteException;
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
use App\Models\ReservaEstoque;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\ConciliacaoNecessidadeAtividade;
use App\Support\Estoque\CoberturaNecessidadeAtividadeQuery;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Melhoria "Posto Operacional" — arquitetura B híbrida aprovada
 * (AtividadeNecessidadeMaterial). Cobertura A-T do pedido de testes
 * (itens 1-19 e 22, domínio puro — sem Livewire/Blade).
 */
class AtividadeNecessidadeMaterialTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidadeMetro;
    private UnidadeMedida $unidadeQuilo;
    private AtualizarNecessidadeMaterialAtividade $action;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-15'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidadeMetro = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
        $this->unidadeQuilo = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'KG', 'nome' => 'Quilo']);

        $this->action = new AtualizarNecessidadeMaterialAtividade();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (mesma toolkit já usada em EstoqueDestinacaoReservaTest) ----

    private function criarAtividade(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
        ], $overrides));
    }

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidadeMetro->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ], $overrides));
    }

    private function criarItemTakeOff(?Material $material, float $quantidade, ?Work $obra = null): ItemTakeOff
    {
        $obra ??= $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item',
            'unidade_medida_id' => $this->unidadeMetro->id,
            'quantidade' => $quantidade, 'material_id' => $material?->id,
        ]);
    }

    private function criarLocal(?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value,
            'ativo' => true,
        ]);
    }

    /** Cadeia comercial completa até Entrada física em estoque — mesmo helper de EstoqueDestinacaoReservaTest. */
    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade): void
    {
        $item = $this->criarItemTakeOff($material, $quantidade * 10);
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), $quantidade, Carbon::parse('2026-12-10'), $this->user);
        (new RegistrarEntradaEstoque())->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);
    }

    // =========================================================
    // 1-5 — Distribuição do TakeOff entre atividades
    // =========================================================

    public function test_1_takeoff_1000_distribuido_a300_b450_c250_saldo_zero(): void
    {
        $item = $this->criarItemTakeOff(null, 1000);
        $a = $this->criarAtividade(['nome' => 'Atividade A']);
        $b = $this->criarAtividade(['nome' => 'Atividade B']);
        $c = $this->criarAtividade(['nome' => 'Atividade C']);

        $this->action->criarTakeOff($a, $item, 300, $this->user);
        $this->action->criarTakeOff($b, $item, 450, $this->user);
        $this->action->criarTakeOff($c, $item, 250, $this->user);

        $this->assertSame(0.0, ConciliacaoNecessidadeAtividade::saldoADistribuir($item->fresh()));
    }

    public function test_2_tentativa_de_mais_1_apos_saldo_zerado_e_bloqueada(): void
    {
        $item = $this->criarItemTakeOff(null, 1000);
        $a = $this->criarAtividade();
        $b = $this->criarAtividade();
        $c = $this->criarAtividade();
        $d = $this->criarAtividade();

        $this->action->criarTakeOff($a, $item, 300, $this->user);
        $this->action->criarTakeOff($b, $item, 450, $this->user);
        $this->action->criarTakeOff($c, $item, 250, $this->user);

        $this->expectException(SaldoNecessidadeInsuficienteException::class);
        $this->action->criarTakeOff($d, $item, 1, $this->user);
    }

    public function test_3_editar_b_450_para_400_libera_saldo_50(): void
    {
        $item = $this->criarItemTakeOff(null, 1000);
        $a = $this->criarAtividade();
        $b = $this->criarAtividade();
        $c = $this->criarAtividade();

        $this->action->criarTakeOff($a, $item, 300, $this->user);
        $necB = $this->action->criarTakeOff($b, $item, 450, $this->user);
        $this->action->criarTakeOff($c, $item, 250, $this->user);

        $this->action->alterar($necB, 400);

        $this->assertSame(50.0, ConciliacaoNecessidadeAtividade::saldoADistribuir($item->fresh()));
    }

    public function test_4_excluir_c_250_libera_saldo_para_250(): void
    {
        $item = $this->criarItemTakeOff(null, 1000);
        $a = $this->criarAtividade();
        $b = $this->criarAtividade();
        $c = $this->criarAtividade();

        $this->action->criarTakeOff($a, $item, 300, $this->user);
        $this->action->criarTakeOff($b, $item, 450, $this->user);
        $necC = $this->action->criarTakeOff($c, $item, 250, $this->user);

        $this->action->remover($necC);

        // 1000 - 300 (A) - 450 (B) = 250 restantes após remover C.
        $this->assertSame(250.0, ConciliacaoNecessidadeAtividade::saldoADistribuir($item->fresh()));
        $this->assertDatabaseMissing('atividade_necessidades_material', ['id' => $necC->id]);
    }

    public function test_5_takeoff_reduzido_depois_preserva_saldo_negativo_sem_truncar(): void
    {
        $item = $this->criarItemTakeOff(null, 1000);
        $a = $this->criarAtividade();
        $b = $this->criarAtividade();
        $c = $this->criarAtividade();

        $this->action->criarTakeOff($a, $item, 300, $this->user);
        $this->action->criarTakeOff($b, $item, 450, $this->user);
        $necC = $this->action->criarTakeOff($c, $item, 250, $this->user);

        // TakeOff reduzido diretamente (fora desta Action, que nunca
        // toca ItemTakeOff.quantidade) — 1000 -> 900.
        $item->update(['quantidade' => 900]);

        $this->assertSame(-100.0, ConciliacaoNecessidadeAtividade::saldoADistribuir($item->fresh()));
        // Nenhuma necessidade já distribuída foi alterada/removida automaticamente.
        $this->assertSame(250.0, (float) $necC->fresh()->quantidade_necessaria);
    }

    // =========================================================
    // 6-7 — Duplicidade dentro da mesma origem
    // =========================================================

    public function test_6_mesma_necessidade_take_off_duplicada_na_mesma_atividade_bloqueada(): void
    {
        $item = $this->criarItemTakeOff(null, 1000);
        $a = $this->criarAtividade();

        $this->action->criarTakeOff($a, $item, 100, $this->user);

        $this->expectException(NecessidadeMaterialAtividadeInvalidaException::class);
        $this->action->criarTakeOff($a, $item, 50, $this->user);
    }

    public function test_7_operacional_duplicado_para_mesmo_material_na_mesma_atividade_bloqueado(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarAtividade();

        $this->action->criarOperacional($a, $material, 10, 'Justificativa 1', $this->user);

        $this->expectException(NecessidadeMaterialAtividadeInvalidaException::class);
        $this->action->criarOperacional($a, $material, 5, 'Justificativa 2', $this->user);
    }

    // =========================================================
    // 8-9 — Invariante de origem, camada de banco (CHECK constraint)
    // =========================================================

    public function test_8_origem_take_off_com_material_id_preenchido_e_invalido_no_banco(): void
    {
        $item = $this->criarItemTakeOff(null, 1000);
        $material = $this->criarMaterial();
        $a = $this->criarAtividade();

        $this->expectException(QueryException::class);
        DB::table('atividade_necessidades_material')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'atividade_id' => $a->id,
            'origem' => 'take_off',
            'item_take_off_id' => $item->id,
            'material_id' => $material->id, // inválido: take_off nunca tem material_id
            'unidade_medida_id' => $this->unidadeMetro->id,
            'quantidade_necessaria' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_9_origem_operacional_com_item_take_off_id_preenchido_e_invalido_no_banco(): void
    {
        $item = $this->criarItemTakeOff(null, 1000);
        $material = $this->criarMaterial();
        $a = $this->criarAtividade();

        $this->expectException(QueryException::class);
        DB::table('atividade_necessidades_material')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'atividade_id' => $a->id,
            'origem' => 'operacional',
            'item_take_off_id' => $item->id, // inválido: operacional nunca tem item_take_off_id
            'material_id' => $material->id,
            'unidade_medida_id' => $this->unidadeMetro->id,
            'quantidade_necessaria' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================
    // 10 — Justificativa obrigatória (origem operacional)
    // =========================================================

    public function test_10_origem_operacional_sem_justificativa_e_invalida(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarAtividade();

        $this->expectException(NecessidadeMaterialAtividadeInvalidaException::class);
        $this->action->criarOperacional($a, $material, 10, '   ', $this->user);
    }

    // =========================================================
    // 11-12 — Isolamento tenant/obra
    // =========================================================

    public function test_11_item_take_off_de_outro_tenant_e_bloqueado(): void
    {
        $outroTenant = Tenant::factory()->create();
        $itemOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $u = UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'M2', 'nome' => 'Metro']);
            $doc = DocumentoEngenharia::create(['obra_id' => $outraObra->id, 'codigo' => 'DX', 'descricao' => 'D']);
            $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
            $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LMX']);

            return ItemTakeOff::create([
                'lista_engenharia_id' => $lista->id, 'codigo' => 'AX', 'descricao' => 'Item',
                'unidade_medida_id' => $u->id, 'quantidade' => 1000,
            ]);
        });

        $a = $this->criarAtividade();

        // Cross-tenant já é coberto de graça pelo global scope de
        // BelongsToTenant (ItemTakeOff::whereKey() não encontra nada de
        // outro tenant) — a Action já lança ModelNotFoundException antes
        // mesmo de chegar no guard de obra.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->action->criarTakeOff($a, ItemTakeOff::find($itemOutroTenant->id) ?? new ItemTakeOff(['id' => $itemOutroTenant->id]), 10, $this->user);
    }

    public function test_12_item_take_off_de_outra_obra_do_mesmo_tenant_e_bloqueado(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $itemOutraObra = $this->criarItemTakeOff(null, 1000, $outraObra);
        $a = $this->criarAtividade(); // na obra padrão $this->obra

        $this->expectException(NecessidadeMaterialAtividadeInvalidaException::class);
        $this->action->criarTakeOff($a, $itemOutraObra, 10, $this->user);
    }

    // =========================================================
    // 13-17 — Cobertura (unidade compatível/incompatível, estados)
    // =========================================================

    public function test_13_unidade_compativel_cobertura_e_calculada(): void
    {
        $material = $this->criarMaterial(['unidade_medida_id' => $this->unidadeMetro->id]);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 50);

        $a = $this->criarAtividade();
        $this->action->criarOperacional($a, $material, 50, 'Necessário pra execução', $this->user);

        $linhas = CoberturaNecessidadeAtividadeQuery::porAtividade($a);
        $this->assertCount(1, $linhas);
        $this->assertTrue($linhas[0]['unidade_compativel']);
        $this->assertSame(50.0, $linhas[0]['fisico_obra']);
    }

    public function test_14_unidade_incompativel_nunca_calcula_cobertura_falsa(): void
    {
        // Material declarado em Metro; necessidade operacional é criada
        // sempre com a unidade CANÔNICA do Material (Section 2) — pra
        // forçar incompatibilidade real (ex.: unidade do Material foi
        // trocada DEPOIS da necessidade já existir), simulamos via update
        // direto na linha de necessidade já persistida.
        $material = $this->criarMaterial(['unidade_medida_id' => $this->unidadeMetro->id]);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 50);

        $a = $this->criarAtividade();
        $necessidade = $this->action->criarOperacional($a, $material, 50, 'Necessário', $this->user);

        DB::table('atividade_necessidades_material')
            ->where('id', $necessidade->id)
            ->update(['unidade_medida_id' => $this->unidadeQuilo->id]);

        $linhas = CoberturaNecessidadeAtividadeQuery::porAtividade($a);
        $this->assertFalse($linhas[0]['unidade_compativel']);
        $this->assertSame(EstadoNecessidadeMaterialAtividade::UnidadeIncompativel, $linhas[0]['estado']);
        $this->assertNull($linhas[0]['fisico_obra']);
        $this->assertNull($linhas[0]['livre_obra']);
        $this->assertNull($linhas[0]['deficit']);
    }

    public function test_15_fisico_120_outras_reservas_100_necessidade_100_livre_20_nao_coberta(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 120);

        // 100 reservado por OUTRO propósito (Pacote genérico, sem
        // necessidade rotulada) — reduz o livre da obra, mas não conta
        // como "reservado_atividade" desta necessidade.
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Outro']);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, usuario: $this->user);

        $a = $this->criarAtividade();
        $this->action->criarOperacional($a, $material, 100, 'Necessário', $this->user);

        $linhas = CoberturaNecessidadeAtividadeQuery::porAtividade($a);
        $this->assertSame(120.0, $linhas[0]['fisico_obra']);
        $this->assertSame(100.0, $linhas[0]['reservado_obra']);
        $this->assertSame(20.0, $linhas[0]['livre_obra']);
        $this->assertSame(0.0, $linhas[0]['reservado_atividade']);
        $this->assertNotSame(EstadoNecessidadeMaterialAtividade::Coberta, $linhas[0]['estado']);
        $this->assertSame(EstadoNecessidadeMaterialAtividade::Parcial, $linhas[0]['estado']);
    }

    public function test_16_fisico_120_livre_120_necessario_100_e_disponivel_para_reserva_nao_coberta(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 120);

        $a = $this->criarAtividade();
        $this->action->criarOperacional($a, $material, 100, 'Necessário', $this->user);

        $linhas = CoberturaNecessidadeAtividadeQuery::porAtividade($a);
        $this->assertSame(120.0, $linhas[0]['livre_obra']);
        $this->assertSame(EstadoNecessidadeMaterialAtividade::DisponivelParaReserva, $linhas[0]['estado']);
        $this->assertNotSame(EstadoNecessidadeMaterialAtividade::Coberta, $linhas[0]['estado']);
        $this->assertSame(0.0, $linhas[0]['deficit']);
    }

    public function test_17_reserva_explicita_100_para_necessidade_100_e_coberta(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 120);

        $a = $this->criarAtividade();
        $necessidade = $this->action->criarOperacional($a, $material, 100, 'Necessário', $this->user);

        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Atividade']);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, usuario: $this->user, necessidade: $necessidade);

        $linhas = CoberturaNecessidadeAtividadeQuery::porAtividade($a);
        $this->assertSame(100.0, $linhas[0]['reservado_atividade']);
        $this->assertSame(EstadoNecessidadeMaterialAtividade::Coberta, $linhas[0]['estado']);
        $this->assertSame(0.0, $linhas[0]['deficit']);
    }

    // =========================================================
    // 18 — Reservar nunca ocorre automaticamente
    // =========================================================

    public function test_18_criar_necessidade_e_calcular_cobertura_nunca_cria_reserva(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 120);

        $a = $this->criarAtividade();
        $this->action->criarOperacional($a, $material, 100, 'Necessário', $this->user);
        CoberturaNecessidadeAtividadeQuery::porAtividade($a);

        $this->assertDatabaseCount('reservas_estoque', 0);
    }

    // =========================================================
    // 19 — Material nunca altera Atividade::estaPronta()
    // =========================================================

    public function test_19_necessidade_material_sem_cobertura_nenhuma_nunca_bloqueia_estapronta(): void
    {
        $material = $this->criarMaterial();
        $a = $this->criarAtividade();

        // Necessidade de 1000 sem NENHUM estoque físico na obra — déficit
        // máximo possível — e mesmo assim a atividade continua liberada,
        // porque Material não participa da regra (Seção 10, "manter esta
        // rodada").
        $this->action->criarOperacional($a, $material, 1000, 'Necessário', $this->user);

        $this->assertTrue($a->fresh()->estaPronta());
    }

    // =========================================================
    // 22 — Documento bloqueante continua alterando estaPronta() (reafirmação,
    // não é funcionalidade nova — só confirma que nada regrediu)
    // =========================================================

    public function test_22_documento_nao_liberado_vinculado_continua_bloqueando_estapronta(): void
    {
        $a = $this->criarAtividade();
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DBLOQ', 'descricao' => 'D']);
        $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $a->documentosEngenharia()->attach($doc->id);

        $this->assertFalse($a->fresh()->estaPronta());
    }

    // =========================================================
    // RODADA DE FECHAMENTO — validações 1/3/4/5 do prompt de
    // fechamento técnico (isolamento, unidade autoritativa,
    // concorrência estrutural, integração com Reserva)
    // =========================================================

    /**
     * Seção 4 — prova estrutural de concorrência, mesmo padrão já usado
     * em AlocacaoPacoteDeleteRaceTest::test_l_ordem_de_lock_deterministica...:
     * como RefreshDatabase impede 2 conexões reais concorrentes, a defesa
     * real contra a corrida é o lock adquirido ANTES de qualquer
     * leitura/escrita de saldo — confirmado aqui espiando a ORDEM real
     * das queries SQL: `SELECT ... FOR UPDATE` sobre itens_take_off
     * precisa vir ANTES do INSERT em atividade_necessidades_material,
     * em toda chamada de criarTakeOff().
     */
    public function test_23_criarTakeOff_trava_item_take_off_antes_de_inserir_necessidade(): void
    {
        $item = $this->criarItemTakeOff(null, 1000);
        $a = $this->criarAtividade();

        $ordem = [];
        DB::listen(function ($q) use (&$ordem) {
            $sql = strtolower($q->sql);
            if (str_contains($sql, 'for update') && str_contains($q->sql, 'itens_take_off')) {
                $ordem[] = 'lock_item_take_off';
            } elseif (str_contains($sql, 'insert') && str_contains($q->sql, 'atividade_necessidades_material')) {
                $ordem[] = 'insert_necessidade';
            }
        });

        $this->action->criarTakeOff($a, $item, 100, $this->user);

        $this->assertSame(['lock_item_take_off', 'insert_necessidade'], $ordem,
            'criarTakeOff() precisa travar ItemTakeOff (FOR UPDATE) ANTES de inserir a necessidade — é isso que impede duas distribuições concorrentes de ultrapassarem o saldo.');
    }

    /**
     * Mesma prova estrutural, agora para alterar()/remover() — o lock em
     * ItemTakeOff precisa vir SEMPRE antes do lock na própria linha de
     * necessidade (mesma ordem determinística documentada no docblock da
     * Action).
     */
    public function test_23b_alterar_trava_item_take_off_antes_da_propria_necessidade(): void
    {
        $item = $this->criarItemTakeOff(null, 1000);
        $a = $this->criarAtividade();
        $necessidade = $this->action->criarTakeOff($a, $item, 100, $this->user);

        $ordem = [];
        DB::listen(function ($q) use (&$ordem) {
            $sql = strtolower($q->sql);
            if (!str_contains($sql, 'for update')) {
                return;
            }
            if (str_contains($q->sql, 'itens_take_off')) {
                $ordem[] = 'item_take_off';
            } elseif (str_contains($q->sql, 'atividade_necessidades_material')) {
                $ordem[] = 'necessidade';
            }
        });

        $this->action->alterar($necessidade->fresh(), 150);

        $this->assertSame(['item_take_off', 'necessidade'], $ordem);
    }

    /**
     * Réplica sequencial do cenário concorrente real (Seção 4): duas
     * "tentativas" disputando o MESMO saldo residual nunca podem, juntas,
     * ultrapassar ItemTakeOff.quantidade — a segunda tentativa, mesmo
     * vendo o saldo já reduzido pela primeira (cada uma travando e
     * recalculando o saldo sob o MESMO lock, nunca um valor cacheado de
     * fora), é bloqueada.
     */
    public function test_23c_duas_tentativas_disputando_o_mesmo_saldo_residual_nunca_ultrapassam_quantidade(): void
    {
        $item = $this->criarItemTakeOff(null, 100);
        $a = $this->criarAtividade();
        $b = $this->criarAtividade();

        // Saldo residual = 40. Duas tentativas de 25 cada (50 no total)
        // — juntas ultrapassariam o saldo — só uma pode ser bem-sucedida.
        $this->action->criarTakeOff($this->criarAtividade(), $item, 60, $this->user);

        $this->action->criarTakeOff($a, $item->fresh(), 25, $this->user);

        $this->expectException(SaldoNecessidadeInsuficienteException::class);
        $this->action->criarTakeOff($b, $item->fresh(), 25, $this->user);
    }

    /**
     * Seção 3.C — unidade AUTORITATIVA de uma necessidade origem=take_off
     * é sempre a do próprio ItemTakeOff no momento da distribuição, NUNCA
     * a unidade canônica do Material (mesmo quando divergem) — provado
     * criando um ItemTakeOff em Metro associado a um Material cuja
     * unidade canônica é Quilo: a necessidade nasce em Metro (herdada do
     * ItemTakeOff), e a cobertura corretamente detecta a incompatibilidade
     * contra o Material (Quilo) sem nunca converter/comparar às cegas.
     */
    public function test_24_origem_take_off_usa_sempre_a_unidade_do_item_take_off_nunca_a_do_material(): void
    {
        $material = $this->criarMaterial(['unidade_medida_id' => $this->unidadeQuilo->id]);
        $item = $this->criarItemTakeOff($material, 1000); // ItemTakeOff criado em Metro (helper fixo)
        $this->assertSame($this->unidadeMetro->id, $item->unidade_medida_id);
        $this->assertSame($this->unidadeQuilo->id, $material->unidade_medida_id);

        $a = $this->criarAtividade();
        $necessidade = $this->action->criarTakeOff($a, $item, 100, $this->user);

        // Unidade autoritativa gravada é a do ItemTakeOff (Metro), nunca a
        // do Material efetivo (Quilo) — nunca copiada/convertida.
        $this->assertSame($this->unidadeMetro->id, $necessidade->unidade_medida_id);

        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 50);

        $linhas = CoberturaNecessidadeAtividadeQuery::porAtividade($a);
        $this->assertFalse($linhas[0]['unidade_compativel']);
        $this->assertSame(EstadoNecessidadeMaterialAtividade::UnidadeIncompativel, $linhas[0]['estado']);
    }

    /**
     * Seção 5 — reserva NUNCA pode ser criada rotulando uma necessidade
     * que pertence a outra obra (mesmo tenant, mesmo Material) — guard
     * `CriarReservaEstoque::garantirNecessidadeCompativel()`, nunca uma
     * segunda regra de autorização.
     */
    public function test_25_reserva_com_necessidade_de_outra_obra_e_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);

        $atividadeOutraObra = $this->criarAtividade([], $outraObra);
        $necessidadeOutraObra = $this->action->criarOperacional($atividadeOutraObra, $material, 50, 'Necessário', $this->user);

        $local = $this->criarLocal(); // Local da obra padrão ($this->obra)
        $this->entradaPronta($material, $local, 100);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote']);

        $this->expectException(ReservaEstoqueInvalidaException::class);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 50, usuario: $this->user, necessidade: $necessidadeOutraObra);
    }

    /** Seção 5 — reserva com necessidade de MATERIAL diferente do informado é bloqueada. */
    public function test_26_reserva_com_necessidade_de_outro_material_e_bloqueada(): void
    {
        $materialA = $this->criarMaterial(['codigo' => 'MAT-A-' . uniqid()]);
        $materialB = $this->criarMaterial(['codigo' => 'MAT-B-' . uniqid()]);
        $local = $this->criarLocal();
        $this->entradaPronta($materialB, $local, 100);

        $a = $this->criarAtividade();
        $necessidadeDoMaterialA = $this->action->criarOperacional($a, $materialA, 50, 'Necessário', $this->user);

        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote']);

        $this->expectException(ReservaEstoqueInvalidaException::class);
        (new CriarReservaEstoque())->execute($pacote, $materialB, $local, 50, usuario: $this->user, necessidade: $necessidadeDoMaterialA);
    }

    /**
     * Seção 5 — liberar (cancelar) a Reserva que cobria a necessidade
     * reverte corretamente a cobertura: `reservado_atividade` volta a 0
     * (a soma já filtra só status=Ativa desde sempre) e o estado deixa de
     * ser Coberta, sem tocar em nenhuma regra histórica de Reserva
     * (`LiberarReservaEstoque` intocado, mesma Action já usada em todo o
     * projeto).
     */
    public function test_27_liberar_reserva_vinculada_reverte_cobertura_de_coberta_para_disponivel(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 120);

        $a = $this->criarAtividade();
        $necessidade = $this->action->criarOperacional($a, $material, 100, 'Necessário', $this->user);

        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Atividade']);
        $reserva = (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, usuario: $this->user, necessidade: $necessidade);

        $antes = CoberturaNecessidadeAtividadeQuery::porAtividade($a);
        $this->assertSame(EstadoNecessidadeMaterialAtividade::Coberta, $antes[0]['estado']);

        (new LiberarReservaEstoque())->execute($reserva->fresh(), $this->user, 'Consumido/estornado');

        $depois = CoberturaNecessidadeAtividadeQuery::porAtividade($a);
        $this->assertSame(0.0, $depois[0]['reservado_atividade']);
        $this->assertNotSame(EstadoNecessidadeMaterialAtividade::Coberta, $depois[0]['estado']);
        $this->assertSame(EstadoNecessidadeMaterialAtividade::DisponivelParaReserva, $depois[0]['estado']);
    }

    /**
     * Seção 1 — "edição não permite trocar referências para entidades
     * externas": `alterar()` só aceita nova quantidade/observação — não
     * existe NENHUM parâmetro pra reatribuir atividade/origem/item de
     * TakeOff/material. Confirmado empiricamente que, após alterar(),
     * as 4 colunas de identidade permanecem bit-a-bit as mesmas.
     */
    public function test_28_alterar_nunca_muda_atividade_origem_item_take_off_ou_material(): void
    {
        $item = $this->criarItemTakeOff(null, 1000);
        $a = $this->criarAtividade();
        $necessidade = $this->action->criarTakeOff($a, $item, 100, $this->user);

        $antes = $necessidade->only(['atividade_id', 'origem', 'item_take_off_id', 'material_id']);

        $depois = $this->action->alterar($necessidade->fresh(), 200, 'Observação atualizada');

        $this->assertSame($antes['atividade_id'], $depois->atividade_id);
        $this->assertSame($antes['origem']->value, $depois->origem->value);
        $this->assertSame($antes['item_take_off_id'], $depois->item_take_off_id);
        $this->assertSame($antes['material_id'], $depois->material_id);
        $this->assertSame(200.0, (float) $depois->quantidade_necessaria);
    }
}
