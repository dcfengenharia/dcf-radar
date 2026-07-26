<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\CurvaAjuste;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\CurvaAvanco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurvaAvancoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;
    private CurvaAvanco $curva;
    private ImportadorCronograma $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant       = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->obra   = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->curva  = app(CurvaAvanco::class);
        $this->importer = app(ImportadorCronograma::class);

        // Base: importa v1 em todos os testes — "Ambos" porque este arquivo
        // testa o comportamento genérico de CurvaAvanco (ajustes, obsolência,
        // percentuais) através das 3 séries da mesma importação, não a
        // separação Linha de Base x Avanço em si.
        $plano = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra, TipoCronogramaImportacao::Ambos);
        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'sample.xml', TipoCronogramaImportacao::Ambos);
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    private function calcular(SerieAvanco $serie = SerieAvanco::Previsto,
                               GranularidadePeriodo $gran = GranularidadePeriodo::Semanal): array
    {
        return $this->curva->calcular($this->obra, $serie, $gran);
    }

    // -------------------------------------------------------------------------

    public function test_detalhe_periodo_inclui_datas_de_linha_de_base_por_atividade(): void
    {
        $importacao = \App\Models\CronogramaImportacao::where('obra_id', $this->obra->id)->firstOrFail();
        $lb = \App\Models\LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'LB teste',
            'cronograma_importacao_id' => $importacao->id,
        ]);

        $periodos = $this->curva->calcular($this->obra, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, null, $lb->id);
        $periodo  = $periodos[0]['periodo_inicio'];

        $linhas = $this->curva->detalhePeriodo(
            $this->obra, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, $periodo, null, $lb->id
        );

        $this->assertNotEmpty($linhas);
        foreach ($linhas as $linha) {
            $snap = \App\Models\AtividadeSnapshot::where('cronograma_importacao_id', $importacao->id)
                ->where('atividade_id', $linha['atividade_id'])
                ->first();

            $this->assertNotNull($linha['baseline_inicio']);
            $this->assertNotNull($linha['baseline_termino']);
            $this->assertTrue($linha['baseline_inicio']->isSameDay($snap->baseline_inicio));
            $this->assertTrue($linha['baseline_termino']->isSameDay($snap->baseline_termino));
        }
    }

    public function test_retorna_valores_calculados_sem_ajuste(): void
    {
        $periodos = $this->calcular();

        $this->assertNotEmpty($periodos);
        $this->assertEquals(64.0, round(array_sum(array_column($periodos, 'horas')), 2));

        foreach ($periodos as $p) {
            $this->assertFalse($p['ajustado']);
            $this->assertNull($p['ajuste_id']);
            $this->assertFalse($p['obsoleto']);
        }
    }

    public function test_acumulado_e_percentual_calculados_corretamente(): void
    {
        $periodos = $this->calcular(SerieAvanco::Realizado);

        $totalCalculado = array_sum(array_column($periodos, 'horas'));
        $ultimoPeriodo  = end($periodos);

        $this->assertEquals(round($totalCalculado, 2), round($ultimoPeriodo['acumulado'], 2));
        $this->assertEquals(100.0, round($ultimoPeriodo['percentual'], 2));
    }

    public function test_ajuste_manual_sobrescreve_horas_exibidas(): void
    {
        $periodos = $this->calcular();
        $periodo  = $periodos[0]['periodo_inicio'];
        $horasCalc = $periodos[0]['horas'];

        CurvaAjuste::updateOrCreate(
            [
                'obra_id'        => $this->obra->id,
                'serie'          => 'previsto',
                'granularidade'  => 'semanal',
                'periodo_inicio' => $periodo,
            ],
            [
                'valor_ajustado'            => 45.0,
                'valor_calculado_no_ajuste' => $horasCalc,
                'ajustado_por'              => $this->user->id,
            ]
        );

        $ajustado = collect($this->calcular())->firstWhere('periodo_inicio', $periodo);

        $this->assertEquals(45.0, $ajustado['horas_exibir']);
        $this->assertEquals(round($horasCalc, 2), $ajustado['horas']); // calculado não muda
        $this->assertTrue($ajustado['ajustado']);
        $this->assertEquals($horasCalc, $ajustado['valor_original']);
        $this->assertFalse($ajustado['obsoleto']); // calculado não mudou desde o ajuste
    }

    public function test_ajuste_nao_e_obsoleto_quando_valor_calculado_nao_mudou(): void
    {
        $periodos = $this->calcular();
        $periodo  = $periodos[0]['periodo_inicio'];
        $horas    = $periodos[0]['horas'];

        CurvaAjuste::updateOrCreate(
            [
                'obra_id'        => $this->obra->id,
                'serie'          => 'previsto',
                'granularidade'  => 'semanal',
                'periodo_inicio' => $periodo,
            ],
            [
                'valor_ajustado'            => 42.0,
                'valor_calculado_no_ajuste' => $horas, // snapshot atual = mesmo valor calculado
                'ajustado_por'              => $this->user->id,
            ]
        );

        $p = collect($this->calcular())->firstWhere('periodo_inicio', $periodo);
        $this->assertFalse($p['obsoleto']);
    }

    public function test_reimportacao_com_hh_diferente_sinaliza_ajuste_obsoleto(): void
    {
        // Obtém o período Jan/2024 da semana 2024-01-01 (Task 2 baseline = 40 HH em v1)
        $periodos = $this->calcular();
        $periodo2401 = collect($periodos)->firstWhere('periodo_inicio', '2024-01-01');

        $this->assertNotNull($periodo2401, 'Deve existir período 2024-01-01 para previsto/semanal');
        $this->assertEquals(40.0, $periodo2401['horas']);

        // Cria ajuste com snapshot = 40 HH (valor calculado no momento do ajuste)
        CurvaAjuste::updateOrCreate(
            [
                'obra_id'        => $this->obra->id,
                'serie'          => 'previsto',
                'granularidade'  => 'semanal',
                'periodo_inicio' => '2024-01-01',
            ],
            [
                'valor_ajustado'            => 42.0,
                'valor_calculado_no_ajuste' => 40.0,
                'ajustado_por'              => $this->user->id,
            ]
        );

        // Reimporta v2: Task 2 baseline passou para 50 HH no mesmo período (Jan 1-7)
        $planoV2 = $this->importer->analisar($this->fixture('cronograma_v2.xml'), $this->obra, TipoCronogramaImportacao::Ambos);
        $this->importer->aplicar($planoV2, $this->obra, $this->user->id, 'v2.xml', TipoCronogramaImportacao::Ambos);

        // Após reimportação, calcula curva — usa a importação mais recente (v2)
        $periodosV2 = $this->calcular();
        $p = collect($periodosV2)->firstWhere('periodo_inicio', '2024-01-01');

        $this->assertNotNull($p);
        $this->assertEquals(50.0, $p['horas'], 'Valor calculado deve refletir a reimportação (50 HH)');
        $this->assertTrue($p['ajustado']);
        $this->assertTrue($p['obsoleto'], '|40 - 50| = 10 > 0.01 deve ser sinalizado como obsoleto');
    }

    public function test_ajuste_feito_sem_filtro_nao_vaza_para_curva_filtrada(): void
    {
        // Atividade 1 (UID=2) é classificada como disciplina "Estrutura"
        // no fixture — ver CronogramaImportacaoTest.
        $disciplina = \App\Models\Disciplina::where('nome', 'Estrutura')->firstOrFail();

        $periodosGeral = $this->calcular();
        $periodo       = $periodosGeral[0]['periodo_inicio'];
        $horasGeral    = $periodosGeral[0]['horas'];

        // Ajuste feito SEM nenhum filtro de escopo (curva geral).
        CurvaAjuste::updateOrCreate(
            [
                'obra_id'        => $this->obra->id,
                'serie'          => 'previsto',
                'granularidade'  => 'semanal',
                'periodo_inicio' => $periodo,
            ],
            [
                'valor_ajustado'            => 999.0,
                'valor_calculado_no_ajuste' => $horasGeral,
                'ajustado_por'              => $this->user->id,
            ]
        );

        // A curva geral reflete o ajuste normalmente.
        $ajustadoGeral = collect($this->calcular())->firstWhere('periodo_inicio', $periodo);
        $this->assertTrue($ajustadoGeral['ajustado']);
        $this->assertEquals(999.0, $ajustadoGeral['horas_exibir']);

        // A curva filtrada por Disciplina NÃO deve herdar o ajuste geral —
        // sem isso, o acumulado da curva filtrada (total bem menor) passa
        // de 100% ao aplicar um valor calibrado pra outro total.
        $periodosFiltrado = $this->curva->calcular(
            $this->obra,
            SerieAvanco::Previsto,
            GranularidadePeriodo::Semanal,
            null,
            null,
            null,
            null,
            $disciplina->id,
        );
        $pontoFiltrado = collect($periodosFiltrado)->firstWhere('periodo_inicio', $periodo);

        $this->assertNotNull($pontoFiltrado);
        $this->assertFalse($pontoFiltrado['ajustado'], 'Ajuste da curva geral não deve vazar pra curva filtrada por disciplina');
        $this->assertEquals($pontoFiltrado['horas'], $pontoFiltrado['horas_exibir']);
        $this->assertLessThanOrEqual(100.01, end($periodosFiltrado)['percentual'], 'Acumulado da curva filtrada não pode passar de 100% por causa de vazamento');
    }

    public function test_ajuste_feito_com_filtro_nao_vaza_para_curva_geral(): void
    {
        $disciplina = \App\Models\Disciplina::where('nome', 'Estrutura')->firstOrFail();

        $periodosFiltrado = $this->curva->calcular(
            $this->obra,
            SerieAvanco::Previsto,
            GranularidadePeriodo::Semanal,
            null,
            null,
            null,
            null,
            $disciplina->id,
        );
        $periodo = $periodosFiltrado[0]['periodo_inicio'];

        CurvaAjuste::updateOrCreate(
            [
                'obra_id'        => $this->obra->id,
                'serie'          => 'previsto',
                'granularidade'  => 'semanal',
                'periodo_inicio' => $periodo,
                'disciplina_id'  => $disciplina->id,
            ],
            [
                'valor_ajustado'            => 5.0,
                'valor_calculado_no_ajuste' => $periodosFiltrado[0]['horas'],
                'ajustado_por'              => $this->user->id,
            ]
        );

        $pontoGeral = collect($this->calcular())->firstWhere('periodo_inicio', $periodo);
        $this->assertFalse($pontoGeral['ajustado'], 'Ajuste escopado à disciplina não deve vazar pra curva geral');
    }

    public function test_detalhe_periodo_lista_atividades_que_compoem_o_periodo(): void
    {
        $periodos = $this->calcular(SerieAvanco::Previsto, GranularidadePeriodo::Semanal);
        $primeiro = $periodos[0];

        $detalhe = $this->curva->detalhePeriodo(
            $this->obra,
            SerieAvanco::Previsto,
            GranularidadePeriodo::Semanal,
            $primeiro['periodo_inicio'],
        );

        $this->assertNotEmpty($detalhe);
        $this->assertEquals(
            round((float) $primeiro['horas'], 2),
            round(array_sum(array_column($detalhe, 'horas')), 2),
            'A soma das atividades do detalhe deve bater com o HH calculado da tabela pro mesmo período'
        );

        foreach ($detalhe as $linha) {
            $this->assertArrayHasKey('nome', $linha);
            $this->assertArrayHasKey('percentual', $linha);
        }

        $this->assertEqualsWithDelta(100.0, array_sum(array_column($detalhe, 'percentual')), 0.2);
    }

    public function test_detalhe_periodo_respeita_filtro_de_disciplina(): void
    {
        $disciplina = \App\Models\Disciplina::where('nome', 'Estrutura')->firstOrFail();

        $periodosFiltrado = $this->curva->calcular(
            $this->obra,
            SerieAvanco::Previsto,
            GranularidadePeriodo::Semanal,
            null,
            null,
            null,
            null,
            $disciplina->id,
        );
        $periodo = $periodosFiltrado[0]['periodo_inicio'];

        $detalhe = $this->curva->detalhePeriodo(
            $this->obra,
            SerieAvanco::Previsto,
            GranularidadePeriodo::Semanal,
            $periodo,
            null,
            null,
            null,
            null,
            $disciplina->id,
        );

        $this->assertNotEmpty($detalhe);
        foreach ($detalhe as $linha) {
            $this->assertEquals('Estrutura', $linha['disciplina']);
        }
    }

    public function test_detalhe_periodo_retorna_vazio_para_periodo_sem_dados(): void
    {
        $detalhe = $this->curva->detalhePeriodo(
            $this->obra,
            SerieAvanco::Previsto,
            GranularidadePeriodo::Semanal,
            '1999-01-01',
        );

        $this->assertEmpty($detalhe);
    }

    public function test_hierarquizar_atividades_monta_pacote_e_atividade_aninhados(): void
    {
        $periodos = $this->calcular(SerieAvanco::Previsto, GranularidadePeriodo::Mensal);
        $primeiro = $periodos[0];

        $detalhe = $this->curva->detalhePeriodo(
            $this->obra,
            SerieAvanco::Previsto,
            GranularidadePeriodo::Mensal,
            $primeiro['periodo_inicio'],
        );

        $arvore = $this->curva->hierarquizarAtividades($this->obra, $detalhe);

        $this->assertNotEmpty($arvore);
        $this->assertEquals('pacote', $arvore[0]['tipo']);
        $this->assertEquals(0, $arvore[0]['nivel']);
        $this->assertEquals('Pacote A', $arvore[0]['nome']);

        $linhaAtividade = collect($arvore)->firstWhere('tipo', 'atividade');
        $this->assertNotNull($linhaAtividade, 'Deve ter ao menos uma linha de atividade aninhada sob o pacote');
        $this->assertEquals(1, $linhaAtividade['nivel'], 'Atividade deve estar um nível abaixo do pacote raiz');
        $this->assertNotNull($linhaAtividade['dados']);
    }

    public function test_hierarquizar_atividades_retorna_vazio_para_lista_vazia(): void
    {
        $this->assertEmpty($this->curva->hierarquizarAtividades($this->obra, []));
    }

    public function test_resumo_por_disciplina_agrupa_e_soma_corretamente(): void
    {
        $atividades = [
            ['nome' => 'A', 'disciplina' => 'Estrutura', 'horas' => 40.0],
            ['nome' => 'B', 'disciplina' => null, 'horas' => 24.0],
        ];

        $resumo = $this->curva->resumoPorDisciplina($atividades);

        $this->assertCount(2, $resumo);
        $porNome = collect($resumo)->keyBy('disciplina');
        $this->assertEquals(40.0, $porNome['Estrutura']['horas']);
        $this->assertEquals(62.5, $porNome['Estrutura']['percentual']);
        $this->assertEquals(24.0, $porNome['Sem disciplina']['horas']);
        $this->assertEquals(37.5, $porNome['Sem disciplina']['percentual']);
    }

    public function test_resumo_por_disciplina_retorna_vazio_para_lista_vazia(): void
    {
        $this->assertEmpty($this->curva->resumoPorDisciplina([]));
    }

    public function test_retorna_vazio_sem_importacao(): void
    {
        // Obra sem importação
        $obraVazia = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $periodos = $this->curva->calcular($obraVazia, SerieAvanco::Previsto, GranularidadePeriodo::Mensal);

        $this->assertEmpty($periodos);
    }
}
