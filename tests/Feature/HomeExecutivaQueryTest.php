<?php

namespace Tests\Feature;

use App\Actions\Estoque\CriarReservaEstoque;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\OrigemNecessidadeMaterialAtividade;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoMovimentacaoEstoque;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\CausaNaoCumprimento;
use App\Models\DocumentoEngenharia;
use App\Models\Fornecedor;
use App\Models\ItemProntidao;
use App\Models\ItemSuprimento;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\PedidoCompra;
use App\Models\PedidoCompraPrevisaoEntrega;
use App\Models\ProgramacaoSemanal;
use App\Models\ProgramacaoSemanalItem;
use App\Models\RequisicaoCompra;
use App\Models\Restricao;
use App\Models\RevisaoLiberacao;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Gestao\HomeExecutivaQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Home Executiva (Ciclo 25) — testes do read model
 * `App\Support\Gestao\HomeExecutivaQuery`. Escopo desta suíte, decidido
 * pelo orçamento de tempo desta entrega (documentado no relatório
 * final): cobre o Hero/horizontes, o ranking de ameaças, as causas por
 * domínio, o bloco de Suprimentos (Motor V1) nos estados mais
 * representativos, Engenharia × Execução, prontidão recuperável,
 * últimos acontecimentos, execução da semana/PPC, isolamento
 * multitenancy e um teste de performance por DELTA — não repete
 * exaustivamente todos os cenários já cobertos pela suíte de
 * `EstadoAtendimentoNecessidadeMaterialQuery`/`DecomposicaoQuantitativaNecessidadeTest`
 * (Motor V1 em si, intocado nesta etapa).
 */
class HomeExecutivaQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Work $obra;

    private UnidadeMedida $unidade;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-14')); // segunda-feira

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function criarAtividade(array $overrides = []): Atividade
    {
        return Atividade::create(array_merge([
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade ' . uniqid(),
            'codigo_cronograma' => 'A' . uniqid(),
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => Carbon::today()->addDays(5),
            'data_termino' => Carbon::today()->addDays(10),
            'fora_do_cronograma' => false,
        ], $overrides));
    }

    private function criarRestricaoBloqueante(Atividade $atividade, array $overrides = []): Restricao
    {
        return Restricao::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'descricao' => 'Restrição bloqueante ' . uniqid(),
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => Carbon::now(),
        ], $overrides));
    }

    private function criarDocumentoNaoLiberado(): DocumentoEngenharia
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'Documento']);
        $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'Emissão']);

        return $doc;
    }

    private function criarMaterial(): Material
    {
        return Material::create([
            'tenant_id' => $this->tenant->id,
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Material de teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
    }

    private function criarNecessidade(Atividade $atividade, Material $material, float $quantidade = 100.0): AtividadeNecessidadeMaterial
    {
        return AtividadeNecessidadeMaterial::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'atividade_id' => $atividade->id,
            'origem' => OrigemNecessidadeMaterialAtividade::Operacional->value,
            'material_id' => $material->id,
            'unidade_medida_id' => $this->unidade->id,
            'quantidade_necessaria' => $quantidade,
        ]);
    }

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Almoxarifado ' . uniqid(), 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
    }

    /**
     * Constrói um Pedido de Compra Emitido + histórico de previsão de
     * entrega, sem passar pela cadeia completa TakeOff→RP→Alocação→RC
     * (irrelevante pra testar só a leitura de `PedidoCompraPrevisaoEntrega`
     * pela Home) — cria as entidades intermediárias mínimas exigidas
     * pelas FKs (Pacote/RC/Fornecedor).
     */
    private function criarPedidoComHistoricoPrevisao(string $dataInicial, ?string $dataRevisada = null, ?Carbon $registradoEmRevisao = null): PedidoCompra
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $rc = RequisicaoCompra::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'item_suprimento_id' => $pacote->id, 'status' => 'emitida', 'numero' => random_int(1000, 999999),
            'emitida_em' => now(), 'emitida_por' => $this->user->id,
        ]);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor', 'cnpj' => '00.000.000/0001-00']);
        $pedido = PedidoCompra::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'requisicao_compra_id' => $rc->id, 'fornecedor_id' => $fornecedor->id,
            'numero' => random_int(1000, 999999), 'status' => 'emitido', 'data_prevista_entrega' => $dataInicial,
            'emitido_em' => now(), 'emitido_por' => $this->user->id,
        ]);

        PedidoCompraPrevisaoEntrega::create([
            'tenant_id' => $this->tenant->id, 'pedido_compra_id' => $pedido->id,
            'data_prevista' => $dataInicial, 'origem' => 'inicial',
            'registrado_por_id' => $this->user->id, 'registrado_em' => Carbon::now()->subDays(20),
        ]);

        if ($dataRevisada) {
            PedidoCompraPrevisaoEntrega::create([
                'tenant_id' => $this->tenant->id, 'pedido_compra_id' => $pedido->id,
                'data_prevista' => $dataRevisada, 'origem' => 'revisao',
                'registrado_por_id' => $this->user->id, 'registrado_em' => $registradoEmRevisao ?? Carbon::now()->subDays(2),
            ]);
        }

        return $pedido;
    }

    // =================================================================
    // A. Obra sem cronograma
    // =================================================================

    public function test_a_obra_sem_atividades_retorna_estado_vazio_honesto(): void
    {
        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $this->assertFalse($resumo->temCronograma);
        $this->assertSame(0, $resumo->heroProntidao->total);
        $this->assertNull($resumo->heroProntidao->percentual);
        $this->assertSame([], $resumo->ameacas);
        $this->assertStringContainsString('Importe o cronograma', $resumo->fraseGerencial);
    }

    // =================================================================
    // B. Hero / prontidão básica
    // =================================================================

    public function test_b_hero_conta_pronta_bloqueada_e_concluida_corretamente(): void
    {
        $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]); // pronta
        $bloqueada = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(4)]);
        $this->criarRestricaoBloqueante($bloqueada);
        $this->criarAtividade([
            'inicio_planejado' => Carbon::today()->addDays(2),
            'status' => StatusAtividade::Concluido->value,
            'concluido_em' => Carbon::now(),
        ]);

        $resumo = HomeExecutivaQuery::resumo($this->obra);
        $hero = $resumo->heroProntidao;

        $this->assertSame(3, $hero->total);
        $this->assertSame(1, $hero->concluidas);
        $this->assertSame(1, $hero->prontas);
        $this->assertSame(1, $hero->bloqueadas);
        $this->assertSame(50.0, $hero->percentual); // 1 pronta / (3-1 avaliáveis)
    }

    public function test_b2_todas_prontas_gera_frase_sem_bloqueio(): void
    {
        $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(1)]);
        $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(2)]);

        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $this->assertSame(0, $resumo->heroProntidao->bloqueadas);
        $this->assertStringContainsString('Nenhuma atividade crítica', $resumo->fraseGerencial);
    }

    // =================================================================
    // C. Horizontes (não vaza atividade fora da janela)
    // =================================================================

    public function test_c_atividade_so_aparece_no_horizonte_certo(): void
    {
        $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]); // semana + todas
        $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(20)]); // só 4 e 8 semanas
        $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(50)]); // só 8 semanas
        $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(120)]); // fora de qualquer horizonte

        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $this->assertSame(1, $resumo->horizontes['duas_semanas']->total);
        $this->assertSame(2, $resumo->horizontes['quatro_semanas']->total);
        $this->assertSame(3, $resumo->horizontes['oito_semanas']->total);
    }

    // =================================================================
    // D. Causas / ameaças — Engenharia
    // =================================================================

    public function test_d_documento_bloqueante_gera_causa_engenharia_e_ameaca(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        $doc = $this->criarDocumentoNaoLiberado();
        $atividade->documentosEngenharia()->attach($doc->id);

        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $causaEngenharia = collect($resumo->causas)->firstWhere('dominio', 'engenharia');
        $this->assertNotNull($causaEngenharia);
        $this->assertSame(1, $causaEngenharia['quantidade_atividades']);

        $ameaca = collect($resumo->ameacas)->firstWhere('atividade_id', $atividade->id);
        $this->assertNotNull($ameaca);
        $this->assertSame('bloqueante', $ameaca['severidade']);
        $this->assertSame('engenharia', $ameaca['categoria']);
        $this->assertSame(1, $resumo->engenharia['total_atividades']);
    }

    // =================================================================
    // E. Causas / ameaças — Restrição de Suprimento
    // =================================================================

    public function test_e_restricao_origem_suprimento_gera_causa_suprimentos(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote']);
        $this->criarRestricaoBloqueante($atividade, ['origem_suprimento_item_id' => $pacote->id]);

        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $causa = collect($resumo->causas)->firstWhere('dominio', 'suprimentos');
        $this->assertNotNull($causa);
        $this->assertSame(1, $causa['quantidade_atividades']);
    }

    // =================================================================
    // F. Checklist pendente
    // =================================================================

    public function test_f_checklist_pendente_gera_causa_e_ameaca(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        ItemProntidao::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Item 1']);

        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $causa = collect($resumo->causas)->firstWhere('dominio', 'checklist');
        $this->assertNotNull($causa);

        $ameaca = collect($resumo->ameacas)->firstWhere('atividade_id', $atividade->id);
        $this->assertNotNull($ameaca);
        $this->assertSame('checklist', $ameaca['categoria']);
    }

    // =================================================================
    // G. Motor V1 — NaoContratada (sem nenhuma cobertura comercial)
    // =================================================================

    public function test_g_necessidade_sem_contratacao_conta_como_sem_cobertura_e_vira_ameaca(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        $material = $this->criarMaterial();
        $this->criarNecessidade($atividade, $material, 100.0);

        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $this->assertSame(1, $resumo->suprimentos['total_necessidades']);
        $this->assertSame(1, $resumo->suprimentos['sem_cobertura_comercial']);

        $causa = collect($resumo->causas)->firstWhere('dominio', 'suprimentos');
        $this->assertNotNull($causa);

        $ameaca = collect($resumo->ameacas)->firstWhere('atividade_id', $atividade->id);
        $this->assertNotNull($ameaca, 'atividade com necessidade sem cobertura deve ser uma ameaça mesmo sendo hard-ready');
    }

    // =================================================================
    // H. Motor V1 — Protegida (reserva específica cobre tudo)
    // =================================================================

    public function test_h_necessidade_protegida_por_reserva_nunca_vira_ameaca(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        $material = $this->criarMaterial();
        $necessidade = $this->criarNecessidade($atividade, $material, 50.0);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote']);
        $local = $this->criarLocal();

        MovimentacaoEstoque::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'tipo' => TipoMovimentacaoEstoque::Entrada->value, 'material_id' => $material->id,
            'local_estoque_id' => $local->id, 'quantidade' => 50.0, 'ocorrido_em' => Carbon::today(),
        ]);

        app(CriarReservaEstoque::class)->execute($pacote, $material, $local, 50.0, necessidade: $necessidade);

        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $this->assertSame(1, $resumo->suprimentos['protegida']);
        $this->assertSame(0, $resumo->suprimentos['sem_cobertura_comercial']);

        $ameaca = collect($resumo->ameacas)->firstWhere('atividade_id', $atividade->id);
        $this->assertNull($ameaca, 'necessidade protegida nunca deve ser listada como ameaça');
    }

    // =================================================================
    // I. Motor V1 — disponível não reservada gera ação recomendada "Reservar"
    // =================================================================

    public function test_i_material_disponivel_sem_reserva_gera_acao_de_reservar(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        $material = $this->criarMaterial();
        $this->criarNecessidade($atividade, $material, 30.0);
        $local = $this->criarLocal();

        MovimentacaoEstoque::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'tipo' => TipoMovimentacaoEstoque::Entrada->value, 'material_id' => $material->id,
            'local_estoque_id' => $local->id, 'quantidade' => 30.0, 'ocorrido_em' => Carbon::today(),
        ]);

        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $this->assertSame(1, $resumo->suprimentos['disponivel_nao_reservada']);
        $acao = collect($resumo->acoesRecomendadas)->firstWhere('tipo', 'reservar');
        $this->assertNotNull($acao);
        $this->assertStringContainsString($material->codigo, $acao['titulo']);
    }

    // =================================================================
    // J. Prontidão recuperável — single vs. multi blocker
    // =================================================================

    public function test_j_recuperavel_distingue_uma_acao_de_multiplas_acoes(): void
    {
        $unicoBloqueio = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        $this->criarRestricaoBloqueante($unicoBloqueio);

        $doisBloqueios = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        $this->criarRestricaoBloqueante($doisBloqueios);
        $doc = $this->criarDocumentoNaoLiberado();
        $doisBloqueios->documentosEngenharia()->attach($doc->id);

        $resumo = HomeExecutivaQuery::resumo($this->obra);
        $rec = $resumo->prontidaoRecuperavel;

        // Fechamento (Ciclo 25) — as duas agora contam como recuperáveis
        // (nunca excluídas só por ter mais de um tipo de blocker), mas
        // distinguidas: 1 com uma única ação conhecida, 1 com múltiplas.
        $this->assertSame(2, $rec['atividades_recuperaveis']);
        $this->assertSame(1, $rec['recuperaveis_uma_acao']);
        $this->assertSame(1, $rec['recuperaveis_multiplas_acoes']);
    }

    public function test_j2_acao_compartilhada_entre_duas_atividades_conta_uma_unica_acao(): void
    {
        // Cenário real de "ação compartilhada" (Seção 21/22 do
        // fechamento): um MESMO Documento de Engenharia (N:N genuína,
        // `documento_engenharia_atividades`) bloqueando 2 atividades
        // distintas, cada uma com exatamente esse 1 blocker — resolver a
        // liberação do documento libera as duas de uma vez.
        $atividadeA = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        $atividadeB = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        $doc = $this->criarDocumentoNaoLiberado();
        $atividadeA->documentosEngenharia()->attach($doc->id);
        $atividadeB->documentosEngenharia()->attach($doc->id);

        $resumo = HomeExecutivaQuery::resumo($this->obra);
        $rec = $resumo->prontidaoRecuperavel;

        $this->assertSame(2, $rec['atividades_recuperaveis']);
        $this->assertSame(2, $rec['recuperaveis_uma_acao']);
        $this->assertSame(0, $rec['recuperaveis_multiplas_acoes']);
        $this->assertSame(1, $rec['acoes_conhecidas'], 'as duas atividades dependem do MESMO documento — 1 ação libera as 2');
        $this->assertStringContainsString('1 ação(ões)', $rec['frase']);
    }

    // =================================================================
    // K. Últimos acontecimentos
    // =================================================================

    public function test_k_restricao_resolvida_ha_3_dias_aparece_e_ha_10_dias_nao_aparece(): void
    {
        $atividade = $this->criarAtividade();
        $recente = $this->criarRestricaoBloqueante($atividade, ['status' => StatusRestricao::Resolvida->value, 'resolvida_em' => Carbon::now()->subDays(3)]);
        $antiga = $this->criarRestricaoBloqueante($atividade, ['status' => StatusRestricao::Resolvida->value, 'resolvida_em' => Carbon::now()->subDays(10)]);

        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $this->assertTrue($resumo->primeiroAcessoHome, 'sem cursor explícito, resumo() cai no comportamento honesto de primeiro acesso (janela fixa de 7 dias)');

        $descricoes = collect($resumo->ultimosAcontecimentos)->pluck('descricao')->implode(' | ');
        $this->assertStringContainsString($recente->descricao, $descricoes);
        $this->assertStringNotContainsString($antiga->descricao, $descricoes);
    }

    public function test_k2_cursor_de_ultima_visita_substitui_a_janela_fixa(): void
    {
        $atividade = $this->criarAtividade();
        // 10 dias atrás é "antigo" pra janela fixa de 7 dias, mas fica
        // DENTRO da janela quando o cursor da última visita é de 15 dias
        // atrás — prova que o cursor passa a mandar, não mais os 7 dias.
        $evento = $this->criarRestricaoBloqueante($atividade, ['status' => StatusRestricao::Resolvida->value, 'resolvida_em' => Carbon::now()->subDays(10)]);

        $resumo = HomeExecutivaQuery::resumo($this->obra, Carbon::now()->subDays(15));

        $this->assertFalse($resumo->primeiroAcessoHome);
        $descricoes = collect($resumo->ultimosAcontecimentos)->pluck('descricao')->implode(' | ');
        $this->assertStringContainsString($evento->descricao, $descricoes);
    }

    public function test_k3_evento_anterior_ao_cursor_da_ultima_visita_nao_aparece(): void
    {
        $atividade = $this->criarAtividade();
        $evento = $this->criarRestricaoBloqueante($atividade, ['status' => StatusRestricao::Resolvida->value, 'resolvida_em' => Carbon::now()->subDays(5)]);

        // Cursor de 3 dias atrás — o evento de 5 dias atrás é ANTERIOR à
        // última visita, então não é "o que mudou desde então".
        $resumo = HomeExecutivaQuery::resumo($this->obra, Carbon::now()->subDays(3));

        $descricoes = collect($resumo->ultimosAcontecimentos)->pluck('descricao')->implode(' | ');
        $this->assertStringNotContainsString($evento->descricao, $descricoes);
    }

    public function test_k4_mudanca_de_previsao_de_pedido_mostra_data_anterior_e_nova(): void
    {
        $this->criarAtividade(); // garante temCronograma=true (senão resumo() cai no estado vazio)
        $this->criarPedidoComHistoricoPrevisao('2027-01-15', '2027-01-25', Carbon::now()->subDays(2));

        $resumo = HomeExecutivaQuery::resumo($this->obra, Carbon::now()->subDays(5));

        $evento = collect($resumo->ultimosAcontecimentos)->firstWhere('tipo', 'pedido_previsao_revisada');
        $this->assertNotNull($evento);
        $this->assertStringContainsString('15/01', $evento['descricao']);
        $this->assertStringContainsString('25/01', $evento['descricao']);
    }

    public function test_k5_primeira_previsao_do_pedido_nunca_e_tratada_como_mudanca(): void
    {
        // Só existe UMA linha de previsão (a inicial, na emissão) —
        // nunca deve virar "mudou de X pra X" nem aparecer como evento,
        // já que não há um valor ANTERIOR de verdade pra comparar.
        $this->criarAtividade();
        $this->criarPedidoComHistoricoPrevisao('2027-01-15');

        $resumo = HomeExecutivaQuery::resumo($this->obra, Carbon::now()->subDays(25));

        $evento = collect($resumo->ultimosAcontecimentos)->firstWhere('tipo', 'pedido_previsao_revisada');
        $this->assertNull($evento);
    }

    // =================================================================
    // L. Execução da semana / PPC (Ciclo 24)
    // =================================================================

    public function test_l_ppc_da_ultima_semana_fechada_bate_com_a_formula_canonica(): void
    {
        $semanaInicio = Carbon::today()->startOfWeek()->subWeek();
        $semanaFim = $semanaInicio->copy()->endOfWeek();

        $programacao = ProgramacaoSemanal::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'semana_inicio' => $semanaInicio->toDateString(), 'semana_fim' => $semanaFim->toDateString(),
            'congelada_em' => $semanaInicio, 'criado_por' => $this->user->id,
            'status' => 'aberta', 'versao' => 1,
        ]);

        $concluida = $this->criarAtividade(['inicio_planejado' => $semanaInicio, 'status' => StatusAtividade::Concluido->value, 'concluido_em' => $semanaFim->copy()->subDay()]);
        $restricaoAntes = $this->criarAtividade(['inicio_planejado' => $semanaInicio]);
        $this->criarRestricaoBloqueante($restricaoAntes, ['aberta_em' => $semanaInicio->copy()->subDay(), 'status' => StatusRestricao::Aberta->value]);
        $semCausa = $this->criarAtividade(['inicio_planejado' => $semanaInicio]);

        foreach ([$concluida, $restricaoAntes, $semCausa] as $a) {
            ProgramacaoSemanalItem::create([
                'tenant_id' => $this->tenant->id,
                'programacao_semanal_id' => $programacao->id,
                'atividade_id' => $a->id,
                'inicio_planejado_congelado' => $semanaInicio, 'data_termino_congelado' => $semanaFim,
                'origem' => \App\Enums\OrigemProgramacaoSemanalItem::Manual->value,
                'criado_por' => $this->user->id,
            ]);
        }

        $resumo = HomeExecutivaQuery::resumo($this->obra);
        $execucao = $resumo->execucaoSemana;

        $this->assertNotNull($execucao);
        $this->assertSame(3, $execucao['comprometidas']);
        $this->assertSame(1, $execucao['concluidas_no_prazo']);
        $this->assertSame(33.3, $execucao['ppc_percentual']);
        $this->assertSame(2, $execucao['nao_concluidas']);
        $this->assertSame(1, $execucao['sistema_ja_sabia']);
    }

    public function test_l1b_restricao_ja_resolvida_antes_da_semana_nunca_conta_como_sistema_ja_sabia(): void
    {
        $semanaInicio = Carbon::today()->startOfWeek()->subWeek();
        $semanaFim = $semanaInicio->copy()->endOfWeek();

        $programacao = ProgramacaoSemanal::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'semana_inicio' => $semanaInicio->toDateString(), 'semana_fim' => $semanaFim->toDateString(),
            'congelada_em' => $semanaInicio, 'criado_por' => $this->user->id,
            'status' => 'aberta', 'versao' => 1,
        ]);

        // Restrição aberta ANTES da semana, mas já resolvida ANTES da
        // semana começar — nunca foi risco DURANTE a semana (Seção 11 do
        // fechamento: "aberta_em <= semana_inicio E não resolvida antes
        // daquele instante").
        $jaResolvidaAntes = $this->criarAtividade(['inicio_planejado' => $semanaInicio]);
        $this->criarRestricaoBloqueante($jaResolvidaAntes, [
            'aberta_em' => $semanaInicio->copy()->subDays(5),
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => $semanaInicio->copy()->subDay(),
        ]);

        ProgramacaoSemanalItem::create([
            'tenant_id' => $this->tenant->id, 'programacao_semanal_id' => $programacao->id,
            'atividade_id' => $jaResolvidaAntes->id,
            'inicio_planejado_congelado' => $semanaInicio, 'data_termino_congelado' => $semanaFim,
            'origem' => \App\Enums\OrigemProgramacaoSemanalItem::Manual->value, 'criado_por' => $this->user->id,
        ]);

        $resumo = HomeExecutivaQuery::resumo($this->obra);
        $execucao = $resumo->execucaoSemana;

        $this->assertSame(0, $execucao['sistema_ja_sabia']);
        $this->assertSame(0, $execucao['causas_nao_conclusao']['restricao']);
        $this->assertSame(1, $execucao['causas_nao_conclusao']['sem_causa']);
    }

    public function test_l1c_documento_nao_liberado_antes_da_semana_conta_como_sistema_ja_sabia(): void
    {
        $semanaInicio = Carbon::today()->startOfWeek()->subWeek();
        $semanaFim = $semanaInicio->copy()->endOfWeek();

        $programacao = ProgramacaoSemanal::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'semana_inicio' => $semanaInicio->toDateString(), 'semana_fim' => $semanaFim->toDateString(),
            'congelada_em' => $semanaInicio, 'criado_por' => $this->user->id,
            'status' => 'aberta', 'versao' => 1,
        ]);

        // Documento criado ANTES da semana, nunca liberado — sinal
        // reconstruível com rigor (revisão vigente hoje já existia antes
        // do cutoff, nenhum evento de liberação em nenhum momento).
        $atividade = $this->criarAtividade(['inicio_planejado' => $semanaInicio]);
        $doc = $this->criarDocumentoNaoLiberado();
        DB::table('documento_engenharia_revisoes')->where('documento_engenharia_id', $doc->id)
            ->update(['created_at' => $semanaInicio->copy()->subDays(30)]);
        $atividade->documentosEngenharia()->attach($doc->id);

        ProgramacaoSemanalItem::create([
            'tenant_id' => $this->tenant->id, 'programacao_semanal_id' => $programacao->id,
            'atividade_id' => $atividade->id,
            'inicio_planejado_congelado' => $semanaInicio, 'data_termino_congelado' => $semanaFim,
            'origem' => \App\Enums\OrigemProgramacaoSemanalItem::Manual->value, 'criado_por' => $this->user->id,
        ]);

        $resumo = HomeExecutivaQuery::resumo($this->obra);
        $execucao = $resumo->execucaoSemana;

        $this->assertSame(1, $execucao['sistema_ja_sabia']);
        $this->assertSame(1, $execucao['causas_nao_conclusao']['engenharia']);
        $this->assertStringContainsString('sinais de risco', $execucao['frase']);
        $this->assertStringNotContainsString('por causa', $execucao['frase']);
    }

    public function test_l1d_revisao_nova_criada_depois_da_semana_nunca_e_usada_para_reconstruir_historico(): void
    {
        $semanaInicio = Carbon::today()->startOfWeek()->subWeek();
        $semanaFim = $semanaInicio->copy()->endOfWeek();

        $programacao = ProgramacaoSemanal::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'semana_inicio' => $semanaInicio->toDateString(), 'semana_fim' => $semanaFim->toDateString(),
            'congelada_em' => $semanaInicio, 'criado_por' => $this->user->id,
            'status' => 'aberta', 'versao' => 1,
        ]);

        // A revisão VIGENTE hoje foi criada DEPOIS de semana_inicio — não
        // temos como saber com segurança qual revisão era vigente
        // naquele instante, então o sinal nunca é inventado (Seção 12).
        $atividade = $this->criarAtividade(['inicio_planejado' => $semanaInicio]);
        $doc = $this->criarDocumentoNaoLiberado(); // created_at = agora, bem depois de semanaInicio
        $atividade->documentosEngenharia()->attach($doc->id);

        ProgramacaoSemanalItem::create([
            'tenant_id' => $this->tenant->id, 'programacao_semanal_id' => $programacao->id,
            'atividade_id' => $atividade->id,
            'inicio_planejado_congelado' => $semanaInicio, 'data_termino_congelado' => $semanaFim,
            'origem' => \App\Enums\OrigemProgramacaoSemanalItem::Manual->value, 'criado_por' => $this->user->id,
        ]);

        $resumo = HomeExecutivaQuery::resumo($this->obra);
        $execucao = $resumo->execucaoSemana;

        $this->assertSame(0, $execucao['sistema_ja_sabia']);
        $this->assertSame(0, $execucao['causas_nao_conclusao']['engenharia']);
    }

    public function test_l2_sem_nenhuma_semana_fechada_execucao_semana_e_nula(): void
    {
        $this->criarAtividade();

        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $this->assertNull($resumo->execucaoSemana);
    }

    // =================================================================
    // M. Multitenancy / isolamento de obra
    // =================================================================

    public function test_m_obra_de_outro_tenant_nunca_aparece_no_resumo(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        Atividade::create([
            'obra_id' => $outraObra->id, 'nome' => 'Outra obra', 'codigo_cronograma' => 'X1',
            'status' => StatusAtividade::Planejado->value, 'inicio_planejado' => Carbon::today()->addDays(2),
            'fora_do_cronograma' => false,
        ]);

        $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(2)]);

        $resumo = HomeExecutivaQuery::resumo($this->obra);

        $this->assertSame(1, $resumo->heroProntidao->total);
    }

    // =================================================================
    // N. Performance — DELTA (10 vs. 50 atividades bloqueadas por restrição)
    // =================================================================

    public function test_n_query_count_nao_escala_linearmente_com_o_total_de_atividades(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $a = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
            $this->criarRestricaoBloqueante($a);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        HomeExecutivaQuery::resumo($this->obra);
        $queries10 = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 0; $i < 40; $i++) {
            $a = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
            $this->criarRestricaoBloqueante($a);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        HomeExecutivaQuery::resumo($this->obra);
        $queries50 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan($queries10 + 15, $queries50, "Query count cresceu de forma explosiva: {$queries10} -> {$queries50}");
    }

    public function test_n2_motor_v1_query_count_escala_com_o_numero_de_atividades_com_necessidade_nao_com_o_total_da_obra(): void
    {
        // 20 atividades SEM necessidade (não devem custar nada extra do Motor V1).
        for ($i = 0; $i < 20; $i++) {
            $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        HomeExecutivaQuery::resumo($this->obra);
        $semNecessidade = count(DB::getQueryLog());
        DB::disableQueryLog();

        $material = $this->criarMaterial();
        $comNecessidade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]);
        $this->criarNecessidade($comNecessidade, $material, 10.0);

        DB::flushQueryLog();
        DB::enableQueryLog();
        HomeExecutivaQuery::resumo($this->obra);
        $comUmaNecessidade = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Uma única atividade nova com necessidade nunca deveria custar dezenas de queries a mais.
        $this->assertLessThan($semNecessidade + 15, $comUmaNecessidade);
    }
}
