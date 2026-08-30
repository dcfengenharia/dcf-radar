<?php

namespace Tests\Feature;

use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Actions\Suprimentos\RegistrarConclusaoEtapaRequisicaoCompra;
use App\Enums\Papel;
use App\Enums\StatusEtapaRequisicaoCompra;
use App\Enums\StatusItemSuprimento;
use App\Enums\StatusRequisicaoCompra;
use App\Exceptions\AlocacaoConsumidaPorRequisicaoCompraException;
use App\Exceptions\AlocacaoRequisicaoInvalidaException;
use App\Exceptions\RequisicaoCompraEmissaoInvalidaException;
use App\Exceptions\RequisicaoCompraImutavelException;
use App\Exceptions\SaldoAlocacaoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\InconsistenciaAvanco;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\PlanoAcao;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoPlanejamentoItem;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.4 — Requisição de Compra (RC) + instância própria
 * do fluxo de suprimentos. Cobertura da matriz do pedido (A-AQ,
 * condensada em cenários representativos, sem redundância mecânica).
 */
class RequisicaoCompraTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private DocumentoEngenharia $documento;
    private ListaEngenharia $lista;

    private CriarRequisicaoPlanejamento $criarRp;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizarRp;
    private EmitirRequisicaoPlanejamento $emitirRp;
    private AlocarRequisicaoAoPacote $alocar;
    private CriarRequisicaoCompra $criarRc;
    private AtualizarRascunhoRequisicaoCompra $atualizarRc;
    private EmitirRequisicaoCompra $emitirRc;
    private RegistrarConclusaoEtapaRequisicaoCompra $concluirEtapa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->documento = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'ISO-001', 'descricao' => 'Isometrico']);
        $revisao = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $this->lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $this->criarRp = new CriarRequisicaoPlanejamento();
        $this->atualizarRp = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitirRp = new EmitirRequisicaoPlanejamento();
        $this->alocar = new AlocarRequisicaoAoPacote();
        $this->criarRc = new CriarRequisicaoCompra();
        $this->atualizarRc = new AtualizarRascunhoRequisicaoCompra();
        $this->emitirRc = new EmitirRequisicaoCompra();
        $this->concluirEtapa = new RegistrarConclusaoEtapaRequisicaoCompra();
    }

    private function criarItemTakeOff(string $codigo, float $quantidade): ItemTakeOff
    {
        return ItemTakeOff::create(['lista_engenharia_id' => $this->lista->id, 'codigo' => $codigo, 'descricao' => "Item {$codigo}", 'quantidade' => $quantidade]);
    }

    private function criarPacote(string $nome = 'Pacote X'): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => $nome, 'codigo' => $nome]);
    }

    private function alocacaoPronta(float $quantidadeAlocada, ?ItemSuprimento $pacote = null, float $quantidadePrevista = 1000): AlocacaoRequisicaoPacote
    {
        $item = $this->criarItemTakeOff('A' . uniqid(), $quantidadePrevista);
        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidadeAlocada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $this->alocar->alocar($rpItem->fresh(), $pacote ?? $this->criarPacote(), $quantidadeAlocada);
    }

    private function criarFluxo(array $etapas): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo RC Teste']);

        foreach ($etapas as $indice => [$nome, $prazo]) {
            $fluxo->etapas()->create([
                'tenant_id' => $this->tenant->id,
                'ordem' => $indice + 1,
                'nome' => $nome,
                'prazo_dias_uteis' => $prazo,
            ]);
        }

        return $fluxo->fresh(['etapas']);
    }

    // ---- A/B: cardinalidade — 1 RC : 1 Pacote sempre, 1 Pacote : N RCs ----

    public function test_a_rc_nasce_rascunho_vinculada_a_exatamente_um_pacote(): void
    {
        $pacote = $this->criarPacote();

        $rc = $this->criarRc->execute($pacote, null, 'Observação', $this->user);

        $this->assertSame(StatusRequisicaoCompra::Rascunho, $rc->status);
        $this->assertSame($pacote->id, $rc->item_suprimento_id);
        $this->assertNull($rc->numero);
    }

    public function test_b_um_pacote_pode_ter_varias_rcs(): void
    {
        $pacote = $this->criarPacote();

        $rc1 = $this->criarRc->execute($pacote, null, null, $this->user);
        $rc2 = $this->criarRc->execute($pacote, null, null, $this->user);

        $this->assertSame(2, RequisicaoCompra::where('item_suprimento_id', $pacote->id)->count());
        $this->assertNotSame($rc1->id, $rc2->id);
    }

    // ---- C: item de RC só pode consumir alocação do MESMO Pacote ----

    public function test_c_item_de_alocacao_de_outro_pacote_rejeitado(): void
    {
        $pacoteA = $this->criarPacote('A');
        $pacoteB = $this->criarPacote('B');
        $alocacaoDeB = $this->alocacaoPronta(50, $pacoteB);
        $rcDeA = $this->criarRc->execute($pacoteA, null, null, $this->user);

        $this->expectException(\InvalidArgumentException::class);
        $this->atualizarRc->adicionarItem($rcDeA, $alocacaoDeB, 20);
    }

    // ---- D/E: emissão exige item e fluxo ----

    public function test_d_emitir_sem_item_falha(): void
    {
        $pacote = $this->criarPacote();
        $rc = $this->criarRc->execute($pacote, $this->criarFluxo([['Cotação', 3]]), null, $this->user);

        $this->expectException(RequisicaoCompraEmissaoInvalidaException::class);
        $this->emitirRc->execute($rc, $this->user);
    }

    public function test_e_emitir_sem_fluxo_falha(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(50, $pacote);
        $rc = $this->criarRc->execute($pacote, null, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 20);

        $this->expectException(RequisicaoCompraEmissaoInvalidaException::class);
        $this->emitirRc->execute($rc->fresh(), $this->user);
    }

    // ---- F/G/H: saldo derivado, over-RC bloqueado, saldo exato ----

    public function test_f_saldo_derivado_da_soma_de_itens_de_rc(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $rc = $this->criarRc->execute($pacote, null, null, $this->user);

        $this->atualizarRc->adicionarItem($rc, $alocacao, 60);

        $this->assertSame('60.000', (string) RequisicaoCompraItem::where('requisicao_compra_id', $rc->id)->first()->quantidade);
    }

    /**
     * Ciclo 19, Etapa 19.4.CORREÇÃO — a partir desta correção, um único
     * Rascunho ainda é limitado ao saldo OFICIAL (que, sem nenhuma RC
     * Emitida/Concluída consumindo esta alocação, é o total da própria
     * alocação) — um draft não pode pedir mais do que fisicamente
     * existe. Cenários de 2 drafts concorrentes (que NÃO bloqueiam mais
     * um ao outro) estão em RequisicaoCompraSaldoCorrecaoTest.
     */
    public function test_g_um_unico_rascunho_ainda_nao_pode_exceder_o_total_da_alocacao(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $rc = $this->criarRc->execute($pacote, null, null, $this->user);

        $this->expectException(SaldoAlocacaoInsuficienteException::class);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 101);
    }

    public function test_h_saldo_exato_permitido_num_unico_rascunho(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $rc = $this->criarRc->execute($pacote, null, null, $this->user);

        $item = $this->atualizarRc->adicionarItem($rc, $alocacao, 100);

        $this->assertNotNull($item->id);
    }

    // ---- I/J: editar/remover item em rascunho ----

    public function test_i_alterar_quantidade_de_item_em_rascunho(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $rc = $this->criarRc->execute($pacote, null, null, $this->user);
        $item = $this->atualizarRc->adicionarItem($rc, $alocacao, 30);

        $this->atualizarRc->alterarQuantidade($item, 80);

        $this->assertSame('80.000', (string) $item->fresh()->quantidade);
    }

    public function test_j_remover_item_em_rascunho(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $rc = $this->criarRc->execute($pacote, null, null, $this->user);
        $item = $this->atualizarRc->adicionarItem($rc, $alocacao, 30);

        $this->atualizarRc->removerItem($item);

        $this->assertDatabaseMissing('requisicao_compra_itens', ['id' => $item->id]);
    }

    // ---- K: emissão congela snapshots ----

    public function test_k_emissao_congela_snapshots_independente_do_item_take_off_ao_vivo(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $rc = $this->criarRc->execute($pacote, $this->criarFluxo([['Cotação', 3]]), null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 30);

        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);

        $itemTakeOff = $alocacao->requisicaoItem->itemTakeOff;
        $itemTakeOff->update(['descricao' => 'Descrição Alterada Depois']);

        $itemRc = $emitida->itens->first();
        $this->assertNotSame('Descrição Alterada Depois', $itemRc->descricao_snapshot);
        $this->assertSame("Item {$itemTakeOff->codigo}", $itemRc->descricao_snapshot);
    }

    // ---- L: instanciação de etapas via DiasUteisCalculator (dias úteis) ----

    public function test_l_emissao_instancia_etapas_com_data_prevista_em_dias_uteis(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24')); // segunda-feira

        try {
            $pacote = $this->criarPacote();
            $alocacao = $this->alocacaoPronta(100, $pacote);
            $fluxo = $this->criarFluxo([['Cotação', 3], ['Pedido', 2]]);
            $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
            $this->atualizarRc->adicionarItem($rc, $alocacao, 30);

            $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);

            $etapas = $emitida->etapas()->orderBy('ordem')->get();
            $this->assertCount(2, $etapas);
            $this->assertSame('Cotação', $etapas[0]->nome_snapshot);
            $this->assertSame(3, $etapas[0]->prazo_dias_snapshot);
            // seg 24/08 + 3 dias úteis = qui 27/08
            $this->assertSame('2026-08-27', $etapas[0]->data_prevista->toDateString());
            // + 2 dias úteis a partir de qui 27/08 = sex 28/08, seg 31/08 (sáb/dom pulados)
            $this->assertSame('2026-08-31', $etapas[1]->data_prevista->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- M: status de etapa é derivado ----

    public function test_m_status_de_etapa_e_derivado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24'));

        try {
            $pacote = $this->criarPacote();
            $alocacao = $this->alocacaoPronta(100, $pacote);
            $fluxo = $this->criarFluxo([['Cotação', 1]]);
            $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
            $this->atualizarRc->adicionarItem($rc, $alocacao, 30);
            $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);
            $etapa = $emitida->etapas->first();

            $this->assertSame(StatusEtapaRequisicaoCompra::Pendente, $etapa->status());

            Carbon::setTestNow(Carbon::parse('2026-09-10'));
            $this->assertSame(StatusEtapaRequisicaoCompra::Atrasada, $etapa->fresh()->status());

            $this->concluirEtapa->execute($etapa->fresh(), $this->user, '2026-09-10');
            $this->assertSame(StatusEtapaRequisicaoCompra::Concluida, $etapa->fresh()->status());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- N/O: conclusão da ÚLTIMA etapa transiciona RC -> Concluida ----

    public function test_n_concluir_ultima_etapa_transiciona_rc_para_concluida(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1], ['Pedido', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 30);
        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        [$etapa1, $etapa2] = $emitida->etapas()->orderBy('ordem')->get()->all();

        $this->concluirEtapa->execute($etapa1, $this->user);
        $this->assertSame(StatusRequisicaoCompra::Emitida, $emitida->fresh()->status);

        $this->concluirEtapa->execute($etapa2, $this->user);
        $this->assertSame(StatusRequisicaoCompra::Concluida, $emitida->fresh()->status);
        $this->assertNotNull($emitida->fresh()->concluida_em);
    }

    public function test_o_concluir_etapa_intermediaria_nao_transiciona_rc(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1], ['Pedido', 1], ['Entrega', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 30);
        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $etapa1 = $emitida->etapas()->orderBy('ordem')->first();

        $this->concluirEtapa->execute($etapa1, $this->user);

        $this->assertSame(StatusRequisicaoCompra::Emitida, $emitida->fresh()->status);
    }

    // ---- P/Q: imutabilidade de RC Emitida/Concluida, Rascunho livre ----

    public function test_p_rc_emitida_nao_pode_ser_excluida(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 30);
        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);

        $this->expectException(RequisicaoCompraImutavelException::class);
        $emitida->delete();
    }

    public function test_q_rc_rascunho_pode_ser_excluida(): void
    {
        $pacote = $this->criarPacote();
        $rc = $this->criarRc->execute($pacote, null, null, $this->user);

        $rc->delete();

        $this->assertSoftDeleted('requisicoes_compra', ['id' => $rc->id]);
    }

    // ---- R: Pacote com RC não pode ser excluído (proativo, seção 43) ----

    public function test_r_pacote_com_rc_nao_pode_ser_excluido(): void
    {
        $pacote = $this->criarPacote();
        $this->criarRc->execute($pacote, null, null, $this->user);

        $this->expectException(AlocacaoRequisicaoInvalidaException::class);
        $pacote->delete();
    }

    public function test_r2_pacote_sem_rc_permanece_livre_para_excluir(): void
    {
        $pacote = $this->criarPacote();

        $pacote->delete();

        $this->assertSoftDeleted('itens_suprimento', ['id' => $pacote->id]);
    }

    /** Prova de lock: criar RC trava a mesma linha que o delete do Pacote trava. */
    public function test_r3_lock_de_criacao_de_rc_disputa_a_mesma_linha_do_delete(): void
    {
        $pacote = $this->criarPacote();

        $sqls = [];
        DB::listen(function ($q) use (&$sqls) {
            if (str_contains(strtolower($q->sql), 'for update') && str_contains($q->sql, 'itens_suprimento')) {
                $sqls[] = $q->sql;
            }
        });

        $this->criarRc->execute($pacote, null, null, $this->user);

        $this->assertGreaterThanOrEqual(1, count($sqls));
    }

    // ---- S/T/U: consumo OFICIAL (Emitida/Concluida) trava alteração/remoção da alocação ----

    public function test_s_reduzir_alocacao_abaixo_do_consumido_oficial_bloqueado(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 60);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        $this->expectException(AlocacaoConsumidaPorRequisicaoCompraException::class);
        $this->alocar->alterarQuantidade($alocacao->fresh(), 50);
    }

    public function test_t_remover_alocacao_com_consumo_oficial_bloqueado(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 10);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        $this->expectException(AlocacaoConsumidaPorRequisicaoCompraException::class);
        $this->alocar->remover($alocacao->fresh());
    }

    public function test_u_reduzir_alocacao_ate_exatamente_o_consumido_oficial_permitido(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 60);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        $this->alocar->alterarQuantidade($alocacao->fresh(), 60);

        $this->assertSame('60.000', (string) $alocacao->fresh()->quantidade_alocada);
    }

    /**
     * Ciclo 19, Etapa 19.4.CORREÇÃO, item 18 — REDUZIR (UPDATE) com
     * apenas consumo de rascunho é permitido (drafts ficam "stale",
     * revalidados de verdade só na emissão) — mas REMOVER (DELETE
     * físico) continua bloqueado mesmo só com rascunho, porque
     * `requisicao_compra_itens.alocacao_requisicao_pacote_id` é
     * `restrictOnDelete()` — o próprio banco rejeita o DELETE enquanto
     * qualquer item (rascunho ou não) ainda referenciar a linha.
     */
    public function test_u3_reduzir_com_apenas_rascunho_e_permitido_mas_remover_continua_bloqueado(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $rc = $this->criarRc->execute($pacote, null, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 80);

        $this->alocar->alterarQuantidade($alocacao->fresh(), 10);
        $this->assertSame('10.000', (string) $alocacao->fresh()->quantidade_alocada);

        $this->expectException(AlocacaoConsumidaPorRequisicaoCompraException::class);
        $this->alocar->remover($alocacao->fresh());
    }

    public function test_u2_alocacao_sem_consumo_de_rc_continua_livre_para_reduzir_remover(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);

        $this->alocar->alterarQuantidade($alocacao, 40);
        $this->assertSame('40.000', (string) $alocacao->fresh()->quantidade_alocada);

        $this->alocar->remover($alocacao->fresh());
        $this->assertDatabaseMissing('alocacoes_requisicao_pacote', ['id' => $alocacao->id]);
    }

    // ---- V: cross-obra ----

    public function test_v_criar_rc_para_pacote_de_outra_obra_nao_afeta_isolamento(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $pacoteOutraObra = ItemSuprimento::create(['obra_id' => $outraObra->id, 'nome' => 'X', 'codigo' => 'X']);

        $rc = $this->criarRc->execute($pacoteOutraObra, null, null, $this->user);

        $this->assertSame($outraObra->id, $rc->obra_id);
    }

    // ---- W: cross-tenant ----

    public function test_w_cross_tenant_rc_nao_visivel(): void
    {
        $outroTenant = Tenant::factory()->create();

        $rcOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $user = User::factory()->create(['tenant_id' => $outroTenant->id]);
            $obra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $pacote = ItemSuprimento::create(['obra_id' => $obra->id, 'nome' => 'X', 'codigo' => 'X']);

            return (new CriarRequisicaoCompra())->execute($pacote, null, null, $user);
        });

        $this->assertNull(RequisicaoCompra::find($rcOutroTenant->id));
    }

    // ---- X: fimPrevisto()/folga (risco de atendimento) — nunca cria Restricao ----

    public function test_x_fim_previsto_deriva_da_ultima_etapa_e_nunca_cria_restricao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24'));

        try {
            $pacote = $this->criarPacote();
            $alocacao = $this->alocacaoPronta(100, $pacote);
            $fluxo = $this->criarFluxo([['Cotação', 3], ['Pedido', 2]]);
            $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
            $this->atualizarRc->adicionarItem($rc, $alocacao, 30);
            $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);

            $fimPrevisto = $emitida->fimPrevisto();

            $this->assertNotNull($fimPrevisto);
            $this->assertSame('2026-08-31', $fimPrevisto->toDateString());
            $this->assertSame(0, Restricao::count());
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- AA: zero efeito colateral em todo o fluxo completo ----

    public function test_aa_zero_restricao_prontidao_inconsistencia_no_fluxo_completo(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 30);
        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $this->concluirEtapa->execute($emitida->etapas->first(), $this->user);

        $this->assertSame(0, Restricao::count());
        $this->assertSame(0, PlanoAcao::count());
        $this->assertSame(0, InconsistenciaAvanco::count());
    }

    // ---- AB: mecanismo legado do Pacote permanece intocado e independente ----

    public function test_ab_mecanismo_legado_do_pacote_permanece_intocado_com_rc_existindo(): void
    {
        $fluxoLegado = $this->criarFluxo([['Cotação', 5]]);
        $pacote = ItemSuprimento::create([
            'obra_id' => $this->obra->id,
            'nome' => 'Legado',
            'codigo' => 'Legado',
            'fluxo_suprimento_id' => $fluxoLegado->id,
        ]);
        $pacote->etapas()->create([
            'tenant_id' => $this->tenant->id,
            'etapa_fluxo_suprimento_id' => $fluxoLegado->etapas->first()->id,
            'ordem' => 1,
            'nome' => 'Cotação',
            'prazo_dias_uteis' => 5,
        ]);
        $statusLegadoAntes = $pacote->fresh()->status;

        $fluxoRc = $this->criarFluxo([['Cotação RC', 2]]);
        $alocacao = $this->alocacaoPronta(50, $pacote);
        $rc = $this->criarRc->execute($pacote, $fluxoRc, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 20);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        $pacoteDepois = $pacote->fresh(['etapas']);
        $this->assertSame($statusLegadoAntes, $pacoteDepois->status);
        $this->assertCount(1, $pacoteDepois->etapas);
        $this->assertSame('Cotação', $pacoteDepois->etapas->first()->nome);
    }

    // ---- AC: numeração sequencial por obra, rascunho não consome número ----

    public function test_ac_numeracao_sequencial_por_obra_rascunho_nao_consome_numero(): void
    {
        $pacote = $this->criarPacote();
        $alocacao1 = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);

        $rcRascunho = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->assertNull($rcRascunho->numero);

        $rc1 = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc1, $alocacao1, 30);
        $emitida1 = $this->emitirRc->execute($rc1->fresh(), $this->user);
        $this->assertSame(1, $emitida1->numero);

        $alocacao2 = $this->alocacaoPronta(100, $pacote);
        $rc2 = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc2, $alocacao2, 30);
        $emitida2 = $this->emitirRc->execute($rc2->fresh(), $this->user);
        $this->assertSame(2, $emitida2->numero);

        $this->assertNull($rcRascunho->fresh()->numero);
    }

    /**
     * Espelha o cenário "stale saldo na emissão" já testado pra RP
     * (`RequisicaoPlanejamentoTest::test_o_stale_saldo_na_emissao_bloqueado`):
     * 2 rascunhos de RC, cada um vendo saldo suficiente no momento de
     * montar, mas o PRIMEIRO a emitir consome o saldo que o segundo
     * contava usar — a emissão do segundo precisa revalidar de verdade
     * (não confiar no saldo "visto" quando o item foi adicionado).
     */
    public function test_ad_emissao_revalida_saldo_stale_contra_outras_rcs_emitidas(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);

        $rc1 = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc1, $alocacao, 60);

        $rc2 = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc2, $alocacao->fresh(), 40);
        // Ambos "veem" saldo suficiente ao montar (100 - 60 = 40 exato pro rc2).

        $this->emitirRc->execute($rc1->fresh(), $this->user);
        // rc1 emitida consome 60 — saldo real agora é 40, rc2 pediu exatamente 40: ainda cabe.
        $emitida2 = $this->emitirRc->execute($rc2->fresh(), $this->user);
        $this->assertSame(StatusRequisicaoCompra::Emitida, $emitida2->status);

        // Agora um 3º rascunho pedindo qualquer saldo > 0 deve falhar na emissão
        // (saldo real = 0), mesmo que a criação do item nunca tivesse sido bloqueada
        // por já não haver mais alocação livre pra criar (simulado direto via terceiro
        // consumo forçado por teste, contornando a validação já testada de adicionarItem).
        $this->expectException(SaldoAlocacaoInsuficienteException::class);
        $rc3 = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        DB::table('requisicao_compra_itens')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $this->tenant->id,
            'requisicao_compra_id' => $rc3->id,
            'alocacao_requisicao_pacote_id' => $alocacao->id,
            'quantidade' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->emitirRc->execute($rc3->fresh(), $this->user);
    }
}
