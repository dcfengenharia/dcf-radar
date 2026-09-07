<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarDestinacaoPlanejada;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\RegistrarAplicacaoMaterialEstoque;
use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Actions\Estoque\RegistrarSaidaEstoque;
use App\Actions\LicoesAprendidas\ConverterCandidatoEmLicao;
use App\Actions\LicoesAprendidas\DescartarCandidatoLicaoAprendida;
use App\Actions\LicoesAprendidas\PrepararContextoNovaLicao;
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
use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusCandidatoLicaoAprendida;
use App\Enums\StatusRestricao;
use App\Enums\TipoCandidatoLicaoAprendida;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Enums\TipoLicaoAprendida;
use App\Enums\TipoLocalEstoque;
use App\Exceptions\CandidatoLicaoAprendidaJaTratadoException;
use App\Models\Atividade;
use App\Models\CandidatoLicaoAprendida;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\LicaoAprendida;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\PedidoCompra;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\LicoesAprendidas\GerarCandidatosLicoesObra;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 23, Etapa 23.3 — Revisão de Lições da Obra: candidatos gerados
 * por 3 regras determinísticas (Restrição relevante / Pedido com atraso
 * final / Aplicação divergente da destinação), workflow
 * Pendente→Convertido|Descartado, integração completa com a captura
 * contextual da 23.2.
 */
class CandidatoLicaoAprendidaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;
    private GerarCandidatosLicoesObra $gerar;

    private RegistrarEntradaEstoque $registrarEntrada;
    private RegistrarSaidaEstoque $registrarSaida;
    private RegistrarAplicacaoMaterialEstoque $registrarAplicacao;
    private AtualizarDestinacaoPlanejada $destinacaoAction;
    private CriarReservaEstoque $reservaAction;
    private CriarRequisicaoPlanejamento $criarRp;
    private AtualizarRascunhoRequisicaoPlanejamento $atualizarRp;
    private EmitirRequisicaoPlanejamento $emitirRp;
    private AlocarRequisicaoAoPacote $alocar;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        \App\Support\ObraContext::set($this->obra);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);

        $this->gerar = new GerarCandidatosLicoesObra();
        $this->registrarEntrada = new RegistrarEntradaEstoque();
        $this->registrarSaida = new RegistrarSaidaEstoque();
        $this->registrarAplicacao = new RegistrarAplicacaoMaterialEstoque();
        $this->destinacaoAction = new AtualizarDestinacaoPlanejada();
        $this->reservaAction = new CriarReservaEstoque();
        $this->criarRp = new CriarRequisicaoPlanejamento();
        $this->atualizarRp = new AtualizarRascunhoRequisicaoPlanejamento();
        $this->emitirRp = new EmitirRequisicaoPlanejamento();
        $this->alocar = new AlocarRequisicaoAoPacote();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function restricaoComDuracao(?Carbon $abertaEm, ?Carbon $resolvidaEm, bool $bloqueante = true, string $status = 'resolvida', ?Work $obra = null): Restricao
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => ($obra ?? $this->obra)->id]);

        return Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'descricao' => 'Restrição de teste',
            'bloqueante' => $bloqueante,
            'status' => $status,
            'aberta_em' => $abertaEm,
            'resolvida_em' => $resolvidaEm,
        ]);
    }

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-'.uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(?Work $obra = null): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Local '.uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true,
        ]);
    }

    private function criarFrente(?Work $obra = null): FrenteTrabalho
    {
        return FrenteTrabalho::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Frente '.uniqid()]);
    }

    private function criarPacoteSimples(?Work $obra = null): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote '.uniqid()]);
    }

    private function criarItemTakeOffOrfao(?Material $material, ?Work $obra = null): ItemTakeOff
    {
        $obra ??= $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'D'.uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM'.uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A'.uniqid(), 'descricao' => 'Item',
            'quantidade' => 1000000, 'material_id' => $material?->id,
        ]);
    }

    private function alocarNoPacote(\App\Models\RequisicaoPlanejamentoItem $rpItem, float $quantidade, ?ItemSuprimento $pacote = null, ?Work $obra = null): \App\Models\AlocacaoRequisicaoPacote
    {
        $pacote ??= $this->criarPacoteSimples($obra);

        return $this->alocar->alocar($rpItem, $pacote, $quantidade);
    }

    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade, ?Work $obra = null): ItemSuprimento
    {
        $obra ??= $this->obra;
        $item = $this->criarItemTakeOffOrfao($material, $obra);
        $rp = $this->criarRp->execute($obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidade);
        $this->emitirRp->execute($rp->fresh(), $this->user);
        $alocacao = $this->alocarNoPacote($rpItem->fresh(), $quantidade, null, $obra);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo '.uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $obra->id, 'nome' => 'Fornecedor '.uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $pedidoItem = $pedidoItem->fresh();

        // Recebido na PRÓPRIA data prevista (nunca atrasado) — evita que
        // os testes da Regra C (Aplicação) disparem espuriamente a Regra B
        // (Pedido atrasado) só por causa do fixture de entrada em estoque.
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::parse('2026-12-01'), $this->user);
        $this->registrarEntrada->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);

        return ItemSuprimento::find($alocacao->item_suprimento_id);
    }

    private function saidaSimples(Material $material, LocalEstoque $local, float $quantidade, ?ItemSuprimento $pacote = null, ?\App\Models\ReservaEstoque $reserva = null): \App\Models\MovimentacaoEstoque
    {
        return $this->registrarSaida->execute(
            $material, $local, $quantidade, Carbon::today(), $this->user,
            reserva: $reserva, pacote: $pacote, unidade: null, retiradoPor: $this->user,
        );
    }

    /** Pedido Emitido com um único item, sem nenhum recebimento ainda — caller registra os recebimentos. */
    private function pedidoEmitido(float $quantidade, string $dataPrevistaEntrega, ?Work $obra = null): array
    {
        $obra ??= $this->obra;
        $pacote = $this->criarPacoteSimples($obra);
        $item = $this->criarItemTakeOffOrfao(null, $obra);
        $rp = $this->criarRp->execute($obra->id, null, $this->user->id);
        $rpItem = $this->atualizarRp->adicionarItem($rp, $item->id, $quantidade);
        $this->emitirRp->execute($rp->fresh(), $this->user);
        $alocacao = $this->alocarNoPacote($rpItem->fresh(), $quantidade, $pacote, $obra);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo '.uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $rcItem = $rcEmitida->itens->first();

        $fornecedor = Fornecedor::create(['obra_id' => $obra->id, 'nome' => 'Fornecedor '.uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, $dataPrevistaEntrega, null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem, $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return [$pedido->fresh(), $pedidoItem->fresh()];
    }

    // =========================================================================
    // A) REGRA A — RESTRIÇÃO: limites
    // =========================================================================

    public function test_a1_restricao_com_menos_de_1_dia_nao_gera(): void
    {
        $this->restricaoComDuracao(Carbon::parse('2026-12-10 10:00:00'), Carbon::parse('2026-12-11 09:59:59'));

        $this->gerar->execute($this->obra);

        $this->assertSame(0, CandidatoLicaoAprendida::count());
    }

    public function test_a2_restricao_com_exatamente_1_dia_gera(): void
    {
        $restricao = $this->restricaoComDuracao(Carbon::parse('2026-12-10 10:00:00'), Carbon::parse('2026-12-11 10:00:00'));

        $this->gerar->execute($this->obra);

        $candidato = CandidatoLicaoAprendida::sole();
        $this->assertSame(TipoCandidatoLicaoAprendida::RestricaoRelevante, $candidato->tipo);
        $this->assertSame("restricao_relevante:{$restricao->id}", $candidato->chave_logica);
        $this->assertSame(1, $candidato->dados_snapshot['dias']);
    }

    public function test_a3_restricao_com_mais_de_1_dia_gera(): void
    {
        $this->restricaoComDuracao(Carbon::parse('2026-12-01 10:00:00'), Carbon::parse('2026-12-19 10:00:00'));

        $this->gerar->execute($this->obra);

        $candidato = CandidatoLicaoAprendida::sole();
        $this->assertSame(18, $candidato->dados_snapshot['dias']);
        $this->assertStringContainsString('18 dias', $candidato->descricao);
    }

    public function test_a4_restricao_nao_bloqueante_nunca_gera(): void
    {
        $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-10'), bloqueante: false);

        $this->gerar->execute($this->obra);

        $this->assertSame(0, CandidatoLicaoAprendida::count());
    }

    public function test_a5_restricao_aberta_nunca_gera(): void
    {
        $this->restricaoComDuracao(Carbon::parse('2026-12-01'), null, status: 'aberta');

        $this->gerar->execute($this->obra);

        $this->assertSame(0, CandidatoLicaoAprendida::count());
    }

    public function test_a6_explicacao_e_snapshot_sao_factuais(): void
    {
        $restricao = $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-19'));
        $this->gerar->execute($this->obra);

        $candidato = CandidatoLicaoAprendida::sole();
        $this->assertStringContainsString('permaneceu aberta por', $candidato->descricao);
        $this->assertArrayHasKey('aberta_em', $candidato->dados_snapshot);
        $this->assertArrayHasKey('resolvida_em', $candidato->dados_snapshot);
        $this->assertArrayHasKey('dias', $candidato->dados_snapshot);
        // Nunca infere impacto/causa/culpado além da duração factual.
        $this->assertArrayNotHasKey('impacto', $candidato->dados_snapshot);
        $this->assertArrayNotHasKey('causa', $candidato->dados_snapshot);
    }

    // =========================================================================
    // B) REGRA B — PEDIDO DE COMPRA: limites
    // =========================================================================

    public function test_b1_pedido_recebido_no_prazo_nao_gera(): void
    {
        [$pedido, $item] = $this->pedidoEmitido(100, '2026-12-10');
        (new RegistrarRecebimentoPedido())->execute($item, 100, Carbon::parse('2026-12-10'), $this->user);

        $this->gerar->execute($this->obra);

        $this->assertSame(0, CandidatoLicaoAprendida::count());
    }

    public function test_b2_pedido_recebido_1_dia_apos_previsao_gera(): void
    {
        [$pedido, $item] = $this->pedidoEmitido(100, '2026-12-10');
        (new RegistrarRecebimentoPedido())->execute($item, 100, Carbon::parse('2026-12-11'), $this->user);

        $this->gerar->execute($this->obra);

        $candidato = CandidatoLicaoAprendida::sole();
        $this->assertSame(TipoCandidatoLicaoAprendida::PedidoAtrasoFinal, $candidato->tipo);
        $this->assertSame(1, $candidato->dados_snapshot['dias_atraso']);
    }

    public function test_b3_pedido_recebido_varios_dias_apos_previsao_gera(): void
    {
        [$pedido, $item] = $this->pedidoEmitido(100, '2026-12-01');
        (new RegistrarRecebimentoPedido())->execute($item, 100, Carbon::parse('2026-12-10'), $this->user);

        $this->gerar->execute($this->obra);

        $candidato = CandidatoLicaoAprendida::sole();
        $this->assertSame(9, $candidato->dados_snapshot['dias_atraso']);
        $this->assertStringContainsString('9 dias após a data prevista', $candidato->descricao);
    }

    public function test_b4_pedido_ainda_nao_completo_nunca_gera(): void
    {
        [$pedido, $item] = $this->pedidoEmitido(100, '2026-12-01');
        (new RegistrarRecebimentoPedido())->execute($item, 50, Carbon::parse('2026-12-15'), $this->user);

        $this->gerar->execute($this->obra);

        $this->assertSame(0, CandidatoLicaoAprendida::count());
    }

    public function test_b5_pedido_recebido_antecipado_nunca_gera(): void
    {
        // Fronteira exata (23.3.CORREÇÃO): conclusão 1 dia ANTES da
        // prevista — margem de 5 dias não testava a fronteira de verdade.
        [$pedido, $item] = $this->pedidoEmitido(100, '2026-12-10');
        (new RegistrarRecebimentoPedido())->execute($item, 100, Carbon::parse('2026-12-09'), $this->user);

        $this->gerar->execute($this->obra);

        $this->assertSame(0, CandidatoLicaoAprendida::count());
    }

    public function test_b6_snapshot_congela_datas_e_dias_nunca_necessidade(): void
    {
        [$pedido, $item] = $this->pedidoEmitido(100, '2026-12-01');
        (new RegistrarRecebimentoPedido())->execute($item, 100, Carbon::parse('2026-12-10'), $this->user);
        $this->gerar->execute($this->obra);

        $candidato = CandidatoLicaoAprendida::sole();
        $this->assertSame('2026-12-01', $candidato->dados_snapshot['data_prevista_entrega']);
        $this->assertSame('2026-12-10', $candidato->dados_snapshot['data_entrega_completa']);
        $this->assertArrayNotHasKey('necessidade', $candidato->dados_snapshot);
    }

    // =========================================================================
    // C) REGRA C — APLICAÇÃO DIVERGENTE: limites
    // =========================================================================

    public function test_c1_aplicacao_aderente_nunca_gera(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $destinacaoA = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, $destinacaoA, $this->user);
        $saida = $this->saidaSimples($material, $local, 300, null, $reserva);
        $this->registrarAplicacao->execute($saida, $frenteA, 300, Carbon::today(), $this->user);

        $this->gerar->execute($this->obra);

        $this->assertSame(0, CandidatoLicaoAprendida::count());
    }

    public function test_c2_aplicacao_divergente_gera(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $destinacaoA = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, $destinacaoA, $this->user);
        $saida = $this->saidaSimples($material, $local, 300, null, $reserva);
        $this->registrarAplicacao->execute($saida, $frenteB, 300, Carbon::today(), $this->user);

        $this->gerar->execute($this->obra);

        $candidato = CandidatoLicaoAprendida::sole();
        $this->assertSame(TipoCandidatoLicaoAprendida::AplicacaoDesvioDestinacao, $candidato->tipo);
        $this->assertSame($frenteA->id, $candidato->dados_snapshot['frente_planejada_id']);
        $this->assertSame($frenteB->id, $candidato->dados_snapshot['frente_aplicada_id']);
    }

    public function test_c3_saida_sem_reserva_nunca_gera(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 1000);
        $frenteB = $this->criarFrente();
        $saida = $this->saidaSimples($material, $local, 300);
        $this->registrarAplicacao->execute($saida, $frenteB, 300, Carbon::today(), $this->user);

        $this->gerar->execute($this->obra);

        $this->assertSame(0, CandidatoLicaoAprendida::count());
    }

    public function test_c4_reserva_generica_sem_frente_planejada_nunca_gera(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteB = $this->criarFrente();
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, null, $this->user);
        $saida = $this->saidaSimples($material, $local, 300, null, $reserva);
        $this->registrarAplicacao->execute($saida, $frenteB, 300, Carbon::today(), $this->user);

        $this->gerar->execute($this->obra);

        $this->assertSame(0, CandidatoLicaoAprendida::count());
    }

    public function test_c5_snapshot_nunca_infere_causa_ou_impacto(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $destinacaoA = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, $destinacaoA, $this->user);
        $saida = $this->saidaSimples($material, $local, 300, null, $reserva);
        $this->registrarAplicacao->execute($saida, $frenteB, 300, Carbon::today(), $this->user);
        $this->gerar->execute($this->obra);

        $candidato = CandidatoLicaoAprendida::sole();
        $this->assertArrayNotHasKey('causa', $candidato->dados_snapshot);
        $this->assertArrayNotHasKey('culpado', $candidato->dados_snapshot);
        $this->assertArrayNotHasKey('impacto', $candidato->dados_snapshot);
    }

    // =========================================================================
    // C) REGRA C — auditoria de historicidade (23.3.CORREÇÃO): a
    // Aplicação divergente só é factual e estável enquanto a Saída dona
    // dela já estiver "fechada" (PoliticaConciliacaoAplicacao::
    // saidaEstaFechada()) — antes disso, frente_trabalho_id/quantidade
    // ainda podem ser editados (AtualizarAplicacaoMaterialEstoque),
    // então gerar o candidato mais cedo descreveria um fato que pode
    // deixar de ser verdade sem nenhuma reabertura de episódio.
    // =========================================================================

    public function test_c6_aplicacao_divergente_em_saida_ainda_nao_fechada_nao_gera_ainda(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $destinacaoA = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, $destinacaoA, $this->user);
        $saida = $this->saidaSimples($material, $local, 300, null, $reserva);

        // Só 200 de 300 aplicados — Saída AINDA NÃO fechada (pendente=100).
        $this->registrarAplicacao->execute($saida, $frenteB, 200, Carbon::today(), $this->user);

        $this->gerar->execute($this->obra);

        $this->assertSame(0, CandidatoLicaoAprendida::count());
    }

    public function test_c7_aplicacao_divergente_gera_somente_apos_saida_fechar(): void
    {
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $destinacaoA = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, $destinacaoA, $this->user);
        $saida = $this->saidaSimples($material, $local, 300, null, $reserva);
        $aplicacaoDivergente = $this->registrarAplicacao->execute($saida, $frenteB, 200, Carbon::today(), $this->user);

        $this->gerar->execute($this->obra);
        $this->assertSame(0, CandidatoLicaoAprendida::count());

        // Fecha a Saída (100 restantes, agora sim na frente planejada) —
        // só a partir daqui a cadeia inteira (destinação/reserva/aplicação)
        // é historicamente estável.
        $this->registrarAplicacao->execute($saida, $frenteA, 100, Carbon::today(), $this->user);
        $this->assertTrue(\App\Support\Estoque\PoliticaConciliacaoAplicacao::saidaEstaFechada($saida->fresh()));

        $this->gerar->execute($this->obra);

        $candidato = CandidatoLicaoAprendida::sole();
        $this->assertSame(TipoCandidatoLicaoAprendida::AplicacaoDesvioDestinacao, $candidato->tipo);
        $this->assertSame($aplicacaoDivergente->id, $candidato->entidade_id);
        $this->assertSame($frenteB->id, $candidato->dados_snapshot['frente_aplicada_id']);
    }

    public function test_c8_editar_frente_da_aplicacao_apos_saida_fechar_e_bloqueado(): void
    {
        // Prova adicional da estabilidade: uma vez fechada, a MESMA
        // Aplicação que fundamenta o candidato não pode mais ter sua
        // frente/quantidade reescritas — o dado que o candidato
        // descreve é literalmente imutável dali pra frente.
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $pacote = $this->entradaPronta($material, $local, 1000);
        $frenteA = $this->criarFrente();
        $frenteB = $this->criarFrente();
        $destinacaoA = $this->destinacaoAction->criar($pacote, $material, $frenteA, 300, $this->user);
        $reserva = $this->reservaAction->execute($pacote, $material, $local, 300, null, $destinacaoA, $this->user);
        $saida = $this->saidaSimples($material, $local, 300, null, $reserva);
        $aplicacao = $this->registrarAplicacao->execute($saida, $frenteB, 300, Carbon::today(), $this->user);
        $this->assertTrue(\App\Support\Estoque\PoliticaConciliacaoAplicacao::saidaEstaFechada($saida->fresh()));

        $this->expectException(\App\Exceptions\AplicacaoConciliacaoFechadaException::class);
        $aplicacao->update(['frente_trabalho_id' => $frenteA->id]);
    }

    // =========================================================================
    // D) SNAPSHOT — imutável após mutação/remoção da entidade de origem
    // =========================================================================

    public function test_d1_snapshot_restricao_imutavel_apos_reabertura(): void
    {
        $restricao = $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-10'));
        $this->gerar->execute($this->obra);
        $candidato = CandidatoLicaoAprendida::sole();
        $snapshotOriginal = $candidato->dados_snapshot;

        // Reabre a restrição (mesmo mecanismo real — resolvida_em some).
        $restricao->update(['status' => StatusRestricao::Aberta->value, 'resolvida_em' => null]);

        $this->assertSame($snapshotOriginal, $candidato->fresh()->dados_snapshot);
        $this->assertSame(StatusCandidatoLicaoAprendida::Pendente, $candidato->fresh()->status);
    }

    public function test_d2_regenerar_apos_reabertura_e_nova_resolucao_nao_duplica(): void
    {
        $restricao = $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-10'));
        $this->gerar->execute($this->obra);
        $this->assertSame(1, CandidatoLicaoAprendida::count());

        $restricao->update(['status' => StatusRestricao::Aberta->value, 'resolvida_em' => null]);
        $restricao->update(['status' => StatusRestricao::Resolvida->value, 'resolvida_em' => Carbon::parse('2026-12-15')]);

        $this->gerar->execute($this->obra);

        // Mesma chave_logica (restricao_id) — a linha já existe, nunca duplica.
        $this->assertSame(1, CandidatoLicaoAprendida::count());
    }

    // =========================================================================
    // E) IDEMPOTÊNCIA — 1x/2x/10x
    // =========================================================================

    public function test_e1_gerar_1x_2x_10x_produz_a_mesma_quantidade(): void
    {
        $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-10'));
        [$pedido, $item] = $this->pedidoEmitido(100, '2026-12-01');
        (new RegistrarRecebimentoPedido())->execute($item, 100, Carbon::parse('2026-12-10'), $this->user);

        $criados1 = $this->gerar->execute($this->obra);
        $this->assertSame(2, $criados1);
        $this->assertSame(2, CandidatoLicaoAprendida::count());

        $criados2 = $this->gerar->execute($this->obra);
        $this->assertSame(0, $criados2);
        $this->assertSame(2, CandidatoLicaoAprendida::count());

        for ($i = 0; $i < 8; $i++) {
            $this->gerar->execute($this->obra);
        }
        $this->assertSame(2, CandidatoLicaoAprendida::count());
    }

    // =========================================================================
    // F) CONCORRÊNCIA — proteção estrutural
    // =========================================================================

    public function test_f1_unique_constraint_bloqueia_duplicata_por_chave_logica(): void
    {
        $restricao = $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-10'));

        $dados = [
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'tipo' => TipoCandidatoLicaoAprendida::RestricaoRelevante->value,
            'chave_logica' => "restricao_relevante:{$restricao->id}",
            'status' => StatusCandidatoLicaoAprendida::Pendente->value,
            'entidade_tipo' => TipoEntidadeVinculoLicao::Restricao->value,
            'entidade_id' => $restricao->id,
            'titulo' => 'x',
            'descricao' => 'x',
            'dados_snapshot' => [],
            'gerado_em' => now(),
        ];

        CandidatoLicaoAprendida::create($dados);

        $this->expectException(\Illuminate\Database\QueryException::class);
        CandidatoLicaoAprendida::create($dados);
    }

    public function test_f2_geracao_apos_duplicata_manual_nunca_falha_nem_duplica(): void
    {
        $restricao = $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-10'));

        // Simula uma corrida: outro processo já criou a linha antes do
        // pré-carregamento — a Action de geração precisa capturar o 1062
        // como idempotência, nunca lançar erro pro usuário.
        CandidatoLicaoAprendida::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCandidatoLicaoAprendida::RestricaoRelevante->value,
            'chave_logica' => "restricao_relevante:{$restricao->id}",
            'status' => StatusCandidatoLicaoAprendida::Pendente->value,
            'entidade_tipo' => TipoEntidadeVinculoLicao::Restricao->value,
            'entidade_id' => $restricao->id,
            'titulo' => 'x', 'descricao' => 'x', 'dados_snapshot' => [], 'gerado_em' => now(),
        ]);

        $criados = $this->gerar->execute($this->obra);

        $this->assertSame(0, $criados);
        $this->assertSame(1, CandidatoLicaoAprendida::count());
    }

    public function test_f3_conversao_dupla_do_mesmo_candidato_apenas_uma_vence(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $candidato = CandidatoLicaoAprendida::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCandidatoLicaoAprendida::RestricaoRelevante->value,
            'chave_logica' => 'restricao_relevante:'.\Illuminate\Support\Str::ulid(),
            'status' => StatusCandidatoLicaoAprendida::Pendente->value,
            'entidade_tipo' => TipoEntidadeVinculoLicao::Atividade->value,
            'entidade_id' => $atividade->id,
            'titulo' => 'x', 'descricao' => 'x', 'dados_snapshot' => [], 'gerado_em' => now(),
        ]);

        $contexto = app(PrepararContextoNovaLicao::class)->execute($this->user, TipoEntidadeVinculoLicao::Atividade, $atividade->id);
        $dadosForm = $this->dadosMinimosForm();

        $licao1 = app(ConverterCandidatoEmLicao::class)->execute($candidato, $contexto, null, $this->user, $dadosForm);

        $this->expectException(CandidatoLicaoAprendidaJaTratadoException::class);
        app(ConverterCandidatoEmLicao::class)->execute($candidato, $contexto, null, $this->user, $dadosForm);

        $this->assertSame(1, LicaoAprendida::count());
    }

    // =========================================================================
    // G) DESCARTE
    // =========================================================================

    public function test_g1_descarte_pendente_para_descartado_com_historico(): void
    {
        $restricao = $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-10'));
        $this->gerar->execute($this->obra);
        $candidato = CandidatoLicaoAprendida::sole();

        app(DescartarCandidatoLicaoAprendida::class)->execute($candidato, $this->user, 'Não relevante para futuros projetos.');

        $candidato->refresh();
        $this->assertSame(StatusCandidatoLicaoAprendida::Descartado, $candidato->status);
        $this->assertSame($this->user->id, $candidato->descartado_por);
        $this->assertNotNull($candidato->descartado_em);
        $this->assertSame('Não relevante para futuros projetos.', $candidato->motivo_descarte);
    }

    public function test_g2_descarte_sem_motivo_e_permitido(): void
    {
        $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-10'));
        $this->gerar->execute($this->obra);
        $candidato = CandidatoLicaoAprendida::sole();

        app(DescartarCandidatoLicaoAprendida::class)->execute($candidato, $this->user, null);

        $this->assertSame(StatusCandidatoLicaoAprendida::Descartado, $candidato->fresh()->status);
        $this->assertNull($candidato->fresh()->motivo_descarte);
    }

    public function test_g3_descarte_nunca_deleta(): void
    {
        $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-10'));
        $this->gerar->execute($this->obra);
        $candidato = CandidatoLicaoAprendida::sole();

        app(DescartarCandidatoLicaoAprendida::class)->execute($candidato, $this->user, null);

        $this->assertSame(1, CandidatoLicaoAprendida::count());
    }

    public function test_g4_convertido_nao_pode_ser_descartado(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $candidato = $this->candidatoAvulso($atividade);
        $contexto = app(PrepararContextoNovaLicao::class)->execute($this->user, TipoEntidadeVinculoLicao::Atividade, $atividade->id);
        app(ConverterCandidatoEmLicao::class)->execute($candidato, $contexto, null, $this->user, $this->dadosMinimosForm());

        $this->expectException(CandidatoLicaoAprendidaJaTratadoException::class);
        app(DescartarCandidatoLicaoAprendida::class)->execute($candidato->fresh(), $this->user, null);
    }

    public function test_g5_descartado_nao_pode_ser_convertido(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $candidato = $this->candidatoAvulso($atividade);
        app(DescartarCandidatoLicaoAprendida::class)->execute($candidato, $this->user, null);

        $contexto = app(PrepararContextoNovaLicao::class)->execute($this->user, TipoEntidadeVinculoLicao::Atividade, $atividade->id);

        $this->expectException(CandidatoLicaoAprendidaJaTratadoException::class);
        app(ConverterCandidatoEmLicao::class)->execute($candidato->fresh(), $contexto, null, $this->user, $this->dadosMinimosForm());

        $this->assertSame(0, LicaoAprendida::count());
    }

    // =========================================================================
    // H) CONVERSÃO — transação/rollback/integração 23.2
    // =========================================================================

    public function test_h1_conversao_cria_licao_com_vinculo_de_origem_e_marca_candidato(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $restricao = Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $atividade->id, 'descricao' => 'X', 'bloqueante' => true, 'status' => 'resolvida', 'aberta_em' => Carbon::parse('2026-12-01'), 'resolvida_em' => Carbon::parse('2026-12-10')]);
        $this->gerar->execute($this->obra);
        $candidato = CandidatoLicaoAprendida::sole();

        $contexto = app(PrepararContextoNovaLicao::class)->execute($this->user, TipoEntidadeVinculoLicao::Restricao, $restricao->id);
        $licao = app(ConverterCandidatoEmLicao::class)->execute($candidato, $contexto, null, $this->user, $this->dadosMinimosForm());

        $this->assertCount(2, $licao->vinculos); // origem (Restrição) + complementar (Atividade)
        $origem = $licao->vinculos->firstWhere('e_origem', true);
        $this->assertSame(TipoEntidadeVinculoLicao::Restricao, $origem->entidade_tipo);
        $this->assertSame($restricao->id, $origem->entidade_id);

        $candidato->refresh();
        $this->assertSame(StatusCandidatoLicaoAprendida::Convertido, $candidato->status);
        $this->assertSame($licao->id, $candidato->licao_aprendida_id);
        $this->assertNotNull($candidato->convertido_em);
    }

    public function test_h2_falha_ao_criar_licao_nunca_marca_candidato(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $candidato = $this->candidatoAvulso($atividade);
        $contexto = app(PrepararContextoNovaLicao::class)->execute($this->user, TipoEntidadeVinculoLicao::Atividade, $atividade->id);

        try {
            app(ConverterCandidatoEmLicao::class)->execute($candidato, $contexto, null, $this->user, [
                'titulo' => 'x',
                // situacao_observada ausente -> CriarLicaoAprendida/DB deve falhar (NOT NULL)
                'recomendacao_futura' => 'x',
                'tipo' => TipoLicaoAprendida::Problema->value,
                'criticidade' => CriticidadeLicao::Alta->value,
                'area_funcional' => AreaFuncionalLicao::Planejamento->value,
            ]);
            $this->fail('Deveria ter lançado exceção de banco.');
        } catch (\Throwable $e) {
            // esperado
        }

        $this->assertSame(0, LicaoAprendida::count());
        $this->assertSame(StatusCandidatoLicaoAprendida::Pendente, $candidato->fresh()->status);
        $this->assertNull($candidato->fresh()->licao_aprendida_id);
    }

    public function test_h3_livewire_abrir_conversao_nunca_cria_licao(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $candidato = $this->candidatoAvulso($atividade);

        Livewire::test('pages::gestao.licoes-aprendidas')
            ->call('iniciarConversao', $candidato->id)
            ->assertSet('modalFormAberto', true)
            ->assertSet('candidatoConvertendoId', $candidato->id);

        $this->assertSame(0, LicaoAprendida::count());
        $this->assertSame(StatusCandidatoLicaoAprendida::Pendente, $candidato->fresh()->status);
    }

    public function test_h4_livewire_cancelar_conversao_nunca_cria_licao_e_candidato_continua_pendente(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $candidato = $this->candidatoAvulso($atividade);

        Livewire::test('pages::gestao.licoes-aprendidas')
            ->call('iniciarConversao', $candidato->id)
            ->call('fecharModalForm')
            ->assertSet('candidatoConvertendoId', null)
            ->assertSet('modalFormAberto', false);

        $this->assertSame(0, LicaoAprendida::count());
        $this->assertSame(StatusCandidatoLicaoAprendida::Pendente, $candidato->fresh()->status);
    }

    public function test_h5_livewire_salvar_converte_uma_unica_vez(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $candidato = $this->candidatoAvulso($atividade);

        $dados = $this->dadosMinimosForm();
        Livewire::test('pages::gestao.licoes-aprendidas')
            ->call('iniciarConversao', $candidato->id)
            ->set('formSituacao', $dados['situacao_observada'])
            ->set('formRecomendacao', $dados['recomendacao_futura'])
            ->set('formTipo', $dados['tipo'])
            ->set('formCriticidade', $dados['criticidade'])
            ->set('formArea', $dados['area_funcional'])
            ->call('salvarForm')
            ->assertSet('candidatoConvertendoId', null);

        $this->assertSame(1, LicaoAprendida::count());
        $this->assertSame(StatusCandidatoLicaoAprendida::Convertido, $candidato->fresh()->status);
    }

    public function test_h6_livewire_tentativa_repetida_de_converter_ja_convertido_nao_duplica(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $candidato = $this->candidatoAvulso($atividade);
        $contexto = app(PrepararContextoNovaLicao::class)->execute($this->user, TipoEntidadeVinculoLicao::Atividade, $atividade->id);
        app(ConverterCandidatoEmLicao::class)->execute($candidato, $contexto, null, $this->user, $this->dadosMinimosForm());

        $c = Livewire::test('pages::gestao.licoes-aprendidas')->call('iniciarConversao', $candidato->id);

        $this->assertNotNull($c->get('erroCandidatos'));
        $this->assertSame(1, LicaoAprendida::count());
    }

    // =========================================================================
    // I) TENANT ISOLATION
    // =========================================================================

    public function test_i1_candidato_de_outro_tenant_invisivel(): void
    {
        $outroTenant = Tenant::factory()->create();
        $candidatoOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $atividade = Atividade::factory()->create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutroTenant->id]);

            return CandidatoLicaoAprendida::create([
                'obra_id' => $obraOutroTenant->id,
                'tipo' => TipoCandidatoLicaoAprendida::RestricaoRelevante->value,
                'chave_logica' => 'restricao_relevante:'.\Illuminate\Support\Str::ulid(),
                'status' => StatusCandidatoLicaoAprendida::Pendente->value,
                'entidade_tipo' => TipoEntidadeVinculoLicao::Atividade->value,
                'entidade_id' => $atividade->id,
                'titulo' => 'x', 'descricao' => 'x', 'dados_snapshot' => [], 'gerado_em' => now(),
            ]);
        });

        $this->assertNull(CandidatoLicaoAprendida::find($candidatoOutroTenant->id));
    }

    public function test_i2_id_manipulado_de_outro_tenant_nao_permite_descarte(): void
    {
        $outroTenant = Tenant::factory()->create();
        $candidatoOutroTenant = TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $atividade = Atividade::factory()->create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutroTenant->id]);

            return CandidatoLicaoAprendida::create([
                'obra_id' => $obraOutroTenant->id,
                'tipo' => TipoCandidatoLicaoAprendida::RestricaoRelevante->value,
                'chave_logica' => 'restricao_relevante:'.\Illuminate\Support\Str::ulid(),
                'status' => StatusCandidatoLicaoAprendida::Pendente->value,
                'entidade_tipo' => TipoEntidadeVinculoLicao::Atividade->value,
                'entidade_id' => $atividade->id,
                'titulo' => 'x', 'descricao' => 'x', 'dados_snapshot' => [], 'gerado_em' => now(),
            ]);
        });

        $c = Livewire::test('pages::gestao.licoes-aprendidas')->call('abrirDescarte', $candidatoOutroTenant->id);

        $this->assertNotNull($c->get('erroCandidatos'));
        $this->assertSame(StatusCandidatoLicaoAprendida::Pendente, $candidatoOutroTenant->fresh()->status);
    }

    public function test_i3_geracao_de_a_nunca_consulta_dados_de_b(): void
    {
        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $atividade = Atividade::factory()->create(['tenant_id' => $outroTenant->id, 'obra_id' => $obraOutroTenant->id]);
            Restricao::factory()->create([
                'tenant_id' => $outroTenant->id, 'atividade_id' => $atividade->id, 'descricao' => 'x',
                'bloqueante' => true, 'status' => 'resolvida',
                'aberta_em' => Carbon::parse('2026-12-01'), 'resolvida_em' => Carbon::parse('2026-12-10'),
            ]);
        });

        $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-05'));
        $criados = $this->gerar->execute($this->obra);

        $this->assertSame(1, $criados);
        $this->assertSame(1, CandidatoLicaoAprendida::count());
    }

    // =========================================================================
    // J) OBRA ISOLATION
    // =========================================================================

    public function test_j1_geracao_de_uma_obra_nunca_mistura_com_outra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-05'), obra: $outraObra);
        $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-05'));

        $criados = $this->gerar->execute($this->obra);

        $this->assertSame(1, $criados);
        $candidato = CandidatoLicaoAprendida::sole();
        $this->assertSame($this->obra->id, $candidato->obra_id);
    }

    public function test_j2_mesma_chave_logica_em_obras_diferentes_do_mesmo_tenant_nunca_colide(): void
    {
        // Mesmo formato de chave_logica, entidades DIFERENTES (Restricao_id
        // é único por natureza — este teste prova que a constraint inclui
        // obra_id, então mesmo se duas obras tivessem coincidentemente a
        // mesma chave_logica, não colidiriam.
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $atividadeA = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $atividadeB = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $outraObra->id]);

        $chaveFixa = 'restricao_relevante:mesma-chave-simulada';

        CandidatoLicaoAprendida::create([
            'obra_id' => $this->obra->id, 'tipo' => TipoCandidatoLicaoAprendida::RestricaoRelevante->value,
            'chave_logica' => $chaveFixa, 'status' => StatusCandidatoLicaoAprendida::Pendente->value,
            'entidade_tipo' => TipoEntidadeVinculoLicao::Atividade->value, 'entidade_id' => $atividadeA->id,
            'titulo' => 'x', 'descricao' => 'x', 'dados_snapshot' => [], 'gerado_em' => now(),
        ]);

        $criado = CandidatoLicaoAprendida::create([
            'obra_id' => $outraObra->id, 'tipo' => TipoCandidatoLicaoAprendida::RestricaoRelevante->value,
            'chave_logica' => $chaveFixa, 'status' => StatusCandidatoLicaoAprendida::Pendente->value,
            'entidade_tipo' => TipoEntidadeVinculoLicao::Atividade->value, 'entidade_id' => $atividadeB->id,
            'titulo' => 'y', 'descricao' => 'y', 'dados_snapshot' => [], 'gerado_em' => now(),
        ]);

        $this->assertSame(2, CandidatoLicaoAprendida::count());
        $this->assertNotNull($criado->id);
    }

    public function test_j3_cross_obra_bloqueia_descarte_e_conversao(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $outraObra->id]);
        // Usuário só tem vínculo com $this->obra, nunca $outraObra.
        $candidato = CandidatoLicaoAprendida::create([
            'obra_id' => $outraObra->id, 'tipo' => TipoCandidatoLicaoAprendida::RestricaoRelevante->value,
            'chave_logica' => 'restricao_relevante:x', 'status' => StatusCandidatoLicaoAprendida::Pendente->value,
            'entidade_tipo' => TipoEntidadeVinculoLicao::Atividade->value, 'entidade_id' => $atividade->id,
            'titulo' => 'x', 'descricao' => 'x', 'dados_snapshot' => [], 'gerado_em' => now(),
        ]);

        $c = Livewire::test('pages::gestao.licoes-aprendidas');
        $c->call('abrirDescarte', $candidato->id);
        $this->assertNotNull($c->get('erroCandidatos'));

        $c->set('erroCandidatos', null)->call('iniciarConversao', $candidato->id);
        $this->assertNotNull($c->get('erroCandidatos'));

        $this->assertSame(StatusCandidatoLicaoAprendida::Pendente, $candidato->fresh()->status);
    }

    // =========================================================================
    // K) AUTORIZAÇÃO
    // =========================================================================

    public function test_k1_usuario_sem_permissao_nao_ve_botao_gerar(): void
    {
        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($semPermissao);
        \App\Support\ObraContext::set($this->obra);

        Livewire::test('pages::gestao.licoes-aprendidas')
            ->set('abaAtiva', 'revisao')
            ->assertDontSee('Atualizar candidatos');
    }

    public function test_k2_usuario_sem_permissao_de_gerar_e_bloqueado_no_backend(): void
    {
        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($semPermissao);
        \App\Support\ObraContext::set($this->obra);

        $c = Livewire::test('pages::gestao.licoes-aprendidas')->call('atualizarCandidatos');

        $this->assertNotNull($c->get('erroCandidatos'));
        $this->assertSame(0, CandidatoLicaoAprendida::count());
    }

    public function test_k3_encarregado_consegue_converter_mas_nao_excluir_e_gerentePlanejamento_pode_ambos(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);

        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $candidato = $this->candidatoAvulso($atividade);

        $this->actingAs($encarregado);
        $this->assertTrue($encarregado->can('converterCandidato', $candidato));
        $this->assertFalse($encarregado->can('descartarCandidato', $candidato));

        $this->assertTrue($this->user->can('descartarCandidato', $candidato));
    }

    // =========================================================================
    // L) PERFORMANCE
    // =========================================================================

    public function test_l1_geracao_nao_tem_n_mais_1_evidente_obra_pequena_vs_grande(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-05'));
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->gerar->execute($this->obra);
        $queriesObraPequena = count(DB::getQueryLog());
        DB::flushQueryLog();

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);
        for ($i = 0; $i < 40; $i++) {
            $this->restricaoComDuracao(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-05'), obra: $outraObra);
        }
        DB::flushQueryLog();
        $this->gerar->execute($outraObra);
        $queriesObraGrande = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Nunca aceitar N+1 evidente — 40 restrições não deveria multiplicar
        // o número de queries por 8x quando comparado a 5 restrições
        // (regra A é 1 query batch + 1 insert por candidato novo).
        $this->assertLessThan($queriesObraPequena * 8, $queriesObraGrande);
    }

    // =========================================================================
    // Helpers de fixture menores
    // =========================================================================

    private function candidatoAvulso(Atividade $atividade): CandidatoLicaoAprendida
    {
        return CandidatoLicaoAprendida::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCandidatoLicaoAprendida::RestricaoRelevante->value,
            'chave_logica' => 'restricao_relevante:'.\Illuminate\Support\Str::ulid(),
            'status' => StatusCandidatoLicaoAprendida::Pendente->value,
            'entidade_tipo' => TipoEntidadeVinculoLicao::Atividade->value,
            'entidade_id' => $atividade->id,
            'titulo' => 'Candidato avulso de teste',
            'descricao' => 'Descrição de teste.',
            'dados_snapshot' => [],
            'gerado_em' => now(),
        ]);
    }

    private function dadosMinimosForm(): array
    {
        return [
            'titulo' => 'Título de teste',
            'situacao_observada' => 'Situação observada de teste.',
            'recomendacao_futura' => 'Recomendação de teste.',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Alta->value,
            'area_funcional' => AreaFuncionalLicao::Planejamento->value,
        ];
    }
}
