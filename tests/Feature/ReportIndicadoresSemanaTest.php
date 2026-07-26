<?php

namespace Tests\Feature;

use App\Enums\SerieAvanco;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\ItemSuprimento;
use App\Models\ItemSuprimentoEtapaData;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\ReportGerador;
use App\Services\SuprimentoScheduler;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportIndicadoresSemanaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->usuario);

        CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'data_status' => now(),
            'importado_em' => now(),
        ]);
    }

    private function gerador(): ReportGerador
    {
        return app(ReportGerador::class);
    }

    private function gerarReport(string $periodoReferencia = null)
    {
        return $this->gerador()->gerarRascunho($this->obra, $this->usuario, [
            'periodo_referencia' => $periodoReferencia ?? now()->toDateString(),
            'curvas' => [],
        ]);
    }

    public function test_restricoes_semana_anterior_conta_previsto_e_concluido_dentro_do_prazo(): void
    {
        $periodoReferencia = now()->startOfWeek();
        $semanaAnterior = $periodoReferencia->copy()->subWeek();

        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        // Concluída dentro do prazo.
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'prazo_limite' => $semanaAnterior->copy()->addDays(2),
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => $semanaAnterior->copy()->addDay(),
        ]);

        // Prevista mas ainda aberta (não concluída).
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'prazo_limite' => $semanaAnterior->copy()->addDays(3),
            'status' => StatusRestricao::Aberta->value,
            'resolvida_em' => null,
        ]);

        $report = $this->gerarReport($periodoReferencia->toDateString());

        $indicador = $report->indicadoresSemana
            ->firstWhere(fn ($i) => $i->categoria === 'restricoes' && $i->janela === 'semana_anterior');

        $this->assertNotNull($indicador);
        $this->assertSame(2, $indicador->total_previsto);
        $this->assertSame(1, $indicador->total_concluido);
        $this->assertCount(2, $indicador->detalhes);
    }

    public function test_restricoes_proxima_semana_conta_apenas_previstas(): void
    {
        $periodoReferencia = now()->startOfWeek();
        $semanaProxima = $periodoReferencia->copy()->addWeek();

        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'prazo_limite' => $semanaProxima->copy()->addDays(1),
            'status' => StatusRestricao::Aberta->value,
        ]);

        $report = $this->gerarReport($periodoReferencia->toDateString());

        $indicador = $report->indicadoresSemana
            ->firstWhere(fn ($i) => $i->categoria === 'restricoes' && $i->janela === 'semana_proxima');

        $this->assertSame(1, $indicador->total_previsto);
        $this->assertNull($indicador->total_concluido);
    }

    public function test_engenharia_semana_anterior_conta_previsto_e_concluido(): void
    {
        $periodoReferencia = now()->startOfWeek();
        $semanaAnterior = $periodoReferencia->copy()->subWeek();

        $doc = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-1',
            'descricao' => 'Documento Teste',
            'data_planejada' => $semanaAnterior->copy()->addDays(2),
        ]);

        $statusConclusivo = \App\Models\StatusDocumento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Aprovado',
            'codigo' => 'APR',
            'cor' => '#00ff00',
            'ordem' => 1,
            'conclusivo' => true,
        ]);

        $doc->revisoes()->create([
            'tenant_id' => $this->tenant->id,
            'status_documento_id' => $statusConclusivo->id,
            'revisao' => 'R0',
            'descricao' => 'Revisão inicial',
            'data_emissao' => $semanaAnterior->copy()->addDay(),
            'criado_por_id' => $this->usuario->id,
        ]);

        DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-2',
            'descricao' => 'Documento Pendente',
            'data_planejada' => $semanaAnterior->copy()->addDays(3),
        ]);

        $report = $this->gerarReport($periodoReferencia->toDateString());

        $indicador = $report->indicadoresSemana
            ->firstWhere(fn ($i) => $i->categoria === 'engenharia' && $i->janela === 'semana_anterior');

        $this->assertSame(2, $indicador->total_previsto);
        $this->assertSame(1, $indicador->total_concluido);
    }

    public function test_suprimentos_semana_anterior_conta_previsto_e_realizado(): void
    {
        $periodoReferencia = now()->startOfWeek();
        $semanaAnterior = $periodoReferencia->copy()->subWeek();

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);
        $etapaTemplate = $fluxo->etapas()->create([
            'tenant_id' => $this->tenant->id,
            'ordem' => 1,
            'nome' => 'Entrega',
            'prazo_dias_uteis' => 5,
        ]);

        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item Teste',
        ]);

        $etapa = $item->etapas()->create([
            'tenant_id' => $this->tenant->id,
            'etapa_fluxo_suprimento_id' => $etapaTemplate->id,
            'ordem' => 1,
            'nome' => 'Entrega',
            'prazo_dias_uteis' => 5,
        ]);

        ItemSuprimentoEtapaData::create([
            'tenant_id' => $this->tenant->id,
            'item_suprimento_etapa_id' => $etapa->id,
            'serie' => SerieAvanco::Previsto->value,
            'data' => $semanaAnterior->copy()->addDays(2),
        ]);
        ItemSuprimentoEtapaData::create([
            'tenant_id' => $this->tenant->id,
            'item_suprimento_etapa_id' => $etapa->id,
            'serie' => SerieAvanco::Realizado->value,
            'data' => $semanaAnterior->copy()->addDays(1),
        ]);

        $report = $this->gerarReport($periodoReferencia->toDateString());

        $indicador = $report->indicadoresSemana
            ->firstWhere(fn ($i) => $i->categoria === 'suprimentos' && $i->janela === 'semana_anterior');

        $this->assertSame(1, $indicador->total_previsto);
        $this->assertSame(1, $indicador->total_concluido);
    }

    public function test_sem_dados_gera_indicador_com_total_zero_sem_erro(): void
    {
        $report = $this->gerarReport();

        $this->assertCount(6, $report->indicadoresSemana);
        foreach ($report->indicadoresSemana as $indicador) {
            $this->assertSame(0, $indicador->total_previsto);
            $this->assertSame([], $indicador->detalhes);
        }
    }

    public function test_indicadores_sao_fotografia_nao_mudam_apos_dado_fonte_mudar(): void
    {
        $periodoReferencia = now()->startOfWeek();
        $semanaAnterior = $periodoReferencia->copy()->subWeek();

        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'prazo_limite' => $semanaAnterior->copy()->addDays(2),
            'status' => StatusRestricao::Aberta->value,
            'resolvida_em' => null,
        ]);

        $report = $this->gerarReport($periodoReferencia->toDateString());

        $indicadorAntes = $report->indicadoresSemana
            ->firstWhere(fn ($i) => $i->categoria === 'restricoes' && $i->janela === 'semana_anterior');
        $this->assertSame(0, $indicadorAntes->total_concluido);

        // Resolve a restrição DEPOIS do report já gerado.
        $restricao->update(['status' => StatusRestricao::Resolvida->value, 'resolvida_em' => now()]);

        $indicadorDepois = $report->indicadoresSemana()
            ->where('categoria', 'restricoes')->where('janela', 'semana_anterior')->first();

        $this->assertSame(0, $indicadorDepois->total_concluido);
    }
}
