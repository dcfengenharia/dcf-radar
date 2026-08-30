<?php

namespace Tests\Feature;

use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\Papel;
use App\Exceptions\AlocacaoRequisicaoInvalidaException;
use App\Exceptions\SaldoRequisicaoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\InconsistenciaAvanco;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\PlanoAcao;
use App\Models\RequisicaoPlanejamento;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.3 — alocação quantitativa de RequisicaoPlanejamentoItem
 * a Pacote de Compra (ItemSuprimento). Cobertura A-N, M/N (cross), AH/AI
 * (zero efeito colateral) da matriz obrigatória.
 */
class AlocacaoRequisicaoPacoteTest extends TestCase
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
    }

    private function criarItemTakeOff(string $codigo, float $quantidade): ItemTakeOff
    {
        return ItemTakeOff::create(['lista_engenharia_id' => $this->lista->id, 'codigo' => $codigo, 'descricao' => "Item {$codigo}", 'quantidade' => $quantidade]);
    }

    private function criarPacote(string $nome = 'Pacote X'): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => $nome, 'codigo' => $nome]);
    }

    private function rpItemEmitido(float $quantidadeRequisitada, string $codigoItem = 'A', float $quantidadePrevista = 1000): \App\Models\RequisicaoPlanejamentoItem
    {
        $item = $this->criarItemTakeOff($codigoItem, $quantidadePrevista);
        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidadeRequisitada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $rpItem->fresh();
    }

    // ---- A: Pacote legado continua existindo (sem quebrar nada) ----

    public function test_a_pacote_legado_continua_existindo(): void
    {
        $pacote = $this->criarPacote();

        $this->assertDatabaseHas('itens_suprimento', ['id' => $pacote->id]);
        $this->assertNull($pacote->necessidade());
    }

    // ---- B/C: só RP Emitida pode ser alocada ----

    public function test_b_rp_emitida_pode_ser_alocada(): void
    {
        $rpItem = $this->rpItemEmitido(60);
        $pacote = $this->criarPacote();

        $alocacao = $this->alocar->alocar($rpItem, $pacote, 40);

        $this->assertSame('40.000', (string) $alocacao->quantidade_alocada);
    }

    public function test_c_rp_rascunho_nao_pode_ser_alocada(): void
    {
        $item = $this->criarItemTakeOff('A', 100);
        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, 60);
        $pacote = $this->criarPacote();

        $this->expectException(AlocacaoRequisicaoInvalidaException::class);
        $this->alocar->alocar($rpItem, $pacote, 30);
    }

    // ---- D: alocação parcial ----

    public function test_d_alocacao_parcial(): void
    {
        $rpItem = $this->rpItemEmitido(100);
        $pacote = $this->criarPacote();

        $alocacao = $this->alocar->alocar($rpItem, $pacote, 40);

        $this->assertSame('40.000', (string) $alocacao->quantidade_alocada);
    }

    // ---- E: uma RPItem em 2 Pacotes ----

    public function test_e_uma_rpitem_em_dois_pacotes(): void
    {
        $rpItem = $this->rpItemEmitido(100);
        $p1 = $this->criarPacote('P1');
        $p2 = $this->criarPacote('P2');

        $this->alocar->alocar($rpItem, $p1, 60);
        $this->alocar->alocar($rpItem, $p2, 40);

        $this->assertSame(2, AlocacaoRequisicaoPacote::where('requisicao_planejamento_item_id', $rpItem->id)->count());
    }

    // ---- F: 1 Pacote com várias RPItems ----

    public function test_f_um_pacote_com_varias_rpitems(): void
    {
        $rpItem1 = $this->rpItemEmitido(30, 'A');
        $rpItem2 = $this->rpItemEmitido(50, 'B');
        $pacote = $this->criarPacote();

        $this->alocar->alocar($rpItem1, $pacote, 30);
        $this->alocar->alocar($rpItem2, $pacote, 50);

        $this->assertSame(2, AlocacaoRequisicaoPacote::where('item_suprimento_id', $pacote->id)->count());
    }

    // ---- G: várias RPs no mesmo Pacote ----

    public function test_g_varias_rps_no_mesmo_pacote(): void
    {
        $rpItemA = $this->rpItemEmitido(60, 'A');
        $rpItemB = $this->rpItemEmitido(100, 'B');
        $rpItemC = $this->rpItemEmitido(25, 'C');
        $pacote = $this->criarPacote('P-TUB');

        $this->alocar->alocar($rpItemA, $pacote, 60);
        $this->alocar->alocar($rpItemB, $pacote, 100);
        $this->alocar->alocar($rpItemC, $pacote, 25);

        $this->assertSame(3, AlocacaoRequisicaoPacote::where('item_suprimento_id', $pacote->id)->count());
    }

    // ---- H: over-allocation bloqueado ----

    public function test_h_over_allocation_bloqueado(): void
    {
        $rpItem = $this->rpItemEmitido(100);
        $p1 = $this->criarPacote('P1');
        $p2 = $this->criarPacote('P2');
        $this->alocar->alocar($rpItem, $p1, 60);
        $this->alocar->alocar($rpItem, $p2, 30);
        // saldo = 10

        $this->expectException(SaldoRequisicaoInsuficienteException::class);
        $this->alocar->alocar($rpItem->fresh(), $this->criarPacote('P3'), 11);
    }

    // ---- I: saldo exato permitido ----

    public function test_i_saldo_exato_permitido(): void
    {
        $rpItem = $this->rpItemEmitido(100);
        $p1 = $this->criarPacote('P1');
        $this->alocar->alocar($rpItem, $p1, 60);

        $alocacao = $this->alocar->alocar($rpItem->fresh(), $this->criarPacote('P2'), 40);

        $this->assertNotNull($alocacao->id);
    }

    // ---- J: alteração de alocação ----

    public function test_j_alteracao_de_alocacao(): void
    {
        $rpItem = $this->rpItemEmitido(100);
        $pacote = $this->criarPacote();
        $alocacao = $this->alocar->alocar($rpItem, $pacote, 30);

        $this->alocar->alterarQuantidade($alocacao, 80);

        $this->assertSame('80.000', (string) $alocacao->fresh()->quantidade_alocada);
    }

    /** Alterar não conta a própria linha duas vezes (mesmo raciocínio de RP). */
    public function test_j2_alterar_nao_conta_a_propria_linha_duas_vezes(): void
    {
        $rpItem = $this->rpItemEmitido(100);
        $pacote = $this->criarPacote();
        $alocacao = $this->alocar->alocar($rpItem, $pacote, 60);

        $this->alocar->alterarQuantidade($alocacao, 30);
        $this->alocar->alterarQuantidade($alocacao->fresh(), 90);

        $this->assertSame('90.000', (string) $alocacao->fresh()->quantidade_alocada);
    }

    // ---- K: remoção de alocação ----

    public function test_k_remocao_de_alocacao(): void
    {
        $rpItem = $this->rpItemEmitido(100);
        $pacote = $this->criarPacote();
        $alocacao = $this->alocar->alocar($rpItem, $pacote, 30);

        $this->alocar->remover($alocacao);

        $this->assertDatabaseMissing('alocacoes_requisicao_pacote', ['id' => $alocacao->id]);
    }

    // ---- L: concorrência (prova estrutural, mesmo padrão já aceito) ----

    /**
     * Mesma limitação de RefreshDatabase já documentada em
     * ListaEngenhariaHardeningTest (O/P) e RequisicaoPlanejamentoTest —
     * prova estrutural: a validação de saldo é SEMPRE lida APÓS o
     * lockForUpdate() no RequisicaoPlanejamentoItem, nunca um valor
     * cacheado de fora. Simulado com uma alocação "concorrente" que só
     * se torna visível DEPOIS que a primeira já tinha "visto" saldo
     * suficiente.
     */
    public function test_l_saldo_sempre_lido_apos_lock_nunca_cacheado(): void
    {
        $rpItem = $this->rpItemEmitido(100);
        $p1 = $this->criarPacote('P1');
        $alocacao1 = $this->alocar->alocar($rpItem, $p1, 60); // saldo "visto" = 100, ok

        // "Concorrente": aloca os 40 restantes antes da primeira tentar aumentar.
        $p2 = $this->criarPacote('P2');
        $this->alocar->alocar($rpItem->fresh(), $p2, 40);

        // alocacao1 tenta subir de 60 pra 61 — saldo real agora é 0.
        $this->expectException(SaldoRequisicaoInsuficienteException::class);
        $this->alocar->alterarQuantidade($alocacao1->fresh(), 61);
    }

    /** Confirma que as operações realmente disputam o lock do MESMO RPItem. */
    public function test_l2_lock_disputado_na_mesma_linha(): void
    {
        $rpItem = $this->rpItemEmitido(100);
        $pacote = $this->criarPacote();

        $sqls = [];
        DB::listen(function ($q) use (&$sqls) {
            if (str_contains(strtolower($q->sql), 'for update') && str_contains($q->sql, 'requisicao_planejamento_itens')) {
                $sqls[] = $q->sql;
            }
        });

        $this->alocar->alocar($rpItem, $pacote, 30);

        $this->assertGreaterThanOrEqual(1, count($sqls));
        foreach ($sqls as $sql) {
            $this->assertStringContainsString('`requisicao_planejamento_itens`.`id` = ?', $sql);
        }
    }

    // ---- M/N: cross-obra e cross-tenant ----

    public function test_m_cross_obra_rp_rejeitado(): void
    {
        $rpItem = $this->rpItemEmitido(60);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $pacoteDeOutraObra = ItemSuprimento::create(['obra_id' => $outraObra->id, 'nome' => 'X', 'codigo' => 'X']);

        $this->expectException(\InvalidArgumentException::class);
        $this->alocar->alocar($rpItem, $pacoteDeOutraObra, 30);
    }

    public function test_n_cross_tenant_alocacao_nao_visivel(): void
    {
        $outroTenant = Tenant::factory()->create();

        $alocacaoOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $user = User::factory()->create(['tenant_id' => $outroTenant->id]);
            $obra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'X', 'descricao' => 'X']);
            $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
            $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM-X']);
            $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'X', 'descricao' => 'X', 'quantidade' => 100]);

            $rp = (new CriarRequisicaoPlanejamento())->execute($obra->id, null, $user->id);
            $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, 50);
            (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $user);
            $pacote = ItemSuprimento::create(['obra_id' => $obra->id, 'nome' => 'X', 'codigo' => 'X']);

            return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 20);
        });

        $this->assertNull(AlocacaoRequisicaoPacote::find($alocacaoOutroTenant->id));
    }

    // ---- Delete de Pacote com alocação bloqueado (seção 38) ----

    public function test_delete_pacote_com_alocacao_bloqueado(): void
    {
        $rpItem = $this->rpItemEmitido(60);
        $pacote = $this->criarPacote();
        $this->alocar->alocar($rpItem, $pacote, 30);

        $this->expectException(AlocacaoRequisicaoInvalidaException::class);
        $pacote->delete();
    }

    public function test_delete_pacote_sem_alocacao_permanece_livre(): void
    {
        $pacote = $this->criarPacote();

        $pacote->delete();

        $this->assertSoftDeleted('itens_suprimento', ['id' => $pacote->id]);
    }

    // ---- AH/AI: zero efeito colateral em prontidão/restrição ----

    public function test_ah_ai_zero_restricao_zero_prontidao_alterada(): void
    {
        $rpItem = $this->rpItemEmitido(60);
        $pacote = $this->criarPacote();
        $this->alocar->alocar($rpItem, $pacote, 30);

        $this->assertSame(0, Restricao::count());
        $this->assertSame(0, PlanoAcao::count());
        $this->assertSame(0, InconsistenciaAvanco::count());
    }
}
