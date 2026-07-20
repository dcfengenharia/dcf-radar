<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Models\CronogramaImportacao;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportListaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private CronogramaImportacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);
    }

    private function usuarioComPapel(Papel $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, $papel->value);

        return $user;
    }

    private function criarReport(StatusReport $status): Report
    {
        return Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'status' => $status->value,
        ]);
    }

    public function test_encarregado_so_ve_reports_emitidos_na_lista(): void
    {
        $this->criarReport(StatusReport::Rascunho);
        $emitido = $this->criarReport(StatusReport::Emitido);
        $encarregado = $this->usuarioComPapel(Papel::Encarregado);

        Livewire::actingAs($encarregado)
            ->test('pages::radar.relatorios', ['obra' => $this->obra])
            ->assertSee($emitido->periodo_referencia->format('d/m/Y'))
            ->assertCount('reports', 1);
    }

    public function test_gerente_ve_rascunho_e_emitido_na_lista(): void
    {
        $this->criarReport(StatusReport::Rascunho);
        $this->criarReport(StatusReport::Emitido);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorios', ['obra' => $this->obra])
            ->assertCount('reports', 2);
    }

    public function test_excluir_remove_o_report(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        Livewire::actingAs($gerente)
            ->test('pages::radar.relatorios', ['obra' => $this->obra])
            ->call('excluir', $report->id);

        $this->assertSoftDeleted('reports', ['id' => $report->id]);
    }

    public function test_encarregado_nao_pode_excluir(): void
    {
        $report = $this->criarReport(StatusReport::Emitido);
        $encarregado = $this->usuarioComPapel(Papel::Encarregado);

        Livewire::actingAs($encarregado)
            ->test('pages::radar.relatorios', ['obra' => $this->obra])
            ->call('excluir', $report->id)
            ->assertForbidden();
    }
}
