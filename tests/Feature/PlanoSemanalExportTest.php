<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlanoSemanalExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Engenheiro->value);
        $this->actingAs($this->user);

        Atividade::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Comprometido->value,
            'inicio_planejado' => now(),
            'data_termino' => now()->addDays(2),
        ]);
    }

    public function test_exportar_pdf_dispara_download_de_pdf(): void
    {
        Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra])
            ->call('exportarPdf')
            ->assertFileDownloaded();
    }

    public function test_exportar_excel_dispara_download_de_xlsx(): void
    {
        Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra])
            ->call('exportarExcel')
            ->assertFileDownloaded();
    }
}
