<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\LiberarReservaEstoque;
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
use App\Enums\PilarLean;
use App\Enums\StatusItemSuprimento;
use App\Enums\StatusRestricao;
use App\Enums\TipoLocalEstoque;
use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Services\SuprimentoScheduler;
use App\Support\SincronizarRestricaoCadeiaSuprimento;
use App\Support\SincronizarRestricaoSuprimento;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correção Segura do Falso Positivo (Revisão Arquitetural 2, Seção
 * 13-18) — cobertura Q-V do pedido de implementação: risco COMERCIAL do
 * Pacote (mecanismos legado E da cadeia formal, nenhum dos dois nunca
 * lê Estoque/Reserva) nunca mais vira bloqueio automático da Atividade
 * quando a necessidade específica de Material já está fisicamente
 * coberta — sem NUNCA apagar o histórico/status comercial em si.
 */
class CorrecaoFalsoPositivoSuprimentosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-15'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UND', 'nome' => 'Unidade']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers ----

    private function criarAtividade(?Carbon $inicioPlanejado = null): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => $inicioPlanejado ?? Carbon::today()->addDays(3),
        ]);
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'codigo' => 'EX-003',
            'descricao' => 'exemplo 02',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
    }

    private function criarItemTakeOff(Material $material, float $quantidade): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'Documento']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'EX-003',
            'unidade_medida_id' => $this->unidade->id, 'quantidade' => $quantidade, 'material_id' => $material->id,
        ]);
    }

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Área 08', 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
    }

    /** Pacote com Alocação real pro MESMO ItemTakeOff (correspondência inequívoca). */
    private function pacoteComAlocacao(ItemTakeOff $ito, float $quantidade, string $nome = 'Solicitações em Reunião'): ItemSuprimento
    {
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => $nome]);
        (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);

        return $pacote->fresh();
    }

    /**
     * Reproduz exatamente `⚡suprimentos.blade.php::salvarItem()` (fluxo
     * legado): cria etapas do Fluxo, congela Previsto, sincroniza a
     * Restrição — mesma sequência real disparada ao vincular a Atividade
     * a um Pacote.
     */
    private function tornarLegadoEmRisco(ItemSuprimento $pacote, Atividade $atividade): ItemSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Legado']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Solicitação', 'prazo_dias_uteis' => 30]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 2, 'nome' => 'Pedido', 'prazo_dias_uteis' => 1]);

        $pacote->update(['fluxo_suprimento_id' => $fluxo->id]);
        $pacote->atividades()->sync([$atividade->id]);

        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($pacote->fresh());
        $scheduler->congelarPrevisto($pacote->fresh(['atividades']));

        return $pacote->fresh(['atividades']);
    }

    /** Cadeia comercial completa até Entrada física em estoque, independente do Pacote de risco. */
    private function entradaFisica(Material $material, LocalEstoque $local, float $quantidade): void
    {
        $ito = $this->criarItemTakeOff($material, $quantidade * 10);
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacoteCompra = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Compra ' . uniqid()]);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacoteCompra, $quantidade);

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

    private function buscarRestricaoLegado(ItemSuprimento $pacote, Atividade $atividade): ?Restricao
    {
        return Restricao::where('atividade_id', $atividade->id)->where('origem_suprimento_item_id', $pacote->id)->first();
    }

    // =========================================================================
    // Q — cenário EX-003 exato: necessário 25, físico 150, reservado 25 = COBERTA
    // =========================================================================

    public function test_q_necessidade_coberta_por_reserva_nunca_gera_restricao_de_nao_chegar(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = (new AtualizarNecessidadeMaterialAtividade())->criarTakeOff($atividade, $ito, 25, $this->user);
        $local = $this->criarLocal();

        // Físico 150 + reservado ESPECÍFICO 25 — exatamente o cenário
        // manual já validado pelo usuário (COBERTA).
        $this->entradaFisica($material, $local, 150);
        $pacote = $this->pacoteComAlocacao($ito, 25);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 25, usuario: $this->user, necessidade: $necessidade);

        // Só DEPOIS a Atividade é vinculada ao Pacote (mesma ordem real
        // do relato: "ao vincular a atividade a um Pacote... surgiu uma
        // restrição bloqueante") — o Pacote já está comercialmente em
        // risco (fluxo legado sem nenhuma etapa realizada).
        $pacote = $this->tornarLegadoEmRisco($pacote, $atividade);
        SincronizarRestricaoSuprimento::sincronizarItem($pacote, $this->user->id);

        $restricao = $this->buscarRestricaoLegado($pacote, $atividade);
        $this->assertTrue(
            $restricao === null || $restricao->status !== StatusRestricao::Aberta,
            'Uma necessidade já Coberta por Reserva específica nunca deve permanecer bloqueando a Atividade.'
        );
    }

    // =========================================================================
    // R — necessário 25, físico 150, reservado 0, livre 150 = DisponivelParaReserva
    // =========================================================================

    public function test_r_necessidade_disponivel_para_reserva_tambem_nunca_bloqueia(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        (new AtualizarNecessidadeMaterialAtividade())->criarTakeOff($atividade, $ito, 25, $this->user);
        $local = $this->criarLocal();

        // Físico 150, SEM nenhuma reserva — material já fisicamente na
        // obra, mas ainda não comprometido especificamente.
        $this->entradaFisica($material, $local, 150);
        $pacote = $this->pacoteComAlocacao($ito, 25);
        $pacote = $this->tornarLegadoEmRisco($pacote, $atividade);

        SincronizarRestricaoSuprimento::sincronizarItem($pacote, $this->user->id);

        $restricao = $this->buscarRestricaoLegado($pacote, $atividade);
        $this->assertTrue(
            $restricao === null || $restricao->status !== StatusRestricao::Aberta,
            'DisponivelParaReserva (material já fisicamente na obra) nunca deve gerar "não vai chegar a tempo".'
        );
    }

    // =========================================================================
    // S — o Pacote continua comercialmente atrasado/em risco nos cenários Q/R
    // =========================================================================

    public function test_s_pacote_continua_em_risco_comercial_mesmo_sem_bloquear_a_atividade(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = (new AtualizarNecessidadeMaterialAtividade())->criarTakeOff($atividade, $ito, 25, $this->user);
        $local = $this->criarLocal();
        $this->entradaFisica($material, $local, 150);
        $pacote = $this->pacoteComAlocacao($ito, 25);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 25, usuario: $this->user, necessidade: $necessidade);
        $pacote = $this->tornarLegadoEmRisco($pacote, $atividade);

        SincronizarRestricaoSuprimento::sincronizarItem($pacote, $this->user->id);

        $this->assertContains(
            $pacote->fresh()->status,
            [StatusItemSuprimento::EmRisco, StatusItemSuprimento::Atrasado],
            'O status COMERCIAL do Pacote nunca é alterado por esta correção — só a Restrição de bloqueio operacional.'
        );
    }

    // =========================================================================
    // T — Restrição manual nunca é tocada
    // =========================================================================

    public function test_t_restricao_manual_nunca_e_fechada_pela_sincronizacao(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        (new AtualizarNecessidadeMaterialAtividade())->criarTakeOff($atividade, $ito, 25, $this->user);
        $local = $this->criarLocal();
        $this->entradaFisica($material, $local, 150);
        $pacote = $this->pacoteComAlocacao($ito, 25);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 25, usuario: $this->user);
        $pacote = $this->tornarLegadoEmRisco($pacote, $atividade);

        $categoria = CategoriaRestricao::create(['tenant_id' => $this->tenant->id, 'nome' => 'Manual', 'pilar_lean' => PilarLean::Materiais->value]);
        $restricaoManual = Restricao::create([
            'atividade_id' => $atividade->id,
            'categoria_id' => $categoria->id,
            'descricao' => 'Restrição manual, sem relação com Suprimentos.',
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
        ]);

        SincronizarRestricaoSuprimento::sincronizarItem($pacote, $this->user->id);

        $this->assertSame(StatusRestricao::Aberta, $restricaoManual->fresh()->status, 'Restrição manual nunca deve ser resolvida automaticamente por este mecanismo.');
    }

    // =========================================================================
    // U — reabertura: cobertura resolve, cobertura desaparece + causa volta -> reabre
    // =========================================================================

    public function test_u_restricao_automatica_reabre_se_cobertura_desaparecer_e_causa_voltar(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = (new AtualizarNecessidadeMaterialAtividade())->criarTakeOff($atividade, $ito, 25, $this->user);
        $local = $this->criarLocal();
        $pacote = $this->pacoteComAlocacao($ito, 25);
        $pacote = $this->tornarLegadoEmRisco($pacote, $atividade);

        // 1) Sem estoque nenhum ainda — bloqueia normalmente (comportamento histórico preservado).
        SincronizarRestricaoSuprimento::sincronizarItem($pacote, $this->user->id);
        $restricao = $this->buscarRestricaoLegado($pacote, $atividade);
        $this->assertNotNull($restricao);
        $this->assertSame(StatusRestricao::Aberta, $restricao->status);

        // 2) Chega estoque EXATO (25) + reserva específica -> cobertura
        // resolve a Restrição. Físico deliberadamente igual à necessidade
        // (nunca com sobra) — é o que torna o passo 3 abaixo capaz de
        // genuinamente fazer a cobertura desaparecer depois.
        $this->entradaFisica($material, $local, 25);
        $reserva = (new CriarReservaEstoque())->execute($pacote, $material, $local, 25, usuario: $this->user, necessidade: $necessidade);
        SincronizarRestricaoSuprimento::sincronizarItem($pacote->fresh(), $this->user->id);
        $this->assertSame(StatusRestricao::Resolvida, $restricao->fresh()->status);

        // 3) A reserva é liberada E o físico sai fisicamente do estoque
        // (Saída pra outra finalidade) — Reserva nunca move físico
        // (Ciclo 20), então só liberar a reserva NUNCA reduziria o
        // "livre" (pelo contrário, aumentaria) — a cobertura só
        // desaparece de verdade quando o próprio físico deixa de existir.
        // A causa comercial (Pacote em risco) continua verdadeira ->
        // a MESMA linha reabre.
        (new LiberarReservaEstoque())->execute($reserva->fresh(), $this->user, 'Liberada para outra frente');
        (new \App\Actions\Estoque\RegistrarSaidaEstoque())->execute($material, $local, 25, Carbon::today(), $this->user, retiradoPor: $this->user);
        SincronizarRestricaoSuprimento::sincronizarItem($pacote->fresh(), $this->user->id);

        $restricaoFinal = $this->buscarRestricaoLegado($pacote, $atividade);
        $this->assertSame($restricao->id, $restricaoFinal->id, 'Reabertura deve ser a MESMA linha, nunca uma nova.');
        $this->assertSame(StatusRestricao::Aberta, $restricaoFinal->status);
    }

    // =========================================================================
    // V — hard readiness (Atividade::estaPronta()) não foi alterado
    // =========================================================================

    public function test_v_hard_readiness_continua_bloqueando_por_restricao_bloqueante_aberta(): void
    {
        $atividade = $this->criarAtividade();
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = (new AtualizarNecessidadeMaterialAtividade())->criarTakeOff($atividade, $ito, 25, $this->user);
        $local = $this->criarLocal();
        $pacote = $this->pacoteComAlocacao($ito, 25);
        $pacote = $this->tornarLegadoEmRisco($pacote, $atividade);

        // Sem cobertura ainda -> bloqueia -> estaPronta() é false (regra
        // canônica já existente, nunca tocada por esta correção).
        SincronizarRestricaoSuprimento::sincronizarItem($pacote, $this->user->id);
        $this->assertFalse($atividade->fresh()->estaPronta());

        // Cobertura chega -> Restrição resolve -> estaPronta() volta a
        // ser derivada normalmente (true, sem nenhuma outra restrição).
        $this->entradaFisica($material, $local, 150);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 25, usuario: $this->user, necessidade: $necessidade);
        SincronizarRestricaoSuprimento::sincronizarItem($pacote->fresh(), $this->user->id);

        $this->assertTrue($atividade->fresh()->estaPronta());
    }

    // =========================================================================
    // Mesmo princípio no SEGUNDO mecanismo automático (Ciclo 19.7)
    // =========================================================================

    public function test_cadeia_formal_tambem_nunca_bloqueia_necessidade_ja_coberta(): void
    {
        $atividade = $this->criarAtividade(Carbon::yesterday()); // necessidade já vencida -> condicaoC potencialmente verdadeira
        $material = $this->criarMaterial();
        $ito = $this->criarItemTakeOff($material, 1000);
        $necessidade = (new AtualizarNecessidadeMaterialAtividade())->criarTakeOff($atividade, $ito, 25, $this->user);
        $local = $this->criarLocal();

        // Pacote com RC->Pedido Emitido, NUNCA recebido -> "material formal
        // pendente" comercialmente verdadeiro (Condição C).
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, 25);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Cadeia Formal']);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 25);
        $pacote->atividades()->sync([$atividade->id]);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 25);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), 25);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        // NUNCA recebido — material formal ainda pendente.

        // Material JÁ coberto fisicamente por outra via (inventário/entrada avulsa).
        $this->entradaFisica($material, $local, 150);
        (new CriarReservaEstoque())->execute($pacote->fresh(), $material, $local, 25, usuario: $this->user, necessidade: $necessidade);

        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        $restricaoCadeia = Restricao::where('atividade_id', $atividade->id)->where('origem_cadeia_suprimento_id', $pacote->id)->first();
        $this->assertTrue(
            $restricaoCadeia === null || $restricaoCadeia->status !== StatusRestricao::Aberta,
            'A cadeia formal (Ciclo 19.7) também nunca deve bloquear uma necessidade já coberta por Estoque/Reserva.'
        );
    }

    // =========================================================================
    // Sem correspondência inequívoca -> comportamento histórico preservado
    // =========================================================================

    public function test_sem_correspondencia_inequivoca_comportamento_historico_e_preservado(): void
    {
        $atividade = $this->criarAtividade();
        // Pacote SEM nenhuma Alocação/necessidade correspondente — cenário
        // legado puro, sem nenhuma cadeia de Estoque envolvida.
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote sem correspondência']);
        $pacote = $this->tornarLegadoEmRisco($pacote, $atividade);

        SincronizarRestricaoSuprimento::sincronizarItem($pacote, $this->user->id);

        $restricao = $this->buscarRestricaoLegado($pacote, $atividade);
        $this->assertNotNull($restricao, 'Sem correspondência inequívoca com nenhuma necessidade, o comportamento histórico (bloquear) deve ser preservado.');
        $this->assertSame(StatusRestricao::Aberta, $restricao->status);
    }
}
