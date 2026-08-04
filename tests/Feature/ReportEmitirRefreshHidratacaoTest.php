<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ReportEmitidoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Correção de um bug DISTINTO do que o hydrate() resolve, achado ao
 * investigar o botão Emitir: `emitir()` (componente
 * pages::radar.relatorio-detalhe) chamava `$this->report->refresh()`
 * logo após `ReportGerador::emitir()`. `Illuminate\Database\Eloquent\
 * Model::refresh()` só recarrega os NOMES de relação de primeiro nível
 * já carregados (`collect($this->relations)->keys()`), descartando
 * qualquer caminho aninhado (`curvas.desvios`, `curvas.desvios.
 * restricaoImpacto`, `curvas.datapoints` etc.) que mount()/hydrate()
 * tinham carregado — mesmo com o hydrate() presente e funcionando
 * corretamente, porque hydrate() roda ANTES da ação, e o refresh()
 * desfazia o trabalho dele DENTRO da mesma requisição, logo antes do
 * render() reavaliar topRiscos()/dadosGraficos().
 *
 * Reproduz o cenário mínimo que expõe o bug: um Report com 2 curvas
 * assimétricas (mesma condição já usada em
 * ReportImpactoRestricoesHidratacaoTest para expor o
 * array_intersect() de Collection::getQueueableRelations()) + ação
 * real `emitir()` (nunca `$refresh` sintético) + render completo.
 */
class ReportEmitirRefreshHidratacaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function usuarioComPapel(Papel $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, $papel->value);

        return $user;
    }

    /**
     * Report com DUAS curvas assimétricas — uma COM um ReportDesvio
     * (restricaoImpacto null, mas carregada) e outra SEM nenhum
     * desvio (coleção vazia). É a mesma assimetria usada em
     * ReportImpactoRestricoesHidratacaoTest.
     */
    private function criarReportComCurvaAssimetrica(): Report
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);
        $report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $importacao->id,
            'status' => StatusReport::Rascunho->value,
        ]);

        $pacoteComDesvio = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
        $curvaComDesvio = ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacoteComDesvio->id,
        ]);
        $curvaComDesvio->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $pacoteComDesvio->id,
            'eh_nivel_pai' => true,
            'titulo_exibicao' => 'Pacote sem impacto de restrições',
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 50.0,
            'percentual_desvio' => 0.0,
            'percentual_impacto' => 0.0,
            'ordem' => 0,
        ]);

        // Segunda curva, propositalmente SEM nenhum desvio.
        $pacoteSemDesvio = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
        ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacoteSemDesvio->id,
        ]);

        return $report;
    }

    public function test_emitir_com_curvas_assimetricas_nao_dispara_lazy_load_no_render_pos_acao(): void
    {
        Notification::fake();

        // Passo 1 — Report com 2 curvas assimétricas, reproduzindo a
        // condição real de produção.
        $report = $this->criarReportComCurvaAssimetrica();
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);
        $destinatario = $this->usuarioComPapel(Papel::Encarregado);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        // Passo 2 — ação real (nunca '$refresh' sintético), seguida do
        // render completo que o Livewire sempre faz após uma ação.
        $component->call('emitir')->assertOk();

        // Passo 3 — render pós-ação consegue avaliar topRiscos()/
        // impactoRestricoes()/dadosGraficos() sem LazyLoadingViolationException.
        $this->assertIsArray($component->instance()->topRiscos);
        $this->assertIsArray($component->instance()->impactoRestricoes);
        $this->assertIsArray($component->instance()->dadosGraficos);

        // Passo 4 — status realmente transicionado.
        $report->refresh();
        $this->assertTrue($report->estaEmitido());
        $this->assertSame(StatusReport::Emitido, $report->status);

        // Passo 5 — emitido_por/emitido_em preenchidos.
        $this->assertSame($gerente->id, $report->emitido_por);
        $this->assertNotNull($report->emitido_em);

        // Passo 6 — notificação disparada pro destinatário certo.
        Notification::assertSentTo($destinatario, ReportEmitidoNotification::class);
        Notification::assertNotSentTo($gerente, ReportEmitidoNotification::class);
    }
}
