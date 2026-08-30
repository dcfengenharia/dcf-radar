<?php

namespace Tests\Feature;

use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\Papel;
use App\Exceptions\RequisicaoPlanejamentoImutavelException;
use App\Exceptions\SaldoTakeOffInsuficienteException;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\InconsistenciaAvanco;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\PlanoAcao;
use App\Models\Restricao;
use App\Models\RequisicaoPlanejamento;
use App\Models\RequisicaoPlanejamentoItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.2 — Requisição do Planejamento (RP): cabeçalho +
 * item + conciliação quantitativa contra o Take Off. Cobertura A-AG do
 * pedido (exceto Q-X, que vivem em ConciliacaoTakeOffTest.php).
 */
class RequisicaoPlanejamentoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private DocumentoEngenharia $documento;

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

        $this->documento = DocumentoEngenharia::create([
            'obra_id' => $this->obra->id,
            'codigo' => 'ISO-001',
            'descricao' => 'Isometrico',
        ]);

        $this->criar = new CriarRequisicaoPlanejamento();
        $this->atualizar = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitir = new EmitirRequisicaoPlanejamento();
    }

    private function criarItem(string $codigo, float $quantidade, string $tipo = 'material', ?ListaEngenharia $lista = null): ItemTakeOff
    {
        if (! $lista) {
            $revisao = $this->documento->revisaoVigente() ?? $this->documento->revisoes()->create([
                'revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E1',
            ]);
            $lista = ListaEngenharia::create([
                'documento_engenharia_revisao_id' => $revisao->id,
                'tipo' => $tipo,
                'codigo' => 'LM-'.uniqid(),
            ]);
        }

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id,
            'codigo' => $codigo,
            'descricao' => "Item {$codigo}",
            'quantidade' => $quantidade,
        ]);
    }

    // ---- A/B: criar rascunho, rascunho não consome saldo oficial ----

    public function test_a_criar_rp_rascunho(): void
    {
        $rp = $this->criar->execute($this->obra->id, 'obs', $this->user->id);

        $this->assertTrue($rp->estaRascunho());
        $this->assertNull($rp->numero);
        $this->assertSame($this->user->id, $rp->created_by_id);
    }

    public function test_b_rascunho_nao_consome_saldo_oficial(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $item->id, 60);

        // Outra RP consegue reservar os 100 inteiros — o rascunho da
        // primeira nunca é contado como saldo oficial já consumido.
        $rp2 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $item2 = $this->atualizar->adicionarItem($rp2, $item->id, 100);

        $this->assertNotNull($item2->id);
    }

    // ---- C/D/E: adicionar item, múltiplas listas/disciplinas ----

    public function test_c_adicionar_item(): void
    {
        $item = $this->criarItem('A', 50);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);

        $rpItem = $this->atualizar->adicionarItem($rp, $item->id, 30);

        $this->assertSame($rp->id, $rpItem->requisicao_planejamento_id);
        $this->assertSame('30.000', (string) $rpItem->quantidade_requisitada);
    }

    public function test_d_e_varias_listas_e_disciplinas_na_mesma_rp(): void
    {
        $revisao = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $discEletrica = Disciplina::create(['nome' => 'Elétrica']);

        $listaTub = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-TUB']);
        $listaInst = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'instrumento', 'codigo' => 'LI-INST']);
        $listaEle = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-ELE', 'disciplina_id' => $discEletrica->id]);

        $a = $this->criarItem('A', 10, lista: $listaTub);
        $b = $this->criarItem('B', 10, lista: $listaTub);
        $c = $this->criarItem('C', 10, lista: $listaInst);
        $d = $this->criarItem('D', 10, lista: $listaEle);
        $e = $this->criarItem('E', 10, lista: $listaEle);

        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        foreach ([$a, $b, $c, $d, $e] as $item) {
            $this->atualizar->adicionarItem($rp, $item->id, 5);
        }

        $this->assertSame(5, RequisicaoPlanejamentoItem::where('requisicao_planejamento_id', $rp->id)->count());
    }

    // ---- F/G/H: quantidade parcial, múltiplas RPs mesmo item, saldo exato ----

    public function test_f_quantidade_parcial(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizar->adicionarItem($rp, $item->id, 40);

        $this->assertSame('40.000', (string) $rpItem->quantidade_requisitada);
    }

    public function test_g_multiplas_rps_mesmo_item_ate_o_limite(): void
    {
        $item = $this->criarItem('A', 100);

        $rp1 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rp2 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rp3 = $this->criar->execute($this->obra->id, null, $this->user->id);

        $this->atualizar->adicionarItem($rp1, $item->id, 30);
        $this->atualizar->adicionarItem($rp2, $item->id, 50);

        $this->emitir->execute($rp1->fresh(), $this->user);
        $this->emitir->execute($rp2->fresh(), $this->user);

        // saldo = 100 - 30 - 50 = 20
        $item21 = $this->atualizar->adicionarItem($rp3, $item->id, 21 - 1); // sanity: 20 cabe
        $this->assertSame('20.000', (string) $item21->quantidade_requisitada);
    }

    public function test_h_saldo_exato_e_permitido(): void
    {
        $item = $this->criarItem('A', 100);
        $rp1 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp1, $item->id, 80);
        $this->emitir->execute($rp1->fresh(), $this->user);

        $rp2 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizar->adicionarItem($rp2, $item->id, 20); // saldo exato = 20

        $this->assertNotNull($rpItem->id);
    }

    // ---- I: over-requisition bloqueado ----

    public function test_i_over_requisition_bloqueado(): void
    {
        $item = $this->criarItem('A', 100);
        $rp1 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp1, $item->id, 80);
        $this->emitir->execute($rp1->fresh(), $this->user);

        $rp2 = $this->criar->execute($this->obra->id, null, $this->user->id);

        $this->expectException(SaldoTakeOffInsuficienteException::class);
        $this->atualizar->adicionarItem($rp2, $item->id, 21); // saldo é 20, pede 21
    }

    // ---- J: update de rascunho não conta a própria linha duas vezes ----

    public function test_j_alterar_quantidade_nao_conta_a_propria_linha_duas_vezes(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizar->adicionarItem($rp, $item->id, 60);

        // Reduzir e depois AUMENTAR de volta pra 90 (< 100) precisa
        // funcionar — se a própria linha fosse contada como "já
        // requisitada" ela bloquearia incorretamente.
        $this->atualizar->alterarQuantidade($rpItem, 30);
        $this->atualizar->alterarQuantidade($rpItem->fresh(), 90);

        $this->assertSame('90.000', (string) $rpItem->fresh()->quantidade_requisitada);
    }

    // ---- K: remover item rascunho ----

    public function test_k_remover_item_rascunho(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizar->adicionarItem($rp, $item->id, 50);

        $this->atualizar->removerItem($rpItem);

        $this->assertDatabaseMissing('requisicao_planejamento_itens', ['id' => $rpItem->id]);
    }

    // ---- L/M: emitir RP, emissão congela ----

    public function test_l_m_emitir_rp_congela_snapshots(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizar->adicionarItem($rp, $item->id, 40);

        $emitida = $this->emitir->execute($rp->fresh(), $this->user);

        $this->assertTrue($emitida->estaEmitida());
        $this->assertNotNull($emitida->numero);
        $this->assertSame($this->user->id, $emitida->emitida_por);

        $rpItem->refresh();
        $this->assertSame($item->codigo, $rpItem->codigo_item_snapshot);
        $this->assertSame($item->descricao, $rpItem->descricao_snapshot);
        $this->assertSame($item->lista->codigo, $rpItem->lista_codigo_snapshot);
        $this->assertSame($item->lista->tipo->label(), $rpItem->tipo_lista_snapshot);
        $this->assertSame($this->documento->codigo, $rpItem->documento_codigo_snapshot);
    }

    public function test_emitir_sem_itens_falha(): void
    {
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);

        $this->expectException(\App\Exceptions\RequisicaoPlanejamentoEmissaoInvalidaException::class);
        $this->emitir->execute($rp, $this->user);
    }

    // ---- N: editar emitida bloqueado ----

    public function test_n_editar_emitida_bloqueado(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizar->adicionarItem($rp, $item->id, 40);
        $this->emitir->execute($rp->fresh(), $this->user);

        $this->expectException(RequisicaoPlanejamentoImutavelException::class);
        $this->atualizar->alterarQuantidade($rpItem->fresh(), 50);
    }

    public function test_n2_adicionar_item_em_rp_emitida_bloqueado(): void
    {
        $item = $this->criarItem('A', 100);
        $item2 = $this->criarItem('B', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $item->id, 40);
        $this->emitir->execute($rp->fresh(), $this->user);

        $this->expectException(RequisicaoPlanejamentoImutavelException::class);
        $this->atualizar->adicionarItem($rp->fresh(), $item2->id, 10);
    }

    public function test_n3_remover_item_de_rp_emitida_bloqueado(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rpItem = $this->atualizar->adicionarItem($rp, $item->id, 40);
        $this->emitir->execute($rp->fresh(), $this->user);

        $this->expectException(RequisicaoPlanejamentoImutavelException::class);
        $this->atualizar->removerItem($rpItem->fresh());
    }

    public function test_n4_excluir_cabecalho_emitido_bloqueado(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $item->id, 40);
        $emitida = $this->emitir->execute($rp->fresh(), $this->user);

        $this->expectException(RequisicaoPlanejamentoImutavelException::class);
        $emitida->delete();
    }

    public function test_rascunho_pode_ser_descartado(): void
    {
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);

        $rp->delete();

        $this->assertSoftDeleted('requisicoes_planejamento', ['id' => $rp->id]);
    }

    // ---- O: stale saldo na emissão bloqueado ----

    public function test_o_stale_saldo_na_emissao_bloqueado(): void
    {
        $item = $this->criarItem('A', 70);

        $rp1 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp1, $item->id, 70);

        // Outra RP emite 50 ENQUANTO rp1 ainda está em rascunho — mas
        // como rascunho não bloqueia visualmente, isso só é possível
        // porque rp1 nunca reservou o saldo (rascunho não consome).
        // Simula reduzindo o Take Off diretamente (equivalente factual
        // a "saldo mudou enquanto o rascunho estava aberto").
        $rp2 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp2, $item->id, 50);
        $this->emitir->execute($rp2->fresh(), $this->user);

        // Agora rp1 (70) não tem mais saldo (só sobrou 20) — emitir precisa falhar.
        $this->expectException(SaldoTakeOffInsuficienteException::class);
        $this->emitir->execute($rp1->fresh(), $this->user);
    }

    // ---- P: emissão transacional all-or-nothing ----

    public function test_p_emissao_transacional_all_or_nothing(): void
    {
        $itemOk = $this->criarItem('OK', 100);
        $itemFalha = $this->criarItem('FALHA', 30);

        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $itemOk->id, 50);
        $this->atualizar->adicionarItem($rp, $itemFalha->id, 30);

        // Consome TODO o saldo de "FALHA" por outra RP emitida, entre a
        // montagem do rascunho e a emissão de $rp.
        $rpConcorrente = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rpConcorrente, $itemFalha->id, 30);
        $this->emitir->execute($rpConcorrente->fresh(), $this->user);

        try {
            $this->emitir->execute($rp->fresh(), $this->user);
            $this->fail('Esperava SaldoTakeOffInsuficienteException.');
        } catch (SaldoTakeOffInsuficienteException) {
            // A RP inteira precisa continuar Rascunho — nenhum snapshot
            // gravado, nenhum número atribuído, mesmo o item "OK" tendo
            // saldo suficiente sozinho.
            $rp->refresh();
            $this->assertTrue($rp->estaRascunho());
            $this->assertNull($rp->numero);
            $itemOkRp = RequisicaoPlanejamentoItem::where('requisicao_planejamento_id', $rp->id)
                ->where('item_take_off_id', $itemOk->id)->first();
            $this->assertNull($itemOkRp->codigo_item_snapshot);
        }
    }

    /**
     * Prova de concorrência — seção 10 do pedido. Uma race genuína com 2
     * conexões/transações reais de banco não é viável nesta suíte: o
     * `RefreshDatabase` embrulha CADA teste numa transação externa não
     * commitada na conexão padrão — uma SEGUNDA conexão real jamais
     * enxergaria nenhum dado criado dentro dela (isolamento de transação
     * MySQL/InnoDB), então "lockForUpdate() bloqueia uma 2ª conexão" não
     * é demonstrável sem desabilitar RefreshDatabase (arriscaria deixar
     * dado real no banco de dev). Prova estrutural equivalente, mesma
     * técnica já usada e aceita em ListaEngenhariaHardeningTest (testes
     * O/P): `validarSaldo()` SEMPRE roda DEPOIS de
     * `ItemTakeOff::lockForUpdate()`, dentro da MESMA transação — nunca
     * recebe um saldo pré-calculado "de fora". Simulado aqui com uma
     * requisição concorrente que só se torna visível (é emitida) DEPOIS
     * que a primeira já tinha "visto" saldo suficiente — se a validação
     * fosse baseada num saldo cacheado/lido antes do lock (naive
     * "validate() antes de save"), a segunda passaria incorretamente.
     */
    public function test_concorrencia_saldo_sempre_lido_apos_lock_nunca_cacheado(): void
    {
        $item = $this->criarItem('A', 100);

        $rp1 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $rp1Item = $this->atualizar->adicionarItem($rp1, $item->id, 60); // saldo "visto" = 100, ok

        // "Concorrente": rp2 reserva os 40 restantes e EMITE antes de rp1 emitir.
        $rp2 = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp2, $item->id, 40);
        $this->emitir->execute($rp2->fresh(), $this->user);

        // rp1 tenta AUMENTAR sua própria reserva de 60 pra 61 — saldo real
        // agora é 0 (100-40 já emitido, e os 60 de rp1 ainda são rascunho,
        // não contam). Se a validação usasse o saldo "visto" no momento em
        // que rp1 foi montada (100), isso passaria errado.
        $this->expectException(SaldoTakeOffInsuficienteException::class);
        $this->atualizar->alterarQuantidade($rp1Item->fresh(), 61);
    }

    // ---- Y/Z: cross-obra e cross-tenant ----

    public function test_y_cross_obra_item_rejeitado(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outroDocumento = DocumentoEngenharia::create(['obra_id' => $outraObra->id, 'codigo' => 'X', 'descricao' => 'X']);
        $outraRevisao = $outroDocumento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $outraLista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $outraRevisao->id, 'tipo' => 'material', 'codigo' => 'LM-X']);
        $itemDeOutraObra = $this->criarItem('X', 10, lista: $outraLista);

        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->atualizar->adicionarItem($rp, $itemDeOutraObra->id, 5);
    }

    public function test_z_cross_tenant_rp_nao_e_visivel(): void
    {
        $outroTenant = Tenant::factory()->create();

        $rpOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obra = Work::factory()->create(['tenant_id' => $outroTenant->id]);

            return (new CriarRequisicaoPlanejamento())->execute($obra->id, null, null);
        });

        $this->assertNull(RequisicaoPlanejamento::find($rpOutroTenant->id));
    }

    // ---- AA/AB: autorização ----

    public function test_aa_usuario_sem_permissao_nao_pode_editar(): void
    {
        $outroUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $outroUser, Papel::Encarregado->value);

        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);

        $this->assertFalse($outroUser->temPermissaoNaObra($this->obra->id, 'planejamento.requisicoes', 'editar'));
        $this->assertFalse((bool) \Illuminate\Support\Facades\Gate::forUser($outroUser)->check('update', $rp));
    }

    public function test_ab_planejamento_autorizado_pode_editar(): void
    {
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);

        $this->assertTrue($this->user->temPermissaoNaObra($this->obra->id, 'planejamento.requisicoes', 'editar'));
        $this->assertTrue((bool) \Illuminate\Support\Facades\Gate::forUser($this->user)->check('update', $rp));
    }

    public function test_ab2_ver_e_concedido_a_qualquer_perfil_vinculado(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);

        $this->assertTrue($encarregado->temPermissaoNaObra($this->obra->id, 'planejamento.requisicoes', 'ver'));
    }

    // ---- AE/AF/AG: zero efeito colateral ----

    public function test_ae_af_ag_zero_restricao_zero_prontidao_zero_itemsuprimento(): void
    {
        $item = $this->criarItem('A', 100);
        $rp = $this->criar->execute($this->obra->id, null, $this->user->id);
        $this->atualizar->adicionarItem($rp, $item->id, 40);
        $this->emitir->execute($rp->fresh(), $this->user);

        $this->assertSame(0, Restricao::count());
        $this->assertSame(0, PlanoAcao::count());
        $this->assertSame(0, InconsistenciaAvanco::count());
        $this->assertSame(0, \App\Models\ItemSuprimento::count());
    }

    // ---- Performance: listar RPs não escala com N ----

    public function test_ac_performance_conciliacao_n100(): void
    {
        $itens = collect();
        for ($i = 0; $i < 100; $i++) {
            $itens->push($this->criarItem("ITEM-{$i}", 10));
        }

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $conciliacao = \App\Support\Suprimentos\ConciliacaoTakeOff::porItens($itens);

        $this->assertSame(100, $conciliacao->count());
        $this->assertLessThan(5, $queries, 'Conciliação por item não pode escalar com N — esperava < 5 queries pra 100 itens.');
    }
}
