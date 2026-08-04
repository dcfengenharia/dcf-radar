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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mesma classe de bug já corrigida em `emitir()` (ver
 * ReportEmitirRefreshHidratacaoTest), achada agora em
 * `removerPontoAtencaoEdit()`/`salvarPontosAtencao()`: os dois chamam
 * `$this->report->load('curvas.pontosAtencao')` depois de mutar um
 * ponto de atenção. `Model::load('curvas.pontosAtencao')` faz uma
 * query nova pra 'curvas', cria instâncias NOVAS de ReportCurva (só com
 * 'pontosAtencao' carregada) e chama `setRelation('curvas', ...)` no
 * Report — isso SUBSTITUI inteiramente `$this->report->relations
 * ['curvas']`, descartando 'desvios'/'desvios.restricaoImpacto'/
 * 'desvios.pacoteTrabalho'/'datapoints'/'pacoteTrabalho' que o
 * hydrate() tinha acabado de restaurar. Mesmo mecanismo comprovado por
 * leitura de `Illuminate\Database\Eloquent\Builder::eagerLoadRelations()`
 * → `HasOneOrMany::matchOneOrMany()` → `Model::setRelation()` (sem
 * lógica de merge em nenhum ponto do pipeline).
 *
 * Reproduz o cenário mínimo: Report com 2 curvas assimétricas (uma com
 * desvio + um ponto de atenção existente, outra sem nenhum desvio) +
 * hydrate() presente + ação real (nunca '$refresh' sintético) + render
 * completo.
 */
