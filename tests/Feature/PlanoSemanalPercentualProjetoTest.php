<?php

namespace Tests\Feature;

use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\GranularidadePeriodo;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\Papel;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
use App\Enums\TipoCronogramaImportacao;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\LinhaBase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Coluna "% do Projeto": HH previsto da atividade nesta semana ÷ HH total
 * do projeto (Previsto/Mensal, via App\Services\CurvaAvanco::totalCalculado,
 * já existente e reaproveitado sem alteração).
 */
class PlanoSemanalPercentualProjetoTest extends TestCase
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

    private function criarImportacao(array $overrides = []): CronogramaImportacao
    {
        return CronogramaImportacao::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
            'tipo' => TipoCronogramaImportacao::Baseline->value,
        ], $overrides));
    }

    public function test_coluna_calcula_hh_semana_dividido_por_hh_total_do_projeto(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $importacao = $this->criarImportacao();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);
        $outraAtividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => $inicioSemana->copy()->addMonths(2),
        ]);

        // HH total do projeto (Previsto/Mensal): 150 + 50 = 200.
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $inicioSemana->copy()->startOfMonth(),
            'horas' => 150,
        ]);
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $outraAtividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $inicioSemana->copy()->addMonths(2)->startOfMonth(),
            'horas' => 50,
        ]);

        // HH previsto da atividade NESTA semana: 20.
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Semanal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $inicioSemana,
            'horas' => 20,
        ]);

        $componente = $this->componente();

        $this->assertEquals(200.0, $componente->instance()->totalHhProjeto);
        $this->assertEquals(20.0, (float) $componente->instance()->hhPrevistoSemanaPorAtividade->get($atividade->id));
        $componente->assertSeeHtml('10.00%');
    }

    public function test_mostra_traco_sem_avanco_periodo_mas_zero_por_cento_quando_horas_e_zero(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();
        $importacao = $this->criarImportacao();

        $semDado = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Sem HH Cadastrado',
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(1),
        ]);
        $comZero = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Com HH Zerado',
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(1),
        ]);

        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $comZero->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $inicioSemana->copy()->startOfMonth(),
            'horas' => 100,
        ]);
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $comZero->id,
            'granularidade' => GranularidadePeriodo::Semanal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $inicioSemana,
            'horas' => 0,
        ]);

        $hh = $this->componente()->instance()->hhPrevistoSemanaPorAtividade;

        $this->assertFalse($hh->has($semDado->id));
        $this->assertTrue($hh->has($comZero->id));
        $this->assertEquals(0.0, (float) $hh->get($comZero->id));

        $this->componente()->assertSeeHtml('0.00%');
    }

    public function test_trocar_linha_de_base_muda_hh_total_e_percentual_exibido(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $importAntiga = $this->criarImportacao(['importado_em' => now()->subDays(10)]);
        $linhaBaseAntiga = LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => 'Baseline Antiga',
            'cronograma_importacao_id' => $importAntiga->id,
            'criado_por' => $this->user->id,
        ]);

        $importRecente = $this->criarImportacao(['importado_em' => now()]);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(1),
        ]);

        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importAntiga->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $inicioSemana->copy()->startOfMonth(),
            'horas' => 50,
        ]);
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importRecente->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $inicioSemana->copy()->startOfMonth(),
            'horas' => 100,
        ]);
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importRecente->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Semanal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $inicioSemana,
            'horas' => 25,
        ]);

        $componente = $this->componente();

        // linhaBaseId nulo -> importação Baseline/Ambos mais recente (100).
        $this->assertEquals(100.0, $componente->instance()->totalHhProjeto);

        $componente->set('linhaBaseId', $linhaBaseAntiga->id);

        $this->assertEquals(50.0, $componente->instance()->totalHhProjeto);
    }

    public function test_semana_congelada_usa_hh_congelado_no_commit_nao_o_avanco_periodo_atual(): void
    {
        $semanaPassada = Carbon::now()->startOfWeek()->subWeeks(2);
        $importacao = $this->criarImportacao();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $semanaPassada,
            'data_termino' => $semanaPassada->copy()->addDays(2),
        ]);

        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $semanaPassada->copy()->startOfMonth(),
            'horas' => 100,
        ]);
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Semanal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $semanaPassada,
            'horas' => 30,
        ]);

        (new RegistrarComprometimentoSemanal)->execute(
            $this->obra, $semanaPassada->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );
        $atividade->update(['status' => StatusAtividade::Comprometido->value]);

        // Simula reimportação: o HH semanal previsto daquela atividade mudou.
        AvancoPeriodo::where('atividade_id', $atividade->id)
            ->where('granularidade', GranularidadePeriodo::Semanal->value)
            ->update(['horas' => 999]);

        $componente = $this->componente()->set('semanaInicio', $semanaPassada->toDateString());

        $this->assertTrue($componente->instance()->semanaEstaCongelada);
        $this->assertEquals(30.0, (float) $componente->instance()->hhPrevistoSemanaPorAtividade->get($atividade->id));
    }
}
