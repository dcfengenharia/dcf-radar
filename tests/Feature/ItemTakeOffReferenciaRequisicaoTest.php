<?php

namespace Tests\Feature;

use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\Papel;
use App\Exceptions\ItemTakeOffReferenciadoException;
use App\Exceptions\ListaEngenhariaImutavelException;
use App\Models\DocumentoEngenharia;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\RequisicaoPlanejamentoItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Suprimentos\ConciliacaoTakeOff;
use App\Support\TakeOff\TakeOffConsolidado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.2.CORREÇÃO — fecha o Achado C da auditoria
 * adversarial da 19.2: um `ItemTakeOff` referenciado por QUALQUER
 * `RequisicaoPlanejamentoItem` (Rascunho ou Emitida) nunca pode
 * desaparecer via soft-delete. Cobertura A-N da matriz obrigatória.
 */
class ItemTakeOffReferenciaRequisicaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private DocumentoEngenharia $documento;
    private ListaEngenharia $lista;

    private CriarRequisicaoPlanejamento $criar;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizar;
    private EmitirRequisicaoPlanejamento $emitir;

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

        $this->criar = new CriarRequisicaoPlanejamento();
        $this->atualizar = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitir = new EmitirRequisicaoPlanejamento();
    }

    private function criarItem(string $codigo = 'A', float $quantidade = 100): ItemTakeOff
    {
        return ItemTakeOff::create(['lista_engenharia_id' => $this->lista->id, 'codigo' => $codigo, 'descricao' => "Item {$codigo}", 'quantidade' => $quantidade]);
    }

    /**
     * Delete com lock discipline — mesmo idioma exato de
     * `⚡take-off.blade.php::excluirItem()`, reproduzido aqui pra testar
     * o mecanismo de domínio isoladamente, sem depender do Livewire.
     */
    private function excluirComLock(ItemTakeOff $item): void
    {
        DB::transaction(function () use ($item) {
            $travado = ItemTakeOff::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $travado->delete();
        });
    }

    // ---- A: item sem RP, lista vigente -> delete permitido ----

    public function test_a_item_sem_rp_lista_vigente_delete_permitido(): void
    {
        $item = $this->criarItem();

        $this->excluirComLock($item);

        $this->assertSoftDeleted('itens_take_off', ['id' => $item->id]);
    }

    // ---- B: RP rascunho bloqueia delete ----

    public function test_b_rp_rascunho_bloqueia_delete(): void
    {
        $item = $this->criarItem();
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $item->id, 30);

        $this->expectException(ItemTakeOffReferenciadoException::class);
        $this->excluirComLock($item);
    }

    // ---- C: remover da RP rascunho libera delete ----

    public function test_c_remover_da_rp_rascunho_libera_delete(): void
    {
        $item = $this->criarItem();
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizar->adicionarItem($rp, $item->id, 30);

        $this->atualizar->removerItem($rpItem);
        $this->excluirComLock($item->fresh());

        $this->assertSoftDeleted('itens_take_off', ['id' => $item->id]);
    }

    // ---- D: RP emitida bloqueia delete permanentemente ----

    public function test_d_rp_emitida_bloqueia_delete(): void
    {
        $item = $this->criarItem();
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $item->id, 30);
        $this->emitir->execute($rp->fresh(), $this->user);

        $this->expectException(ItemTakeOffReferenciadoException::class);
        $this->excluirComLock($item->fresh());
    }

    // ---- E: múltiplas RPs (rascunho) bloqueiam mesmo removendo de uma só ----

    public function test_e_multiplas_rps_bloqueiam_mesmo_removendo_de_uma(): void
    {
        $item = $this->criarItem();
        $rp1 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rp2 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rpItem1 = $this->atualizar->adicionarItem($rp1, $item->id, 10);
        $this->atualizar->adicionarItem($rp2, $item->id, 10);

        $this->atualizar->removerItem($rpItem1);

        $this->expectException(ItemTakeOffReferenciadoException::class);
        $this->excluirComLock($item->fresh());
    }

    // ---- F: rascunho + emitida juntos bloqueiam ----

    public function test_f_rascunho_e_emitida_juntos_bloqueiam(): void
    {
        $item = $this->criarItem('A', 100);
        $rpEmitida = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rpEmitida, $item->id, 30);
        $this->emitir->execute($rpEmitida->fresh(), $this->user);

        $rpRascunho = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rpRascunho, $item->id, 20);

        $this->expectException(ItemTakeOffReferenciadoException::class);
        $this->excluirComLock($item->fresh());
    }

    // ---- G: revisão superada continua bloqueada, mesmo sem nenhuma RP ----

    public function test_g_revisao_superada_continua_bloqueada_sem_rp(): void
    {
        $item = $this->criarItem();
        $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);

        // A nova regra (verificação de RP) NUNCA enfraquece a proteção
        // já existente de vigência — a exceção continua sendo a de
        // ListaEngenhariaImutavelException (checada primeiro no Observer).
        $this->expectException(ListaEngenhariaImutavelException::class);
        $this->excluirComLock($item->fresh());
    }

    // ---- H: forceDelete referenciado nunca remove ----

    public function test_h_forcedelete_referenciado_bloqueado(): void
    {
        $item = $this->criarItem();
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $item->id, 30);

        try {
            $item->fresh()->forceDelete();
            $this->fail('Esperava ItemTakeOffReferenciadoException.');
        } catch (ItemTakeOffReferenciadoException) {
            // Mecanismo real: o Observer dispara ANTES da FK entrar em
            // jogo (deleting() roda antes de performDeleteOnModel()) —
            // é a checagem de domínio que bloqueia aqui, não a FK.
        }

        $this->assertNotNull(ItemTakeOff::find($item->id));
        $this->assertDatabaseHas('itens_take_off', ['id' => $item->id, 'deleted_at' => null]);
    }

    /** Controle do H: SEM nenhuma RP, forceDelete físico funciona normalmente. */
    public function test_h2_forcedelete_sem_referencia_funciona(): void
    {
        $item = $this->criarItem();

        $item->forceDelete();

        $this->assertDatabaseMissing('itens_take_off', ['id' => $item->id]);
    }

    // ---- I: RP histórica continua navegável após tentativa bloqueada ----

    public function test_i_rp_historica_continua_navegavel_apos_tentativa_bloqueada(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizar->adicionarItem($rp, $item->id, 60);
        $emitida = $this->emitir->execute($rp->fresh(), $this->user);

        try { $this->excluirComLock($item->fresh()); } catch (ItemTakeOffReferenciadoException) {}

        $emitida->refresh();
        $rpItem->refresh();
        $this->assertTrue($emitida->estaEmitida());
        $this->assertSame($item->id, $rpItem->item_take_off_id);
        $this->assertNotNull($rpItem->itemTakeOff);
        $this->assertSame($this->lista->id, $rpItem->itemTakeOff->lista->id);
        $this->assertSame($this->documento->id, $rpItem->itemTakeOff->lista->revisao->documento->id);
        $this->assertSame('Item A', $rpItem->descricao_snapshot);
    }

    // ---- J: ConciliacaoTakeOff permanece correta após tentativa bloqueada ----

    public function test_j_conciliacao_permanece_correta_apos_tentativa_bloqueada(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $item->id, 60);
        $this->emitir->execute($rp->fresh(), $this->user);

        try { $this->excluirComLock($item->fresh()); } catch (ItemTakeOffReferenciadoException) {}

        $c = ConciliacaoTakeOff::porItem($item->fresh());
        $this->assertSame(100.0, $c['quantidade_prevista']);
        $this->assertSame(60.0, $c['quantidade_requisitada']);
        $this->assertSame(40.0, $c['saldo']);
        $this->assertSame(ConciliacaoTakeOff::STATUS_PARCIAL, $c['status']);
    }

    // ---- K: TakeOffConsolidado permanece correto após tentativa bloqueada ----

    public function test_k_takeoff_consolidado_permanece_correto_apos_tentativa_bloqueada(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $item->id, 60);
        $this->emitir->execute($rp->fresh(), $this->user);

        try { $this->excluirComLock($item->fresh()); } catch (ItemTakeOffReferenciadoException) {}

        $itensVigentes = TakeOffConsolidado::itensVigentes($this->obra->id);
        $this->assertTrue($itensVigentes->contains('id', $item->id));
    }

    // ---- L/M: cross-obra e cross-tenant não mudaram ----

    public function test_l_cross_obra_nao_mudou(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outroDocumento = DocumentoEngenharia::create(['obra_id' => $outraObra->id, 'codigo' => 'X', 'descricao' => 'X']);
        $outraRevisao = $outroDocumento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $outraLista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $outraRevisao->id, 'tipo' => 'material', 'codigo' => 'LM-X']);
        $itemDeOutraObra = ItemTakeOff::create(['lista_engenharia_id' => $outraLista->id, 'codigo' => 'X', 'descricao' => 'X', 'quantidade' => 10]);

        // Item de outra obra, sem RP nenhuma, continua excluível normalmente
        // (a nova regra não introduziu nenhum vazamento cross-obra).
        $this->excluirComLock($itemDeOutraObra);
        $this->assertSoftDeleted('itens_take_off', ['id' => $itemDeOutraObra->id]);
    }

    public function test_m_cross_tenant_nao_mudou(): void
    {
        $outroTenant = Tenant::factory()->create();

        $itemOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'X', 'descricao' => 'X']);
            $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
            $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM-X']);

            return ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'X', 'descricao' => 'X', 'quantidade' => 10]);
        });

        // Não alcançável a partir do tenant atual (global scope) — nunca
        // vaza a checagem de RP de outro tenant contra um item deste.
        $this->assertNull(ItemTakeOff::find($itemOutroTenant->id));
    }

    // ---- N: prova estrutural de concorrência/lock ----

    /**
     * Prova de que a disciplina de lock impede o resultado proibido
     * ("RPItem apontando para ItemTakeOff soft-deleted") nos dois
     * sentidos de ordem possível. Concorrência real com 2 conexões não é
     * viável sob RefreshDatabase (mesma limitação já documentada em
     * ListaEngenhariaHardeningTest, testes O/P) — a prova aqui é
     * estrutural: como as DUAS operações (`adicionarItem()` e o delete
     * com lock) travam a MESMA linha `ItemTakeOff` via `lockForUpdate()`
     * dentro de transação, o caso concorrente se reduz ao caso
     * sequencial — se qualquer ORDEM sequencial das duas operações
     * produz um resultado seguro (nunca o proibido), a versão concorrente
     * também produz, porque o banco serializa o acesso à linha.
     */
    public function test_n1_delete_primeiro_depois_adicionar_item_falha_naturalmente(): void
    {
        $item = $this->criarItem();
        $this->excluirComLock($item); // item já soft-deleted

        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);

        // adicionarItem() faz ItemTakeOff::whereKey(...)->lockForUpdate()->firstOrFail()
        // -- respeita o scope de SoftDeletes, então nunca encontra o item.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->atualizar->adicionarItem($rp, $item->id, 10);

        $this->assertSame(0, RequisicaoPlanejamentoItem::where('item_take_off_id', $item->id)->count());
    }

    public function test_n2_adicionar_item_primeiro_depois_delete_falha_com_referencia(): void
    {
        $item = $this->criarItem();
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $item->id, 10); // RPItem já existe

        $this->expectException(ItemTakeOffReferenciadoException::class);
        $this->excluirComLock($item->fresh());

        $this->assertNull($item->fresh()->deleted_at);
    }

    /**
     * Confirma que as DUAS operações realmente disputam o MESMO lock
     * (evidência de que a proteção não é coincidência de timing): tanto
     * `adicionarItem()` quanto o delete com lock disparam uma query
     * `... FOR UPDATE` sobre `itens_take_off` filtrada pelo MESMO id.
     */
    public function test_n3_ambas_operacoes_disputam_lock_na_mesma_linha(): void
    {
        $item = $this->criarItem();
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);

        $sqlsComLock = [];
        DB::listen(function ($q) use (&$sqlsComLock, $item) {
            if (str_contains(strtolower($q->sql), 'for update') && str_contains($q->sql, 'itens_take_off')) {
                $sqlsComLock[] = $q->sql;
            }
        });

        $this->atualizar->adicionarItem($rp, $item->id, 10);
        try { $this->excluirComLock($item->fresh()); } catch (ItemTakeOffReferenciadoException) {}

        $this->assertCount(2, $sqlsComLock, 'Esperava exatamente 2 SELECTs FOR UPDATE em itens_take_off (1 do adicionarItem, 1 do delete) -- mesma linha disputada nas duas operações.');
        foreach ($sqlsComLock as $sql) {
            $this->assertStringContainsString('`itens_take_off`.`id` = ?', $sql);
        }
    }
}
