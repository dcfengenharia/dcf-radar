<?php

namespace Tests\Feature;

use App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\GranularidadePeriodo;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\Papel;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\ProgramacaoSemanal;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Congela (insert imutável) o lado PREVISTO do que foi comprometido em uma
 * semana, pelas duas ações que existem no sistema — "Inserir na
 * Programação" (Plano Semanal, manual) e "Gerar Plano Semanal" (Lookahead,
 * em lote) — CLAUDE.md / RegistrarComprometimentoSemanal.
 */
class ProgramacaoSemanalSnapshotTest extends TestCase
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

    private function planoSemanalComponente()
    {
        return Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra]);
    }

    private function lookaheadComponente()
    {
        return Livewire::test('pages::radar.lookahead', ['obra' => $this->obra]);
    }

    public function test_comprometer_selecionadas_congela_header_e_itens_com_hh_correto(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);

        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Semanal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => $inicioSemana,
            'horas' => 40.5,
        ]);

        $this->planoSemanalComponente()
            ->set('selecionadas', [$atividade->id])
            ->call('comprometerSelecionadas');

        $header = ProgramacaoSemanal::where('obra_id', $this->obra->id)
            ->where('semana_inicio', $inicioSemana->toDateString())
            ->first();

        $this->assertNotNull($header);

        $item = ProgramacaoSemanalItem::where('programacao_semanal_id', $header->id)
            ->where('atividade_id', $atividade->id)
            ->first();

        $this->assertNotNull($item);
        $this->assertEquals(40.5, (float) $item->horas_previstas_congeladas);
        $this->assertEquals(OrigemProgramacaoSemanalItem::Manual, $item->origem);
        $this->assertTrue($inicioSemana->isSameDay($item->inicio_planejado_congelado));
    }

    public function test_hh_congelado_fica_null_quando_nao_ha_avanco_periodo_para_semana(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $this->planoSemanalComponente()
            ->set('selecionadas', [$atividade->id])
            ->call('comprometerSelecionadas');

        $item = ProgramacaoSemanalItem::where('atividade_id', $atividade->id)->firstOrFail();

        $this->assertNull($item->horas_previstas_congeladas);
    }

    public function test_registrar_duas_vezes_a_mesma_atividade_na_mesma_semana_nao_duplica_item(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek()->toDateString();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);

        $action = new RegistrarComprometimentoSemanal;
        $header1 = $action->execute($this->obra, $inicioSemana, collect([$atividade]), OrigemProgramacaoSemanalItem::Manual);
        $header2 = $action->execute($this->obra, $inicioSemana, collect([$atividade]), OrigemProgramacaoSemanalItem::Manual);

        $this->assertEquals($header1->id, $header2->id);
        $this->assertSame(1, ProgramacaoSemanal::where('obra_id', $this->obra->id)->count());
        $this->assertSame(1, ProgramacaoSemanalItem::where('programacao_semanal_id', $header1->id)->count());
    }

    public function test_as_duas_acoes_escrevem_no_mesmo_header_da_semana_com_origem_correta(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        // gerarPlanoSemanal() só inclui atividades cujo início caia dentro da
        // janela [hoje, hoje+30 dias] (⚡lookahead.blade.php). $viaLote começa
        // na semana+5 dias (sábado); sem congelar o "hoje" real, o teste é
        // flaky perto do fim de semana, quando esse sábado já ficou no
        // passado. Trava o relógio na segunda-feira desta mesma semana.
        $this->travelTo($inicioSemana->copy()->addHours(9));

        $viaManual = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(1),
        ]);

        $viaLote = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana->copy()->addDays(5),
            'baseline_inicio' => $inicioSemana->copy()->addDays(5),
        ]);

        $this->planoSemanalComponente()
            ->set('selecionadas', [$viaManual->id])
            ->call('comprometerSelecionadas');

        $this->lookaheadComponente()
            ->set('fonteData', 'tendencia')
            ->call('gerarPlanoSemanal');

        $headers = ProgramacaoSemanal::where('obra_id', $this->obra->id)
            ->where('semana_inicio', $inicioSemana->toDateString())
            ->get();

        $this->assertSame(1, $headers->count());

        $itemManual = ProgramacaoSemanalItem::where('atividade_id', $viaManual->id)->firstOrFail();
        $itemLote = ProgramacaoSemanalItem::where('atividade_id', $viaLote->id)->firstOrFail();

        $this->assertEquals(OrigemProgramacaoSemanalItem::Manual, $itemManual->origem);
        $this->assertEquals(OrigemProgramacaoSemanalItem::Lote, $itemLote->origem);
        $this->assertEquals($headers->first()->id, $itemManual->programacao_semanal_id);
        $this->assertEquals($headers->first()->id, $itemLote->programacao_semanal_id);
    }

    public function test_falha_de_transacao_nao_grava_status_nem_snapshot(): void
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
        DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('falha forçada de teste'));

        $this->planoSemanalComponente()
            ->set('selecionadas', [$atividade->id])
            ->call('comprometerSelecionadas');

        DB::swap($conexaoReal);

        $this->assertSame(StatusAtividade::Planejado, $atividade->fresh()->status);
        $this->assertSame(0, ProgramacaoSemanal::where('obra_id', $this->obra->id)->count());
        $this->assertSame(0, ProgramacaoSemanalItem::where('atividade_id', $atividade->id)->count());
    }

    public function test_comprometer_numa_programacao_ja_fechada_lanca_excecao(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek()->toDateString();

        $atividade1 = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $atividade2 = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $action = new RegistrarComprometimentoSemanal;
        $header = $action->execute($this->obra, $inicioSemana, collect([$atividade1]), OrigemProgramacaoSemanalItem::Manual);
        (new FecharProgramacaoSemanal)->execute($header);

        $this->expectException(\RuntimeException::class);
        $action->execute($this->obra, $inicioSemana, collect([$atividade2]), OrigemProgramacaoSemanalItem::Manual);
    }

    public function test_botao_gerar_programacao_fecha_a_semana_vigente(): void
    {
        $inicioSemana = Carbon::now()->startOfWeek();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana,
            'data_termino' => $inicioSemana->copy()->addDays(2),
        ]);

        $componente = $this->planoSemanalComponente()
            ->set('selecionadas', [$atividade->id])
            ->call('comprometerSelecionadas');

        $this->assertFalse($componente->instance()->semanaEstaFechada);

        $componente->call('fecharProgramacao');

        $this->assertTrue($componente->instance()->semanaEstaFechada);

        $header = ProgramacaoSemanal::where('obra_id', $this->obra->id)
            ->where('semana_inicio', $inicioSemana->toDateString())
            ->firstOrFail();
        $this->assertTrue($header->estaFechada());
    }
}