class ReportPontosAtencaoRefreshHidratacaoTest extends TestCase
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
     * Report com DUAS curvas assimétricas — curva 1 COM um desvio
     * (restricaoImpacto null, mas carregada) e UM ponto de atenção já
     * existente; curva 2 SEM nenhum desvio (coleção vazia). Retorna
     * [$report, $curva1, $pontoAtencao].
     */
    private function criarReportComCurvaAssimetricaEPontoAtencao(): array
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

        $pacote1 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
        $curva1 = ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacote1->id,
        ]);
        $curva1->desvios()->create([
            'tenant_id' => $this->tenant->id,
            'pacote_trabalho_id' => $pacote1->id,
            'eh_nivel_pai' => true,
            'titulo_exibicao' => 'Pacote sem impacto de restrições',
            'peso' => 1.0,
            'percentual_previsto' => 50.0,
            'percentual_real' => 50.0,
            'percentual_desvio' => 0.0,
            'percentual_impacto' => 0.0,
            'ordem' => 0,
        ]);
        $pontoAtencao = $curva1->pontosAtencao()->create([
            'tenant_id' => $this->tenant->id,
            'categoria' => 'Clima',
            'texto' => 'Chuva atrasou a frente 2.',
            'ordem' => 0,
        ]);

        // Segunda curva, propositalmente SEM nenhum desvio.
        $pacote2 = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
        ReportCurva::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacote2->id,
        ]);

        return [$report, $curva1, $pontoAtencao];
    }

    public function test_remover_ponto_atencao_com_curvas_assimetricas_nao_dispara_lazy_load_no_render_pos_acao(): void
    {
        [$report, $curva1, $pontoAtencao] = $this->criarReportComCurvaAssimetricaEPontoAtencao();
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        // mount() já sincronizou pontosAtencaoEdit via sincronizarPontosAtencaoEdit().
        $this->assertSame(
            $pontoAtencao->id,
            $component->instance()->pontosAtencaoEdit[$curva1->id][0]['id']
        );

        // Ação real — nunca '$refresh' sintético.
        $component->call('removerPontoAtencaoEdit', $curva1->id, 0)->assertOk();

        // Render pós-ação consegue avaliar os computeds que iteram
        // curvas.desvios/curvas.datapoints sem LazyLoadingViolationException.
        $this->assertIsArray($component->instance()->topRiscos);
        $this->assertIsArray($component->instance()->impactoRestricoes);
        $this->assertIsArray($component->instance()->dadosGraficos);

        $this->assertDatabaseMissing('report_pontos_atencao', ['id' => $pontoAtencao->id]);
    }

    public function test_salvar_pontos_atencao_com_curvas_assimetricas_nao_dispara_lazy_load_no_render_pos_acao(): void
    {
        [$report, $curva1, $pontoAtencao] = $this->criarReportComCurvaAssimetricaEPontoAtencao();
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        // Edita o texto do ponto já existente (via wire:model, síncrono
        // com a ação real de salvar — mesmo padrão real do formulário).
        $component->set("pontosAtencaoEdit.{$curva1->id}.0.texto", 'Chuva forte atrasou a frente 2 e 3.');

        // Ação real — nunca '$refresh' sintético.
        $component->call('salvarPontosAtencao', $curva1->id)->assertOk();

        $this->assertIsArray($component->instance()->topRiscos);
        $this->assertIsArray($component->instance()->impactoRestricoes);
        $this->assertIsArray($component->instance()->dadosGraficos);

        $this->assertSame(
            'Chuva forte atrasou a frente 2 e 3.',
            $pontoAtencao->fresh()->texto
        );
    }

    /**
     * Fluxo de CRIAÇÃO (branch $ponto['id'] === null de
     * salvarPontosAtencao) — nunca exercitado pelo teste anterior, que
     * só cobria edição de um ponto já existente. adicionarPontoAtencaoEdit()
     * em si não faz nenhuma query (só array_push em pontosAtencaoEdit);
     * o load() problemático só entra em jogo em salvarPontosAtencao().
     */
    public function test_adicionar_e_salvar_novo_ponto_atencao_com_curvas_assimetricas_nao_dispara_lazy_load(): void
    {
        [$report, $curva1] = $this->criarReportComCurvaAssimetricaEPontoAtencao();
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $component->call('adicionarPontoAtencaoEdit', $curva1->id)->assertOk();
        $novoIndice = count($component->instance()->pontosAtencaoEdit[$curva1->id]) - 1;
        $component->set("pontosAtencaoEdit.{$curva1->id}.{$novoIndice}.texto", 'Atraso na entrega de material.');

        $component->call('salvarPontosAtencao', $curva1->id)->assertOk();

        $this->assertIsArray($component->instance()->topRiscos);
        $this->assertIsArray($component->instance()->impactoRestricoes);
        $this->assertIsArray($component->instance()->dadosGraficos);

        $this->assertSame(
            'Atraso na entrega de material.',
            $curva1->fresh()->pontosAtencao->firstWhere('texto', 'Atraso na entrega de material.')?->texto
        );
    }

    /**
     * Verificação DIRETA (não só "não lançou exceção") de que TODAS as
     * relações de curvas pedidas continuam carregadas — em AMBAS as
     * curvas, inclusive a curva 2 (sem desvio) — depois de
     * removerPontoAtencaoEdit()/salvarPontosAtencao(). Cobre exatamente
     * as 6 relações citadas no pedido de correção.
     */
    public function test_remover_ponto_atencao_preserva_todas_as_relacoes_de_curvas(): void
    {
        [$report, $curva1, $pontoAtencao] = $this->criarReportComCurvaAssimetricaEPontoAtencao();
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $component->call('removerPontoAtencaoEdit', $curva1->id, 0)->assertOk();

        $curvas = $component->instance()->report->curvas;
        $this->assertCount(2, $curvas);

        foreach ($curvas as $curva) {
            $this->assertTrue($curva->relationLoaded('datapoints'), "curva {$curva->id}: datapoints não carregada");
            $this->assertTrue($curva->relationLoaded('desvios'), "curva {$curva->id}: desvios não carregada");
            $this->assertTrue($curva->relationLoaded('pacoteTrabalho'), "curva {$curva->id}: pacoteTrabalho não carregada");
            $this->assertTrue($curva->relationLoaded('pontosAtencao'), "curva {$curva->id}: pontosAtencao não carregada");

            foreach ($curva->desvios as $desvio) {
                $this->assertTrue($desvio->relationLoaded('restricaoImpacto'), "desvio {$desvio->id}: restricaoImpacto não carregada");
                $this->assertTrue($desvio->relationLoaded('pacoteTrabalho'), "desvio {$desvio->id}: pacoteTrabalho não carregada");
            }
        }

        // curva1 deve ter ficado com 0 pontos de atenção após a remoção.
        $this->assertCount(0, $curvas->firstWhere('id', $curva1->id)->pontosAtencao);
    }

    public function test_salvar_pontos_atencao_preserva_todas_as_relacoes_de_curvas(): void
    {
        [$report, $curva1, $pontoAtencao] = $this->criarReportComCurvaAssimetricaEPontoAtencao();
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        $component->set("pontosAtencaoEdit.{$curva1->id}.0.texto", 'Chuva forte atrasou a frente 2 e 3.');
        $component->call('salvarPontosAtencao', $curva1->id)->assertOk();

        $curvas = $component->instance()->report->curvas;
        $this->assertCount(2, $curvas);

        foreach ($curvas as $curva) {
            $this->assertTrue($curva->relationLoaded('datapoints'), "curva {$curva->id}: datapoints não carregada");
            $this->assertTrue($curva->relationLoaded('desvios'), "curva {$curva->id}: desvios não carregada");
            $this->assertTrue($curva->relationLoaded('pacoteTrabalho'), "curva {$curva->id}: pacoteTrabalho não carregada");
            $this->assertTrue($curva->relationLoaded('pontosAtencao'), "curva {$curva->id}: pontosAtencao não carregada");

            foreach ($curva->desvios as $desvio) {
                $this->assertTrue($desvio->relationLoaded('restricaoImpacto'), "desvio {$desvio->id}: restricaoImpacto não carregada");
                $this->assertTrue($desvio->relationLoaded('pacoteTrabalho'), "desvio {$desvio->id}: pacoteTrabalho não carregada");
            }
        }
    }
}
