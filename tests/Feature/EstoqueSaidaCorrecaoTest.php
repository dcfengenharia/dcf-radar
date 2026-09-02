<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarDestinacaoPlanejada;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\LiberarReservaEstoque;
use App\Actions\Estoque\RegistrarEntradaEstoque;
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
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\SaidaEstoqueInvalidaException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\FrenteTrabalho;
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
use App\Support\Estoque\ResolverMaterialDaCadeia;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.3.CORREÇÃO — fecha o Achado C1 (retirado_por
 * cross-tenant), a Decisão D2 (retirante obrigatório) e o Achado B de
 * N+1 (recebimentosPendentes) da auditoria adversarial da 20.3.
 */
class EstoqueSaidaCorrecaoTest extends TestCase
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
    private RegistrarEntradaEstoque $registrarEntrada;
    private RegistrarSaidaEstoque $registrarSaida;
    private AtualizarDestinacaoPlanejada $destinacaoAction;
    private CriarReservaEstoque $reservaAction;
    private LiberarReservaEstoque $liberarAction;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

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
        $this->registrarEntrada = new RegistrarEntradaEstoque();
        $this->registrarSaida = new RegistrarSaidaEstoque();
        $this->destinacaoAction = new AtualizarDestinacaoPlanejada();
        $this->reservaAction = new CriarReservaEstoque();
        $this->liberarAction = new LiberarReservaEstoque();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (mesma toolkit de EstoqueSaidaTest) ----

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

    private function criarPacoteSimples(?Work $obra = null): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote ' . uniqid()]);
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
        $rp = $this->criarRp->execute($obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidade);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $rpItem->fresh();
    }

    private function alocarNoPacote(\App\Models\RequisicaoPlanejamentoItem $rpItem, float $quantidade, ?ItemSuprimento $pacote = null, ?Work $obra = null): AlocacaoRequisicaoPacote
    {
        $pacote ??= $this->criarPacoteSimples($obra);

        return $this->alocar->alocar($rpItem, $pacote, $quantidade);
    }

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade, ?string $codigoLote = null, ?ItemSuprimento $pacoteExistente = null): ItemSuprimento
    {
        $item = $this->criarItemTakeOffOrfao($material, $this->obra);
        $rpItem = $this->criarRpItemEmitido($item, $quantidade);
        $alocacao = $this->alocarNoPacote($rpItem, $quantidade, $pacoteExistente);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-10'), $this->user);

        $this->registrarEntrada->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user, $codigoLote);

        return ItemSuprimento::find($alocacao->item_suprimento_id);
    }

    // =========================================================
    // A-J: Retirante — regra final
    // =========================================================

    public function test_a_usuario_interno_valido(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $mov = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertSame($this->user->id, $mov->retirado_por);
        $this->assertNull($mov->retirado_por_externo);
    }

    public function test_b_externo_valido(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $mov = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPorExterno: 'Carlos Silva — Eletricista — Empresa XYZ');

        $this->assertNull($mov->retirado_por);
        $this->assertSame('Carlos Silva — Eletricista — Empresa XYZ', $mov->retirado_por_externo);
    }

    public function test_c_ambos_null_bloqueia(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user);
    }

    public function test_d_ambos_preenchidos_bloqueia(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPor: $this->user, retiradoPorExterno: 'Fulano');
    }

    public function test_e_externo_vazio_bloqueia(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPorExterno: '   ');
    }

    public function test_f_externo_trim(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $mov = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPorExterno: '  Carlos Silva  ');

        $this->assertSame('Carlos Silva', $mov->retirado_por_externo);
    }

    public function test_g_retirado_user_cross_tenant_bloqueia(): void
    {
        $outroTenant = Tenant::factory()->create();
        $usuarioOutroTenant = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPor: $usuarioOutroTenant);

        $this->assertSame(0, MovimentacaoEstoque::where('tipo', TipoMovimentacaoEstoque::Saida->value)->count());
    }

    public function test_h_retirado_user_cross_obra_bloqueia(): void
    {
        // Decisão do usuário (Seção 5): retirante interno precisa também
        // estar vinculado à MESMA obra, não só ao mesmo tenant.
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $usuarioOutraObra = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $usuarioOutraObra, Papel::GerentePlanejamento->value);

        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPor: $usuarioOutraObra);

        $this->assertSame(0, MovimentacaoEstoque::where('tipo', TipoMovimentacaoEstoque::Saida->value)->count());
    }

    public function test_i_registrado_por_diferente_de_retirado_por(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);
        $retirante = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $retirante, Papel::Encarregado->value);

        $mov = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPor: $retirante);

        $this->assertSame($this->user->id, $mov->registrado_por);
        $this->assertSame($retirante->id, $mov->retirado_por);
        $this->assertNotSame($mov->registrado_por, $mov->retirado_por);
    }

    public function test_j_mesmo_user_pode_ser_ambos(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $mov = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertSame($this->user->id, $mov->registrado_por);
        $this->assertSame($this->user->id, $mov->retirado_por);
    }

    // =========================================================
    // K-O: Action direta / UI / efeito parcial
    // =========================================================

    public function test_k_action_direto_valida(): void
    {
        // Confirma que a validação vive na Action, não só na UI —
        // chamada direta (sem Livewire) já bloqueia.
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user);
    }

    public function test_l_livewire_payload_manipulado_bloqueia(): void
    {
        // Simula manipulação de payload: usuário força um ID de retirante
        // de outro tenant diretamente na propriedade Livewire.
        $outroTenant = Tenant::factory()->create();
        $usuarioOutroTenant = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obra])
            ->set('abaAtiva', 'saidas')
            ->call('abrirModalSaida')
            ->set('saidaMaterialId', $material->id)
            ->set('saidaLocalId', $local->id)
            ->set('saidaQuantidade', 50)
            ->set('saidaData', now()->toDateString())
            ->set('saidaRetiradoPorId', $usuarioOutroTenant->id)
            ->call('confirmarSaida')
            ->assertHasErrors('saidaGeral');

        $this->assertSame(0, MovimentacaoEstoque::where('tipo', TipoMovimentacaoEstoque::Saida->value)->count());
    }

    public function test_m_zero_efeito_parcial_na_falha(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        try {
            $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user);
        } catch (SaidaEstoqueInvalidaException $e) {
            // esperado
        }

        $this->assertSame(0, MovimentacaoEstoque::where('tipo', TipoMovimentacaoEstoque::Saida->value)->count());
    }

    public function test_n_saldo_permanece_intacto_na_falha(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 500);

        try {
            $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user);
        } catch (SaidaEstoqueInvalidaException $e) {
            // esperado
        }

        $this->assertEquals(500, SaldoEstoque::porMaterialLocal($material, $local));
    }

    public function test_o_reserva_permanece_intacta_na_falha(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        try {
            $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, reserva: $reserva);
        } catch (SaidaEstoqueInvalidaException $e) {
            // esperado (sem retirante)
        }

        $this->assertEquals(200, (float) $reserva->fresh()->quantidade);
        $this->assertEquals(0, SaldoReserva::consumidoPorSaidas($reserva->fresh()));
    }

    // =========================================================
    // P-S: N+1 recebimentosPendentes
    // =========================================================

    public function test_p_n1_baseline_caracterizado(): void
    {
        // Mede o custo do computed recebimentosPendentes() ISOLADO (não
        // full-render), com 1 vs 20 recebimentos — prova que o custo
        // ANTES desta correção escalava linearmente (documentado como
        // referência; a correção já está deployada, então este teste
        // mede o comportamento JÁ CORRIGIDO — ver test_q).
        $this->markTestSkipped('Baseline documentado no relatório da auditoria (166 queries/20 materiais) — a correção já está deployada neste código; ver test_q para o comportamento pós-correção.');
    }

    public function test_q_n1_corrigido_20_recebimentos(): void
    {
        $material1 = $this->criarMaterial();
        $local1 = $this->criarLocal();
        $this->entradaPronta($material1, $local1, 10);

        for ($i = 0; $i < 20; $i++) {
            $m = $this->criarMaterial();
            $l = $this->criarLocal();
            $this->entradaPronta($m, $l, 10);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obra]);
        $resultado = $component->instance()->recebimentosPendentes;
        $totalQueries = count(DB::getQueryLog());

        fwrite(STDERR, "[Q] recebimentosPendentes com 21 recebimentos: {$totalQueries} queries totais\n");

        // Todos entraram como incorporação TOTAL (recebimentoPronta ja
        // incorpora 100% via entradaPronta) -> pendente deveria ser 0
        // pra todos, resultado vazio (correto), mas o CUSTO de resolver
        // a cadeia é pago mesmo que o filtro final remova tudo.
        $this->assertLessThan(40, $totalQueries, 'Query count deveria ser um numero fixo pequeno (medido: ~21), nunca proporcional a 21 recebimentos');
    }

    public function test_r_100_recebimentos_sem_crescimento_linear(): void
    {
        // Medição por DELTA na MESMA obra/tenant (evita qualquer risco de
        // troca de contexto mid-test) — 5 recebimentos presentes, depois
        // mais 95 (total 100), comparando o custo do MESMO computed
        // isolado. O delta não pode escalar proporcionalmente a 95
        // recebimentos a mais.
        for ($i = 0; $i < 5; $i++) {
            $m = $this->criarMaterial();
            $l = $this->criarLocal();
            $this->entradaPronta($m, $l, 10);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $c1 = Livewire::test('pages::radar.estoque', ['obra' => $this->obra]);
        $c1->instance()->recebimentosPendentes;
        $q5 = count(DB::getQueryLog());

        for ($i = 0; $i < 95; $i++) {
            $m = $this->criarMaterial();
            $l = $this->criarLocal();
            $this->entradaPronta($m, $l, 10);
        }

        DB::flushQueryLog();
        $c2 = Livewire::test('pages::radar.estoque', ['obra' => $this->obra]);
        $c2->instance()->recebimentosPendentes;
        $q100 = count(DB::getQueryLog());

        fwrite(STDERR, "[R] 5 recebimentos: {$q5} queries | 100 recebimentos: {$q100} queries\n");

        $this->assertLessThanOrEqual($q5 + 10, $q100, 'Query count não deveria escalar de 5 para 100 recebimentos (N+1 suspeito)');
    }

    public function test_s_grep_zero_mass_writer_producao(): void
    {
        $base = base_path('app');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        $encontrados = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $conteudo = file_get_contents($file->getPathname());
            if (preg_match('/MovimentacaoEstoque::where\([^)]*\)->(update|delete)\(/', $conteudo)
                || str_contains($conteudo, "DB::table('movimentacoes_estoque')->update")
                || str_contains($conteudo, "DB::table('movimentacoes_estoque')->delete")
            ) {
                $encontrados[] = $file->getPathname();
            }
        }

        $this->assertEmpty($encontrados, 'Writer de mass-update/delete encontrado em produção: ' . implode(', ', $encontrados));
    }

    // =========================================================
    // T-X: comportamentos preservados
    // =========================================================

    public function test_t_saida_emergencial_continua_permitida(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 100);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 100, null, null, $this->user);

        $mov = $this->registrarSaida->execute($material, $local, 100, Carbon::today(), $this->user, retiradoPor: $this->user);

        $this->assertEquals(0, SaldoEstoque::porMaterialLocal($material, $local));
        $this->assertEquals(100, (float) $reserva->fresh()->quantidade);
        $this->assertSame(\App\Enums\StatusReservaEstoque::Ativa, $reserva->fresh()->status);
        $this->assertNull($mov->reserva_estoque_id);
    }

    public function test_u_frente_a_para_b_continua_permitida(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500);
        $frenteA = FrenteTrabalho::create(['obra_id' => $this->obra->id, 'nome' => 'Frente A']);
        $frenteB = FrenteTrabalho::create(['obra_id' => $this->obra->id, 'nome' => 'Frente B']);
        $dest = $this->destinacaoAction->criar($pacote, $material, $frenteA, 200, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, $dest, $this->user);

        $mov = $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, reserva: $reserva, frenteInformada: $frenteB, retiradoPor: $this->user);

        $this->assertSame($frenteB->id, $mov->frente_trabalho_id);
        $this->assertSame($frenteA->id, $dest->fresh()->frente_trabalho_id);
    }

    public function test_v_bobina_continua_correta(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Lote->value]);
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000, 'BCORR1');
        $unidade = \App\Models\UnidadeEstoque::where('codigo_lote', 'BCORR1')->firstOrFail();

        $this->registrarSaida->execute($material, $local, 120, Carbon::today(), $this->user, unidade: $unidade, retiradoPor: $this->user);
        $this->registrarSaida->execute($material, $local, 80, Carbon::today(), $this->user, unidade: $unidade->fresh(), retiradoPor: $this->user);

        $this->assertEquals(800, SaldoEstoque::porUnidade($unidade->fresh()));
        $this->assertSame(1, \App\Models\UnidadeEstoque::where('material_id', $material->id)->count());
    }

    public function test_w_serial_continua_correto(): void
    {
        $material = $this->criarMaterial(['modo_rastreabilidade' => ModoRastreabilidadeMaterial::Serializado->value]);
        $local = $this->criarLocal();

        $item = $this->criarItemTakeOffOrfao($material, $this->obra);
        $rpItem = $this->criarRpItemEmitido($item, 1);
        $alocacao = $this->alocarNoPacote($rpItem, 1);
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 1);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, 1)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, 1, Carbon::parse('2026-12-10'), $this->user);
        $this->registrarEntrada->execute($recebimento, $local, 1, Carbon::today(), $this->user, null, 'SERCORR1');

        $unidade = \App\Models\UnidadeEstoque::where('serial_unico', 'SERCORR1')->firstOrFail();
        $this->registrarSaida->execute($material, $local, 1, Carbon::today(), $this->user, unidade: $unidade, retiradoPor: $this->user);

        $this->expectException(\App\Exceptions\SaldoFisicoInsuficienteException::class);
        $this->registrarSaida->execute($material, $local, 1, Carbon::today(), $this->user, unidade: $unidade->fresh(), retiradoPor: $this->user);
    }

    public function test_x_cross_tenant_material_local_continuam_bloqueados(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500);
        $pacoteOutraObra = $this->criarPacoteSimples($outraObra);

        $this->expectException(SaidaEstoqueInvalidaException::class);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, pacote: $pacoteOutraObra, retiradoPor: $this->user);
    }

    // =========================================================
    // Y: zero 20.4
    // =========================================================

    /**
     * Ciclo 20, Etapa 20.4 — mesma correção de
     * `EstoqueSaidaTest::test_ar_zero_conceito_pos_20_4_no_codigo_de_producao()`:
     * a 20.4 (Aplicação/Conciliação/Desvio/Déficit/Recomposição) já foi
     * implementada e aprovada com nomes reais — removidos da lista de
     * proibidos. O guard real (nunca antecipar Industrialização/
     * Inventário/Transferência/Ajuste, conceitos de fases futuras)
     * permanece intacto.
     */
    public function test_y_zero_conceito_pos_20_4(): void
    {
        $base = base_path('app');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        // Ciclo 21, Etapa 21.2 — mesmo achado/mesma correção de
        // EstoqueSaidaTest::test_ar_...(): espaço à direita exige o nome
        // EXATO do case, fechando a colisão de prefixo com
        // App\Enums\TipoSituacaoGerencial (enum não relacionado, catálogo
        // de situações gerenciais) sem enfraquecer o invariante real.
        $proibidos = ['case Industrializacao ', 'case Divergencia ', 'case Inventario ', 'case Transferencia ', 'case Ajuste '];

        $encontrados = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $conteudo = file_get_contents($file->getPathname());
            foreach ($proibidos as $termo) {
                if (str_contains($conteudo, $termo)) {
                    $encontrados[] = $file->getPathname() . ' :: ' . $termo;
                }
            }
        }

        $this->assertEmpty($encontrados, 'Conceitos da 20.4 encontrados prematuramente: ' . implode(', ', $encontrados));
    }

    public function test_zero_alteracao_em_restricao_prontidao(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 500);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 200, null, null, $this->user);

        $this->registrarSaida->execute($material, $local, 300, Carbon::today(), $this->user, retiradoPor: $this->user);
        $this->registrarSaida->execute($material, $local, 50, Carbon::today(), $this->user, reserva: $reserva, retiradoPorExterno: 'Externo Teste');

        $this->assertSame(0, \App\Models\Restricao::count());
    }
}
