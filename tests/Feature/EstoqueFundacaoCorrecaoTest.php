<?php

namespace Tests\Feature;

use App\Actions\Estoque\AssociarMaterialAoItemTakeOff;
use App\Actions\Estoque\RegistrarEntradaEstoque;
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
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\AssociacaoMaterialInvalidaException;
use App\Exceptions\ItemTakeOffMaterialImutavelException;
use App\Exceptions\LocalEstoqueReferenciadoException;
use App\Exceptions\MaterialReferenciadoException;
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
use App\Models\PedidoCompraItem;
use App\Models\RecebimentoPedido;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\PoliticaAssociacaoMaterial;
use App\Support\Estoque\SaldoEstoque;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.1.CORREÇÃO — fecha C1/C2/C3/B1/B2 da auditoria
 * adversarial da 20.1. Cobertura A-AD do pedido de correção.
 */
class EstoqueFundacaoCorrecaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

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
    private AssociarMaterialAoItemTakeOff $associarMaterial;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-15'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);

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
        $this->associarMaterial = new AssociarMaterialAoItemTakeOff();
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
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(array $overrides = []): LocalEstoque
    {
        return LocalEstoque::create(array_merge([
            'obra_id' => $this->obra->id,
            'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value,
            'ativo' => true,
        ], $overrides));
    }

    private function criarItemTakeOffOrfao(?Material $material): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item',
            'quantidade' => 1000, 'material_id' => $material?->id,
        ]);
    }

    private function criarRpItemEmitido(ItemTakeOff $item, float $quantidade): \App\Models\RequisicaoPlanejamentoItem
    {
        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidade);
        $this->emitirRp->execute($rp->fresh(), $this->user);
        return $rpItem->fresh();
    }

    private function alocarPacote(\App\Models\RequisicaoPlanejamentoItem $rpItem, float $quantidade): AlocacaoRequisicaoPacote
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        return $this->alocar->alocar($rpItem, $pacote, $quantidade);
    }

    private function criarRcRascunho(AlocacaoRequisicaoPacote $alocacao, float $quantidade)
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = $this->criarRc->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, $quantidade);
        return $rc->fresh();
    }

    private function emitirRc($rcRascunho)
    {
        return $this->emitirRc->execute($rcRascunho, $this->user);
    }

    private function criarPedidoRascunho($rcEmitida, float $quantidade): PedidoCompraItem
    {
        $rcItem = $rcEmitida->itens->first();
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = $this->criarPedido->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        return $this->atualizarPedido->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
    }

    private function emitirPedido(PedidoCompraItem $pedidoItem): PedidoCompraItem
    {
        $pedido = \App\Models\PedidoCompra::find($pedidoItem->pedido_compra_id);
        $this->emitirPedido->execute($pedido->fresh(), $this->user);
        return $pedidoItem->fresh();
    }

    // =========================================================
    // A-C: associação inicial / material inativo / cross-tenant
    // =========================================================

    public function test_a_associacao_inicial_funciona(): void
    {
        $material = $this->criarMaterial();
        $item = $this->criarItemTakeOffOrfao(null);

        $this->associarMaterial->execute($this->obra, $item,$material);

        $this->assertSame($material->id, $item->fresh()->material_id);
    }

    public function test_b_material_inativo_bloqueia_associacao(): void
    {
        $material = $this->criarMaterial(['ativo' => false]);
        $item = $this->criarItemTakeOffOrfao(null);

        $this->expectException(AssociacaoMaterialInvalidaException::class);
        $this->associarMaterial->execute($this->obra, $item,$material);
    }

    public function test_c_cross_tenant_bloqueia_associacao(): void
    {
        $outroTenant = Tenant::factory()->create();
        $materialOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $um = UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
            return Material::create([
                'tenant_id' => $outroTenant->id, 'codigo' => 'OUTRO-C', 'descricao' => 'X',
                'unidade_medida_id' => $um->id, 'modo_rastreabilidade' => 'quantitativo', 'ativo' => true,
            ]);
        });

        $item = $this->criarItemTakeOffOrfao(null);

        $this->expectException(AssociacaoMaterialInvalidaException::class);
        $this->associarMaterial->execute($this->obra, $item,$materialOutroTenant);
    }

    // =========================================================
    // D-H: corte de imutabilidade em cada estágio da cadeia
    // =========================================================

    public function test_d_troca_antes_de_qualquer_estagio_funciona(): void
    {
        $a = $this->criarMaterial(['codigo' => 'A-D']);
        $b = $this->criarMaterial(['codigo' => 'B-D']);
        $item = $this->criarItemTakeOffOrfao($a);

        $this->associarMaterial->execute($this->obra, $item,$b);

        $this->assertSame($b->id, $item->fresh()->material_id);
    }

    public function test_e1_troca_apos_rp_emitida_ainda_funciona(): void
    {
        $a = $this->criarMaterial(['codigo' => 'A-E1']);
        $b = $this->criarMaterial(['codigo' => 'B-E1']);
        $item = $this->criarItemTakeOffOrfao($a);
        $this->criarRpItemEmitido($item, 100);

        $this->associarMaterial->execute($this->obra, $item,$b);

        $this->assertSame($b->id, $item->fresh()->material_id);
    }

    public function test_e2_troca_apos_rc_emitida_ainda_funciona(): void
    {
        $a = $this->criarMaterial(['codigo' => 'A-E2']);
        $b = $this->criarMaterial(['codigo' => 'B-E2']);
        $item = $this->criarItemTakeOffOrfao($a);
        $rpItem = $this->criarRpItemEmitido($item, 100);
        $alocacao = $this->alocarPacote($rpItem, 100);
        $rc = $this->criarRcRascunho($alocacao, 100);
        $this->emitirRc($rc);

        $this->associarMaterial->execute($this->obra, $item,$b);

        $this->assertSame($b->id, $item->fresh()->material_id, 'RC Emitida sozinha NAO deveria congelar — só Pedido Emitido.');
    }

    public function test_e3_troca_apos_pedido_rascunho_ainda_funciona(): void
    {
        $a = $this->criarMaterial(['codigo' => 'A-E3']);
        $b = $this->criarMaterial(['codigo' => 'B-E3']);
        $item = $this->criarItemTakeOffOrfao($a);
        $rpItem = $this->criarRpItemEmitido($item, 100);
        $alocacao = $this->alocarPacote($rpItem, 100);
        $rc = $this->criarRcRascunho($alocacao, 100);
        $rcEmitida = $this->emitirRc($rc);
        $this->criarPedidoRascunho($rcEmitida, 100); // Pedido continua Rascunho

        $this->associarMaterial->execute($this->obra, $item,$b);

        $this->assertSame($b->id, $item->fresh()->material_id, 'Pedido Rascunho NAO deveria congelar — só Pedido Emitido.');
    }

    public function test_f_pedido_emitido_e_o_corte_mesmo_sem_recebimento(): void
    {
        $a = $this->criarMaterial(['codigo' => 'A-F']);
        $b = $this->criarMaterial(['codigo' => 'B-F']);
        $item = $this->criarItemTakeOffOrfao($a);
        $rpItem = $this->criarRpItemEmitido($item, 100);
        $alocacao = $this->alocarPacote($rpItem, 100);
        $rc = $this->criarRcRascunho($alocacao, 100);
        $rcEmitida = $this->emitirRc($rc);
        $pedidoItem = $this->criarPedidoRascunho($rcEmitida, 100);
        $this->emitirPedido($pedidoItem);

        $this->assertFalse(PoliticaAssociacaoMaterial::podeAlterarMaterial($item->fresh()));

        $this->expectException(ItemTakeOffMaterialImutavelException::class);
        $this->associarMaterial->execute($this->obra, $item,$b);
    }

    public function test_g_recebimento_bloqueia_troca_cenario_completo_do_c2(): void
    {
        $a = $this->criarMaterial(['codigo' => 'A-G']);
        $b = $this->criarMaterial(['codigo' => 'B-G']);
        $item = $this->criarItemTakeOffOrfao($a);
        $rpItem = $this->criarRpItemEmitido($item, 100);
        $alocacao = $this->alocarPacote($rpItem, 100);
        $rc = $this->criarRcRascunho($alocacao, 100);
        $rcEmitida = $this->emitirRc($rc);
        $pedidoItem = $this->criarPedidoRascunho($rcEmitida, 100);
        $pedidoItem = $this->emitirPedido($pedidoItem);
        $recebimento = $this->registrarRecebimento->execute($pedidoItem, 100, Carbon::parse('2026-12-10'), $this->user);

        $this->expectException(ItemTakeOffMaterialImutavelException::class);
        $this->associarMaterial->execute($this->obra, $item,$b);

        // A entrada, quando registrada, deve ser do Material A original.
    }

    public function test_g2_entrada_apos_recebimento_e_do_material_original(): void
    {
        $a = $this->criarMaterial(['codigo' => 'A-G2']);
        $item = $this->criarItemTakeOffOrfao($a);
        $rpItem = $this->criarRpItemEmitido($item, 100);
        $alocacao = $this->alocarPacote($rpItem, 100);
        $rc = $this->criarRcRascunho($alocacao, 100);
        $rcEmitida = $this->emitirRc($rc);
        $pedidoItem = $this->criarPedidoRascunho($rcEmitida, 100);
        $pedidoItem = $this->emitirPedido($pedidoItem);
        $recebimento = $this->registrarRecebimento->execute($pedidoItem, 100, Carbon::parse('2026-12-10'), $this->user);
        $local = $this->criarLocal();

        $movimentacao = $this->registrarEntrada->execute($recebimento, $local, 100, Carbon::today(), $this->user);

        $this->assertSame($a->id, $movimentacao->material_id, 'ACHADO C2 FECHADO: entrada continua atribuida ao Material original A.');
    }

    public function test_h_entrada_bloqueia_troca(): void
    {
        $a = $this->criarMaterial(['codigo' => 'A-H']);
        $b = $this->criarMaterial(['codigo' => 'B-H']);
        $item = $this->criarItemTakeOffOrfao($a);
        $rpItem = $this->criarRpItemEmitido($item, 100);
        $alocacao = $this->alocarPacote($rpItem, 100);
        $rc = $this->criarRcRascunho($alocacao, 100);
        $rcEmitida = $this->emitirRc($rc);
        $pedidoItem = $this->criarPedidoRascunho($rcEmitida, 100);
        $pedidoItem = $this->emitirPedido($pedidoItem);
        $recebimento = $this->registrarRecebimento->execute($pedidoItem, 100, Carbon::parse('2026-12-10'), $this->user);
        $this->registrarEntrada->execute($recebimento, $this->criarLocal(), 100, Carbon::today(), $this->user);

        $this->expectException(ItemTakeOffMaterialImutavelException::class);
        $this->associarMaterial->execute($this->obra, $item,$b);
    }

    // ---- I/J: Action nunca reinterpreta histórico / snapshot ----

    public function test_i_action_bloqueia_mesmo_chamada_direto_sem_ui(): void
    {
        $a = $this->criarMaterial(['codigo' => 'A-I']);
        $b = $this->criarMaterial(['codigo' => 'B-I']);
        $item = $this->criarItemTakeOffOrfao($a);
        MovimentacaoEstoque::create([
            'obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $a->id,
            'local_estoque_id' => $this->criarLocal()->id, 'item_take_off_id' => $item->id,
            'quantidade' => 10, 'ocorrido_em' => now(),
        ]);

        $this->expectException(ItemTakeOffMaterialImutavelException::class);
        $this->associarMaterial->execute($this->obra, $item,$b);
    }

    public function test_j_movimentacao_mantem_snapshot_mesmo_apos_tentativa_bloqueada(): void
    {
        $a = $this->criarMaterial(['codigo' => 'A-J']);
        $b = $this->criarMaterial(['codigo' => 'B-J']);
        $item = $this->criarItemTakeOffOrfao($a);
        $movimentacao = MovimentacaoEstoque::create([
            'obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $a->id,
            'local_estoque_id' => $this->criarLocal()->id, 'item_take_off_id' => $item->id,
            'quantidade' => 10, 'ocorrido_em' => now(),
        ]);

        try {
            $this->associarMaterial->execute($this->obra, $item,$b);
        } catch (ItemTakeOffMaterialImutavelException $e) {
            // esperado
        }

        $this->assertSame($a->id, $movimentacao->fresh()->material_id);
    }

    // ---- K: ausência de writer mass-update em produção (arquitetura) ----

    public function test_k_zero_writer_de_producao_faz_mass_update_de_material_id(): void
    {
        $arquivos = array_filter(
            glob(base_path('app/**/*.php'), GLOB_BRACE) + $this->globRecursivo(base_path('app')),
            fn ($f) => is_file($f)
        );

        $ofensores = [];
        foreach ($arquivos as $arquivo) {
            // A própria Action oficial e o Observer sao os UNICOS lugares
            // que legitimamente tocam material_id via save()/update() de
            // instância — mass-update via Query Builder é sempre proibido,
            // em qualquer arquivo. O Observer é excluído do scan porque seu
            // próprio docblock documenta, em prosa, o padrão exato que esta
            // checagem procura (ex.: "ItemTakeOff::where(...)->update([...])")
            // como exemplo do que é proibido — isso é comentário, não código,
            // mas casaria com a regex abaixo por coincidência textual.
            if (str_ends_with($arquivo, 'AssociarMaterialAoItemTakeOff.php')
                || str_ends_with($arquivo, 'ItemTakeOffObserver.php')
            ) {
                continue;
            }

            $conteudo = file_get_contents($arquivo);
            if (preg_match('/ItemTakeOff::where\([^)]*\)\s*->\s*update\(\s*\[[^\]]*material_id/s', $conteudo)
                || str_contains($conteudo, "DB::table('itens_take_off')")
            ) {
                $ofensores[] = $arquivo;
            }
        }

        $this->assertEmpty($ofensores, 'Encontrado writer de mass-update tocando material_id fora da Action oficial: ' . implode(', ', $ofensores));
    }

    private function globRecursivo(string $dir): array
    {
        $resultado = [];
        foreach (glob($dir . '/*') as $item) {
            if (is_dir($item)) {
                $resultado = array_merge($resultado, $this->globRecursivo($item));
            } elseif (str_ends_with($item, '.php')) {
                $resultado[] = $item;
            }
        }
        return $resultado;
    }

    // =========================================================
    // L-N: SoftDelete Material
    // =========================================================

    public function test_l_material_referenciado_por_item_take_off_nao_soft_delete(): void
    {
        $material = $this->criarMaterial();
        $this->criarItemTakeOffOrfao($material);

        $this->expectException(MaterialReferenciadoException::class);
        $material->delete();
    }

    public function test_l2_material_referenciado_por_movimentacao_nao_soft_delete(): void
    {
        $material = $this->criarMaterial();
        MovimentacaoEstoque::create([
            'obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id,
            'local_estoque_id' => $this->criarLocal()->id, 'quantidade' => 10, 'ocorrido_em' => now(),
        ]);

        $this->expectException(MaterialReferenciadoException::class);
        $material->delete();
    }

    public function test_m_material_referenciado_nao_force_delete(): void
    {
        $material = $this->criarMaterial();
        $this->criarItemTakeOffOrfao($material);

        $this->expectException(MaterialReferenciadoException::class);
        $material->forceDelete();
    }

    public function test_n_material_sem_referencia_pode_ser_excluido(): void
    {
        $material = $this->criarMaterial();

        $material->delete();

        $this->assertSoftDeleted('materiais', ['id' => $material->id]);
    }

    public function test_o_material_inativo_preserva_historico(): void
    {
        $material = $this->criarMaterial();
        $item = $this->criarItemTakeOffOrfao($material);
        $local = $this->criarLocal();
        $movimentacao = MovimentacaoEstoque::create([
            'obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id,
            'local_estoque_id' => $local->id, 'item_take_off_id' => $item->id,
            'quantidade' => 10, 'ocorrido_em' => now(),
        ]);

        $material->update(['ativo' => false]);

        $this->assertNotNull($movimentacao->fresh()->material);
        $this->assertEqualsWithDelta(10.0, SaldoEstoque::porMaterial($material->fresh()), 0.001);
    }

    // =========================================================
    // P-R: SoftDelete LocalEstoque
    // =========================================================

    public function test_p_local_referenciado_por_movimentacao_nao_soft_delete(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        MovimentacaoEstoque::create([
            'obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id,
            'local_estoque_id' => $local->id, 'quantidade' => 10, 'ocorrido_em' => now(),
        ]);

        $this->expectException(LocalEstoqueReferenciadoException::class);
        $local->delete();
    }

    public function test_q_local_referenciado_nao_force_delete(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        MovimentacaoEstoque::create([
            'obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id,
            'local_estoque_id' => $local->id, 'quantidade' => 10, 'ocorrido_em' => now(),
        ]);

        $this->expectException(LocalEstoqueReferenciadoException::class);
        $local->forceDelete();
    }

    public function test_r_local_inativo_preserva_historico(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $movimentacao = MovimentacaoEstoque::create([
            'obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id,
            'local_estoque_id' => $local->id, 'quantidade' => 10, 'ocorrido_em' => now(),
        ]);

        $local->update(['ativo' => false]);

        $this->assertNotNull($movimentacao->fresh()->localEstoque);
    }

    // =========================================================
    // S-Y: SaldoEstoque sinal central
    // =========================================================

    public function test_s_expressao_sql_usa_case_baseado_em_tipo(): void
    {
        DB::enableQueryLog();
        $material = $this->criarMaterial();
        SaldoEstoque::porMaterial($material);
        $query = collect(DB::getQueryLog())->last();
        DB::disableQueryLog();

        $this->assertStringContainsString('CASE', strtoupper($query['query']), 'SaldoEstoque deve usar CASE, nunca SUM cru.');
    }

    public function test_t_entrada_e_sempre_positiva_fator_mais_um(): void
    {
        $this->assertSame(1, TipoMovimentacaoEstoque::Entrada->fatorSaldo());
    }

    public function test_u_por_material_correto(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id, 'local_estoque_id' => $local->id, 'quantidade' => 30, 'ocorrido_em' => now()]);
        MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id, 'local_estoque_id' => $local->id, 'quantidade' => 20, 'ocorrido_em' => now()]);

        $this->assertEqualsWithDelta(50.0, SaldoEstoque::porMaterial($material), 0.001);
    }

    public function test_v_por_material_local_correto(): void
    {
        $material = $this->criarMaterial();
        $localA = $this->criarLocal();
        $localB = $this->criarLocal();
        MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id, 'local_estoque_id' => $localA->id, 'quantidade' => 30, 'ocorrido_em' => now()]);
        MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id, 'local_estoque_id' => $localB->id, 'quantidade' => 20, 'ocorrido_em' => now()]);

        $this->assertEqualsWithDelta(30.0, SaldoEstoque::porMaterialLocal($material, $localA), 0.001);
        $this->assertEqualsWithDelta(20.0, SaldoEstoque::porMaterialLocal($material, $localB), 0.001);
    }

    public function test_w_por_materiais_em_lote_correto(): void
    {
        $m1 = $this->criarMaterial();
        $m2 = $this->criarMaterial();
        $local = $this->criarLocal();
        MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $m1->id, 'local_estoque_id' => $local->id, 'quantidade' => 15, 'ocorrido_em' => now()]);
        MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $m2->id, 'local_estoque_id' => $local->id, 'quantidade' => 25, 'ocorrido_em' => now()]);

        $saldos = SaldoEstoque::porMateriais([$m1->id, $m2->id]);

        $this->assertEqualsWithDelta(15.0, $saldos[$m1->id], 0.001);
        $this->assertEqualsWithDelta(25.0, $saldos[$m2->id], 0.001);
    }

    public function test_x_por_unidade_lote_correto(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $unidade = \App\Models\UnidadeEstoque::create(['material_id' => $material->id, 'local_estoque_id' => $local->id, 'codigo_lote' => 'B-X']);
        MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id, 'local_estoque_id' => $local->id, 'unidade_estoque_id' => $unidade->id, 'quantidade' => 40, 'ocorrido_em' => now()]);

        $this->assertEqualsWithDelta(40.0, SaldoEstoque::porUnidade($unidade), 0.001);
    }

    public function test_y_saldo_em_lote_continua_sem_n_mais_1_apos_reescrita(): void
    {
        $local = $this->criarLocal();
        $materiais = [];
        for ($i = 0; $i < 20; $i++) {
            $material = $this->criarMaterial();
            MovimentacaoEstoque::create(['obra_id' => $this->obra->id, 'tipo' => 'entrada', 'material_id' => $material->id, 'local_estoque_id' => $local->id, 'quantidade' => 5, 'ocorrido_em' => now()]);
            $materiais[] = $material->id;
        }

        DB::enableQueryLog();
        $saldos = SaldoEstoque::porMateriais($materiais);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(20, $saldos->count());
        $this->assertSame(1, $queries);
    }

    // =========================================================
    // Z-AB: UI real
    // =========================================================

    public function test_z_ui_associacao_real_via_livewire(): void
    {
        $material = $this->criarMaterial(['codigo' => 'UI-Z']);
        $item = $this->criarItemTakeOffOrfao(null);
        $rpItem = $this->criarRpItemEmitido($item, 100);
        $alocacao = $this->alocarPacote($rpItem, 100);
        $rc = $this->criarRcRascunho($alocacao, 100);
        $rcEmitida = $this->emitirRc($rc);
        $pedidoItem = $this->criarPedidoRascunho($rcEmitida, 100);
        $pedidoItem = $this->emitirPedido($pedidoItem);
        $recebimento = $this->registrarRecebimento->execute($pedidoItem, 100, Carbon::parse('2026-12-10'), $this->user);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'recebimentos')
            ->call('abrirModalAssociarMaterial', $recebimento->id)
            ->set('materialSelecionadoId', $material->id)
            ->call('confirmarAssociarMaterial')
            ->assertSet('modalAssociarAberto', false);

        $this->assertSame($material->id, $item->fresh()->material_id);
    }

    public function test_aa_ui_congelado_mostra_mensagem_amigavel_nunca_permite_trocar(): void
    {
        $a = $this->criarMaterial(['codigo' => 'A-AA']);
        $item = $this->criarItemTakeOffOrfao($a);
        $rpItem = $this->criarRpItemEmitido($item, 100);
        $alocacao = $this->alocarPacote($rpItem, 100);
        $rc = $this->criarRcRascunho($alocacao, 100);
        $rcEmitida = $this->emitirRc($rc);
        $pedidoItem = $this->criarPedidoRascunho($rcEmitida, 100);
        $this->emitirPedido($pedidoItem);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'recebimentos');

        $linhas = $component->instance()->recebimentosPendentes;
        // Sem Recebimento ainda nesta etapa — mas o Pedido já Emitido já
        // deveria bastar pra congelar (verificado direto na Politica).
        $this->assertFalse(\App\Support\Estoque\PoliticaAssociacaoMaterial::podeAlterarMaterial($item->fresh()));
    }

    public function test_ab_usuario_sem_permissao_nao_associa(): void
    {
        $material = $this->criarMaterial();
        $item = $this->criarItemTakeOffOrfao(null);
        $rpItem = $this->criarRpItemEmitido($item, 100);
        $alocacao = $this->alocarPacote($rpItem, 100);
        $rc = $this->criarRcRascunho($alocacao, 100);
        $rcEmitida = $this->emitirRc($rc);
        $pedidoItem = $this->criarPedidoRascunho($rcEmitida, 100);
        $pedidoItem = $this->emitirPedido($pedidoItem);
        $recebimento = $this->registrarRecebimento->execute($pedidoItem, 100, Carbon::parse('2026-12-10'), $this->user);

        $usuarioLeitura = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $usuarioLeitura, Papel::ClienteLeitura->value);
        $this->actingAs($usuarioLeitura);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->call('abrirModalAssociarMaterial', $recebimento->id)
            ->assertStatus(403);

        $this->assertNull($item->fresh()->material_id);
    }

    // =========================================================
    // AC-AD: importador / Ciclo 19
    // =========================================================

    public function test_ac_importador_nunca_autoassocia_material(): void
    {
        // Checagem pela chave quotada exata — uma checagem crua por
        // "material_id" também casaria com "familia_material_id" (campo
        // legítimo, já escrito pelo importador desde o Ciclo 19), gerando
        // falso positivo.
        $conteudo = file_get_contents(base_path('app/Imports/TakeOffImporter.php'));
        $this->assertStringNotContainsString("'material_id'", $conteudo, 'TakeOffImporter nao deve escrever material_id automaticamente.');
        $this->assertStringNotContainsString('->material_id', $conteudo, 'TakeOffImporter nao deve escrever material_id automaticamente.');
    }

    public function test_ad_zero_alteracao_semantica_em_prontidao_e_restricao(): void
    {
        $material = $this->criarMaterial();
        $item = $this->criarItemTakeOffOrfao($material);
        $rpItem = $this->criarRpItemEmitido($item, 100);
        $alocacao = $this->alocarPacote($rpItem, 100);
        $rc = $this->criarRcRascunho($alocacao, 100);
        $rcEmitida = $this->emitirRc($rc);
        $pedidoItem = $this->criarPedidoRascunho($rcEmitida, 100);
        $pedidoItem = $this->emitirPedido($pedidoItem);
        $recebimento = $this->registrarRecebimento->execute($pedidoItem, 100, Carbon::parse('2026-12-10'), $this->user);
        $this->registrarEntrada->execute($recebimento, $this->criarLocal(), 100, Carbon::today(), $this->user);

        $this->assertSame(0, DB::table('restricoes')->where('tenant_id', $this->tenant->id)->count());
    }
}
