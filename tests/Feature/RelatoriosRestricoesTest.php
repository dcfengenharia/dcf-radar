<?php

namespace Tests\Feature;

use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\Papel;
use App\Enums\PilarLean;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\CategoriaRestricao;
use App\Models\Disciplina;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class RelatoriosRestricoesTest extends TestCase
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
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        ObraContext::set($this->obra);
    }

    private function componente()
    {
        return Livewire::test('pages::radar.relatorios-restricoes', ['obra' => $this->obra]);
    }

    public function test_pagina_renderiza_com_dados_reais(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->componente()
            ->assertOk()
            ->assertSee('Restrições por Responsável')
            ->assertSee('Restrições por Período')
            ->assertSee('Itens de Prontidão por Disciplina')
            ->assertSee('Restrições Atrasadas');
    }

    public function test_restricoes_por_responsavel_agrupa_corretamente(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ana', 'last_name' => 'Souza']);

        Restricao::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => $responsavel->id,
            'responsavel_externo' => null,
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => null,
            'responsavel_externo' => 'Fornecedor X',
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => null,
            'responsavel_externo' => null,
        ]);

        $dados = collect($this->componente()->instance()->porResponsavel)->keyBy('nome');

        $this->assertEquals(2, $dados->get('Ana Souza')['total']);
        $this->assertEquals(1, $dados->get('Externo: Fornecedor X')['total']);
        $this->assertEquals(1, $dados->get('Sem responsável')['total']);
    }

    public function test_filtro_periodo_usa_prazo_limite_por_padrao(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $dentroDoRangePorPrazo = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'prazo_limite' => now()->addDays(5),
            'aberta_em' => now()->subYear(),
        ]);

        $foraDoRangePorPrazo = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'prazo_limite' => now()->addYear(),
            'aberta_em' => now(),
        ]);

        $componente = $this->componente();
        $totalNoRange = collect($componente->instance()->porPeriodo)->sum('total');

        $this->assertGreaterThanOrEqual(1, $totalNoRange);

        // Confere via P×I específico: conta total de restrições cujo prazo cai no range
        // configurado por padrão (6 meses pra trás / 6 pra frente) batendo com a única
        // restrição cujo prazo_limite está dentro dessa janela.
        $componente->set('filtroDataInicio', now()->startOfDay()->format('Y-m-d'))
            ->set('filtroDataFim', now()->addDays(10)->format('Y-m-d'));

        $total = collect($componente->instance()->porPeriodo)->sum('total');
        $this->assertEquals(1, $total);
    }

    public function test_restricoes_atrasadas_kpi(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $atrasada = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
            'prazo_limite' => now()->subDays(3),
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Resolvida->value,
            'prazo_limite' => now()->subDays(3),
            'resolvida_em' => now(),
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
            'prazo_limite' => null,
        ]);

        $ids = $this->componente()->instance()->atrasadas->pluck('id');

        $this->assertTrue($ids->contains($atrasada->id));
        $this->assertCount(1, $ids);
    }

    public function test_tempo_medio_resolucao(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Resolvida->value,
            'aberta_em' => now()->subDays(10),
            'resolvida_em' => now(),
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Resolvida->value,
            'aberta_em' => now()->subDays(20),
            'resolvida_em' => now(),
        ]);

        $geral = $this->componente()->instance()->tempoMedioResolucao['geral'];

        $this->assertEquals(15.0, $geral);
    }

    public function test_itens_prontidao_por_disciplina(): void
    {
        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Estrutural']);

        $atividadeCom = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'disciplina_id' => $disciplina->id,
        ]);

        $atividadeSem = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'disciplina_id' => null,
        ]);

        $item = ItemProntidao::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Projeto aprovado', 'ordem' => 1]);

        AtividadeItemProntidao::create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividadeCom->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
        ]);

        AtividadeItemProntidao::create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividadeSem->id,
            'item_prontidao_id' => $item->id,
            'concluido' => false,
        ]);

        $dados = collect($this->componente()->instance()->prontidaoPorDisciplina)->keyBy('disciplina');

        $this->assertEquals(100.0, $dados->get('Estrutural')['percentual']);
        $this->assertEquals(0.0, $dados->get('Sem disciplina')['percentual']);
    }

    public function test_filtros_aplicam_em_todos_indicadores(): void
    {
        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);
        $outraDisciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Hidráulica']);

        $atividadeCom = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'disciplina_id' => $disciplina->id,
        ]);

        $atividadeOutra = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'disciplina_id' => $outraDisciplina->id,
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividadeCom->id,
            'bloqueante' => true,
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividadeOutra->id,
            'bloqueante' => false,
        ]);

        $componente = $this->componente()
            ->set('filtroDisciplinaId', $disciplina->id)
            ->set('apenasBloqueantes', true);

        $totalResponsavel = collect($componente->instance()->porResponsavel)->sum('total');
        $totalCategoria = collect($componente->instance()->porCategoria)->sum('total');
        $totalPeriodo = $componente->instance()->totais['total'];

        $this->assertEquals(1, $totalResponsavel);
        $this->assertEquals(1, $totalCategoria);
        $this->assertEquals(1, $totalPeriodo);
    }

    public function test_exportar_excel(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $atividade->id]);

        $this->componente()
            ->call('exportarExcel')
            ->assertFileDownloaded();
    }

    public function test_exportar_pdf(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $atividade->id]);

        $response = $this->componente()->call('exportarPdf');

        $response->assertOk();
    }

    public function test_menu_mostra_item_para_usuario_com_permissao(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $atividade->id]);

        $this->get(route('radar.relatorios-restricoes'))
            ->assertOk()
            ->assertSeeLivewire('pages::radar.relatorios-restricoes');
    }

    public function test_por_pilar_soma_categorias_do_mesmo_pilar(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $categoria = CategoriaRestricao::create([
            'tenant_id' => $this->tenant->id,
            'nome' => 'Chuva',
            'pilar_lean' => PilarLean::CondicoesPrecedentes->value,
        ]);

        Restricao::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'categoria_id' => $categoria->id,
        ]);

        $dados = collect($this->componente()->instance()->porPilar)->keyBy('label');

        $this->assertEquals(3, $dados->get('Condições Precedentes')['total']);
    }

    public function test_status_geral_agrupa_por_status(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        Restricao::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => now(),
        ]);

        $dados = collect($this->componente()->instance()->statusGeral)->keyBy('status');

        $this->assertEquals(2, $dados->get('Aberta')['total']);
        $this->assertEquals(1, $dados->get('Resolvida')['total']);
        $this->assertFalse($dados->has('Em Tratamento'));
    }

    public function test_risco_distribuicao_classifica_por_faixa(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
            'probabilidade' => 2,
            'impacto' => 2, // risco 4 -> baixo
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
            'probabilidade' => 5,
            'impacto' => 6, // risco 30 -> médio
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
            'probabilidade' => 9,
            'impacto' => 9, // risco 81 -> alto
        ]);

        // resolvida não entra na distribuição (só restrições em aberto)
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Resolvida->value,
            'probabilidade' => 10,
            'impacto' => 10,
            'resolvida_em' => now(),
        ]);

        $dados = collect($this->componente()->instance()->riscoDistribuicao)->keyBy('label');

        $this->assertEquals(1, $dados->get('Baixo')['total']);
        $this->assertEquals(1, $dados->get('Médio')['total']);
        $this->assertEquals(1, $dados->get('Alto')['total']);
        $this->assertEquals(1, $this->componente()->instance()->riscoAltoPXI);
    }

    public function test_ppc_por_semana_calcula_percentual_corretamente(): void
    {
        $semanaInicio = Carbon::now()->subWeeks(3)->startOfWeek();
        $semanaFim = $semanaInicio->copy()->endOfWeek();

        $comprometidas = Atividade::factory()->count(4)->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
        ]);

        (new RegistrarComprometimentoSemanal())->execute(
            $this->obra,
            $semanaInicio->toDateString(),
            $comprometidas,
            OrigemProgramacaoSemanalItem::Manual
        );

        // 2 concluídas dentro da semana, 1 concluída DEPOIS do prazo, 1 nunca concluída
        DB::table('atividades')->whereIn('id', $comprometidas->take(2)->pluck('id'))
            ->update(['status' => 'concluido', 'concluido_em' => $semanaInicio->copy()->addDays(2)]);
        DB::table('atividades')->where('id', $comprometidas[2]->id)
            ->update(['status' => 'concluido', 'concluido_em' => $semanaFim->copy()->addDays(3)]);

        $ppc = $this->componente()
            ->set('filtroDataInicio', $semanaInicio->copy()->subWeek()->toDateString())
            ->set('filtroDataFim', $semanaInicio->copy()->addWeek()->toDateString())
            ->instance()->ppcPorSemana;

        $this->assertCount(1, $ppc);
        $this->assertSame(4, $ppc[0]['comprometidas']);
        $this->assertSame(2, $ppc[0]['concluidas_no_prazo']);
        $this->assertEquals(50.0, $ppc[0]['ppc_percentual']);
    }

    public function test_ppc_exclui_semana_corrente_ainda_em_andamento(): void
    {
        $semanaInicio = Carbon::now()->startOfWeek();

        $comprometidas = Atividade::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
        ]);

        (new RegistrarComprometimentoSemanal())->execute(
            $this->obra,
            $semanaInicio->toDateString(),
            $comprometidas,
            OrigemProgramacaoSemanalItem::Manual
        );

        $ppc = $this->componente()
            ->set('filtroDataInicio', $semanaInicio->copy()->subWeek()->toDateString())
            ->set('filtroDataFim', $semanaInicio->copy()->addWeek()->toDateString())
            ->instance()->ppcPorSemana;

        $this->assertCount(0, $ppc);
    }

    public function test_ppc_respeita_filtro_de_data(): void
    {
        $semanaA = Carbon::now()->subWeeks(5)->startOfWeek();
        $semanaB = Carbon::now()->subWeeks(2)->startOfWeek();

        foreach ([$semanaA, $semanaB] as $semana) {
            $atividades = Atividade::factory()->count(1)->create([
                'tenant_id' => $this->tenant->id,
                'obra_id' => $this->obra->id,
                'status' => StatusAtividade::Planejado->value,
            ]);

            (new RegistrarComprometimentoSemanal())->execute(
                $this->obra,
                $semana->toDateString(),
                $atividades,
                OrigemProgramacaoSemanalItem::Manual
            );
        }

        $ppc = $this->componente()
            ->set('filtroDataInicio', $semanaB->copy()->subDay()->toDateString())
            ->set('filtroDataFim', $semanaB->copy()->addWeek()->toDateString())
            ->instance()->ppcPorSemana;

        $this->assertCount(1, $ppc);
    }
}
