<?php

namespace Tests\Feature;

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
use App\Enums\Papel;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\InconsistenciaAvanco;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\PedidoCompraItem;
use App\Models\PlanoAcao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\SincronizarRestricaoCadeiaSuprimento;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.7 — sincronização Restrição automática × cadeia
 * formal de Suprimentos. Cobertura A-X do pedido (condensada em cenários
 * representativos, mesma convenção de `RecebimentoPedidoTest`).
 */
class SincronizarRestricaoCadeiaSuprimentoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private RegistrarRecebimentoPedido $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        Carbon::setTestNow(Carbon::parse('2026-12-15'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        $this->registrar = new RegistrarRecebimentoPedido();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarPacote(?Work $obra = null): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote' . uniqid()]);
    }

    private function criarAtividade(string $necessidade, ItemSuprimento $pacote, ?Work $obra = null): Atividade
    {
        $atividade = Atividade::factory()->create([
            'obra_id' => ($obra ?? $this->obra)->id,
            'inicio_planejado' => $necessidade,
        ]);
        $pacote->atividades()->attach($atividade->id);
        return $atividade->fresh();
    }

    /** Monta um PedidoCompraItem já Emitido pra um Pacote (cadeia formal completa). */
    private function pedidoItemEmitido(ItemSuprimento $pacote, float $quantidade = 100, ?Work $obra = null): PedidoCompraItem
    {
        $obraAlvo = $obra ?? $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obraAlvo->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item A', 'quantidade' => 1000]);
        $rp = (new CriarRequisicaoPlanejamento())->execute($obraAlvo->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, max($quantidade, 100));
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, max($quantidade, 100));
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($pacote, $fluxo, null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $fornecedor = Fornecedor::create(['obra_id' => $obraAlvo->id, 'nome' => 'F', 'cnpj' => '00.000.000/0001-00']);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $itemPedido = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        return $itemPedido->fresh();
    }

    private function restricaoDe(ItemSuprimento $pacote, Atividade $atividade): ?Restricao
    {
        return Restricao::where('atividade_id', $atividade->id)
            ->where('origem_cadeia_suprimento_id', $pacote->id)
            ->first();
    }

    /**
     * Confirma que NÃO HÁ bloqueio ativo — nunca "nenhuma linha existe",
     * já que os hooks event-driven (EmitirPedidoCompra/
     * RegistrarRecebimentoPedido) podem legitimamente ter criado E JÁ
     * RESOLVIDO a mesma linha antes do ponto do teste que faz a asserção
     * (mesma linha, reaproveitada, nunca duplicada — é exatamente o
     * comportamento correto sendo exercitado end-to-end pelos próprios
     * hooks, não um bug de teste a esconder).
     */
    private function assertSemRestricaoAberta(ItemSuprimento $pacote, Atividade $atividade): void
    {
        $restricao = $this->restricaoDe($pacote, $atividade);
        if ($restricao === null) {
            $this->assertTrue(true);
            return;
        }
        $this->assertSame(StatusRestricao::Resolvida, $restricao->status);
    }

    // ---- A: risco projetado sem Restrição ----

    public function test_a_risco_projetado_sem_restricao(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2027-01-10', $pacote);
        $item = $this->pedidoItemEmitido($pacote, 100);
        // Necessidade futura, material ainda pendente — risco projetado, mas nunca Restrição.
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertNull($this->restricaoDe($pacote, $atividade));
    }

    // ---- B: Pedido atrasado comercialmente sem necessidade vencida → sem Restrição ----

    public function test_b_pedido_atrasado_sem_necessidade_vencida_sem_restricao(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2027-02-01', $pacote); // necessidade bem futura
        $item = $this->pedidoItemEmitido($pacote, 100);
        // previsão do pedido já era 2026-12-01, hoje é 2026-12-15 -> comercialmente atrasado
        $this->assertNotNull($item->pedidoCompra->diasAtrasoAtual());

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertNull($this->restricaoDe($pacote, $atividade));
    }

    // ---- C/D: necessidade chegou + saldo pendente → Restrição bloqueante ----

    public function test_c_d_necessidade_vencida_com_saldo_pendente_cria_restricao_bloqueante(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote); // já passou
        $item = $this->pedidoItemEmitido($pacote, 100);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $restricao = $this->restricaoDe($pacote, $atividade);
        $this->assertNotNull($restricao);
        $this->assertSame(StatusRestricao::Aberta, $restricao->status);
        $this->assertTrue($restricao->bloqueante);
    }

    // ---- E: Atividade fica não pronta pela Restrição canônica ----

    public function test_e_atividade_fica_nao_pronta_pela_restricao_canonica(): void
    {
        $pacote = $this->criarPacote();
        // Necessidade FUTURA — emissão do Pedido (hook event-driven) não
        // dispara Condição C ainda, isolando a asserção "antes" do teste.
        $atividade = $this->criarAtividade('2027-01-10', $pacote);
        $this->pedidoItemEmitido($pacote, 100);

        $this->assertTrue($atividade->fresh()->estaPronta());

        $atividade->update(['inicio_planejado' => '2026-12-01']);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertFalse($atividade->fresh()->estaPronta());
    }

    // ---- F: receber tudo resolve Restrição ----

    public function test_f_receber_tudo_resolve_restricao(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $item = $this->pedidoItemEmitido($pacote, 100);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $this->assertNotNull($this->restricaoDe($pacote, $atividade));

        $this->registrar->execute($item->fresh(), 100, Carbon::today(), $this->user);

        $restricao = $this->restricaoDe($pacote, $atividade);
        $this->assertSame(StatusRestricao::Resolvida, $restricao->status);
        $this->assertTrue($atividade->fresh()->estaPronta());
    }

    // ---- G: atraso histórico completo não mantém Restrição ----

    public function test_g_atraso_historico_completo_nao_mantem_restricao(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $item = $this->pedidoItemEmitido($pacote, 100);
        $this->registrar->execute($item, 100, Carbon::today(), $this->user); // completo, mesmo que atrasado (previsão 12-01)

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertSemRestricaoAberta($pacote, $atividade);
    }

    // ---- H: A1 vencida / A2 futura → só A1 ----

    public function test_h_a1_vencida_a2_futura_so_a1(): void
    {
        $pacote = $this->criarPacote();
        $a1 = $this->criarAtividade('2026-12-01', $pacote);
        $a2 = $this->criarAtividade('2027-03-01', $pacote);
        $this->pedidoItemEmitido($pacote, 100);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertNotNull($this->restricaoDe($pacote, $a1));
        $this->assertNull($this->restricaoDe($pacote, $a2));
    }

    // ---- I: múltiplos Pacotes mesma Atividade → duas causas independentes ----

    public function test_i_multiplos_pacotes_mesma_atividade_duas_causas(): void
    {
        $pacote1 = $this->criarPacote();
        $pacote2 = $this->criarPacote();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-12-01']);
        $pacote1->atividades()->attach($atividade->id);
        $pacote2->atividades()->attach($atividade->id);
        $this->pedidoItemEmitido($pacote1, 100);
        $this->pedidoItemEmitido($pacote2, 100);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote1->fresh(), $this->user->id);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote2->fresh(), $this->user->id);

        $r1 = $this->restricaoDe($pacote1, $atividade);
        $r2 = $this->restricaoDe($pacote2, $atividade);
        $this->assertNotNull($r1);
        $this->assertNotNull($r2);
        $this->assertNotSame($r1->id, $r2->id);
    }

    // ---- J: múltiplos Pedidos mesmo Pacote → uma Restrição por Pacote/Atividade ----

    public function test_j_multiplos_pedidos_mesmo_pacote_uma_restricao(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $this->pedidoItemEmitido($pacote, 60);
        $this->pedidoItemEmitido($pacote, 40);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertSame(1, Restricao::where('atividade_id', $atividade->id)
            ->where('origem_cadeia_suprimento_id', $pacote->id)->count());
    }

    // ---- K: rerun 10x → zero duplicata ----

    public function test_k_rerun_10x_zero_duplicata(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $this->pedidoItemEmitido($pacote, 100);

        for ($i = 0; $i < 10; $i++) {
            SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        }

        $this->assertSame(1, Restricao::where('atividade_id', $atividade->id)
            ->where('origem_cadeia_suprimento_id', $pacote->id)->count());
    }

    // ---- L: manual não é tocada ----

    public function test_l_restricao_manual_nao_e_tocada(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $this->pedidoItemEmitido($pacote, 100);

        $manual = Restricao::create([
            'atividade_id' => $atividade->id,
            'descricao' => 'Material do Pacote pendente (texto parecido, mas manual)',
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
        ]);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertSame(StatusRestricao::Aberta, $manual->fresh()->status);
        $this->assertSame(2, Restricao::where('atividade_id', $atividade->id)->count());
    }

    // ---- M/N: reprogramação futuro->passado cria, passado->futuro resolve ----

    public function test_m_reprogramar_futuro_para_passado_cria_restricao(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2027-01-10', $pacote);
        $this->pedidoItemEmitido($pacote, 100);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $this->assertNull($this->restricaoDe($pacote, $atividade));

        $atividade->update(['inicio_planejado' => '2026-12-01']);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertNotNull($this->restricaoDe($pacote, $atividade));
    }

    public function test_n_reprogramar_passado_para_futuro_resolve_restricao(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $this->pedidoItemEmitido($pacote, 100);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $this->assertNotNull($this->restricaoDe($pacote, $atividade));

        $atividade->update(['inicio_planejado' => '2027-01-10']);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertSame(StatusRestricao::Resolvida, $this->restricaoDe($pacote, $atividade)->status);
    }

    // ---- Reabertura: mesma linha, novo episódio ----

    public function test_reabertura_reaproveita_mesma_linha(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $this->pedidoItemEmitido($pacote, 100);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $primeira = $this->restricaoDe($pacote, $atividade);

        $atividade->update(['inicio_planejado' => '2027-01-10']);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $this->assertSame(StatusRestricao::Resolvida, $primeira->fresh()->status);

        $atividade->update(['inicio_planejado' => '2026-12-01']);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $reaberta = $this->restricaoDe($pacote, $atividade);
        $this->assertSame($primeira->id, $reaberta->id);
        $this->assertSame(StatusRestricao::Aberta, $reaberta->status);
    }

    // ---- O/P: arquivar resolve/ignora, restaurar reavalia ----

    public function test_o_p_arquivar_resolve_restaurar_reavalia(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $this->pedidoItemEmitido($pacote, 100);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $this->assertNotNull($this->restricaoDe($pacote, $atividade));

        $atividade->update(['fora_do_cronograma' => true]);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $this->assertSame(StatusRestricao::Resolvida, $this->restricaoDe($pacote, $atividade)->status);

        $atividade->update(['fora_do_cronograma' => false]);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $this->assertSame(StatusRestricao::Aberta, $this->restricaoDe($pacote, $atividade)->status);
    }

    // ---- R: cross-obra ----

    public function test_r_cross_obra_isolamento(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);

        $pacoteA = $this->criarPacote($this->obra);
        $atividadeA = $this->criarAtividade('2026-12-01', $pacoteA, $this->obra);
        $this->pedidoItemEmitido($pacoteA, 100, $this->obra);

        // Necessidade FUTURA em B — isola a asserção de que sincronizar A
        // nunca cria nada em B (nunca dispara nem pelo hook de emissão).
        $pacoteB = $this->criarPacote($outraObra);
        $atividadeB = $this->criarAtividade('2027-03-01', $pacoteB, $outraObra);
        $this->pedidoItemEmitido($pacoteB, 100, $outraObra);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacoteA->fresh(), $this->user->id);

        $this->assertNotNull($this->restricaoDe($pacoteA, $atividadeA));
        $this->assertNull($this->restricaoDe($pacoteB, $atividadeB));
    }

    // ---- S: cross-tenant ----

    public function test_s_cross_tenant_isolamento(): void
    {
        $novoTenant = Tenant::factory()->create();

        TenantContext::actingAs($novoTenant, function () use ($novoTenant) {
            $outraObra = Work::factory()->create(['tenant_id' => $novoTenant->id]);
            $outroUser = User::factory()->create(['tenant_id' => $novoTenant->id]);
            $this->vincularObra($outraObra, $outroUser, Papel::GerentePlanejamento->value);
            $this->actingAs($outroUser);

            $pacote = $this->criarPacote($outraObra);
            $this->criarAtividade('2026-12-01', $pacote, $outraObra);
            $this->pedidoItemEmitido($pacote, 100, $outraObra);
            SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $outroUser->id);
        });

        $this->actingAs($this->user);
        $this->assertSame(0, Restricao::where('tenant_id', $this->tenant->id)
            ->whereNotNull('origem_cadeia_suprimento_id')->count());
    }

    // ---- T: sem atividade ----

    public function test_t_pacote_sem_atividade_nunca_gera_restricao(): void
    {
        $pacote = $this->criarPacote();
        $this->pedidoItemEmitido($pacote, 100);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertSame(0, Restricao::whereNotNull('origem_cadeia_suprimento_id')->count());
    }

    // ---- U: sem demanda formal ----

    public function test_u_sem_demanda_formal_nunca_gera_restricao(): void
    {
        $pacote = $this->criarPacote();
        $this->criarAtividade('2026-12-01', $pacote);
        // nenhum RC/Pedido criado

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertSame(0, Restricao::whereNotNull('origem_cadeia_suprimento_id')->count());
    }

    // ---- V: Pedido completo ----

    public function test_v_pedido_completo_nunca_gera_restricao(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $item = $this->pedidoItemEmitido($pacote, 100);
        $this->registrar->execute($item, 100, Carbon::today(), $this->user);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertSemRestricaoAberta($pacote, $atividade);
    }

    // ---- W: parcial ----

    public function test_w_recebimento_parcial_mantem_restricao(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $item = $this->pedidoItemEmitido($pacote, 100);
        $this->registrar->execute($item, 40, Carbon::today(), $this->user);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertNotNull($this->restricaoDe($pacote, $atividade));
    }

    // ---- X: múltiplas unidades sem soma indevida (mesmo já validado em ConciliacaoRecebimento — reconfirmado aqui) ----

    public function test_x_multiplas_unidades_nunca_afetam_deteccao_de_pendencia(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $itemKg = $this->pedidoItemEmitido($pacote, 50);
        $this->registrar->execute($itemKg, 50, Carbon::today(), $this->user); // este completo

        // 2º item, unidade/lista diferente, ainda pendente
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DB' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LMB' . uniqid()]);
        $itemTo = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'B' . uniqid(), 'descricao' => 'Item B', 'quantidade' => 200]);
        $rp2 = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem2 = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp2, $itemTo->id, 20);
        (new EmitirRequisicaoPlanejamento())->execute($rp2->fresh(), $this->user);
        $alocacaoM = (new AlocarRequisicaoAoPacote())->alocar($rpItem2->fresh(), $pacote, 20);
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'FluxoM' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rcM = (new CriarRequisicaoCompra())->execute($pacote, $fluxo, null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rcM, $alocacaoM, 20);
        $rcMEmitida = (new EmitirRequisicaoCompra())->execute($rcM->fresh(), $this->user);
        $fornecedorM = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'FM', 'cnpj' => '00.000.000/0001-00']);
        $pedidoM = (new CriarPedidoCompra())->execute($rcMEmitida, $fornecedorM, '2026-12-01', null, null, null, $this->user);
        (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedidoM, $rcMEmitida->itens->first(), 20);
        (new EmitirPedidoCompra())->execute($pedidoM->fresh(), $this->user);
        // itemM nunca recebido

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        // Detecção de pendência nunca soma kg+un — só CONTAGEM (itemM pendente basta pra Condição C).
        $this->assertNotNull($this->restricaoDe($pacote, $atividade));
    }

    // ---- Zero efeito colateral em Suprimentos legado ----

    public function test_zero_efeito_em_legado_e_inconsistencia_avanco(): void
    {
        $pacote = $this->criarPacote();
        $atividade = $this->criarAtividade('2026-12-01', $pacote);
        $fluxoLegado = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Legado']);
        $fluxoLegado->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 3]);
        $pacote->update(['fluxo_suprimento_id' => $fluxoLegado->id]);
        (new \App\Services\SuprimentoScheduler())->criarEtapasDoItem($pacote->fresh());
        $etapasAntes = $pacote->fresh()->etapas()->count();

        $this->pedidoItemEmitido($pacote, 100);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $this->assertSame($etapasAntes, $pacote->fresh()->etapas()->count());
        $this->assertSame(0, PlanoAcao::count());
        $this->assertSame(0, InconsistenciaAvanco::count());
        // A Restrição automática legada (origem_suprimento_item_id) nunca é criada por este mecanismo.
        $this->assertSame(0, Restricao::whereNotNull('origem_suprimento_item_id')->count());
    }
}
