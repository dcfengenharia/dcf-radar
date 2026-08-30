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
use App\Models\DocumentoEngenharia;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\RequisicaoPlanejamentoItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.3.CORREÇÃO — fecha o Achado C da auditoria
 * adversarial da 19.3: um Pacote de Compra (`ItemSuprimento`) referenciado
 * por QUALQUER `AlocacaoRequisicaoPacote` nunca pode desaparecer via
 * soft-delete, mesmo sob concorrência com a criação de uma nova alocação.
 * Cobertura A-P da matriz obrigatória.
 */
class AlocacaoPacoteDeleteRaceTest extends TestCase
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

    private function rpItemEmitido(float $quantidadeRequisitada, string $codigoItem, float $quantidadePrevista = 1000): RequisicaoPlanejamentoItem
    {
        $item = ItemTakeOff::create(['lista_engenharia_id' => $this->lista->id, 'codigo' => $codigoItem, 'descricao' => "Item {$codigoItem}", 'quantidade' => $quantidadePrevista]);
        $rp = $this->criarRp->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidadeRequisitada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $rpItem->fresh();
    }

    private function criarPacote(string $nome = 'Pacote'): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => $nome, 'codigo' => $nome]);
    }

    /**
     * Delete com lock discipline — mesmo idioma exato de
     * `⚡suprimentos.blade.php::excluirItem()`, reproduzido aqui pra testar
     * o mecanismo de domínio isoladamente, sem depender do Livewire.
     */
    private function excluirComLock(ItemSuprimento $pacote): void
    {
        DB::transaction(function () use ($pacote) {
            $travado = ItemSuprimento::whereKey($pacote->id)->lockForUpdate()->firstOrFail();
            $travado->delete();
        });
    }

    // ---- A: Pacote sem alocação pode ser excluído ----

    public function test_a_pacote_sem_alocacao_pode_ser_excluido(): void
    {
        $pacote = $this->criarPacote();

        $this->excluirComLock($pacote);

        $this->assertSoftDeleted('itens_suprimento', ['id' => $pacote->id]);
    }

    // ---- B: Pacote com 1 alocação não pode ----

    public function test_b_pacote_com_uma_alocacao_bloqueia_delete(): void
    {
        $rpItem = $this->rpItemEmitido(60, 'A');
        $pacote = $this->criarPacote();
        $this->alocar->alocar($rpItem, $pacote, 30);

        $this->expectException(AlocacaoRequisicaoInvalidaException::class);
        $this->excluirComLock($pacote->fresh());
    }

    // ---- C: múltiplas alocações bloqueiam ----

    public function test_c_multiplas_alocacoes_bloqueiam(): void
    {
        $rpItem1 = $this->rpItemEmitido(60, 'A');
        $rpItem2 = $this->rpItemEmitido(40, 'B');
        $pacote = $this->criarPacote();
        $this->alocar->alocar($rpItem1, $pacote, 20);
        $this->alocar->alocar($rpItem2, $pacote->fresh(), 15);

        $this->expectException(AlocacaoRequisicaoInvalidaException::class);
        $this->excluirComLock($pacote->fresh());
    }

    // ---- D: remover algumas ainda bloqueia ----

    public function test_d_remover_algumas_alocacoes_ainda_bloqueia(): void
    {
        $rpItem1 = $this->rpItemEmitido(60, 'A');
        $rpItem2 = $this->rpItemEmitido(40, 'B');
        $pacote = $this->criarPacote();
        $alocacao1 = $this->alocar->alocar($rpItem1, $pacote, 20);
        $this->alocar->alocar($rpItem2, $pacote->fresh(), 15);

        $this->alocar->remover($alocacao1);

        $this->expectException(AlocacaoRequisicaoInvalidaException::class);
        $this->excluirComLock($pacote->fresh());
    }

    // ---- E: remover todas libera delete ----

    public function test_e_remover_todas_alocacoes_libera_delete(): void
    {
        $rpItem1 = $this->rpItemEmitido(60, 'A');
        $rpItem2 = $this->rpItemEmitido(40, 'B');
        $pacote = $this->criarPacote();
        $alocacao1 = $this->alocar->alocar($rpItem1, $pacote, 20);
        $alocacao2 = $this->alocar->alocar($rpItem2, $pacote->fresh(), 15);

        $this->alocar->remover($alocacao1);
        $this->alocar->remover($alocacao2);

        $this->excluirComLock($pacote->fresh());

        $this->assertSoftDeleted('itens_suprimento', ['id' => $pacote->id]);
    }

    // ---- F: forceDelete referenciado bloqueia ----

    public function test_f_forcedelete_referenciado_bloqueado(): void
    {
        $rpItem = $this->rpItemEmitido(60, 'A');
        $pacote = $this->criarPacote();
        $this->alocar->alocar($rpItem, $pacote, 20);

        try {
            $pacote->fresh()->forceDelete();
            $this->fail('Esperava AlocacaoRequisicaoInvalidaException.');
        } catch (AlocacaoRequisicaoInvalidaException) {
            // Observer dispara ANTES da FK entrar em jogo.
        }

        $this->assertNotNull(ItemSuprimento::find($pacote->id));
        $this->assertDatabaseHas('itens_suprimento', ['id' => $pacote->id, 'deleted_at' => null]);
    }

    public function test_f2_forcedelete_sem_referencia_funciona(): void
    {
        $pacote = $this->criarPacote();

        $pacote->forceDelete();

        $this->assertDatabaseMissing('itens_suprimento', ['id' => $pacote->id]);
    }

    // ---- G: delete-primeiro depois ADD falha naturalmente ----

    public function test_g_delete_primeiro_depois_add_falha_naturalmente(): void
    {
        $rpItem = $this->rpItemEmitido(60, 'A');
        $pacote = $this->criarPacote();

        $this->excluirComLock($pacote); // pacote já soft-deleted, sem alocação nenhuma

        // alocar() faz ItemSuprimento::whereKey(...)->lockForUpdate()->firstOrFail()
        // -- respeita o scope de SoftDeletes, nunca encontra o pacote.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->alocar->alocar($rpItem, $pacote->fresh(), 10);

        $this->assertSame(0, AlocacaoRequisicaoPacote::where('item_suprimento_id', $pacote->id)->count());
    }

    // ---- H: ADD-primeiro depois delete falha ----

    public function test_h_add_primeiro_depois_delete_falha_com_referencia(): void
    {
        $rpItem = $this->rpItemEmitido(60, 'A');
        $pacote = $this->criarPacote();
        $this->alocar->alocar($rpItem, $pacote, 20); // alocação já existe

        $this->expectException(AlocacaoRequisicaoInvalidaException::class);
        $this->excluirComLock($pacote->fresh());

        $this->assertNull($pacote->fresh()->deleted_at);
    }

    // ---- I: update de alocação × delete permanece íntegro ----

    public function test_i_update_alocacao_e_delete_permanece_integro(): void
    {
        $rpItem = $this->rpItemEmitido(60, 'A');
        $pacote = $this->criarPacote();
        $alocacao = $this->alocar->alocar($rpItem, $pacote, 20);

        $this->alocar->alterarQuantidade($alocacao, 40);

        try {
            $this->excluirComLock($pacote->fresh());
            $this->fail('Esperava AlocacaoRequisicaoInvalidaException.');
        } catch (AlocacaoRequisicaoInvalidaException) {
        }

        $this->assertNull($pacote->fresh()->deleted_at);
        $this->assertSame('40.000', (string) $alocacao->fresh()->quantidade_alocada);
    }

    // ---- J: remove última alocação × delete serializa corretamente ----

    public function test_j_remover_ultima_alocacao_depois_delete_funciona(): void
    {
        $rpItem = $this->rpItemEmitido(60, 'A');
        $pacote = $this->criarPacote();
        $alocacao = $this->alocar->alocar($rpItem, $pacote, 20);

        $this->alocar->remover($alocacao);
        $this->excluirComLock($pacote->fresh());

        $this->assertSoftDeleted('itens_suprimento', ['id' => $pacote->id]);
        $this->assertSame(0, AlocacaoRequisicaoPacote::where('item_suprimento_id', $pacote->id)->count());
    }

    // ---- K: lock comum comprovado nas queries ----

    public function test_k_lock_comum_comprovado_via_for_update_na_mesma_pk(): void
    {
        $rpItem = $this->rpItemEmitido(60, 'A');
        $pacote = $this->criarPacote();

        $sqls = [];
        DB::listen(function ($q) use (&$sqls) {
            if (str_contains(strtolower($q->sql), 'for update') && str_contains($q->sql, 'itens_suprimento')) {
                $sqls[] = $q->sql;
            }
        });

        $alocacao = $this->alocar->alocar($rpItem, $pacote, 20);
        $this->alocar->remover($alocacao);
        $this->excluirComLock($pacote->fresh());

        // 1 do alocar() + 1 do remover() + 1 do delete() = 3 SELECT ... FOR UPDATE
        // sobre itens_suprimento, todos filtrando pela MESMA PK.
        $this->assertGreaterThanOrEqual(3, count($sqls));
        foreach ($sqls as $sql) {
            $this->assertStringContainsString('`itens_suprimento`.`id` = ?', $sql);
        }
    }

    /** Confirma a ORDEM determinística: RequisicaoPlanejamentoItem sempre antes de ItemSuprimento. */
    public function test_l_ordem_de_lock_deterministica_rpitem_antes_de_pacote(): void
    {
        $rpItem = $this->rpItemEmitido(60, 'A');
        $pacote = $this->criarPacote();

        $ordemLocks = [];
        DB::listen(function ($q) use (&$ordemLocks) {
            if (!str_contains(strtolower($q->sql), 'for update')) {
                return;
            }
            if (str_contains($q->sql, 'requisicao_planejamento_itens')) {
                $ordemLocks[] = 'rpitem';
            } elseif (str_contains($q->sql, 'itens_suprimento')) {
                $ordemLocks[] = 'pacote';
            }
        });

        $this->alocar->alocar($rpItem, $pacote, 20);

        $this->assertSame(['rpitem', 'pacote'], $ordemLocks, 'alocar() precisa travar RequisicaoPlanejamentoItem ANTES de ItemSuprimento, sempre.');
    }

    // ---- M: over-allocation permanece ----

    public function test_m_over_allocation_permanece_bloqueado(): void
    {
        $rpItem = $this->rpItemEmitido(100, 'A');
        $p1 = $this->criarPacote('P1');
        $this->alocar->alocar($rpItem, $p1, 60);

        $p2 = $this->criarPacote('P2');
        $this->expectException(SaldoRequisicaoInsuficienteException::class);
        $this->alocar->alocar($rpItem->fresh(), $p2, 41);
    }

    public function test_m2_saldo_exato_ainda_permitido(): void
    {
        $rpItem = $this->rpItemEmitido(100, 'A');
        $p1 = $this->criarPacote('P1');
        $this->alocar->alocar($rpItem, $p1, 60);

        $p2 = $this->criarPacote('P2');
        $alocacao = $this->alocar->alocar($rpItem->fresh(), $p2, 40);

        $this->assertNotNull($alocacao->id);
    }

    // ---- N: cross-obra permanece ----

    public function test_n_cross_obra_permanece_bloqueado(): void
    {
        $rpItem = $this->rpItemEmitido(60, 'A');
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $pacoteDeOutraObra = ItemSuprimento::create(['obra_id' => $outraObra->id, 'nome' => 'X', 'codigo' => 'X']);

        $this->expectException(\InvalidArgumentException::class);
        $this->alocar->alocar($rpItem, $pacoteDeOutraObra, 20);
    }

    // ---- O: cross-tenant permanece ----

    public function test_o_cross_tenant_permanece_isolado(): void
    {
        $outroTenant = Tenant::factory()->create();

        $pacoteOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            return ItemSuprimento::create(['obra_id' => $obra->id, 'nome' => 'X', 'codigo' => 'X']);
        });

        $this->assertNull(ItemSuprimento::find($pacoteOutroTenant->id));
    }

    // ---- P: UI mostra mensagem amigável, nunca 500 ----

    public function test_p_ui_mostra_mensagem_amigavel_ao_excluir_pacote_referenciado(): void
    {
        $rpItem = $this->rpItemEmitido(60, 'A');
        $pacote = $this->criarPacote();
        $this->alocar->alocar($rpItem, $pacote, 20);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('excluirItem', $pacote->id)
            ->assertDispatched('show-toast');

        $this->assertNull($pacote->fresh()->deleted_at);
    }
}
