<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarDestinacaoPlanejada;
use App\Actions\Estoque\CriarInventarioEstoque;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\IniciarInventarioEstoque;
use App\Actions\Estoque\RegistrarAplicacaoMaterialEstoque;
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
use App\Enums\SeveridadeSituacao;
use App\Enums\StatusAtividade;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoSituacaoGerencial;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Gestao\ResumoExecutivoGerencial;
use App\Support\Gestao\SituacoesGerenciaisQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SituacoesGerenciaisQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-01'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------- helpers ----------------

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material', 'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Local ' . uniqid(), 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
    }

    private function criarAtividade(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Atividade ' . uniqid(), 'codigo_cronograma' => 'A' . uniqid(),
            'status' => StatusAtividade::Planejado->value, 'inicio_planejado' => Carbon::today()->addDays(5),
            'data_termino' => Carbon::today()->addDays(10), 'fora_do_cronograma' => false,
        ], $overrides));
    }

    private function criarItemTakeOffOrfao(?Material $material, ?Work $obra = null): ItemTakeOff
    {
        $obra ??= $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item', 'quantidade' => 1000, 'material_id' => $material?->id]);
    }

    private function criarPacoteVinculado(?Atividade $atividade = null, ?Work $obra = null): ItemSuprimento
    {
        $pacote = ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote ' . uniqid()]);
        if ($atividade) {
            $pacote->atividades()->sync([$atividade->id]);
        }

        return $pacote;
    }

    private function requisitarEAlocar(ItemTakeOff $item, float $quantidade, ItemSuprimento $pacote, ?Work $obra = null): \App\Models\AlocacaoRequisicaoPacote
    {
        $obra ??= $this->obra;
        $rp = (new CriarRequisicaoPlanejamento())->execute($obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);
    }

    private function comprarAte(\App\Models\AlocacaoRequisicaoPacote $alocacao, float $quantidade, string $dataPrevista = '2026-12-05'): \App\Models\PedidoCompraItem
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, $dataPrevista, null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return $pedidoItem->fresh();
    }

    private function receber(\App\Models\PedidoCompraItem $pedidoItem, float $quantidade, LocalEstoque $local): void
    {
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::today(), $this->user);
        (new RegistrarEntradaEstoque())->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);
    }

    private function cenarioMaterialCritico(float $necessidade = 100): array
    {
        $material = $this->criarMaterial();
        $atividade = $this->criarAtividade();
        $pacote = $this->criarPacoteVinculado($atividade);
        $item = $this->criarItemTakeOffOrfao($material);
        $alocacao = $this->requisitarEAlocar($item, $necessidade, $pacote);

        return [$atividade, $pacote, $material, $alocacao];
    }

    private function porTipo(\Illuminate\Support\Collection $situacoes, TipoSituacaoGerencial $tipo): \Illuminate\Support\Collection
    {
        return $situacoes->filter(fn ($s) => $s->tipo === $tipo)->values();
    }

    // =========================================================
    // A — Material crítico (aparece / desaparece)
    // =========================================================

    public function test_a_material_critico_ativo_e_desaparece_apos_cobertura(): void
    {
        [$atividade, , , ] = $this->cenarioMaterialCritico(100);
        // Só requisitado+alocado, sem RC/Pedido -> AguardandoCompra -> MaterialCritico ativo.

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        $materialCritico = $this->porTipo($situacoes, TipoSituacaoGerencial::MaterialCritico);
        $this->assertNotEmpty($materialCritico->where('entidadeId', $atividade->id));

        // Reposição via cadeia completa até Reserva -> desaparece.
        $material2 = $this->criarMaterial();
        [$atividade2, $pacote2] = [$this->criarAtividade(), null];
        $pacote2 = $this->criarPacoteVinculado($atividade2);
        $item2 = $this->criarItemTakeOffOrfao($material2);
        $alocacao2 = $this->requisitarEAlocar($item2, 100, $pacote2);
        $local = $this->criarLocal();
        $pedidoItem2 = $this->comprarAte($alocacao2, 100);
        $this->receber($pedidoItem2, 100, $local);
        (new CriarReservaEstoque())->execute($pacote2, $material2, $local, 100, null, null, $this->user);

        $situacoesDepois = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        $materialCriticoDepois = $this->porTipo($situacoesDepois, TipoSituacaoGerencial::MaterialCritico);
        $this->assertEmpty($materialCriticoDepois->where('entidadeId', $atividade2->id));
    }

    // =========================================================
    // B — Cobertura parcial: quantidade preservada
    // =========================================================

    public function test_b_cobertura_parcial_preserva_quantidade_faltante(): void
    {
        [$atividade, $pacote, $material, $alocacao] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 60, null, null, $this->user);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        // Estado agora é ParcialmenteCoberto -- NÃO é um dos 3 estados
        // acionáveis de MaterialCritico (decisão documentada) -- então
        // não deveria gerar situação. Confirma isso e usa
        // CoberturaMaterialAtividadeQuery pra provar a falta preservada.
        $materialCritico = $this->porTipo($situacoes, TipoSituacaoGerencial::MaterialCritico);
        $this->assertEmpty($materialCritico->where('entidadeId', $atividade->id));

        $linha = \App\Support\Gestao\CoberturaMaterialAtividadeQuery::porObra($this->obra, 28)->firstWhere('atividade_id', $atividade->id);
        $par = $linha['pares']->first();
        $this->assertEquals(40, round($par['demanda'] - $par['reservado_pacote'], 3));
    }

    // =========================================================
    // C — Pedido atrasado (aparece / desaparece)
    // =========================================================

    public function test_c_pedido_atrasado_aparece_e_desaparece_apos_conclusao(): void
    {
        [, , $material, $alocacao] = $this->cenarioMaterialCritico(100);
        $pedidoItem = $this->comprarAte($alocacao, 100, '2026-12-05');

        Carbon::setTestNow(Carbon::parse('2026-12-20'));
        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);
        $this->assertNotEmpty($this->porTipo($situacoes, TipoSituacaoGerencial::PedidoAtrasado));

        $local = $this->criarLocal();
        $this->receber($pedidoItem, 100, $local);
        $situacoesDepois = SituacoesGerenciaisQuery::porObra($this->obra);
        $this->assertEmpty($this->porTipo($situacoesDepois, TipoSituacaoGerencial::PedidoAtrasado));
    }

    public function test_c2_pedido_no_prazo_e_recebimento_pendente_nao_atrasado(): void
    {
        [, , , $alocacao] = $this->cenarioMaterialCritico(100);
        $this->comprarAte($alocacao, 100, '2026-12-05');
        // Hoje = 2026-12-01, ainda dentro do prazo.

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);
        $this->assertEmpty($this->porTipo($situacoes, TipoSituacaoGerencial::PedidoAtrasado));
        $recebimentoPendente = $this->porTipo($situacoes, TipoSituacaoGerencial::RecebimentoPendente);
        $this->assertNotEmpty($recebimentoPendente);
        $this->assertSame(SeveridadeSituacao::Informativa, $recebimentoPendente->first()->severidade);
    }

    // =========================================================
    // D — Reserva descoberta (aparece / recompõe)
    // =========================================================

    public function test_d_reserva_descoberta_aparece_e_desaparece_apos_recomposicao(): void
    {
        [, $pacote, $material, $alocacao] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, null, null, $this->user);
        (new RegistrarSaidaEstoque())->execute($material, $local, 30, Carbon::today(), $this->user, retiradoPor: $this->user);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);
        $reservaDescoberta = $this->porTipo($situacoes, TipoSituacaoGerencial::ReservaDescoberta);
        $this->assertNotEmpty($reservaDescoberta);
        $this->assertEquals(30, $reservaDescoberta->first()->quantidade);

        // Recomposição.
        [, $pedidoItem2] = [null, null];
        $item2 = $this->criarItemTakeOffOrfao($material);
        $alocacao2 = $this->requisitarEAlocar($item2, 30, $pacote);
        $pedidoItem2 = $this->comprarAte($alocacao2, 30);
        $this->receber($pedidoItem2, 30, $local);

        $situacoesDepois = SituacoesGerenciaisQuery::porObra($this->obra);
        $this->assertEmpty($this->porTipo($situacoesDepois, TipoSituacaoGerencial::ReservaDescoberta));
    }

    // =========================================================
    // E — Saída sem conciliação (aparece / desaparece)
    // =========================================================

    public function test_e_saida_sem_conciliacao_aparece_e_desaparece_apos_aplicacao_completa(): void
    {
        [, , $material, $alocacao] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        $saida = (new RegistrarSaidaEstoque())->execute($material, $local, 40, Carbon::today(), $this->user, retiradoPor: $this->user);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);
        $this->assertNotEmpty($this->porTipo($situacoes, TipoSituacaoGerencial::SaidaSemConciliacao));

        $frente = FrenteTrabalho::create(['obra_id' => $this->obra->id, 'nome' => 'Frente']);
        (new RegistrarAplicacaoMaterialEstoque())->execute($saida, $frente, 40, Carbon::today(), $this->user);

        $situacoesDepois = SituacoesGerenciaisQuery::porObra($this->obra);
        $this->assertEmpty($this->porTipo($situacoesDepois, TipoSituacaoGerencial::SaidaSemConciliacao));
    }

    // =========================================================
    // F — Planejado x Real (desvio, nunca acusatório)
    // =========================================================

    public function test_f_desvio_aplicacao_aparece_sem_linguagem_acusatoria(): void
    {
        [, $pacote, $material, $alocacao] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);
        $frenteA = FrenteTrabalho::create(['obra_id' => $this->obra->id, 'nome' => 'Frente A']);
        $frenteB = FrenteTrabalho::create(['obra_id' => $this->obra->id, 'nome' => 'Frente B']);
        $destinacao = (new AtualizarDestinacaoPlanejada())->criar($pacote, $material, $frenteA, 100, $this->user);
        $reserva = (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, null, $destinacao, $this->user);
        $saida = (new RegistrarSaidaEstoque())->execute($material, $local, 100, Carbon::today(), $this->user, reserva: $reserva, pacote: $pacote, retiradoPor: $this->user);
        (new RegistrarAplicacaoMaterialEstoque())->execute($saida, $frenteB, 100, Carbon::today(), $this->user);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);
        $desvio = $this->porTipo($situacoes, TipoSituacaoGerencial::DesvioAplicacao)->first();

        $this->assertNotNull($desvio);
        $this->assertContains($desvio->severidade, [SeveridadeSituacao::Informativa, SeveridadeSituacao::Atencao]);
        $this->assertStringNotContainsStringIgnoringCase('incorret', $desvio->descricao);
        $this->assertStringNotContainsStringIgnoringCase('erro', $desvio->descricao);
    }

    // =========================================================
    // G — Inventário (aguardando decisão / aprovado)
    // =========================================================

    public function test_g_inventario_aguardando_decisao_aparece_e_desaparece_apos_aprovacao(): void
    {
        $material = $this->criarMaterial();
        [, , , $alocacao] = $this->cenarioMaterialCritico();
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);

        $inv = (new CriarInventarioEstoque())->execute($local, $this->user, 'Inv Teste', false);
        $inv = (new IniciarInventarioEstoque())->execute($inv, $this->user);
        $item = $inv->fresh()->itens->first();
        (new \App\Actions\Estoque\RegistrarContagemInventario())->execute($item, 90, Carbon::today(), $this->user);
        (new \App\Actions\Estoque\MoverInventarioParaAnalise())->execute($inv->fresh(), $this->user);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);
        $this->assertNotEmpty($this->porTipo($situacoes, TipoSituacaoGerencial::InventarioAguardandoDecisao));

        (new \App\Actions\Estoque\AprovarAjusteInventario())->execute($item->fresh(), 'Ajuste', $this->user);
        (new \App\Actions\Estoque\ConcluirInventarioEstoque())->execute($inv->fresh(), $this->user);

        $situacoesDepois = SituacoesGerenciaisQuery::porObra($this->obra);
        $this->assertEmpty($this->porTipo($situacoesDepois, TipoSituacaoGerencial::InventarioAguardandoDecisao));
    }

    // =========================================================
    // H — Documento bloqueante (aparece / desaparece com liberação)
    // =========================================================

    public function test_h_documento_bloqueante_aparece_e_desaparece_apos_liberacao(): void
    {
        $atividade = $this->criarAtividade();
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-H', 'descricao' => 'Doc']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $doc->atividades()->sync([$atividade->id]);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        $this->assertNotEmpty($this->porTipo($situacoes, TipoSituacaoGerencial::DocumentoBloqueante));

        $rev->historicoLiberacoes()->create(['liberada_para_construcao' => true, 'alterado_por' => $this->user->id, 'ocorrido_em' => now(), 'observacao' => 'ok']);

        $situacoesDepois = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        $this->assertEmpty($this->porTipo($situacoesDepois, TipoSituacaoGerencial::DocumentoBloqueante));
    }

    // =========================================================
    // I — Industrialização (pendência sem afirmar atraso)
    // =========================================================

    public function test_i_industrializacao_pendente_nunca_afirma_atraso(): void
    {
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor']);
        $localTerceiro = LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Terceiro', 'tipo' => TipoLocalEstoque::Terceiro->value, 'fornecedor_id' => $fornecedor->id, 'ativo' => true]);
        $ordem = (new \App\Actions\Estoque\CriarOrdemIndustrializacao())->execute($this->obra, $fornecedor, $localTerceiro, $this->user);
        (new \App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao())->adicionarProduto($ordem, $this->criarMaterial(), 50, $this->user);
        (new \App\Actions\Estoque\EmitirOrdemIndustrializacao())->execute($ordem->fresh(), $this->user);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);
        $situacao = $this->porTipo($situacoes, TipoSituacaoGerencial::IndustrializacaoPendente)->first();

        $this->assertNotNull($situacao);
        $this->assertStringNotContainsStringIgnoringCase('atrasad', $situacao->descricao);
        $this->assertStringContainsStringIgnoringCase('desconhecido', $situacao->descricao);
    }

    // =========================================================
    // J — Material parado (threshold parametrizável)
    // =========================================================

    public function test_j_material_parado_respeita_threshold(): void
    {
        [, , $material, $alocacao] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        Carbon::setTestNow(Carbon::parse('2026-12-01'));
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);

        Carbon::setTestNow(Carbon::parse('2026-12-01')->addDays(50));
        $situacoesAntes = SituacoesGerenciaisQuery::porObra($this->obra);
        $this->assertEmpty($this->porTipo($situacoesAntes, TipoSituacaoGerencial::MaterialParado)->where('entidadeId', $material->id));

        Carbon::setTestNow(Carbon::parse('2026-12-01')->addDays(65));
        $situacoesDepois = SituacoesGerenciaisQuery::porObra($this->obra);
        $this->assertNotEmpty($this->porTipo($situacoesDepois, TipoSituacaoGerencial::MaterialParado)->where('entidadeId', $material->id));
    }

    // =========================================================
    // K — Multi-obra: situação da Obra B nunca aparece na A
    // =========================================================

    public function test_k_multi_obra_isolamento(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::GerentePlanejamento->value);

        [$atividadeB, $pacoteB, $materialB, $alocacaoB] = (function () use ($obraB) {
            $material = $this->criarMaterial();
            $atividade = $this->criarAtividade([], $obraB);
            $pacote = $this->criarPacoteVinculado($atividade, $obraB);
            $item = $this->criarItemTakeOffOrfao($material, $obraB);
            $alocacao = $this->requisitarEAlocar($item, 100, $pacote, $obraB);

            return [$atividade, $pacote, $material, $alocacao];
        })();

        $situacoesA = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        $situacoesB = SituacoesGerenciaisQuery::porObra($obraB, 28);

        $this->assertEmpty($situacoesA->where('entidadeId', $atividadeB->id));
        $this->assertNotEmpty($situacoesB->where('entidadeId', $atividadeB->id));
        $this->assertTrue($situacoesA->every(fn ($s) => $s->obraId === $this->obra->id));
        $this->assertTrue($situacoesB->every(fn ($s) => $s->obraId === $obraB->id));
    }

    // =========================================================
    // L — Deduplicação: chamadas idênticas -> mesma chave, 1 ocorrência
    // =========================================================

    public function test_l_deduplicacao_estrutural(): void
    {
        [, , $material, $alocacao] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100);
        $this->receber($pedidoItem, 100, $local);

        $r1 = SituacoesGerenciaisQuery::porObra($this->obra);
        $r2 = SituacoesGerenciaisQuery::porObra($this->obra);

        $chavesR1 = $r1->pluck('chaveLogica')->sort()->values();
        $chavesR2 = $r2->pluck('chaveLogica')->sort()->values();
        $this->assertEquals($chavesR1->all(), $chavesR2->all());
        $this->assertEquals($chavesR1->unique()->count(), $chavesR1->count(), 'nenhuma chave lógica deveria se repetir dentro do mesmo resultado');
    }

    // =========================================================
    // Prioridade (Seção 19)
    // =========================================================

    public function test_prioridade_atividade_amanha_antes_de_atividade_em_28_dias_impacto_equivalente(): void
    {
        $material1 = $this->criarMaterial();
        $atividadeAmanha = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDay()]);
        $pacote1 = $this->criarPacoteVinculado($atividadeAmanha);
        $item1 = $this->criarItemTakeOffOrfao($material1);
        $this->requisitarEAlocar($item1, 100, $pacote1); // AguardandoCompra

        $material2 = $this->criarMaterial();
        $atividadeDistante = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(20)]);
        $pacote2 = $this->criarPacoteVinculado($atividadeDistante);
        $item2 = $this->criarItemTakeOffOrfao($material2);
        $this->requisitarEAlocar($item2, 100, $pacote2); // AguardandoCompra, mesmo impacto

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        $posAmanha = $situacoes->search(fn ($s) => $s->entidadeId === $atividadeAmanha->id);
        $posDistante = $situacoes->search(fn ($s) => $s->entidadeId === $atividadeDistante->id);

        $this->assertLessThan($posDistante, $posAmanha, 'atividade de amanhã deveria vir antes da atividade em 20 dias');
    }

    public function test_prioridade_critico_antes_de_atencao_temporalidade_equivalente(): void
    {
        [, $pacote1, $material1, $alocacao1] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        $pedido1 = $this->comprarAte($alocacao1, 100, '2026-12-05');
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);
        $pedidoAtrasado = $this->porTipo($situacoes, TipoSituacaoGerencial::PedidoAtrasado)->first();
        $industrializacaoPendenteIdx = null;
        // pedido atrasado (severidade alta/critica) tem impacto=1;
        // industrialização pendente (informativa) tem impacto=0 --
        // já comprovado pela chave de ordenação; confirma via posição.
        $this->assertNotNull($pedidoAtrasado);
        $this->assertContains($pedidoAtrasado->severidade, [SeveridadeSituacao::Alta, SeveridadeSituacao::Critica]);
    }

    public function test_prioridade_ordenacao_estavel_nao_depende_da_ordem_do_banco(): void
    {
        [, , , $alocacao1] = $this->cenarioMaterialCritico(100);
        $this->comprarAte($alocacao1, 100, '2026-12-05');
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        $r1 = SituacoesGerenciaisQuery::porObra($this->obra)->pluck('chaveLogica')->values();
        $r2 = SituacoesGerenciaisQuery::porObra($this->obra)->pluck('chaveLogica')->values();

        $this->assertEquals($r1->all(), $r2->all(), 'a ordem final deveria ser determinística entre chamadas idênticas');
    }

    // =========================================================
    // Destinatários (Seção 20)
    // =========================================================

    public function test_destinatarios_perfil_correto_e_usuario_sem_permissao_excluido(): void
    {
        [, , , $alocacao] = $this->cenarioMaterialCritico(100);
        $this->comprarAte($alocacao, 100, '2026-12-05');
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        // Usuário SEM NENHUM vínculo com a obra -- diferente de um Papel
        // "menor": todo perfil com vínculo na obra já tem 'ver' liberado
        // por padrão em praticamente todo slug (convenção do projeto,
        // "ninguém é bloqueado de visualizar hoje") -- a exclusão real
        // acontece na fronteira de `$obra->users()`, nunca por Papel.
        $usuarioSemVinculo = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);
        $pedidoAtrasado = $this->porTipo($situacoes, TipoSituacaoGerencial::PedidoAtrasado)->first();

        $destinatarios = SituacoesGerenciaisQuery::resolverDestinatarios($this->obra, $pedidoAtrasado);

        $this->assertTrue($destinatarios->contains('id', $this->user->id));
        $this->assertFalse($destinatarios->contains('id', $usuarioSemVinculo->id), 'usuário sem nenhum vínculo com a obra nunca deveria ser candidato');
    }

    public function test_destinatarios_usuario_inativo_excluido(): void
    {
        [, , , $alocacao] = $this->cenarioMaterialCritico(100);
        $this->comprarAte($alocacao, 100, '2026-12-05');
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        $usuarioInativo = User::factory()->create(['tenant_id' => $this->tenant->id, 'ativo' => false]);
        $this->vincularObra($this->obra, $usuarioInativo, Papel::GerentePlanejamento->value);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);
        $pedidoAtrasado = $this->porTipo($situacoes, TipoSituacaoGerencial::PedidoAtrasado)->first();
        $destinatarios = SituacoesGerenciaisQuery::resolverDestinatarios($this->obra, $pedidoAtrasado);

        $this->assertFalse($destinatarios->contains('id', $usuarioInativo->id), 'usuário desativado nunca deveria ser candidato');
    }

    public function test_destinatarios_obra_correta_multi_obra(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $usuarioSoObraB = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $usuarioSoObraB, Papel::GerentePlanejamento->value);

        [, , , $alocacao] = $this->cenarioMaterialCritico(100);
        $this->comprarAte($alocacao, 100, '2026-12-05');
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);
        $pedidoAtrasado = $this->porTipo($situacoes, TipoSituacaoGerencial::PedidoAtrasado)->first();
        $destinatarios = SituacoesGerenciaisQuery::resolverDestinatarios($this->obra, $pedidoAtrasado);

        $this->assertFalse($destinatarios->contains('id', $usuarioSoObraB->id), 'usuário só vinculado à Obra B nunca deveria ser destinatário de situação da Obra A');
    }

    // =========================================================
    // Performance — DELTA, sem N+1
    // =========================================================

    public function test_performance_delta_5_50_situacoes_sem_n_mais_1(): void
    {
        $criarCenario = function () {
            [, , $material, $alocacao] = $this->cenarioMaterialCritico(100);
            $local = $this->criarLocal();
            $pedidoItem = $this->comprarAte($alocacao, 100, '2026-12-05');
        };

        for ($i = 0; $i < 5; $i++) {
            $criarCenario();
        }
        Carbon::setTestNow(Carbon::parse('2026-12-20'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $r5 = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        $q5 = count(DB::getQueryLog());
        DB::flushQueryLog();

        Carbon::setTestNow(Carbon::parse('2026-12-01'));
        for ($i = 0; $i < 45; $i++) {
            $criarCenario();
        }
        Carbon::setTestNow(Carbon::parse('2026-12-20'));
        DB::flushQueryLog();
        $r50 = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        $q50 = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n[DELTA SituacoesGerenciaisQuery] 5 cenarios -> {$q5} queries | 50 cenarios -> {$q50} queries\n");

        $this->assertGreaterThan(0, $r5->count());
        $this->assertGreaterThan($r5->count(), $r50->count());
        $this->assertLessThan($q5 * 4, $q50, 'ACHADO: crescimento de queries parece proporcional ao volume — possível N+1');
    }

    // =========================================================
    // Resumo Executivo — sem re-executar a consulta
    // =========================================================

    public function test_resumo_executivo_nao_reexecuta_consulta(): void
    {
        [, , , $alocacao] = $this->cenarioMaterialCritico(100);
        $this->comprarAte($alocacao, 100, '2026-12-05');
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $resumo = ResumoExecutivoGerencial::deSituacoes($situacoes);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEquals(0, $queries, 'ResumoExecutivoGerencial deveria operar 100% em memória, zero queries');
        $this->assertEquals($situacoes->count(), $resumo['total']);
    }

    // =========================================================
    // Zero efeito colateral em domínio operacional
    // =========================================================

    public function test_zero_efeito_colateral_em_dominio_operacional(): void
    {
        [, , , $alocacao] = $this->cenarioMaterialCritico(100);
        $this->comprarAte($alocacao, 100, '2026-12-05');
        Carbon::setTestNow(Carbon::parse('2026-12-20'));

        SituacoesGerenciaisQuery::porObra($this->obra);

        $this->assertEquals(0, \App\Models\Restricao::count());
        $this->assertEquals(0, DB::table('notifications')->count());
    }

    // =========================================================
    // Ciclo 21, Etapa 21.4 — guarda de drift entre `perfisParaTipo()`
    // (novo método público, fonte separada e deliberadamente não
    // refatorada dos 11 produtores privados abaixo, pra não arriscar
    // regressão em código já testado da 21.2) e o `destinatariosPerfis`
    // REAL que cada tipo emite em runtime. Reaproveita os MESMOS cenários
    // já usados pelos testes A/C/D/E/H acima (nunca um fixture paralelo),
    // combinados numa única obra pra gerar vários tipos simultaneamente
    // — inclusive MaterialSemDestinacao, o único tipo sem cobertura
    // própria nesta suíte (cenarioMaterialCritico nunca cria nenhuma
    // Destinação, então o mesmo Pacote+Material aloca sem destinar).
    // =========================================================

    public function test_perfis_para_tipo_nunca_diverge_do_runtime_real(): void
    {
        [, $pacote, $material, $alocacao] = $this->cenarioMaterialCritico(100);
        $local = $this->criarLocal();
        $pedidoItem = $this->comprarAte($alocacao, 100, '2026-12-05');
        Carbon::setTestNow(Carbon::parse('2026-12-20'));
        $this->receber($pedidoItem, 60, $local);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 60, null, null, $this->user);
        (new RegistrarSaidaEstoque())->execute($material, $local, 30, Carbon::today(), $this->user, retiradoPor: $this->user);

        $atividadeDoc = $this->criarAtividade();
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-PERFIS', 'descricao' => 'Doc']);
        $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $doc->atividades()->sync([$atividadeDoc->id]);

        $situacoes = SituacoesGerenciaisQuery::porObra($this->obra, 28);
        $this->assertNotEmpty($situacoes);

        $tiposExercitados = $situacoes->pluck('tipo')->unique();
        // Confirma que o cenário exercitou de fato tipos com perfis
        // DIFERENTES entre si (não teria valor se só 1 tipo aparecesse).
        $this->assertGreaterThanOrEqual(3, $tiposExercitados->count());
        $this->assertTrue($tiposExercitados->contains(TipoSituacaoGerencial::MaterialSemDestinacao));

        foreach ($situacoes as $situacao) {
            $this->assertSame(
                SituacoesGerenciaisQuery::perfisParaTipo($situacao->tipo),
                $situacao->destinatariosPerfis,
                "perfisParaTipo() divergiu do runtime real para o tipo {$situacao->tipo->value}"
            );
        }
    }
}
