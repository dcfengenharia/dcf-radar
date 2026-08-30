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
use App\Enums\StatusRequisicaoCompra;
use App\Exceptions\EtapaRequisicaoCompraJaConcluidaException;
use App\Exceptions\RequisicaoCompraImutavelException;
use App\Exceptions\SaldoAlocacaoInsuficienteException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\InconsistenciaAvanco;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\PlanoAcao;
use App\Models\RequisicaoCompra;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.4.CORREÇÃO — cobertura permanente dos achados C1/C2/C3
 * e das novas invariantes (política de saldo, imutabilidade de etapa,
 * cross-obra) confirmados/corrigidos na microauditoria adversarial.
 */
class RequisicaoCompraCorrecaoTest extends TestCase
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

    private function criarPacote(string $nome = 'Pacote X', ?Work $obra = null): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => $nome, 'codigo' => $nome . uniqid()]);
    }

    private function alocacaoPronta(float $quantidadeAlocada, ?ItemSuprimento $pacote = null, ?Work $obra = null, float $quantidadePrevista = 1000): AlocacaoRequisicaoPacote
    {
        $obraAlvo = $obra ?? $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obraAlvo->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => "Item A", 'quantidade' => $quantidadePrevista]);
        $rp = $this->criarRp->execute($obraAlvo->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidadeAlocada);
        $this->emitirRp->execute($rp->fresh(), $this->user);

        return $this->alocar->alocar($rpItem->fresh(), $pacote ?? $this->criarPacote(obra: $obraAlvo), $quantidadeAlocada);
    }

    private function criarFluxo(array $etapas): FluxoSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo RC Correção']);
        foreach ($etapas as $indice => [$nome, $prazo]) {
            $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => $indice + 1, 'nome' => $nome, 'prazo_dias_uteis' => $prazo]);
        }
        return $fluxo->fresh(['etapas']);
    }

    // ==================== A/B/C — delete stale × lock discipline ====================

    /** A. stale Rascunho→Emitida→delete bloqueado */
    public function test_a_delete_de_rc_stale_apos_emissao_concorrente_e_bloqueado(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 30);

        // "usuário A" carrega o objeto ANTES de qualquer emissão.
        $rcCarregado = RequisicaoCompra::findOrFail($rc->id);

        // Emissão real e concorrente (já commitada).
        $emitida = $this->emitirRc->execute(RequisicaoCompra::findOrFail($rc->id), $this->user);

        // "usuário A" tenta excluir usando o id (não mais o objeto stale —
        // a UI corrigida SEMPRE resolve/trava do zero dentro da transação).
        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('excluirRcRascunho', $rcCarregado->id);

        $final = RequisicaoCompra::withTrashed()->find($rc->id);
        $this->assertNull($final->deleted_at);
        $this->assertSame(StatusRequisicaoCompra::Emitida, $final->status);
        $this->assertSame($emitida->numero, $final->numero);
        $this->assertNotNull($final->numero);
        $this->assertCount(1, $final->itens);
        $this->assertCount(1, $final->etapas);
    }

    /** B. delete-first × emissão */
    public function test_b_delete_trava_primeiro_emissao_falha_depois(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 30);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('excluirRcRascunho', $rc->id);

        $this->assertSoftDeleted('requisicoes_compra', ['id' => $rc->id]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->emitirRc->execute(RequisicaoCompra::findOrFail($rc->id), $this->user);
    }

    /** C. emissão-first × delete */
    public function test_c_emissao_trava_primeiro_delete_falha_depois(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 30);

        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('excluirRcRascunho', $rc->id);

        $final = RequisicaoCompra::withTrashed()->find($rc->id);
        $this->assertNull($final->deleted_at);
        $this->assertSame(StatusRequisicaoCompra::Emitida, $final->status);
        $this->assertSame($emitida->numero, $final->numero);
    }

    // ==================== D/E/F/G — política de saldo (drafts) ====================

    /** D. draft não consome saldo oficial */
    public function test_d_draft_nao_consome_saldo_oficial(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $rc = $this->criarRc->execute($pacote, null, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 80);

        $this->assertSame(100.0, $alocacao->fresh()->saldoOficialParaRc());
    }

    /** E/F. dois drafts sobrepostos coexistem; emissão do segundo falha */
    public function test_ef_dois_drafts_sobrepostos_coexistem_emissao_do_segundo_falha(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);

        $rcA = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rcA, $alocacao, 80);

        $rcB = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rcB, $alocacao->fresh(), 80);

        $this->assertSame(1, RequisicaoCompra::where('id', $rcA->id)->whereHas('itens')->count());
        $this->assertSame(1, RequisicaoCompra::where('id', $rcB->id)->whereHas('itens')->count());

        $emitidaA = $this->emitirRc->execute($rcA->fresh(), $this->user);
        $this->assertSame(StatusRequisicaoCompra::Emitida, $emitidaA->status);

        try {
            $this->emitirRc->execute($rcB->fresh(), $this->user);
            $this->fail('Esperava SaldoAlocacaoInsuficienteException.');
        } catch (SaldoAlocacaoInsuficienteException $e) {
            // esperado
        }

        $rcBFinal = RequisicaoCompra::findOrFail($rcB->id);
        $this->assertSame(StatusRequisicaoCompra::Rascunho, $rcBFinal->status);
        $this->assertNull($rcBFinal->numero);
        $this->assertNull($rcBFinal->emitida_em);
        $this->assertNull($rcBFinal->emitida_por);
        $this->assertCount(0, $rcBFinal->etapas);
        $this->assertCount(1, $rcBFinal->itens); // item do rascunho preservado, nada parcial
    }

    /** G. reduzir segundo draft e emitir funciona */
    public function test_g_reduzir_segundo_draft_e_emitir_funciona(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);

        $rcA = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rcA, $alocacao, 80);
        $rcB = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $itemB = $this->atualizarRc->adicionarItem($rcB, $alocacao->fresh(), 80);

        $this->emitirRc->execute($rcA->fresh(), $this->user);

        $this->atualizarRc->alterarQuantidade($itemB, 20);
        $emitidaB = $this->emitirRc->execute($rcB->fresh(), $this->user);

        $this->assertSame(StatusRequisicaoCompra::Emitida, $emitidaB->status);
        $this->assertSame(0.0, $alocacao->fresh()->saldoOficialParaRc());
    }

    // ==================== H/I — Concluída continua consumindo; delete draft libera ====================

    /** H. RC Concluída continua consumindo */
    public function test_h_rc_concluida_continua_consumindo_saldo_oficial(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 60);
        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $this->concluirEtapa->execute($emitida->etapas->first(), $this->user);

        $this->assertSame(StatusRequisicaoCompra::Concluida, $emitida->fresh()->status);
        $this->assertSame(40.0, $alocacao->fresh()->saldoOficialParaRc());
    }

    /** I. delete draft não prende saldo */
    public function test_i_delete_de_draft_nao_prende_saldo(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $rc = $this->criarRc->execute($pacote, null, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 80);

        $this->assertSame(100.0, $alocacao->fresh()->saldoOficialParaRc());

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('excluirRcRascunho', $rc->id);

        $this->assertSame(100.0, $alocacao->fresh()->saldoOficialParaRc());

        // Outra RC 100 deve ser possível — e a alocação também volta a
        // poder ser removida fisicamente (itens órfãos do rascunho
        // excluído foram limpos, não só ignorados pelo saldo).
        $rc2 = $this->criarRc->execute($pacote, null, null, $this->user);
        $item2 = $this->atualizarRc->adicionarItem($rc2, $alocacao->fresh(), 100);
        $this->assertNotNull($item2->id);
    }

    // ==================== J/K/L — etapas ====================

    /**
     * J. etapa não pode ser concluída 2x — fluxo com 2 etapas de
     * propósito: com 1 etapa só, concluí-la já transiciona a RC pra
     * Concluida, e a 2ª tentativa bateria no guard de STATUS da RC
     * (RequisicaoCompraImutavelException), nunca no guard de etapa já
     * concluída — precisamos isolar exatamente o guard sob teste.
     */
    public function test_j_etapa_nao_pode_ser_concluida_duas_vezes(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1], ['Pedido', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 30);
        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $etapa = $emitida->etapas()->orderBy('ordem')->first();

        $primeiraData = $this->concluirEtapa->execute($etapa, $this->user)->data_realizada->toDateString();
        $primeiroAutor = $etapa->fresh()->realizada_por;

        $this->expectException(EtapaRequisicaoCompraJaConcluidaException::class);
        try {
            $this->concluirEtapa->execute($etapa->fresh(), $this->user);
        } finally {
            $depois = $etapa->fresh();
            $this->assertSame($primeiraData, $depois->data_realizada->toDateString());
            $this->assertSame($primeiroAutor, $depois->realizada_por);
        }
    }

    /** K. etapa fora de ordem continua permitida (decisão consciente) */
    public function test_k_concluir_etapa_fora_de_ordem_continua_permitido(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1], ['Pedido', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 30);
        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        [$etapa1, $etapa2] = $emitida->etapas()->orderBy('ordem')->get()->all();

        $this->concluirEtapa->execute($etapa2, $this->user);

        $this->assertNotNull($etapa2->fresh()->data_realizada);
        $this->assertNull($etapa1->fresh()->data_realizada);
        $this->assertSame(StatusRequisicaoCompra::Emitida, $emitida->fresh()->status);
    }

    /** L. RC só conclui quando TODAS concluídas (mesmo fora de ordem) */
    public function test_l_rc_conclui_so_quando_todas_etapas_concluidas_mesmo_fora_de_ordem(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1], ['Pedido', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 30);
        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        [$etapa1, $etapa2] = $emitida->etapas()->orderBy('ordem')->get()->all();

        $this->concluirEtapa->execute($etapa2, $this->user); // fora de ordem
        $this->assertSame(StatusRequisicaoCompra::Emitida, $emitida->fresh()->status);

        $this->concluirEtapa->execute($etapa1, $this->user);
        $this->assertSame(StatusRequisicaoCompra::Concluida, $emitida->fresh()->status);
    }

    // ==================== M-S — cross-obra (6 mutadores + abertura de modal) ====================

    /**
     * Os resolvers obra-scoped (`resolverPacoteDaObraAtual()` etc.)
     * bloqueiam via `findOrFail()` — `ModelNotFoundException` (404),
     * mesmo mecanismo já usado por `resolverGrdDaObraAtual()` no resto
     * do projeto. Chamado DENTRO do próprio método Livewire (sem
     * try/catch lá), então a exceção sobe através de `Livewire::test()
     * ->call()` — captura aqui pra depois inspecionar que NADA mudou.
     */
    private function assertBloqueadoPorObra(callable $acao): void
    {
        try {
            $acao();
            $this->fail('Esperava ModelNotFoundException (bloqueio cross-obra) e nenhuma foi lançada.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // esperado — bloqueio confirmado.
        }
    }

    private function montarPacoteObraB(): array
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::GerentePlanejamento->value);
        $pacoteB = $this->criarPacote('Pacote B', $obraB);
        $alocacaoB = $this->alocacaoPronta(100, $pacoteB, $obraB);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rcB = $this->criarRc->execute($pacoteB, $fluxo, null, $this->user);
        $itemB = $this->atualizarRc->adicionarItem($rcB, $alocacaoB, 30);

        return [$obraB, $pacoteB, $alocacaoB, $rcB, $itemB];
    }

    /** M. criar RC no Pacote B a partir da Obra A é bloqueado */
    public function test_m_criar_rc_no_pacote_b_a_partir_da_obra_a_bloqueado(): void
    {
        [$obraB, $pacoteB] = $this->montarPacoteObraB();
        $totalAntes = RequisicaoCompra::where('item_suprimento_id', $pacoteB->id)->count();

        $this->assertBloqueadoPorObra(fn () => Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->set('rcPacoteId', $pacoteB->id)
            ->call('criarRcRascunho'));

        $this->assertSame($totalAntes, RequisicaoCompra::where('item_suprimento_id', $pacoteB->id)->count());
    }

    /** N. adicionar item usando Alocação B é bloqueado */
    public function test_n_adicionar_item_com_alocacao_b_a_partir_da_obra_a_bloqueado(): void
    {
        [$obraB, $pacoteB, $alocacaoB] = $this->montarPacoteObraB();
        $pacoteA = $this->criarPacote();
        $rcA = $this->criarRc->execute($pacoteA, null, null, $this->user);

        $this->assertBloqueadoPorObra(fn () => Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->set('rcDetalheId', $rcA->id)
            ->set('rcItemAlocacaoIdNovo', $alocacaoB->id)
            ->set('rcItemQuantidadeNovo', '10')
            ->call('adicionarItemRc'));

        $this->assertSame(0, $rcA->itens()->count()); // não ganhou o item da Obra B
    }

    /** O. remover item da RC B é bloqueado */
    public function test_o_remover_item_da_rc_b_a_partir_da_obra_a_bloqueado(): void
    {
        [, , , $rcB, $itemB] = $this->montarPacoteObraB();

        $this->assertBloqueadoPorObra(fn () => Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('removerItemRc', $itemB->id));

        $this->assertDatabaseHas('requisicao_compra_itens', ['id' => $itemB->id]);
    }

    /** P. emitir RC B a partir da Obra A é bloqueado */
    public function test_p_emitir_rc_b_a_partir_da_obra_a_bloqueado(): void
    {
        [, , , $rcB] = $this->montarPacoteObraB();

        $this->assertBloqueadoPorObra(fn () => Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->set('rcDetalheId', $rcB->id)
            ->call('emitirRc'));

        $this->assertSame(StatusRequisicaoCompra::Rascunho, $rcB->fresh()->status);
        $this->assertNull($rcB->fresh()->numero);
    }

    /** Q. concluir etapa da RC B a partir da Obra A é bloqueado */
    public function test_q_concluir_etapa_da_rc_b_a_partir_da_obra_a_bloqueado(): void
    {
        [, , , $rcB] = $this->montarPacoteObraB();
        $emitidaB = $this->emitirRc->execute($rcB->fresh(), $this->user);
        $etapaB = $emitidaB->etapas->first();

        $this->assertBloqueadoPorObra(fn () => Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('concluirEtapaRc', $etapaB->id));

        $this->assertNull($etapaB->fresh()->data_realizada);
    }

    /** R. excluir RC Rascunho B a partir da Obra A é bloqueado */
    public function test_r_excluir_rc_rascunho_b_a_partir_da_obra_a_bloqueado(): void
    {
        [, , , $rcB] = $this->montarPacoteObraB();

        $this->assertBloqueadoPorObra(fn () => Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('excluirRcRascunho', $rcB->id));

        $this->assertNull($rcB->fresh()->deleted_at);
    }

    /** S. usuário TEM permissão nas duas obras — mesmo assim, cross-obra é bloqueado */
    public function test_s_usuario_com_permissao_nas_duas_obras_ainda_assim_bloqueado(): void
    {
        // montarPacoteObraB() já vincula o MESMO $this->user (com editar/
        // criar/excluir em ambas) — a garantia testada aqui é que ter
        // permissão nas duas obras NÃO basta: o bloqueio é de CONTEXTO
        // da obra da página, não de ACL.
        [$obraB, $pacoteB, $alocacaoB, $rcB, $itemB] = $this->montarPacoteObraB();

        $this->assertTrue(Auth::user()->temPermissaoNaObra($this->obra->id, 'suprimentos.mapa', 'editar'));
        $this->assertTrue(Auth::user()->temPermissaoNaObra($obraB->id, 'suprimentos.mapa', 'editar'));

        $this->assertBloqueadoPorObra(fn () => Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->set('rcDetalheId', $rcB->id)
            ->call('emitirRc'));

        $this->assertSame(StatusRequisicaoCompra::Rascunho, $rcB->fresh()->status);
        $this->assertSame(100.0, $alocacaoB->fresh()->saldoOficialParaRc());
        $this->assertDatabaseHas('requisicao_compra_itens', ['id' => $itemB->id]);
    }

    // ==================== T — cross-tenant ====================

    /** T. cross-tenant permanece estruturalmente bloqueado */
    public function test_t_cross_tenant_permanece_bloqueado(): void
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

    // ==================== U — RC → legado intacto ====================

    /** U. concluir etapa de RC nunca altera o mecanismo legado do Pacote */
    public function test_u_concluir_etapa_de_rc_nunca_altera_mecanismo_legado(): void
    {
        $fluxoLegado = $this->criarFluxo([['Cotação Legada', 5]]);
        $pacote = ItemSuprimento::create([
            'obra_id' => $this->obra->id, 'nome' => 'Legado', 'codigo' => 'Legado' . uniqid(),
            'fluxo_suprimento_id' => $fluxoLegado->id,
        ]);
        $etapaLegada = $pacote->etapas()->create([
            'tenant_id' => $this->tenant->id,
            'etapa_fluxo_suprimento_id' => $fluxoLegado->etapas->first()->id,
            'ordem' => 1, 'nome' => 'Cotação Legada', 'prazo_dias_uteis' => 5,
        ]);
        $statusLegadoAntes = $pacote->fresh()->status;

        $fluxoRc = $this->criarFluxo([['Cotação RC', 2]]);
        $alocacao = $this->alocacaoPronta(50, $pacote);
        $rc = $this->criarRc->execute($pacote, $fluxoRc, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 20);
        $emitida = $this->emitirRc->execute($rc->fresh(), $this->user);
        $this->concluirEtapa->execute($emitida->etapas->first(), $this->user);

        $pacoteDepois = $pacote->fresh(['etapas']);
        $this->assertSame($statusLegadoAntes, $pacoteDepois->status);
        $etapaLegadaDepois = $pacoteDepois->etapas->first();
        $this->assertSame('Cotação Legada', $etapaLegadaDepois->nome);
        $this->assertSame(5, $etapaLegadaDepois->prazo_dias_uteis);
        $this->assertCount(0, $etapaLegadaDepois->datas); // scheduler nunca rodou aqui, nenhuma data gravada
    }

    // ==================== V/W/X — reafirmação das guardas de alocação ====================

    /** V. redução de alocação respeita só consumo formal */
    public function test_v_reducao_de_alocacao_respeita_so_consumo_formal(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 60);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        $this->expectException(\App\Exceptions\AlocacaoConsumidaPorRequisicaoCompraException::class);
        $this->alocar->alterarQuantidade($alocacao->fresh(), 59);
    }

    /** W. remover alocação com consumo formal bloqueado */
    public function test_w_remover_alocacao_com_consumo_formal_bloqueado(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);
        $rc = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rc, $alocacao, 10);
        $this->emitirRc->execute($rc->fresh(), $this->user);

        $this->expectException(\App\Exceptions\AlocacaoConsumidaPorRequisicaoCompraException::class);
        $this->alocar->remover($alocacao->fresh());
    }

    /** X. emissão stale revalida saldo (reafirma test_ad do arquivo principal) */
    public function test_x_emissao_stale_revalida_saldo(): void
    {
        $pacote = $this->criarPacote();
        $alocacao = $this->alocacaoPronta(100, $pacote);
        $fluxo = $this->criarFluxo([['Cotação', 1]]);

        $rcA = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rcA, $alocacao, 70);
        $rcB = $this->criarRc->execute($pacote, $fluxo, null, $this->user);
        $this->atualizarRc->adicionarItem($rcB, $alocacao->fresh(), 70);

        $this->emitirRc->execute($rcA->fresh(), $this->user); // consome 70 oficialmente

        $this->expectException(SaldoAlocacaoInsuficienteException::class);
        $this->emitirRc->execute($rcB->fresh(), $this->user); // só resta 30, pediu 70
    }

    // ==================== zero efeito colateral (reafirmação) ====================

    public function test_zero_restricao_prontidao_inconsistencia_apos_toda_a_correcao(): void
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
}
