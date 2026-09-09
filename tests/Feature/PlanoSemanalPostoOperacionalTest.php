<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\RegistrarEntradaEstoque;
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
use App\Enums\GranularidadePeriodo;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
use App\Enums\TipoCronogramaImportacao;
use App\Enums\TipoLocalEstoque;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\LinhaBase;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\ReservaEstoque;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Melhoria "Posto Operacional" — % previsto no período, Documentos de
 * Engenharia e Materiais para Execução no popup do Plano Semanal.
 */
class PlanoSemanalPostoOperacionalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;
    private string $semanaInicio;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-15')); // uma terça-feira

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        // Admin: tem engenharia.pacotes|editar E restricoes.plano_semanal|editar.
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
        $this->semanaInicio = Carbon::now()->startOfWeek()->toDateString();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function componente()
    {
        return Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra]);
    }

    private function atividadeNaSemana(array $overrides = []): Atividade
    {
        $inicioSemana = Carbon::parse($this->semanaInicio);

        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana->copy()->addDay(),
            'data_termino' => $inicioSemana->copy()->addDays(3),
        ], $overrides));
    }

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ], $overrides));
    }

    private function criarItemTakeOff(?Material $material, float $quantidade): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item',
            'unidade_medida_id' => $this->unidade->id,
            'quantidade' => $quantidade, 'material_id' => $material?->id,
        ]);
    }

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create([
            'obra_id' => $this->obra->id,
            'nome' => 'Local ' . uniqid(),
            'tipo' => TipoLocalEstoque::Almoxarifado->value,
            'ativo' => true,
        ]);
    }

    /** Cadeia comercial completa até Entrada física — mesmo helper já usado em outras suítes de Estoque. */
    private function entradaPronta(Material $material, LocalEstoque $local, float $quantidade): void
    {
        $item = $this->criarItemTakeOff($material, $quantidade * 10);
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);

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

    // =========================================================
    // % PREVISTO NO PERÍODO (Seção 11)
    // =========================================================

    public function test_a_percentual_previsto_no_periodo_e_incremental_nao_acumulado(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Curva']);

        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(30),
        ]);
        LinhaBase::create(['obra_id' => $this->obra->id, 'nome' => 'BL01', 'cronograma_importacao_id' => $importacao->id]);

        $semanaAnterior = Carbon::parse($this->semanaInicio)->subWeek();
        // Semana anterior: 50h. Semana selecionada: 60h. Total (só essas
        // 2): 110h. Previsto acumulado no INÍCIO da semana selecionada =
        // 50/110 = 45,45%; no FIM = 110/110 = 100%. Incremento = 60/110 = 54,55%.
        AvancoPeriodo::create([
            'tenant_id' => $this->obra->tenant_id, 'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $at->id, 'granularidade' => GranularidadePeriodo::Semanal->value,
            'serie' => SerieAvanco::Previsto->value, 'periodo_inicio' => $semanaAnterior, 'horas' => 50,
        ]);
        AvancoPeriodo::create([
            'tenant_id' => $this->obra->tenant_id, 'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $at->id, 'granularidade' => GranularidadePeriodo::Semanal->value,
            'serie' => SerieAvanco::Previsto->value, 'periodo_inicio' => Carbon::parse($this->semanaInicio), 'horas' => 60,
        ]);

        $componente = $this->componente()->call('verAtividadeDetalhe', $at->id);
        $previsto = $componente->instance()->percentualPrevistoPeriodoPopup;

        $this->assertEqualsWithDelta(54.55, $previsto['percentual_previsto_periodo'], 0.1);
        $this->assertEqualsWithDelta(100.0, $previsto['percentual_previsto_acumulado'], 0.1);
        $this->assertNotEqualsWithDelta($previsto['percentual_previsto_periodo'], $previsto['percentual_previsto_acumulado'], 0.1);
    }

    public function test_a2_sem_dado_phased_mostra_traco_nunca_inventa_distribuicao_linear(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Sem Curva']);

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertSee('Sem dados de HH previsto');
    }

    // =========================================================
    // DOCUMENTOS DE ENGENHARIA (Seção 12)
    // =========================================================

    public function test_b_vincular_documento_pelo_popup(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Vinculo Doc']);
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-100', 'descricao' => 'Projeto Executivo']);

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->set('buscaDocumentoVincular', 'DOC-100')
            ->assertSee('Projeto Executivo')
            ->call('vincularDocumento', $doc->id);

        $this->assertTrue($at->documentosEngenharia()->where('documento_engenharia_id', $doc->id)->exists());
    }

    public function test_c_desvincular_documento_pelo_popup_recalcula_liberacao(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Doc Bloqueante']);
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-200', 'descricao' => 'Sem revisão ainda']);
        $at->documentosEngenharia()->attach($doc->id);

        $this->assertFalse($at->fresh()->estaPronta());

        $componente = $this->componente()->call('verAtividadeDetalhe', $at->id);
        $componente->assertSee('Bloqueada');

        $componente->call('desvincularDocumento', $doc->id);

        $this->assertFalse($at->documentosEngenharia()->where('documento_engenharia_id', $doc->id)->exists());
        $this->assertTrue($at->fresh()->estaPronta());
        $componente->assertSee('Liberada');
    }

    public function test_d_usuario_sem_permissao_engenharia_nao_ve_acoes_de_documento(): void
    {
        $at = $this->atividadeNaSemana();
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-300', 'descricao' => 'D']);
        $at->documentosEngenharia()->attach($doc->id);

        // GerentePlanejamento tem restricoes.plano_semanal|editar, mas
        // NUNCA engenharia.pacotes|editar (Admin-only) — mesma Policy
        // já usada em ⚡documentos-engenharia.blade.php, nunca uma nova
        // mais permissiva inventada aqui.
        $gerente = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $gerente, Papel::GerentePlanejamento->value);
        $this->actingAs($gerente);

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertDontSee('Buscar documento por código');

        $this->componente()
            ->call('desvincularDocumento', $doc->id)
            ->assertForbidden();
    }

    // =========================================================
    // MATERIAIS PARA EXECUÇÃO (Seções 6/7/9/13)
    // =========================================================

    public function test_e_atividade_sem_necessidade_mostra_mensagem_vazia(): void
    {
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertSee('Nenhuma necessidade de material cadastrada');
    }

    public function test_f_adicionar_material_origem_take_off_pelo_popup(): void
    {
        $at = $this->atividadeNaSemana();
        $item = $this->criarItemTakeOff(null, 1000);

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'take_off')
            ->set('buscaItemTakeOffNecessidade', $item->codigo)
            ->set('itemTakeOffIdNovaNecessidade', $item->id)
            ->set('quantidadeNovaNecessidade', 300)
            ->call('salvarNecessidade');

        $this->assertDatabaseHas('atividade_necessidades_material', [
            'atividade_id' => $at->id,
            'item_take_off_id' => $item->id,
            'origem' => 'take_off',
        ]);
    }

    public function test_g_adicionar_material_origem_operacional_exige_justificativa(): void
    {
        $at = $this->atividadeNaSemana();
        $material = $this->criarMaterial();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->set('materialIdNovaNecessidade', $material->id)
            ->set('quantidadeNovaNecessidade', 5)
            ->set('observacaoNovaNecessidade', '')
            ->call('salvarNecessidade')
            ->assertHasErrors(['observacaoNovaNecessidade']);

        $this->assertDatabaseCount('atividade_necessidades_material', 0);

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->set('materialIdNovaNecessidade', $material->id)
            ->set('quantidadeNovaNecessidade', 5)
            ->set('observacaoNovaNecessidade', 'Necessário pra reparo emergencial')
            ->call('salvarNecessidade');

        $this->assertDatabaseHas('atividade_necessidades_material', [
            'atividade_id' => $at->id, 'material_id' => $material->id, 'origem' => 'operacional',
        ]);
    }

    public function test_h_editar_quantidade_da_necessidade(): void
    {
        $at = $this->atividadeNaSemana();
        $material = $this->criarMaterial();
        $necessidade = app(AtualizarNecessidadeMaterialAtividade::class)->criarOperacional($at, $material, 10, 'Justificativa', $this->user);

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirEdicaoNecessidade', $necessidade->id)
            ->set('quantidadeNovaNecessidade', 25)
            ->call('salvarNecessidade');

        $this->assertSame(25.0, (float) $necessidade->fresh()->quantidade_necessaria);
    }

    public function test_i_remover_necessidade(): void
    {
        $at = $this->atividadeNaSemana();
        $material = $this->criarMaterial();
        $necessidade = app(AtualizarNecessidadeMaterialAtividade::class)->criarOperacional($at, $material, 10, 'Justificativa', $this->user);

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('removerNecessidade', $necessidade->id);

        $this->assertDatabaseMissing('atividade_necessidades_material', ['id' => $necessidade->id]);
    }

    public function test_j_reservar_agora_pelo_popup_cria_reserva_rotulada(): void
    {
        $at = $this->atividadeNaSemana();
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 120);

        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote da Atividade']);
        $at->itensSuprimento()->attach($pacote->id);

        $necessidade = app(AtualizarNecessidadeMaterialAtividade::class)->criarOperacional($at, $material, 100, 'Justificativa', $this->user);

        $componente = $this->componente()->call('verAtividadeDetalhe', $at->id);
        $componente->assertSee('Disponível para reserva');

        $componente
            ->call('abrirModalReservar', $necessidade->id)
            ->set('pacoteIdReserva', $pacote->id)
            ->set('localIdReserva', $local->id)
            ->set('quantidadeReserva', 100)
            ->call('confirmarReserva');

        $this->assertDatabaseHas('reservas_estoque', [
            'necessidade_atividade_id' => $necessidade->id,
            'quantidade' => 100,
        ]);
        $componente->assertSee('Coberta');
    }

    public function test_j2_reservar_nunca_ocorre_automaticamente_ao_abrir_o_popup(): void
    {
        $at = $this->atividadeNaSemana();
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 120);
        app(AtualizarNecessidadeMaterialAtividade::class)->criarOperacional($at, $material, 100, 'Justificativa', $this->user);

        $this->componente()->call('verAtividadeDetalhe', $at->id);

        $this->assertDatabaseCount('reservas_estoque', 0);
    }

    public function test_k_usuario_sem_permissao_plano_semanal_nao_pode_adicionar_material(): void
    {
        $at = $this->atividadeNaSemana();

        // ClienteLeitura nunca tem restricoes.plano_semanal|editar.
        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertDontSee('Adicionar Material');

        $this->componente()
            ->call('abrirModalNecessidade')
            ->assertForbidden();
    }

    // =========================================================
    // PPC NUNCA ALTERADO (Seção 20 / item 15 do pedido original)
    // =========================================================

    public function test_l_acoes_de_documento_e_material_nunca_alteram_ppc(): void
    {
        $comprometida = $this->atividadeNaSemana(['status' => StatusAtividade::Comprometido->value]);
        $at = $this->atividadeNaSemana();
        $material = $this->criarMaterial();
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-PPC', 'descricao' => 'D']);

        $componente = $this->componente();
        $ppcAntes = $componente->instance()->ppc;

        $componente
            ->call('verAtividadeDetalhe', $at->id)
            ->set('buscaDocumentoVincular', 'DOC-PPC')
            ->call('vincularDocumento', $doc->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->set('materialIdNovaNecessidade', $material->id)
            ->set('quantidadeNovaNecessidade', 5)
            ->set('observacaoNovaNecessidade', 'Justificativa qualquer')
            ->call('salvarNecessidade');

        $this->assertSame($ppcAntes, $componente->instance()->ppc);
    }

    // =========================================================
    // PERFORMANCE (Seção 15)
    // =========================================================

    public function test_m_popup_com_varias_necessidades_nao_gera_n_mais_1(): void
    {
        $atPoucas = $this->atividadeNaSemana(['nome' => 'Poucas Necessidades']);
        for ($i = 1; $i <= 2; $i++) {
            $mat = $this->criarMaterial();
            app(AtualizarNecessidadeMaterialAtividade::class)->criarOperacional($atPoucas, $mat, 10, "Justificativa {$i}", $this->user);
        }

        DB::enableQueryLog();
        $this->componente()->call('verAtividadeDetalhe', $atPoucas->id)->assertOk();
        $queriesPoucas = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $atMuitas = $this->atividadeNaSemana(['nome' => 'Muitas Necessidades']);
        for ($i = 1; $i <= 15; $i++) {
            $mat = $this->criarMaterial();
            app(AtualizarNecessidadeMaterialAtividade::class)->criarOperacional($atMuitas, $mat, 10, "Justificativa {$i}", $this->user);
        }

        DB::enableQueryLog();
        $this->componente()->call('verAtividadeDetalhe', $atMuitas->id)->assertOk();
        $queriesMuitas = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($queriesPoucas + 8, $queriesMuitas,
            "Esperado custo praticamente constante; poucas={$queriesPoucas} muitas={$queriesMuitas}");
    }

    // =========================================================
    // RODADA DE FECHAMENTO — validações 6/7/8 do prompt de
    // fechamento técnico (% previsto com 3 semanas, Documentos
    // liberado/cross-obra, Material nunca hard-block em cenários
    // combinados reais)
    // =========================================================

    /** Cria uma LinhaBase + N pontos de AvancoPeriodo Previsto (semanal) numa única importação. */
    private function criarPrevistoPhased(array $horasPorSemanaAPartirDe, Carbon $semanaInicial): void
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(30),
        ]);
        LinhaBase::create(['obra_id' => $this->obra->id, 'nome' => 'BL-' . uniqid(), 'cronograma_importacao_id' => $importacao->id]);

        $semana = $semanaInicial->copy();
        foreach ($horasPorSemanaAPartirDe as $horas) {
            if ($horas !== null) {
                AvancoPeriodo::create([
                    'tenant_id' => $this->obra->tenant_id, 'cronograma_importacao_id' => $importacao->id,
                    'atividade_id' => $this->atividadePrevistoAtual->id, 'granularidade' => GranularidadePeriodo::Semanal->value,
                    'serie' => SerieAvanco::Previsto->value, 'periodo_inicio' => $semana->copy(), 'horas' => $horas,
                ]);
            }
            $semana->addWeek();
        }
    }

    private Atividade $atividadePrevistoAtual;

    /**
     * Seção 6 — 3 semanas consecutivas (20h/30h/10h, total 60h). Verifica,
     * pra semana SELECIONADA (a última do trio), que incremental
     * (10/60=16,67%) e acumulado ((20+30+10)/60=100%) são NUMEROS
     * DIFERENTES e cada um aparece no rótulo certo — nunca confundidos
     * entre si (cobre os cenários A-D: a fórmula é sempre por PERÍODO
     * declarado em avanco_periodos, nunca uma distribuição linear
     * inventada a partir de inicio_planejado/data_termino da atividade).
     */
    public function test_n_tres_semanas_consecutivas_incremental_e_acumulado_nunca_se_confundem(): void
    {
        $this->atividadePrevistoAtual = $this->atividadeNaSemana(['nome' => 'Atividade 3 Semanas']);

        $semanaAnterior2 = Carbon::parse($this->semanaInicio)->subWeeks(2);
        $this->criarPrevistoPhased([20, 30, 10], $semanaAnterior2);

        $componente = $this->componente()->call('verAtividadeDetalhe', $this->atividadePrevistoAtual->id);
        $previsto = $componente->instance()->percentualPrevistoPeriodoPopup;

        $this->assertEqualsWithDelta(16.67, $previsto['percentual_previsto_periodo'], 0.1);
        $this->assertEqualsWithDelta(100.0, $previsto['percentual_previsto_acumulado'], 0.1);
        $this->assertNotEqualsWithDelta(
            $previsto['percentual_previsto_periodo'], $previsto['percentual_previsto_acumulado'], 5.0
        );
    }

    /**
     * Seção 6 (item F/gap) — atividade TEM dado phased (semanas antes e
     * depois), mas a semana SELECIONADA especificamente não tem nenhum
     * ponto — nunca inventa 0%/interpola: mostra "—" só pra essa métrica,
     * SEM disparar a mensagem de "sem dados de HH previsto" (que é
     * reservada pra ausência TOTAL de phased data, já coberta por
     * test_a2). As duas situações são visualmente e semanticamente
     * diferentes, nunca confundidas na UI.
     */
    public function test_o_semana_selecionada_sem_ponto_mostra_traco_sem_mensagem_de_ausencia_total(): void
    {
        $this->atividadePrevistoAtual = $this->atividadeNaSemana(['nome' => 'Atividade Com Gap']);

        $semanaAnterior = Carbon::parse($this->semanaInicio)->subWeek();
        $semanaSeguinte = Carbon::parse($this->semanaInicio)->addWeek();
        $this->criarPrevistoPhased([40, null, 60], $semanaAnterior);
        // índice 1 (a própria $this->semanaInicio) fica sem nenhuma linha —
        // criarPrevistoPhased() pula a criação quando $horas é null, mas
        // ainda avança a semana corretamente.

        $componente = $this->componente()->call('verAtividadeDetalhe', $this->atividadePrevistoAtual->id);
        $previsto = $componente->instance()->percentualPrevistoPeriodoPopup;

        $this->assertNull($previsto['percentual_previsto_periodo']);
        $this->assertTrue($previsto['tem_previsto']);

        $componente
            ->assertSee('—')
            ->assertDontSee('Sem dados de HH previsto');
    }

    /** Seção 7 — Documento LIBERADO nunca bloqueia estaPronta() nem aparece na lista de bloqueantes do popup. */
    public function test_p_documento_liberado_nao_bloqueia_e_nao_aparece_como_bloqueante(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Doc Liberado']);
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'DOC-LIB', 'descricao' => 'Projeto Liberado']);
        $revisao = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $at->documentosEngenharia()->attach($doc->id);

        (new AlterarLiberacaoRevisaoDocumento())->liberar($revisao, $this->user);

        $this->assertTrue($at->fresh()->estaPronta());

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertSee('Liberada')
            ->assertSee('Projeto Liberado');
    }

    /** Seção 7 — Documento de OUTRA obra nunca aparece na busca de vínculo e nunca pode ser vinculado por payload manipulado. */
    public function test_q_documento_de_outra_obra_nao_e_vinculavel(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::Admin->value);
        $docOutraObra = DocumentoEngenharia::create(['obra_id' => $outraObra->id, 'codigo' => 'DOC-EXTERNO', 'descricao' => 'De Outra Obra']);

        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->set('buscaDocumentoVincular', 'DOC-EXTERNO')
            ->assertDontSee('De Outra Obra');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('vincularDocumento', $docOutraObra->id);
    }

    /**
     * Seção 8 — cenário combinado 1: atividade JÁ liberada (sem restrição/
     * documento/checklist pendente) recebe uma necessidade de material SEM
     * NENHUM estoque físico na obra (déficit máximo) — continua Liberada
     * (Material é dimensão operacional separada, nunca hard-block), mas o
     * popup mostra claramente o risco material ("Sem estoque").
     */
    public function test_r_atividade_liberada_com_material_sem_estoque_continua_liberada_com_risco_visivel(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Liberada Sem Estoque']);
        $material = $this->criarMaterial();

        $this->assertTrue($at->fresh()->estaPronta());

        app(AtualizarNecessidadeMaterialAtividade::class)->criarOperacional($at, $material, 500, 'Necessário, sem estoque ainda', $this->user);

        $this->assertTrue($at->fresh()->estaPronta());

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertSee('Liberada')
            ->assertSee('Sem estoque');
    }

    /**
     * Seção 8 — cenário combinado 2: atividade BLOQUEADA por Restrição
     * bloqueante em aberto, com a necessidade de material TOTALMENTE
     * COBERTA (reservado_atividade >= necessário) — continua Bloqueada.
     * Material coberto nunca "libera" uma atividade travada por outra
     * dimensão (checklist/documento/restrição).
     */
    public function test_s_atividade_bloqueada_por_restricao_com_material_coberto_continua_bloqueada(): void
    {
        $at = $this->atividadeNaSemana(['nome' => 'Atividade Bloqueada Material Coberto']);
        $material = $this->criarMaterial();
        $local = $this->criarLocal();
        $this->entradaPronta($material, $local, 100);

        \App\Models\Restricao::create([
            'obra_id' => $this->obra->id, 'atividade_id' => $at->id,
            'descricao' => 'Impedimento real', 'bloqueante' => true,
            'status' => 'aberta', 'aberta_em' => now(),
        ]);

        $this->assertFalse($at->fresh()->estaPronta());

        $necessidade = app(AtualizarNecessidadeMaterialAtividade::class)->criarOperacional($at, $material, 100, 'Necessário', $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Coberto']);
        (new CriarReservaEstoque())->execute($pacote, $material, $local, 100, usuario: $this->user, necessidade: $necessidade);

        $this->assertFalse($at->fresh()->estaPronta());

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->assertSee('Bloqueada')
            ->assertSee('Coberta');
    }

    /** Seção 1 — material_id de outro tenant, manipulado no payload, nunca é aceito (protegido de graça pelo global scope de BelongsToTenant, nenhuma regra nova). */
    public function test_t_material_de_outro_tenant_via_payload_manipulado_e_rejeitado(): void
    {
        $outroTenant = Tenant::factory()->create();
        $materialOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $u = UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'MX', 'nome' => 'Metro X']);

            return Material::create([
                'tenant_id' => $outroTenant->id, 'codigo' => 'MAT-X', 'descricao' => 'Material de outro tenant',
                'unidade_medida_id' => $u->id, 'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
            ]);
        });

        $at = $this->atividadeNaSemana();

        // Material::findOrFail() dentro de salvarNecessidade() já é
        // tenant-scoped pelo global scope de BelongsToTenant — nenhuma
        // segunda regra de autorização — então o ID de outro tenant
        // nunca é encontrado e a ModelNotFoundException propaga (mesmo
        // padrão já documentado no projeto pra chamadas de método
        // Livewire: não vira erro de formulário gracioso, mas o dado
        // nunca é escrito).
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->set('materialIdNovaNecessidade', $materialOutroTenant->id)
            ->set('quantidadeNovaNecessidade', 5)
            ->set('observacaoNovaNecessidade', 'Justificativa qualquer')
            ->call('salvarNecessidade');
    }
}
