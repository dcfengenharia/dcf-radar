<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Models\CronogramaImportacao;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);

        $this->report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $importacao->id,
            'status' => StatusReport::Emitido->value,
        ]);

        $curva = ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $this->report->id,
        ]);

        $curva->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => \App\Models\PacoteTrabalho::factory()->create([
                'tenant_id' => $this->tenant->id,
                'obra_id' => $this->obra->id,
            ])->id,
            'eh_nivel_pai' => true,
            'titulo_exibicao' => 'CIVIL',
            'peso' => 1,
            'percentual_previsto' => 70,
            'percentual_real' => 40,
            'percentual_desvio' => -30,
            'percentual_impacto' => -30,
            'ordem' => 0,
        ]);
    }

    private function gerente(): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, Papel::GerentePlanejamento->value);

        return $user;
    }

    public function test_exportar_pdf_dispara_download(): void
    {
        Livewire::actingAs($this->gerente())
            ->test('pages::radar.relatorio-detalhe', ['report' => $this->report])
            ->call('exportarPdf')
            ->assertFileDownloaded();
    }

    public function test_exportar_excel_dispara_download(): void
    {
        Livewire::actingAs($this->gerente())
            ->test('pages::radar.relatorio-detalhe', ['report' => $this->report])
            ->call('exportarExcel')
            ->assertFileDownloaded();
    }
}
