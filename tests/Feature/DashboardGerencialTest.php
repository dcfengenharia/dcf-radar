<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\Papel;
use App\Enums\PilarLean;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\CausaNaoCumprimento;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportCurvaDatapoint;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardGerencialTest extends TestCase
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
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        ObraContext::set($this->obra);
    }

    public function test_pagina_renderiza_com_dados_reais(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::EmExecucao->value,
        ]);

        $categoria = CategoriaRestricao::create([
            'tenant_id' => $this->tenant->id,
            'nome' => 'Materiais',
            'pilar_lean' => PilarLean::Materiais->value,
        ]);

        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'categoria_id' => $categoria->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
            'aberta_em' => now()->subDays(10),
        ]);

        CausaNaoCumprimento::create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'created_by_id' => $this->user->id,
            'descricao' => 'Chuva',
        ]);

        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);

        $report = Report::factory()->emitido()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $importacao->id,
        ]);

        $curva = ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => null,
            'titulo_exibicao' => 'Obra Inteira',
        ]);

        $semana = now()->startOfWeek();
        foreach ([SerieAvanco::Previsto, SerieAvanco::Tendencia, SerieAvanco::Realizado] as $serie) {
            ReportCurvaDatapoint::create([
                'tenant_id' => $this->tenant->id,
                'report_curva_id' => $curva->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => $serie->value,
                'periodo_inicio' => $semana,
                'horas' => 40,
                'percentual_acumulado' => $serie === SerieAvanco::Realizado ? 45.5 : 50.0,
            ]);
        }

        Livewire::test('pages::radar.dashboard', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee('Aderência')
            ->assertSee('Avanço Físico Acumulado')
            ->assertSee('Restrições Abertas')
            ->assertSee('Chuva')
            ->assertSee('91%'); // 45.5/50*100 arredondado
    }

    public function test_pagina_renderiza_sem_report_emitido(): void
    {
        Livewire::test('pages::radar.dashboard', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee('Ainda não há nenhum Report emitido');
    }

    public function test_menu_mostra_item_dashboard_por_padrao(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $atividade->id]);

        $this->get(route('radar.dashboard'))
            ->assertOk()
            ->assertSeeLivewire('pages::radar.dashboard');
    }

    public function test_seletor_troca_obra_atual_e_redireciona(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);

        Livewire::test('pages::radar.dashboard', ['obra' => $this->obra])
            ->call('trocarObra', $outraObra->id)
            ->assertRedirect(route('radar.dashboard'));

        $this->assertSame($outraObra->id, ObraContext::current()->id);
    }

    public function test_seletor_nao_permite_trocar_para_obra_sem_acesso(): void
    {
        $outroTenant = Tenant::factory()->create();
        $obraDeOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        Livewire::test('pages::radar.dashboard', ['obra' => $this->obra])
            ->call('trocarObra', $obraDeOutroTenant->id)
            ->assertForbidden();
    }

    /**
     * Regressão do bug relatado pelo usuário: trocar a obra pelo seletor e
     * dar F5 (nova requisição HTTP real) tinha que mostrar os dados da
     * NOVA obra, não os da obra anterior. O bug real era o redirect usar
     * navigate:true pra uma URL IDÊNTICA à atual — o wire:navigate tratava
     * como no-op e nunca refazia o fetch, então a página nunca recarregava
     * de verdade.
     */
    public function test_apos_trocar_obra_pagina_recarregada_mostra_dados_da_nova_obra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Nova']);
        $this->vincularObra($outraObra, $this->user, Papel::GerentePlanejamento->value);

        $atividadeOutraObra = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $outraObra->id,
        ]);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $atividadeOutraObra->id]);

        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $outraObra->id,
            'importado_em' => now(),
        ]);

        $report = Report::factory()->emitido()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $outraObra->id,
            'cronograma_importacao_id' => $importacao->id,
        ]);

        $curva = ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => null,
        ]);

        $semana = now()->startOfWeek();
        foreach ([SerieAvanco::Previsto, SerieAvanco::Realizado] as $serie) {
            ReportCurvaDatapoint::create([
                'tenant_id' => $this->tenant->id,
                'report_curva_id' => $curva->id,
                'granularidade' => GranularidadePeriodo::Semanal->value,
                'serie' => $serie->value,
                'periodo_inicio' => $semana,
                'horas' => 40,
                'percentual_acumulado' => $serie === SerieAvanco::Realizado ? 30.0 : 40.0,
            ]);
        }

        Livewire::test('pages::radar.dashboard', ['obra' => $this->obra])
            ->call('trocarObra', $outraObra->id);

        $this->assertSame($outraObra->id, ObraContext::current()->id);

        // Simula o F5/nova navegação real que acontece após o redirect.
        $this->get(route('radar.dashboard'))
            ->assertOk()
            ->assertSee('Obra Nova')
            ->assertSee('75%'); // 30/40*100 arredondado — só existe na obra nova
    }

    public function test_curva_geral_e_pacotes_ate_nivel_2_aparecem_no_seletor(): void
    {
        $pacoteNivel1 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => '1',
            'nome' => 'Estrutura',
        ]);

        $pacoteNivel2 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $pacoteNivel1->id,
            'codigo' => '1.1',
            'nome' => 'Fundação',
        ]);

        $pacoteNivel3 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $pacoteNivel2->id,
            'codigo' => '1.1.1',
            'nome' => 'Sapatas',
        ]);

        Livewire::test('pages::radar.dashboard', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee($this->obra->name . ' (Geral)')
            ->assertSee('Estrutura')
            ->assertSee('Fundação')
            ->assertDontSee('Sapatas')
            ->set('curvaSelecionada', $pacoteNivel1->id)
            ->assertOk();
    }
}
