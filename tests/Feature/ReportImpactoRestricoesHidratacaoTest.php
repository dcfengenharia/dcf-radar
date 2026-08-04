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
 * Correção do bug reportado em produção: `Attempted to lazy load
 * [restricaoImpacto] on model [App\Models\ReportDesvio] but lazy loading
 * is disabled`. Causa raiz: Livewire 4 (Model::getQueueableRelations())
 * usa isset() pra decidir quais relações reaplicar ->with() ao
 * rehidratar um componente entre requisições — isset() retorna false
 * pra uma relação HasOne carregada com valor NULL, então
 * restricaoImpacto (frequentemente null — ReportDesvio sem restrição
 * aberta) é descartada silenciosamente pelo Livewire numa requisição
 * SUBSEQUENTE à primeira, mesmo tendo sido eager-carregada em mount().
 * Corrigido com loadMissing() no início de impactoRestricoes() —
 * idempotente, não duplica query no primeiro load.
 *
 * A interação real que dispara o bug em produção é o clique no botão de
 * salvar o Título do Report (⚡relatorio-detalhe.blade.php, ícone de
 * disquete no cabeçalho, `wire:click="salvarTitulo"`) — NÃO uma edição
 * de perfil de usuário (hipótese inicial descartada por investigação:
 * `UpdateProfileInformationForm` nunca participa dessa requisição).
 * `salvarTitulo()` é uma ação real DENTRO do próprio componente
 * `pages::radar.relatorio-detalhe`; Livewire sempre re-renderiza o
 * template inteiro do componente a cada ação, então esse clique força a
 * reavaliação de `$this->topRiscos` (mais abaixo na mesma página), que
 * chama `impactoRestricoes()` → `$desvio->restricaoImpacto`.
 *
 * Uma primeira versão deste teste usava `call('$refresh')` pra simular a
 * requisição subsequente e NÃO reproduziu a falha — achado explicado
 * lendo `Livewire\Features\SupportTesting\Testable::call()`: `$refresh`
 * cai em `commit()` → `update()` SEM `calls` e SEM `updates`, um
 * round-trip vazio. Uma ação real (`salvarTitulo`) percorre
 * `update(calls: [...])`, incluindo a mutação real do model
 * (`$this->report->update([...])`) — caminho de código bem mais
 * próximo do que acontece no navegador. Por isso este teste chama a
 * ação de verdade, nunca `$refresh`.
 *
 * Uma segunda versão (com 1 única curva/1 único desvio) TAMBÉM não
 * reproduziu a falha mesmo já usando `salvarTitulo` de verdade — achado
 * explicado lendo `Illuminate\Database\Eloquent\Collection::
 * getQueueableRelations()`: quando uma coleção Eloquent tem 2+ itens,
 * o método faz `array_intersect(...)` entre as relações "queueáveis"
 * de CADA item. Uma curva SEM nenhum desvio retorna cedo `[]`
 * (`if ($this->isEmpty()) return [];`) pra sua própria coleção de
 * desvios — então, ao agregar múltiplas curvas via
 * `array_intersect(...)`, `desvios.restricaoImpacto` (presente nas
 * curvas COM desvio) é descartado do conjunto final, porque a curva
 * SEM desvio não contribui esse caminho. Com 1 curva só (ou todas as
 * curvas tendo exatamente o mesmo conjunto de relações), a intersecção
 * é trivial e não derruba nada — por isso o cenário mínimo de 1
 * curva/1 desvio não reproduzia. Este teste usa 2 curvas: uma COM um
 * desvio (restricaoImpacto null, mas carregada) e outra SEM nenhum
 * desvio — a combinação que reproduz a falha real de produção.
 */
class ReportImpactoRestricoesHidratacaoTest extends TestCase
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
     * Cria o cenário que reproduz a falha real: um Report com DUAS
     * curvas — uma COM um ReportDesvio sem snapshot da Etapa C2
     * (restricaoImpacto carrega como null, mas É carregada) e outra
     * SEM nenhum desvio (coleção vazia). É a assimetria entre as duas
     * curvas que aciona o array_intersect() de
     * Collection::getQueueableRelations() e derruba
     * 'desvios.restricaoImpacto' da lista de relações reaplicadas pelo
     * Livewire na rehidratação (ver docblock da classe).
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

    public function test_salvarTitulo_com_restricaoImpacto_null_nao_dispara_lazy_load(): void
    {
        // Passo 1 — Report com duas curvas assimétricas (uma com desvio
        // sem restricaoImpacto, outra sem nenhum desvio), reproduzindo
        // exatamente a condição de produção.
        $report = $this->criarReportComCurvaAssimetrica();
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $component = Livewire::actingAs($gerente)
            ->test('pages::radar.relatorio-detalhe', ['report' => $report])
            ->assertOk();

        // Primeira leitura — sempre funcionou, mount() eager-carrega via
        // 'curvas.desvios.restricaoImpacto'.
        $this->assertIsArray($component->instance()->topRiscos);

        // Passo 2 — reproduz a interação REAL do botão de disquete do
        // Título: preenche o campo (wire:model="tituloEdit", deferido) e
        // executa a ação de verdade (wire:click="salvarTitulo"). Nunca
        // '$refresh', que é um round-trip vazio (ver docblock da classe).
        $component
            ->set('tituloEdit', 'Report Semana de Teste — Interação Real')
            ->call('salvarTitulo')
            ->assertOk();

        // Passo 3 — com loadMissing() presente em impactoRestricoes(),
        // salvarTitulo() termina sem LazyLoadingViolationException e o
        // re-render subsequente consegue avaliar topRiscos normalmente.
        $this->assertIsArray($component->instance()->topRiscos);
        $this->assertIsArray($component->instance()->impactoRestricoes);

        // O título foi realmente persistido — confirma que a ação real
        // (não um no-op) foi executada até o fim.
        $this->assertSame(
            'Report Semana de Teste — Interação Real',
            $report->fresh()->titulo
        );
    }
}
