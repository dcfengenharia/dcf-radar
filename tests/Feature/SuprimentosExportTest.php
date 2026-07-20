<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Atividade;
use App\Models\FluxoSuprimento;
use App\Models\ItemSuprimento;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\SuprimentoScheduler;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SuprimentosExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, Papel::GerentePlanejamento->value);
        $this->actingAs($user);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 5]);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => '2026-09-01',
        ]);

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Exportável',
        ]);
        TenantContext::actingAs($this->tenant, fn () => $item->atividades()->attach($atividade->id));
        $item = $item->fresh(['atividades']);

        $scheduler = new SuprimentoScheduler();
        $scheduler->criarEtapasDoItem($item);
        $scheduler->congelarPrevisto($item);
    }

    public function test_exportar_excel_dispara_download(): void
    {
        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('exportarExcel')
            ->assertFileDownloaded("suprimentos-{$this->obra->id}.xlsx");
    }

    public function test_exportar_pdf_dispara_download(): void
    {
        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obra])
            ->call('exportarPdf')
            ->assertFileDownloaded("suprimentos-{$this->obra->id}.pdf");
    }
}
