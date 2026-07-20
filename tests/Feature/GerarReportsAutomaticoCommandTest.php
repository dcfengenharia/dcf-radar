<?php

namespace Tests\Feature;

use App\Enums\TipoCronogramaImportacao;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GerarReportsAutomaticoCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $usuario;
    private PacoteTrabalho $raiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function criarObraComAvanco(?int $diaSemanaReport): Work
    {
        $obra = Work::factory()->create([
            'tenant_id' => $this->tenant->id,
            'dia_semana_report' => $diaSemanaReport,
        ]);

        CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $obra->id,
            'data_status' => Carbon::today()->toDateString(),
            'importado_em' => now(),
        ]);

        $this->raiz = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $obra->id,
            'nome' => 'CIVIL',
            'codigo' => '1',
        ]);

        return $obra;
    }

    private function criarReportAnterior(Work $obra, string $periodoReferencia): Report
    {
        $importacaoId = CronogramaImportacao::where('obra_id', $obra->id)
            ->orderByDesc('importado_em')
            ->value('id');

        $report = Report::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $obra->id,
            'periodo_referencia' => $periodoReferencia,
            'criado_por' => $this->usuario->id,
            'cronograma_importacao_id' => $importacaoId,
        ]);

        $report->curvas()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $this->raiz->id,
            'ordem' => 0,
            'titulo_exibicao' => $this->raiz->nome,
            'termino_linha_base' => null,
            'termino_tendencia' => null,
        ]);

        return $report->fresh(['curvas']);
    }

    public function test_gera_rascunho_para_obra_configurada_no_dia_certo(): void
    {
        $obra = $this->criarObraComAvanco(Carbon::today()->dayOfWeek);
        $this->criarReportAnterior($obra, Carbon::today()->subWeek()->startOfWeek()->toDateString());

        $this->artisan('reports:gerar-automatico')->assertSuccessful();

        $novoReport = Report::where('obra_id', $obra->id)
            ->whereDate('periodo_referencia', Carbon::today()->startOfWeek()->toDateString())
            ->first();

        $this->assertNotNull($novoReport);
        $this->assertTrue($novoReport->estaRascunho());
        $this->assertCount(1, $novoReport->curvas);
        $this->assertSame($this->raiz->id, $novoReport->curvas->first()->pacote_trabalho_id);
    }

    public function test_nao_duplica_se_ja_existe_report_do_periodo(): void
    {
        $obra = $this->criarObraComAvanco(Carbon::today()->dayOfWeek);
        $this->criarReportAnterior($obra, Carbon::today()->subWeek()->startOfWeek()->toDateString());
        // Já existe um report pro período corrente.
        $this->criarReportAnterior($obra, Carbon::today()->startOfWeek()->toDateString());

        $this->artisan('reports:gerar-automatico')->assertSuccessful();

        $totalDoPeriodo = Report::where('obra_id', $obra->id)
            ->whereDate('periodo_referencia', Carbon::today()->startOfWeek()->toDateString())
            ->count();

        $this->assertSame(1, $totalDoPeriodo);
    }

    public function test_obra_sem_importacao_de_avanco_nao_quebra_o_comando(): void
    {
        $obra = Work::factory()->create([
            'tenant_id' => $this->tenant->id,
            'dia_semana_report' => Carbon::today()->dayOfWeek,
        ]);
        $this->raiz = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $obra->id,
        ]);
        // Só linha de base importada — nenhuma elegível pra Realizado, então
        // gerarRascunho() lança RuntimeException, que o comando precisa
        // engolir sem quebrar as demais obras.
        CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $obra->id,
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'data_status' => Carbon::today()->toDateString(),
            'importado_em' => now(),
        ]);
        $this->criarReportAnterior($obra, Carbon::today()->subWeek()->startOfWeek()->toDateString());

        $this->artisan('reports:gerar-automatico')->assertSuccessful();

        $this->assertSame(1, Report::where('obra_id', $obra->id)->count());
    }

    public function test_obra_sem_dia_semana_report_configurado_nunca_gera(): void
    {
        $obra = $this->criarObraComAvanco(null);
        $this->criarReportAnterior($obra, Carbon::today()->subWeek()->startOfWeek()->toDateString());

        $this->artisan('reports:gerar-automatico')->assertSuccessful();

        $this->assertSame(1, Report::where('obra_id', $obra->id)->count());
    }

    public function test_obra_sem_report_anterior_nao_quebra_o_comando(): void
    {
        $obra = $this->criarObraComAvanco(Carbon::today()->dayOfWeek);
        // Nenhum report anterior criado de propósito — nada pra copiar a
        // definição de curvas, o comando deve pular sem quebrar.

        $this->artisan('reports:gerar-automatico')->assertSuccessful();

        $this->assertSame(0, Report::where('obra_id', $obra->id)->count());
    }
}
