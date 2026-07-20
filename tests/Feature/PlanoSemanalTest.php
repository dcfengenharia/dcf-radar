<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\PilarLean;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\Etapa;
use App\Models\FrenteTrabalho;
use App\Models\PacoteTrabalho;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Melhoria pedida pelo usuário: (1) o intervalo da semana deve ser uma
 * checagem de SOBREPOSIÇÃO real (não só início OU término dentro da
 * semana — perdia atividades que atravessam a semana inteira); (2) a
 * hierarquia da tabela deve seguir a estrutura do cronograma (EAP),
 * igual a Lookahead/Linhas de Base.
 */
class PlanoSemanalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Engenheiro->value);
        $this->actingAs($this->user);
    }

    private function componente()
    {
        return Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra]);
    }

    public function test_atividade_que_atravessa_a_semana_inteira_aparece(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        // Começa 2 semanas antes e só termina 2 semanas depois — nem
        // início nem término caem dentro desta semana, mas ela está em
        // execução durante toda a semana (sobreposição de intervalo).
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade Longa',
            'status' => StatusAtividade::EmExecucao->value,
            'inicio_planejado' => $inicioSemana->copy()->subWeeks(2),
            'data_termino' => $inicioSemana->copy()->addWeeks(2),
        ]);

        $this->componente()->assertSee('Atividade Longa');
    }

    public function test_atividade_fora_da_semana_nao_aparece(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Atividade De Outra Semana',
            'status' => StatusAtividade::Comprometido->value,
            'inicio_planejado' => $inicioSemana->copy()->addWeeks(3),
            'data_termino' => $inicioSemana->copy()->addWeeks(3)->addDays(2),
        ]);

        $this->componente()->assertDontSee('Atividade De Outra Semana');
    }

    public function test_hierarquia_segue_a_estrutura_do_cronograma(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $pacotePai = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Estrutura',
            'codigo' => '1',
        ]);
        $pacoteFilho = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $pacotePai->id,
            'nome' => 'Fundação',
            'codigo' => '1.1',
        ]);

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacoteFilho->id,
            'nome' => 'Concretar Sapata',
            'codigo_cronograma' => '1.1.1',
            'status' => StatusAtividade::Comprometido->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $arvore = $this->componente()->instance()->arvoreAtividades;

        $this->assertEquals('pacote', $arvore[0]['tipo']);
        $this->assertEquals($pacotePai->id, $arvore[0]['id']);
        $this->assertEquals(0, $arvore[0]['nivel']);

        $this->assertEquals('pacote', $arvore[1]['tipo']);
        $this->assertEquals($pacoteFilho->id, $arvore[1]['id']);
        $this->assertEquals(1, $arvore[1]['nivel']);

        $this->assertEquals('atividade', $arvore[2]['tipo']);
        $this->assertEquals('Concretar Sapata', $arvore[2]['atividade']->nome);
        $this->assertEquals(2, $arvore[2]['nivel']);
    }

    public function test_pacote_sem_atividade_na_semana_nao_aparece_na_arvore(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $pacoteComAtividade = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Com Atividade Na Semana',
            'codigo' => '1',
        ]);
        $pacoteSemAtividadeNaSemana = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Sem Atividade Na Semana',
            'codigo' => '2',
        ]);

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacoteComAtividade->id,
            'status' => StatusAtividade::Comprometido->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(1),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacoteSemAtividadeNaSemana->id,
            'status' => StatusAtividade::Comprometido->value,
            'inicio_planejado' => $inicioSemana->copy()->addWeeks(5),
            'data_termino' => $inicioSemana->copy()->addWeeks(5)->addDays(1),
        ]);

        $this->componente()
            ->assertSee('Com Atividade Na Semana')
            ->assertDontSee('Sem Atividade Na Semana');
    }

    /**
     * Melhoria pedida pelo usuário: o filtro da semana deve mostrar TODA
     * atividade que inicia, termina ou está em execução no período,
     * independente do status já estar comprometido ou não — vira um
     * "Lookahead da semana", não só o rastreamento do que já foi
     * inserido na programação.
     */
    public function test_atividade_ainda_planejada_no_periodo_tambem_aparece(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Ainda Nao Comprometida',
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $this->componente()->assertSee('Ainda Nao Comprometida');
    }

    public function test_atividade_arquivada_fora_do_cronograma_nao_aparece(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Arquivada',
            'status' => StatusAtividade::Planejado->value,
            'fora_do_cronograma' => true,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $this->componente()->assertDontSee('Arquivada');
    }

    public function test_badge_de_liberacao_reflete_restricao_bloqueante_aberta(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $bloqueada = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Tarefa Bloqueada',
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $categoria = CategoriaRestricao::create([
            'tenant_id' => $this->tenant->id,
            'nome' => 'Materiais',
            'pilar_lean' => PilarLean::Materiais->value,
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $bloqueada->id,
            'categoria_id' => $categoria->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Tarefa Liberada',
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $componente = $this->componente();

        $componente->assertSeeHtml('Bloqueada')->assertSeeHtml('Liberada');
        $this->assertSame(1, $componente->instance()->totalComRestricao);
        $this->assertSame(1, $componente->instance()->totalSemRestricao);
        $this->assertSame(2, $componente->instance()->totalAtividadesPeriodo);
    }

    public function test_apenas_planejada_e_liberada_entra_na_lista_de_selecionaveis(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $planejadaLiberada = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $jaComprometida = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Comprometido->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $planejadaBloqueada = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $categoria = CategoriaRestricao::create([
            'tenant_id' => $this->tenant->id,
            'nome' => 'Materiais',
            'pilar_lean' => PilarLean::Materiais->value,
        ]);
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $planejadaBloqueada->id,
            'categoria_id' => $categoria->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);

        $ids = $this->componente()->instance()->idsSelecionaveis;

        $this->assertContains($planejadaLiberada->id, $ids);
        $this->assertNotContains($jaComprometida->id, $ids);
        $this->assertNotContains($planejadaBloqueada->id, $ids);
    }

    public function test_comprometer_selecionadas_muda_status_para_comprometido(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $this->componente()
            ->set('selecionadas', [$atividade->id])
            ->call('comprometerSelecionadas');

        $this->assertSame(StatusAtividade::Comprometido, $atividade->fresh()->status);
    }

    public function test_marcar_concluida_registra_concluido_em(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Comprometido->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $this->componente()->call('marcarConcluida', $atividade->id);

        $this->assertNotNull($atividade->fresh()->concluido_em);
    }

    public function test_falha_de_banco_ao_comprometer_mostra_toast_de_erro_sem_gravar(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $conexaoReal = app('db');
        \Illuminate\Support\Facades\DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('falha forçada de teste'));

        $this->componente()
            ->set('selecionadas', [$atividade->id])
            ->call('comprometerSelecionadas')
            ->assertDispatched('show-toast', function (string $name, array $params) {
                return ($params['type'] ?? null) === 'error';
            });

        \Illuminate\Support\Facades\DB::swap($conexaoReal);
        $this->assertSame(StatusAtividade::Planejado, $atividade->fresh()->status);
    }

    /**
     * Defesa em profundidade: mesmo que o array selecionadas chegue com
     * o id de uma atividade bloqueada (ex: manipulação direta do
     * wire:model, bypassando o checkbox desabilitado na UI), o servidor
     * nunca deve comprometê-la — mantém a regra de negócio do CLAUDE.md
     * mesmo se o cliente mentir.
     */
    public function test_comprometer_selecionadas_ignora_atividade_bloqueada_mesmo_se_enviada(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $bloqueada = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $categoria = CategoriaRestricao::create([
            'tenant_id' => $this->tenant->id,
            'nome' => 'Materiais',
            'pilar_lean' => PilarLean::Materiais->value,
        ]);
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $bloqueada->id,
            'categoria_id' => $categoria->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);

        $this->componente()
            ->set('selecionadas', [$bloqueada->id])
            ->call('comprometerSelecionadas');

        $this->assertSame(StatusAtividade::Planejado, $bloqueada->fresh()->status);
    }

    public function test_ppc_nao_conta_atividades_ainda_planejadas_no_denominador(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Concluido->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $ppc = $this->componente()->instance()->ppc;

        $this->assertSame(1, $ppc['total']);
        $this->assertSame(1, $ppc['concluidas']);
        $this->assertEquals(100, $ppc['percentual']);
    }

    /**
     * Melhoria pedida pelo usuário: mesmos filtros do Lookahead (busca,
     * etapa, frente de trabalho, ocultar concluídas) + colapsar por
     * nível na árvore.
     */
    public function test_filtro_de_busca_por_nome(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Concretagem da Laje',
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Alvenaria do Térreo',
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $this->componente()
            ->set('search', 'laje')
            ->assertSee('Concretagem da Laje')
            ->assertDontSee('Alvenaria do Térreo');
    }

    public function test_filtro_por_etapa(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $etapa = Etapa::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Na Etapa Filtrada',
            'etapa_id' => $etapa->id,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Fora Da Etapa',
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $this->componente()
            ->set('etapaIdFiltro', $etapa->id)
            ->assertSee('Na Etapa Filtrada')
            ->assertDontSee('Fora Da Etapa');
    }

    public function test_filtro_por_frente_de_trabalho(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $frente = FrenteTrabalho::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Na Frente Filtrada',
            'frente_trabalho_id' => $frente->id,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Fora Da Frente',
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $this->componente()
            ->set('frenteTrabalhoIdFiltro', $frente->id)
            ->assertSee('Na Frente Filtrada')
            ->assertDontSee('Fora Da Frente');
    }

    /**
     * "Ocultar concluídas" é preferência de EXIBIÇÃO da árvore — não pode
     * remover concluídas do cálculo de PPC, senão o "concluídas" do PPC
     * sempre zeraria quando o toggle estivesse ligado.
     */
    public function test_ocultar_concluidas_esconde_da_arvore_mas_nao_afeta_ppc(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Tarefa Concluida X',
            'status' => StatusAtividade::Concluido->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $componente = $this->componente()->set('ocultarConcluidas', true);

        $componente->assertDontSee('Tarefa Concluida X');
        $ppc = $componente->instance()->ppc;
        $this->assertSame(1, $ppc['total']);
        $this->assertSame(1, $ppc['concluidas']);
    }

    public function test_colapsar_por_nivel_agrupa_pacotes_por_nivel_na_arvore(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $pacotePai = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => '1',
        ]);
        $pacoteFilho = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $pacotePai->id,
            'codigo' => '1.1',
        ]);

        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacoteFilho->id,
            'codigo_cronograma' => '1.1.1',
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(1),
        ]);

        $arvore = $this->componente()->instance()->arvoreAtividades;

        $niveis = collect($arvore)->where('tipo', 'pacote')->pluck('nivel')->unique()->sort()->values();

        $this->assertEquals([0, 1], $niveis->all());
    }
}
