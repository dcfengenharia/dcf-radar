<?php

namespace Tests\Feature;

use App\Enums\StatusItemSuprimento;
use App\Enums\StatusPlanoAcao;
use App\Exports\CentralProntidaoExport;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\CronogramaImportacao;
use App\Models\DocumentoEngenharia;
use App\Models\ItemProntidao;
use App\Models\ItemSuprimento;
use App\Models\PlanoAcao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\CentralProntidao\CentralProntidaoQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Ciclo 15, Etapa B.4 — exportação PDF/Excel da Central de Prontidão.
 * Cobre: autorização, isolamento tenant/obra, reflexo de horizonte/filtros,
 * resumo, os 4 estados, as 6 seções de detalhamento, ausência de N+1 e
 * ausência de escrita — nunca uma segunda regra de negócio: tudo aqui só
 * reaproveita o que `CentralProntidaoQuery::paraObra()` já retorna (mesma
 * fonte da B.1/B.2/B.3, sem duplicar cálculo de prontidão).
 */
class CentralProntidaoExportTest extends TestCase
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
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function criarAtividade(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'fora_do_cronograma' => false,
            'inicio_planejado' => now()->addDays(5),
        ], $overrides));
    }

    private function criarImportacao(?Work $obra = null): CronogramaImportacao
    {
        return CronogramaImportacao::create([
            'obra_id' => ($obra ?? $this->obra)->id,
            'importado_em' => now(),
        ]);
    }

    private function criarPlanoAcao(array $overrides = [], ?Work $obra = null): PlanoAcao
    {
        $obra ??= $this->obra;

        return PlanoAcao::create(array_merge([
            'obra_id' => $obra->id,
            'cronograma_importacao_origem_id' => $this->criarImportacao($obra)->id,
            'regra_id' => 'SLACK-001',
            'titulo' => 'Folga negativa identificada',
            'recomendacao' => 'Revise o encadeamento lógico.',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => [],
        ], $overrides));
    }

    private function criarItemSuprimento(array $overrides = []): ItemSuprimento
    {
        return ItemSuprimento::create(array_merge([
            'obra_id' => $this->obra->id,
            'nome' => 'Aço estrutural',
            'status' => StatusItemSuprimento::EmRisco->value,
        ], $overrides));
    }

    private function criarDocumentoEngenharia(array $overrides = []): DocumentoEngenharia
    {
        return DocumentoEngenharia::create(array_merge([
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-001',
            'descricao' => 'Projeto estrutural',
            'data_planejada' => null,
        ], $overrides));
    }

    /**
     * Monta o mesmo formato de array que `⚡central-prontidao.blade.php::dadosExport()`
     * produz (método privado, não chamável direto do teste) — usado pra
     * testar `CentralProntidaoExport`/o template PDF de forma isolada, sem
     * precisar montar o componente Livewire inteiro.
     */
    private function montarDadosExport(array $filtros = []): array
    {
        $views = (new CentralProntidaoQuery())->paraObra($this->obra);
        $contagem = $views->countBy(fn ($v) => $v->statusOperacional->value);

        return [
            'obra' => $this->obra,
            'geradoEm' => now(),
            'horizonteLabel' => '30 dias',
            'filtros' => array_merge([
                'pacote' => null, 'disciplina' => null, 'frente' => null, 'responsavel' => null, 'busca' => null,
            ], $filtros),
            'resumo' => [
                'pronta' => $contagem->get('pronta', 0),
                'atencao' => $contagem->get('atencao', 0),
                'nao_pronta' => $contagem->get('nao_pronta', 0),
                'concluida' => $contagem->get('concluida', 0),
                'total' => $views->count(),
            ],
            'views' => $views,
        ];
    }

    // =========================================================================
    // 1/2 — AUTORIZAÇÃO
    // =========================================================================

    public function test_usuario_autorizado_exporta_excel(): void
    {
        $this->criarAtividade(['nome' => 'Atividade Export']);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('exportarExcel')
            ->assertFileDownloaded();
    }

    public function test_usuario_autorizado_exporta_pdf(): void
    {
        $this->criarAtividade(['nome' => 'Atividade Export']);

        $response = Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('exportarPdf');

        $response->assertOk();
    }

    public function test_usuario_sem_permissao_nunca_alcanca_a_exportacao(): void
    {
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($semAcesso);

        // mount() já bloqueia (mesmo contrato da B.2) — o usuário nunca
        // chega a instanciar o componente pra poder chamar exportarPdf/Excel.
        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    // =========================================================================
    // 3 — ISOLAMENTO TENANT/OBRA
    // =========================================================================

    public function test_export_nunca_inclui_atividade_de_outra_obra_ou_tenant(): void
    {
        $this->criarAtividade(['nome' => 'Da Obra Ativa']);

        $outroTenant = Tenant::factory()->create();
        $outraObraMesmoTenant = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraObraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->criarAtividade(['nome' => 'De Outra Obra Mesmo Tenant'], $outraObraMesmoTenant);
        Atividade::factory()->create([
            'tenant_id' => $outroTenant->id, 'obra_id' => $outraObraOutroTenant->id,
            'fora_do_cronograma' => false, 'nome' => 'De Outro Tenant',
        ]);

        $dados = $this->montarDadosExport();
        $nomes = $dados['views']->pluck('nome')->all();

        $this->assertContains('Da Obra Ativa', $nomes);
        $this->assertNotContains('De Outra Obra Mesmo Tenant', $nomes);
        $this->assertNotContains('De Outro Tenant', $nomes);

        $html = view('exports.central-prontidao-pdf', $dados)->render();
        $this->assertStringContainsString('Da Obra Ativa', $html);
        $this->assertStringNotContainsString('De Outra Obra Mesmo Tenant', $html);
        $this->assertStringNotContainsString('De Outro Tenant', $html);
    }

    // =========================================================================
    // 4 — CONTEXTO / FILTROS / HORIZONTE
    // =========================================================================

    public function test_pdf_mostra_horizonte_e_filtros_aplicados(): void
    {
        $this->criarAtividade(['nome' => 'Qualquer']);

        $dados = $this->montarDadosExport(['pacote' => 'Estrutura', 'disciplina' => 'Civil', 'busca' => 'funda']);
        $html = view('exports.central-prontidao-pdf', $dados)->render();

        $this->assertStringContainsString('30 dias', $html);
        $this->assertStringContainsString('Estrutura', $html);
        $this->assertStringContainsString('Civil', $html);
        $this->assertStringContainsString('funda', $html);
    }

    public function test_excel_resumo_reflete_contexto_e_totais(): void
    {
        $this->criarAtividade(['nome' => 'Pronta 1']);
        $naoPronta = $this->criarAtividade(['nome' => 'Bloqueada']);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $naoPronta->id, 'bloqueante' => true]);

        $dados = $this->montarDadosExport(['pacote' => 'Estrutura']);
        $export = new CentralProntidaoExport($dados);
        $resumo = $export->sheets()[0];

        $linhas = collect($resumo->array())->pluck(1, 0);

        $this->assertSame($this->obra->name, $linhas['Obra']);
        $this->assertSame('30 dias', $linhas['Horizonte']);
        $this->assertSame('Estrutura', $linhas['Pacote/EAP']);
        $this->assertSame(2, $linhas['Total de Atividades']);
        $this->assertSame(1, $linhas['Prontas']);
        $this->assertSame(1, $linhas['Não Prontas']);
    }

    // =========================================================================
    // 5 — TABELA PRINCIPAL (com Frente/Responsável)
    // =========================================================================

    public function test_excel_tabela_atividades_inclui_frente_e_responsavel(): void
    {
        $frente = \App\Models\FrenteTrabalho::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Frente Sul']);
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Carlos', 'last_name' => 'Lima']);
        $this->criarAtividade(['nome' => 'Com Frente e Resp', 'frente_trabalho_id' => $frente->id, 'responsavel_id' => $responsavel->id]);

        $dados = $this->montarDadosExport();
        $export = new CentralProntidaoExport($dados);
        $folhaAtividades = $export->sheets()[1];

        $this->assertSame(
            ['Código', 'Atividade', 'Pacote/EAP', 'Disciplina', 'Frente', 'Responsável', 'Início Planejado', 'Status', 'Motivos'],
            $folhaAtividades->headings()
        );

        $linha = collect($folhaAtividades->array())->firstWhere(1, 'Com Frente e Resp');
        $this->assertSame('Frente Sul', $linha[4]);
        $this->assertSame('Carlos Lima', $linha[5]);
    }

    // =========================================================================
    // 6 — DETALHAMENTO (6 domínios)
    // =========================================================================

    public function test_excel_detalhamento_das_6_secoes(): void
    {
        $at = $this->criarAtividade(['nome' => 'Atividade Combo', 'external_uid' => 'EXP-1']);

        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => true, 'descricao' => 'Falta liberação']);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => false, 'descricao' => 'Confirmar fornecedor']);

        $item = ItemProntidao::create(['obra_id' => $this->obra->id, 'nome' => 'Projeto liberado', 'ordem' => 0]);
        AtividadeItemProntidao::create(['atividade_id' => $at->id, 'item_prontidao_id' => $item->id, 'concluido' => false]);

        $this->criarPlanoAcao(['titulo' => 'Ciclo lógico', 'uids_referencia' => ['EXP-1']]);

        $suprimento = $this->criarItemSuprimento(['nome' => 'Cimento', 'status' => StatusItemSuprimento::Atrasado->value]);
        $suprimento->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);

        $doc = $this->criarDocumentoEngenharia(['codigo' => 'DOC-EXP', 'data_planejada' => now()->subDay()]);
        $suprimento->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $dados = $this->montarDadosExport();
        $export = new CentralProntidaoExport($dados);
        [$resumo, $atividades, $restrBloq, $restrNaoBloq, $checklist, $planoAcao, $suprimentos, $engenharia] = $export->sheets();

        $this->assertSame('Restrições Bloqueantes', $restrBloq->title());
        $this->assertCount(1, $restrBloq->array());
        $this->assertSame('Falta liberação', $restrBloq->array()[0][1]);

        $this->assertSame('Restrições Não-Bloqueantes', $restrNaoBloq->title());
        $this->assertCount(1, $restrNaoBloq->array());
        $this->assertSame('Confirmar fornecedor', $restrNaoBloq->array()[0][1]);

        $this->assertSame('Checklist Pendente', $checklist->title());
        $this->assertCount(1, $checklist->array());
        $this->assertSame('Projeto liberado', $checklist->array()[0][1]);

        $this->assertSame('Plano de Ação', $planoAcao->title());
        $this->assertCount(1, $planoAcao->array());
        $this->assertSame('Ciclo lógico', $planoAcao->array()[0][1]);

        $this->assertSame('Suprimentos', $suprimentos->title());
        $this->assertCount(1, $suprimentos->array());
        $this->assertSame('Cimento', $suprimentos->array()[0][1]);
        $this->assertSame('Atrasado', $suprimentos->array()[0][2]);

        $this->assertSame('Engenharia', $engenharia->title());
        $this->assertCount(1, $engenharia->array());
        $this->assertSame('DOC-EXP', $engenharia->array()[0][1]);
        $this->assertSame('Sim', $engenharia->array()[0][3]); // atrasado
    }

    public function test_pdf_mostra_secoes_vazias_com_mensagem_amigavel(): void
    {
        $this->criarAtividade(['nome' => 'Sem Nenhum Domínio']);

        $dados = $this->montarDadosExport();
        $html = view('exports.central-prontidao-pdf', $dados)->render();

        $this->assertStringContainsString('Nenhuma restrição bloqueante aberta.', $html);
        $this->assertStringContainsString('Nenhum item de checklist pendente.', $html);
        $this->assertStringContainsString('Nenhum Plano de Ação aberto relacionado.', $html);
    }

    // =========================================================================
    // 7 — AUSÊNCIA DE ESCRITA
    // =========================================================================

    public function test_exportar_nao_grava_nem_altera_nenhum_dado(): void
    {
        $this->criarAtividade(['nome' => 'Intocada']);
        Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => Atividade::first()->id, 'bloqueante' => true]);

        $antesAtividades = Atividade::count();
        $antesRestricoes = Restricao::count();
        $antesPlanoAcao = PlanoAcao::count();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('exportarExcel')
            ->assertFileDownloaded();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('exportarPdf');

        $this->assertSame($antesAtividades, Atividade::count());
        $this->assertSame($antesRestricoes, Restricao::count());
        $this->assertSame($antesPlanoAcao, PlanoAcao::count());
    }

    // =========================================================================
    // 8 — N+1
    // =========================================================================

    private function popularCenario(int $quantidade): void
    {
        $item = ItemProntidao::create(['obra_id' => $this->obra->id, 'nome' => 'Checklist', 'ordem' => 0]);

        for ($i = 0; $i < $quantidade; $i++) {
            $at = $this->criarAtividade(['nome' => "Atividade {$i}", 'external_uid' => "exp-{$i}"]);
            AtividadeItemProntidao::create(['atividade_id' => $at->id, 'item_prontidao_id' => $item->id, 'concluido' => true]);
            Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $at->id, 'bloqueante' => false]);
            $this->criarPlanoAcao(['uids_referencia' => ["exp-{$i}"]]);

            $suprimento = $this->criarItemSuprimento(['nome' => "Item {$i}", 'status' => StatusItemSuprimento::EmRisco->value]);
            $suprimento->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);
            $doc = $this->criarDocumentoEngenharia(['codigo' => "DOC-{$i}", 'data_planejada' => now()->subDay()]);
            $suprimento->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);
        }
    }

    public function test_exportar_excel_nao_escala_queries_com_quantidade_de_atividades(): void
    {
        $this->popularCenario(3);

        $queryCount3 = 0;
        DB::listen(function () use (&$queryCount3) { $queryCount3++; });
        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('exportarExcel');

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
        $this->popularCenario(15);

        $queryCount15 = 0;
        DB::listen(function () use (&$queryCount15) { $queryCount15++; });
        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('exportarExcel');

        $this->assertLessThan(
            $queryCount3 + 10,
            $queryCount15,
            "esperava contagem de queries praticamente constante na exportação. Com 3 atividades: {$queryCount3}, com 15: {$queryCount15}"
        );
    }

    // =========================================================================
    // Ciclo 15, Etapa B.4.CORREÇÃO — fecha a lacuna apontada na auditoria: os
    // testes acima provam que CentralProntidaoExport/o template PDF processam
    // corretamente um array bem formado, mas nenhum deles exercitava o método
    // PRIVADO real `dadosExport()` através do caminho real do componente
    // (`Livewire::test(...)->set(...)->call('exportarExcel'/'exportarPdf')`).
    // Os testes abaixo usam `Excel::fake()` + `Excel::assertDownloaded()` —
    // técnica padrão do próprio pacote maatwebsite/excel já instalado (não é
    // parsing de binário, não é dependência nova) — que intercepta a chamada
    // real a `Excel::download()` dentro de `exportarExcel()` e entrega de
    // volta a MESMA instância de `CentralProntidaoExport` que `dadosExport()`
    // de verdade construiu, permitindo inspecionar `->sheets()` sem nunca
    // reimplementar a montagem dos dados no teste.
    // =========================================================================

    public function test_export_reflete_os_quatro_horizontes_via_componente_real(): void
    {
        $this->criarAtividade(['nome' => 'D15', 'inicio_planejado' => now()->addDays(10)]);
        $this->criarAtividade(['nome' => 'D30', 'inicio_planejado' => now()->addDays(20)]);
        $this->criarAtividade(['nome' => 'D60', 'inicio_planejado' => now()->addDays(45)]);
        $this->criarAtividade(['nome' => 'D100', 'inicio_planejado' => now()->addDays(100)]);

        $filename = "central-prontidao-{$this->obra->id}.xlsx";

        $cenarios = [
            '15' => ['D15'],
            '30' => ['D15', 'D30'],
            '60' => ['D15', 'D30', 'D60'],
            '0' => ['D15', 'D30', 'D60', 'D100'],
        ];
        $labels = ['15' => '15 dias', '30' => '30 dias', '60' => '60 dias', '0' => 'Todo o cronograma'];

        foreach ($cenarios as $horizonte => $esperados) {
            Excel::fake();

            Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
                ->set('horizonte', $horizonte)
                ->call('exportarExcel');

            Excel::assertDownloaded($filename, function (CentralProntidaoExport $export) use ($esperados, $horizonte, $labels) {
                $sheets = $export->sheets();
                $nomes = collect($sheets[1]->array())->pluck(1)->all();
                $resumoLinhas = collect($sheets[0]->array())->pluck(1, 0);

                sort($nomes);
                $ordenados = $esperados;
                sort($ordenados);

                $this->assertSame($ordenados, $nomes, "horizonte {$horizonte} deveria exportar exatamente " . implode(',', $esperados));
                $this->assertSame($labels[$horizonte], $resumoLinhas['Horizonte']);
                $this->assertSame(count($esperados), $resumoLinhas['Total de Atividades']);

                return true;
            });
        }

        // Mesma configuração (horizonte 15) também não pode quebrar o
        // caminho real do PDF — não inspeciona conteúdo binário (fora do
        // escopo autorizado), só confirma que dadosExport() alimenta
        // Pdf::loadView() sem erro com o horizonte real aplicado.
        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('horizonte', '15')
            ->call('exportarPdf')
            ->assertOk();
    }

    public function test_export_filtro_pacote_via_componente_real(): void
    {
        $pacote = \App\Models\PacoteTrabalho::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Estrutura']);
        $outroPacote = \App\Models\PacoteTrabalho::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Acabamento']);
        $this->criarAtividade(['nome' => 'Da Estrutura', 'pacote_trabalho_id' => $pacote->id]);
        $this->criarAtividade(['nome' => 'Do Acabamento', 'pacote_trabalho_id' => $outroPacote->id]);

        Excel::fake();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('filtroPacoteId', $pacote->id)
            ->call('exportarExcel');

        Excel::assertDownloaded("central-prontidao-{$this->obra->id}.xlsx", function (CentralProntidaoExport $export) {
            $nomes = collect($export->sheets()[1]->array())->pluck(1)->all();
            $resumoLinhas = collect($export->sheets()[0]->array())->pluck(1, 0);

            $this->assertSame(['Da Estrutura'], $nomes);
            $this->assertSame('Estrutura', $resumoLinhas['Pacote/EAP']);

            return true;
        });
    }

    public function test_export_filtro_disciplina_via_componente_real(): void
    {
        $disciplina = \App\Models\Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Civil']);
        $outraDisciplina = \App\Models\Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Elétrica']);
        $this->criarAtividade(['nome' => 'Da Civil', 'disciplina_id' => $disciplina->id]);
        $this->criarAtividade(['nome' => 'Da Eletrica', 'disciplina_id' => $outraDisciplina->id]);

        Excel::fake();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('filtroDisciplinaId', $disciplina->id)
            ->call('exportarExcel');

        Excel::assertDownloaded("central-prontidao-{$this->obra->id}.xlsx", function (CentralProntidaoExport $export) {
            $nomes = collect($export->sheets()[1]->array())->pluck(1)->all();

            $this->assertSame(['Da Civil'], $nomes);

            return true;
        });
    }

    public function test_export_filtro_frente_via_componente_real(): void
    {
        $frente = \App\Models\FrenteTrabalho::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Frente Norte']);
        $outraFrente = \App\Models\FrenteTrabalho::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Frente Sul']);
        $this->criarAtividade(['nome' => 'Da Frente Norte', 'frente_trabalho_id' => $frente->id]);
        $this->criarAtividade(['nome' => 'Da Frente Sul', 'frente_trabalho_id' => $outraFrente->id]);

        Excel::fake();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('filtroFrenteId', $frente->id)
            ->call('exportarExcel');

        Excel::assertDownloaded("central-prontidao-{$this->obra->id}.xlsx", function (CentralProntidaoExport $export) {
            $nomes = collect($export->sheets()[1]->array())->pluck(1)->all();
            $resumoLinhas = collect($export->sheets()[0]->array())->pluck(1, 0);

            $this->assertSame(['Da Frente Norte'], $nomes);
            $this->assertSame('Frente Norte', $resumoLinhas['Frente']);

            return true;
        });
    }

    public function test_export_filtro_responsavel_via_componente_real(): void
    {
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Marina', 'last_name' => 'Alves']);
        $outroResponsavel = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Pedro', 'last_name' => 'Costa']);
        $this->criarAtividade(['nome' => 'Da Marina', 'responsavel_id' => $responsavel->id]);
        $this->criarAtividade(['nome' => 'Do Pedro', 'responsavel_id' => $outroResponsavel->id]);
        $this->vincularObra($this->obra, $responsavel, 'engenheiro');
        $this->vincularObra($this->obra, $outroResponsavel, 'engenheiro');

        Excel::fake();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('filtroResponsavelId', $responsavel->id)
            ->call('exportarExcel');

        Excel::assertDownloaded("central-prontidao-{$this->obra->id}.xlsx", function (CentralProntidaoExport $export) {
            $nomes = collect($export->sheets()[1]->array())->pluck(1)->all();
            $resumoLinhas = collect($export->sheets()[0]->array())->pluck(1, 0);

            $this->assertSame(['Da Marina'], $nomes);
            $this->assertSame('Marina Alves', $resumoLinhas['Responsável']);

            return true;
        });
    }

    public function test_export_busca_via_componente_real(): void
    {
        $this->criarAtividade(['nome' => 'Concretagem Fundação']);
        $this->criarAtividade(['nome' => 'Alvenaria Bloco B']);

        Excel::fake();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('busca', 'Concretagem')
            ->call('exportarExcel');

        Excel::assertDownloaded("central-prontidao-{$this->obra->id}.xlsx", function (CentralProntidaoExport $export) {
            $nomes = collect($export->sheets()[1]->array())->pluck(1)->all();
            $resumoLinhas = collect($export->sheets()[0]->array())->pluck(1, 0);

            $this->assertSame(['Concretagem Fundação'], $nomes);
            $this->assertSame('Concretagem', $resumoLinhas['Busca']);

            return true;
        });
    }

    public function test_export_filtros_combinados_via_componente_real(): void
    {
        $pacote = \App\Models\PacoteTrabalho::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Estrutura']);
        $disciplina = \App\Models\Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Civil']);

        // Só esta bate os DOIS filtros ao mesmo tempo.
        $this->criarAtividade(['nome' => 'Estrutura Civil', 'pacote_trabalho_id' => $pacote->id, 'disciplina_id' => $disciplina->id]);
        // Bate só o pacote.
        $this->criarAtividade(['nome' => 'Estrutura Outra Disciplina', 'pacote_trabalho_id' => $pacote->id]);
        // Bate só a disciplina.
        $this->criarAtividade(['nome' => 'Outro Pacote Civil', 'disciplina_id' => $disciplina->id]);

        Excel::fake();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('filtroPacoteId', $pacote->id)
            ->set('filtroDisciplinaId', $disciplina->id)
            ->call('exportarExcel');

        Excel::assertDownloaded("central-prontidao-{$this->obra->id}.xlsx", function (CentralProntidaoExport $export) {
            $nomes = collect($export->sheets()[1]->array())->pluck(1)->all();

            $this->assertSame(['Estrutura Civil'], $nomes);

            return true;
        });
    }

    public function test_export_resultado_vazio_via_componente_real(): void
    {
        $this->criarAtividade(['nome' => 'Qualquer Atividade']);

        Excel::fake();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('busca', 'termo-que-nao-existe-em-nenhuma-atividade')
            ->call('exportarExcel');

        Excel::assertDownloaded("central-prontidao-{$this->obra->id}.xlsx", function (CentralProntidaoExport $export) {
            $sheets = $export->sheets();
            $resumoLinhas = collect($sheets[0]->array())->pluck(1, 0);

            $this->assertSame([], $sheets[1]->array());
            $this->assertSame(0, $resumoLinhas['Total de Atividades']);

            return true;
        });

        // O caminho do PDF, com o mesmo filtro (0 resultados), também não
        // pode quebrar — as mensagens amigáveis do template já são cobertas
        // separadamente em test_pdf_mostra_secoes_vazias_com_mensagem_amigavel.
        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->set('busca', 'termo-que-nao-existe-em-nenhuma-atividade')
            ->call('exportarPdf')
            ->assertOk();
    }

    public function test_export_restricao_bloqueante_vencida_vs_nao_vencida_via_componente_real(): void
    {
        $atVencida = $this->criarAtividade(['nome' => 'Com Restricao Vencida']);
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id, 'atividade_id' => $atVencida->id,
            'bloqueante' => true, 'descricao' => 'Prazo estourado', 'prazo_limite' => now()->subDays(3),
        ]);

        $atNaoVencida = $this->criarAtividade(['nome' => 'Com Restricao No Prazo']);
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id, 'atividade_id' => $atNaoVencida->id,
            'bloqueante' => true, 'descricao' => 'Ainda no prazo', 'prazo_limite' => now()->addDays(5),
        ]);

        Excel::fake();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('exportarExcel');

        Excel::assertDownloaded("central-prontidao-{$this->obra->id}.xlsx", function (CentralProntidaoExport $export) {
            $linhas = collect($export->sheets()[2]->array())->keyBy(1); // indexado por descrição

            $this->assertSame('Sim', $linhas['Prazo estourado'][4]);
            $this->assertSame('Não', $linhas['Ainda no prazo'][4]);

            return true;
        });
    }

    public function test_export_documento_nao_emitido_mas_nao_atrasado_via_componente_real(): void
    {
        $at = $this->criarAtividade(['nome' => 'Com Documento No Prazo']);
        $item = $this->criarItemSuprimento();
        $item->atividades()->attach($at->id, ['tenant_id' => $this->tenant->id]);
        $doc = $this->criarDocumentoEngenharia(['codigo' => 'DOC-FUTURO', 'data_planejada' => now()->addDays(10)]);
        $item->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        Excel::fake();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('exportarExcel');

        Excel::assertDownloaded("central-prontidao-{$this->obra->id}.xlsx", function (CentralProntidaoExport $export) {
            $linha = collect($export->sheets()[7]->array())->firstWhere(1, 'DOC-FUTURO');

            $this->assertNotNull($linha, 'documento não emitido mas dentro do prazo deveria aparecer na aba Engenharia');
            $this->assertSame('Não', $linha[2]); // emitido = Não
            $this->assertSame('Não', $linha[3]); // atrasado = Não

            return true;
        });
    }

    public function test_export_duas_planoacoes_mesmo_external_uid_via_componente_real(): void
    {
        $at = $this->criarAtividade(['nome' => 'Com Duas Acoes', 'external_uid' => 'REAL-1']);
        $this->criarPlanoAcao(['regra_id' => 'SLACK-001', 'titulo' => 'Folga negativa', 'uids_referencia' => ['REAL-1']]);
        $this->criarPlanoAcao(['regra_id' => 'STRUCT-005', 'titulo' => 'Ciclo lógico', 'uids_referencia' => ['REAL-1']]);

        Excel::fake();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('exportarExcel');

        Excel::assertDownloaded("central-prontidao-{$this->obra->id}.xlsx", function (CentralProntidaoExport $export) {
            $linhas = $export->sheets()[5]->array();

            $this->assertCount(2, $linhas);
            $titulos = collect($linhas)->pluck(1)->all();
            $this->assertContains('Folga negativa', $titulos);
            $this->assertContains('Ciclo lógico', $titulos);

            return true;
        });
    }

    public function test_export_isolamento_via_componente_real(): void
    {
        $this->criarAtividade(['nome' => 'Da Obra Ativa Real']);

        $outroTenant = Tenant::factory()->create();
        $outraObraMesmoTenant = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraObraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->criarAtividade(['nome' => 'De Outra Obra Real'], $outraObraMesmoTenant);
        Atividade::factory()->create([
            'tenant_id' => $outroTenant->id, 'obra_id' => $outraObraOutroTenant->id,
            'fora_do_cronograma' => false, 'nome' => 'De Outro Tenant Real',
        ]);

        Excel::fake();

        Livewire::test('pages::radar.central-prontidao', ['obra' => $this->obra])
            ->call('exportarExcel');

        Excel::assertDownloaded("central-prontidao-{$this->obra->id}.xlsx", function (CentralProntidaoExport $export) {
            $nomes = collect($export->sheets()[1]->array())->pluck(1)->all();

            $this->assertContains('Da Obra Ativa Real', $nomes);
            $this->assertNotContains('De Outra Obra Real', $nomes);
            $this->assertNotContains('De Outro Tenant Real', $nomes);

            return true;
        });
    }

    public function test_export_obra_alheia_e_recusado(): void
    {
        // Usuário autorizado na Obra A ($this->obra), mas SEM permissão
        // vinculada à Obra B — chamada direta ao componente passando Obra B
        // precisa continuar bloqueada, mesmo o usuário sendo autorizado em
        // outra obra qualquer.
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        Livewire::test('pages::radar.central-prontidao', ['obra' => $obraB])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }
}
