<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\ReportGerador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ClienteRelatorioPublicoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private User $usuario;
    private PacoteTrabalho $pacote;
    private CronogramaImportacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->usuario);

        $this->importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'data_status' => '2026-01-20',
            'importado_em' => now(),
        ]);

        $this->pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'CIVIL',
            'codigo' => '1',
        ]);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $this->pacote->id,
        ]);

        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => '2026-01-01',
            'horas' => 100,
        ]);
    }

    private function criarReport(bool $emitido): Report
    {
        $report = app(ReportGerador::class)->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => '2026-01-19',
            'curvas' => [
                ['pacote_trabalho_id' => $this->pacote->id, 'ordem' => 0, 'pontos_atencao' => []],
            ],
        ]);

        if ($emitido) {
            app(ReportGerador::class)->emitir($report, $this->usuario);
        }

        return $report->fresh();
    }

    public function test_link_valido_mostra_o_report_emitido(): void
    {
        $report = $this->criarReport(emitido: true);

        $url = URL::temporarySignedRoute('cliente.relatorio.publico', now()->addDays(30), ['report' => $report->id]);

        $this->get($url)
            ->assertOk()
            ->assertSee($this->obra->name)
            ->assertSee('CIVIL');
    }

    public function test_link_sem_assinatura_valida_retorna_403(): void
    {
        $report = $this->criarReport(emitido: true);

        $this->get(route('cliente.relatorio.publico', ['report' => $report->id]))
            ->assertForbidden();
    }

    public function test_link_expirado_retorna_403(): void
    {
        $report = $this->criarReport(emitido: true);

        $url = URL::temporarySignedRoute('cliente.relatorio.publico', now()->subDay(), ['report' => $report->id]);

        $this->get($url)->assertForbidden();
    }

    public function test_link_de_report_em_rascunho_nao_funciona(): void
    {
        $report = $this->criarReport(emitido: false);

        $url = URL::temporarySignedRoute('cliente.relatorio.publico', now()->addDays(30), ['report' => $report->id]);

        $this->get($url)->assertNotFound();
    }

    public function test_link_com_id_inexistente_retorna_404(): void
    {
        $url = URL::temporarySignedRoute('cliente.relatorio.publico', now()->addDays(30), ['report' => (string) \Illuminate\Support\Str::ulid()]);

        $this->get($url)->assertNotFound();
    }

    public function test_botao_gerar_link_so_aparece_para_report_emitido(): void
    {
        $rascunho = $this->criarReport(emitido: false);
        $this->actingAs($this->usuario);
        $this->vincularObra($this->obra, $this->usuario, \App\Enums\Papel::GerentePlanejamento->value);

        \Livewire\Livewire::test('pages::radar.relatorio-detalhe', ['report' => $rascunho])
            ->assertDontSee('Link para o cliente');
    }

    public function test_gerar_link_cliente_produz_url_valida(): void
    {
        $report = $this->criarReport(emitido: true);
        $this->actingAs($this->usuario);
        $this->vincularObra($this->obra, $this->usuario, \App\Enums\Papel::GerentePlanejamento->value);

        $componente = \Livewire\Livewire::test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->call('gerarLinkCliente');

        $url = $componente->get('linkClienteGerado');
        $this->assertStringContainsString('/cliente/relatorio/'.$report->id, $url);
        $this->assertStringContainsString('signature=', $url);

        $this->get($url)->assertOk();
    }
}
