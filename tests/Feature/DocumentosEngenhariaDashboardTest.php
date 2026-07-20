<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\StatusDocumento;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentosEngenhariaDashboardTest extends TestCase
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
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
    }

    private function componente()
    {
        return Livewire::test('pages::engenharia.documentos-engenharia');
    }

    public function test_aba_dashboard_renderiza_estado_vazio_sem_documentos(): void
    {
        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('setAba', 'dashboard')
            ->assertSet('abaAtiva', 'dashboard')
            ->assertSee('Nenhum documento cadastrado nesta obra ainda')
            ->assertOk();
    }

    public function test_aba_dashboard_renderiza_graficos_com_dados(): void
    {
        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Civil']);
        $status = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Aprovado', 'conclusivo' => true]);

        $doc1 = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-1',
            'descricao' => 'Documento 1',
            'disciplina_id' => $disciplina->id,
            'data_planejada' => '2026-07-15',
        ]);
        $doc1->revisoes()->create([
            'tenant_id' => $this->tenant->id,
            'revisao' => 'R0',
            'descricao' => 'Emissão',
            'data_emissao' => '2026-07-20',
            'status_documento_id' => $status->id,
        ]);

        DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-2',
            'descricao' => 'Documento 2',
            'disciplina_id' => $disciplina->id,
            'data_planejada' => now()->subDays(3)->toDateString(),
        ]);

        $component = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('setAba', 'dashboard');

        $component->assertOk()
            ->assertSee('Curva S de Emissões — Mensal')
            ->assertSee('Curva S de Emissões — Semanal')
            ->assertSee('Documentos por Disciplina')
            ->assertSee('Documentos por Status')
            ->assertSee('Aging dos Documentos Atrasados')
            ->assertSee('Top 5 Mais Reprogramados');

        // mês default selecionado deve ser o único mês disponível (julho/2026)
        $this->assertSame('2026-07', $component->get('mesSelecionadoDashboard'));

        $curva = $component->instance()->curvaEmissoes;
        $this->assertSame(2, $curva['total']);
    }

    public function test_trocar_mes_selecionado_filtra_curva_semanal(): void
    {
        DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'D1', 'descricao' => 'D1', 'data_planejada' => '2026-07-15']);
        DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'D2', 'descricao' => 'D2', 'data_planejada' => '2026-09-15']);

        $component = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('setAba', 'dashboard')
            ->set('mesSelecionadoDashboard', '2026-07');

        $semanalJulho = $component->instance()->curvaSemanalFiltrada;
        $this->assertNotEmpty($semanalJulho['labels']);
        foreach ($semanalJulho['labels'] as $label) {
            $this->assertStringStartsWith('2026-07', $label);
        }

        $component->set('mesSelecionadoDashboard', '2026-09');
        $semanalSetembro = $component->instance()->curvaSemanalFiltrada;
        $this->assertNotEmpty($semanalSetembro['labels']);
        foreach ($semanalSetembro['labels'] as $label) {
            $this->assertStringStartsWith('2026-09', $label);
        }
    }

    public function test_top_reprogramados_lista_documentos_com_reprogramacao(): void
    {
        $documento = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-REPROG',
            'descricao' => 'Documento',
            'data_planejada' => '2026-07-01',
        ]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('editarDocumento', $documento->id)
            ->set('dataPrevistaNovo', '2026-08-01')
            ->call('salvarDocumento');

        $top = $this->componente()->set('obraId', $this->obra->id)->instance()->topReprogramados;

        $this->assertCount(1, $top);
        $this->assertSame('DOC-REPROG', $top->first()->codigo);
        $this->assertSame(1, $top->first()->reprogramacoes_count);
    }
}
