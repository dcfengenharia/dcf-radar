<?php

namespace Tests\Feature;

use App\Enums\OrigemAtividade;
use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeSnapshot;
use App\Models\AvancoPeriodo;
use App\Models\Disciplina;
use App\Models\Etapa;
use App\Models\FrenteTrabalho;
use App\Models\ItemProntidao;
use App\Models\PacoteTrabalho;
use App\Models\Restricao;
use App\Models\AtividadeAnexo;
use App\Models\AtividadeComentario;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CronogramaImportacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;
    private ImportadorCronograma $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant       = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->obra   = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->importer = app(ImportadorCronograma::class);
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    // -------------------------------------------------------------------------
    // Prévia (analisar)
    // -------------------------------------------------------------------------

    public function test_analise_conta_criadas_e_totais_corretos(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);

        $this->assertCount(2, $plano->criar);      // Atividade 1 + Atividade 2
        $this->assertCount(0, $plano->atualizar);
        $this->assertCount(0, $plano->removerIds);
        $this->assertEquals(64.0, $plano->totalBaselineHh);
        $this->assertEquals(64.0, $plano->totalWorkHh);
        $this->assertEquals(24.0, $plano->totalRealHh);
        $this->assertEquals('2024-01-31', $plano->dataStatus->toDateString());
    }

    // -------------------------------------------------------------------------
    // Aplicar — estrutura criada
    // -------------------------------------------------------------------------

    public function test_aplicar_cria_pacote_e_atividades_com_campos_corretos(): void
    {
        $plano     = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $importacao = $this->importer->aplicar($plano, $this->obra, $this->user->id, 'sample.xml');

        // Contagens (BelongsToTenant filtra pelo tenant do user autenticado)
        $this->assertEquals(1, PacoteTrabalho::where('obra_id', $this->obra->id)->count());
        $this->assertEquals(2, Atividade::where('obra_id', $this->obra->id)->count());

        // Campos de Atividade 1 (UID=2)
        $at1 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();
        $this->assertNotNull($at1);
        $this->assertEquals(40.0, (float) $at1->baseline_horas);
        $this->assertEquals(40.0, (float) $at1->work_horas);
        $this->assertEquals(16.0, (float) $at1->real_horas);
        $this->assertTrue($at1->caminho_critico);
        $this->assertEquals(OrigemAtividade::MsProject, $at1->origem);
        $this->assertFalse((bool) $at1->fora_do_cronograma);

        // ExtendedAttribute Text20/21/22 capturados em textos (json)
        $this->assertEquals(
            ['Text20' => 'Elétrica', 'Text21' => 'Estrutura', 'Text22' => 'Berço 3'],
            $at1->textos
        );

        // PercentWorkComplete (campo nativo do MSPDI, não Texto customizado)
        $this->assertEquals(40.0, (float) $at1->percentual_concluido);

        // Atividade 2 (UID=3) não tem PercentWorkComplete no fixture — fica null, não 0
        $at2 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '3')->first();
        $this->assertNull($at2->percentual_concluido);

        // Snapshot da importação registrado
        $this->assertEquals(2, $importacao->criadas);
        $this->assertEquals(0, $importacao->atualizadas);
        $this->assertEquals(0, $importacao->removidas);
    }

    // -------------------------------------------------------------------------
    // Classificação automática via Texto21 (disciplina) / Texto22 (frente)
    // -------------------------------------------------------------------------

    public function test_importacao_classifica_disciplina_e_frente_de_trabalho_via_texto21_22(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'sample.xml');

        $at1 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $this->assertNotNull($at1->disciplina_id);
        $this->assertEquals('Estrutura', $at1->disciplina->nome);

        $this->assertNotNull($at1->frente_trabalho_id);
        $this->assertEquals('Berço 3', $at1->frenteTrabalho->nome);
        $this->assertEquals($this->obra->id, $at1->frenteTrabalho->obra_id);

        // Não duplica registros já existentes
        $this->assertEquals(1, Disciplina::where('nome', 'Estrutura')->count());
        $this->assertEquals(1, FrenteTrabalho::where('nome', 'Berço 3')->count());

        // Atividade 2 (UID=3) não tem Texto21/22 no fixture — permanece sem classificação
        $at2 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '3')->first();
        $this->assertNull($at2->disciplina_id);
        $this->assertNull($at2->frente_trabalho_id);
    }

    public function test_importacao_reconhece_campos_customizados_em_portugues(): void
    {
        // Reproduz o bug real: MS Project em português exporta os campos
        // customizados como "Texto20/21/22", não "Text20/21/22" (inglês).
        $plano = $this->importer->analisar($this->fixture('cronograma_texto_pt.xml'), $this->obra);
        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'pt.xml');

        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $this->assertNotNull($at->etapa_id);
        $this->assertEquals('MOBILIZAÇÃO', $at->etapa->nome);
        $this->assertNotNull($at->disciplina_id);
        $this->assertEquals('ENGENHARIA', $at->disciplina->nome);
        $this->assertNotNull($at->frente_trabalho_id);
        $this->assertEquals('Berço 3', $at->frenteTrabalho->nome);
    }

    public function test_importacao_cria_snapshot_historico_por_tarefa(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $importacao = $this->importer->aplicar($plano, $this->obra, $this->user->id, 'sample.xml');

        $at1 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $snapshot = AtividadeSnapshot::where('cronograma_importacao_id', $importacao->id)
            ->where('atividade_id', $at1->id)
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->inicio_planejado->isSameDay($at1->inicio_planejado));
        $this->assertTrue($snapshot->baseline_inicio->isSameDay($at1->baseline_inicio));

        // Reimportar cria um segundo snapshot, ligado à nova importação
        $planoV2 = $this->importer->analisar($this->fixture('cronograma_v2.xml'), $this->obra);
        $importacaoV2 = $this->importer->aplicar($planoV2, $this->obra, $this->user->id, 'v2.xml');

        $this->assertEquals(
            2,
            AtividadeSnapshot::where('atividade_id', $at1->id)->count(),
            'Cada importação deve gerar seu próprio snapshot, sem apagar o anterior'
        );

        $snapshotV2 = AtividadeSnapshot::where('cronograma_importacao_id', $importacaoV2->id)
            ->where('atividade_id', $at1->id)
            ->first();
        // v2 aumenta o baseline de 40 para 50 HH, e as datas mudam de 01-14/jan para 01-21/jan
        $this->assertEquals('2024-01-21', $snapshotV2->baseline_termino->toDateString());
        // o snapshot da v1 continua intacto com a data antiga
        $this->assertEquals('2024-01-14', $snapshot->fresh()->baseline_termino->toDateString());
    }

    public function test_reimportacao_nao_apaga_classificacao_manual_quando_xml_nao_traz_texto(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'sample.xml');

        $at2 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '3')->first();
        $disciplinaManual = Disciplina::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $at2->update(['disciplina_id' => $disciplinaManual->id]);

        // Reimporta o mesmo XML (Atividade 2 continua sem Texto21 no fixture)
        $planoB = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoB, $this->obra, $this->user->id, 'sample.xml');

        $this->assertEquals($disciplinaManual->id, $at2->fresh()->disciplina_id);
    }

    public function test_importacao_classifica_faturamento_direto_entregavel_e_equipe_via_texto23_24_25(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_texto_pt.xml'), $this->obra);
        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'pt.xml');

        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $this->assertTrue((bool) $at->faturamento_direto);
        $this->assertNotNull($at->entregavel_id);
        $this->assertEquals('Projeto Executivo', $at->entregavel->nome);
        $this->assertNotNull($at->equipe_responsavel_id);
        $this->assertEquals('Equipe Alpha', $at->equipeResponsavel->nome);
    }

    public function test_reimportacao_nao_apaga_faturamento_direto_manual_quando_xml_nao_traz_texto(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'sample.xml');

        $at2 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '3')->first();
        $at2->update(['faturamento_direto' => true]);

        // Reimporta o mesmo XML (Atividade 2 continua sem Texto23 no fixture)
        $planoB = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoB, $this->obra, $this->user->id, 'sample.xml');

        $this->assertTrue((bool) $at2->fresh()->faturamento_direto);
    }

    // -------------------------------------------------------------------------
    // Regra 1: recurso Material (Type=2) ignorado nos avanco_periodos
    // Regra 2: Value é duração ISO (PT..H..M..S)
    // Regra 3: ponto médio
    // -------------------------------------------------------------------------

    public function test_avanco_periodos_totais_por_serie_conferem(): void
    {
        // Linha de base (seção OBRA): só grava Previsto.
        $planoBaseline = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra, TipoCronogramaImportacao::Baseline);
        $importacaoBaseline = $this->importer->aplicar($planoBaseline, $this->obra, $this->user->id, 'sample.xml', TipoCronogramaImportacao::Baseline);

        // Previsto (Baseline): Task2=40 + Task3=24 = 64. Os PT999H do Material devem ser ignorados.
        $previsto = (float) AvancoPeriodo::where('cronograma_importacao_id', $importacaoBaseline->id)
            ->where('serie', 'previsto')
            ->where('granularidade', 'semanal')
            ->sum('horas');
        $this->assertEquals(64.0, $previsto, 'Regra 1: Material Type=2 deve ser ignorado (999 HH)');

        // Mensal também fecha
        $prevMensal = (float) AvancoPeriodo::where('cronograma_importacao_id', $importacaoBaseline->id)
            ->where('serie', 'previsto')
            ->where('granularidade', 'mensal')
            ->sum('horas');
        $this->assertEquals(64.0, $prevMensal);

        // A importação de Baseline nunca grava Realizado/Tendência.
        $this->assertEquals(0, AvancoPeriodo::where('cronograma_importacao_id', $importacaoBaseline->id)->where('serie', 'realizado')->count());
        $this->assertEquals(0, AvancoPeriodo::where('cronograma_importacao_id', $importacaoBaseline->id)->where('serie', 'tendencia')->count());

        // Importação de Avanço (seção Relatórios): mesmo XML, agora só
        // atualiza progresso das atividades já existentes e grava
        // Realizado/Tendência — nunca Previsto.
        $planoAvanco = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra, TipoCronogramaImportacao::Avanco);
        $importacaoAvanco = $this->importer->aplicar($planoAvanco, $this->obra, $this->user->id, 'sample.xml', TipoCronogramaImportacao::Avanco);

        // Realizado (Actual): Task2=16 + Task3=8 = 24
        $realizado = (float) AvancoPeriodo::where('cronograma_importacao_id', $importacaoAvanco->id)
            ->where('serie', 'realizado')
            ->where('granularidade', 'semanal')
            ->sum('horas');
        $this->assertEquals(24.0, $realizado);

        // Tendência (Actual+Remaining): (16+24) + (8+16) = 64
        $tendencia = (float) AvancoPeriodo::where('cronograma_importacao_id', $importacaoAvanco->id)
            ->where('serie', 'tendencia')
            ->where('granularidade', 'semanal')
            ->sum('horas');
        $this->assertEquals(64.0, $tendencia);

        $this->assertEquals(0, AvancoPeriodo::where('cronograma_importacao_id', $importacaoAvanco->id)->where('serie', 'previsto')->count());
    }

    // -------------------------------------------------------------------------
    // Separação Linha de Base (OBRA) x Realizado/Tendência (Relatórios)
    // -------------------------------------------------------------------------

    public function test_modo_avanco_nunca_cria_atividade_nova_lista_como_ignorada(): void
    {
        $planoBaseline = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra, TipoCronogramaImportacao::Baseline);
        $this->importer->aplicar($planoBaseline, $this->obra, $this->user->id, 'sample.xml', TipoCronogramaImportacao::Baseline);

        $this->assertEquals(2, Atividade::where('obra_id', $this->obra->id)->count());

        // O fixture de avanço traz uma tarefa nova (UID=4, "Atividade Nova")
        // que não existe na linha de base.
        $planoAvanco = $this->importer->analisar($this->fixture('cronograma_avanco_com_nova_tarefa.xml'), $this->obra, TipoCronogramaImportacao::Avanco);

        $this->assertCount(0, $planoAvanco->criar, 'Modo Avanço nunca deve propor criação de atividade');
        $this->assertContains('Atividade Nova', $planoAvanco->ignoradasNomes);

        $this->importer->aplicar($planoAvanco, $this->obra, $this->user->id, 'avanco.xml', TipoCronogramaImportacao::Avanco);

        // Continua só com as 2 atividades da linha de base — nada foi criado.
        $this->assertEquals(2, Atividade::where('obra_id', $this->obra->id)->count());
        $this->assertNull(Atividade::where('obra_id', $this->obra->id)->where('external_uid', '4')->first());
    }

    public function test_modo_avanco_nunca_mexe_em_pacote_trabalho(): void
    {
        $planoBaseline = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra, TipoCronogramaImportacao::Baseline);
        $this->importer->aplicar($planoBaseline, $this->obra, $this->user->id, 'sample.xml', TipoCronogramaImportacao::Baseline);

        $this->assertEquals(1, PacoteTrabalho::where('obra_id', $this->obra->id)->count());
        $pacoteAntes = PacoteTrabalho::where('obra_id', $this->obra->id)->first();

        $planoAvanco = $this->importer->analisar($this->fixture('cronograma_avanco_com_nova_tarefa.xml'), $this->obra, TipoCronogramaImportacao::Avanco);
        $this->assertCount(0, $planoAvanco->pacotes, 'Modo Avanço nunca deve propor mudanças de pacote');

        $this->importer->aplicar($planoAvanco, $this->obra, $this->user->id, 'avanco.xml', TipoCronogramaImportacao::Avanco);

        $this->assertEquals(1, PacoteTrabalho::where('obra_id', $this->obra->id)->count());
        $this->assertEquals($pacoteAntes->nome, $pacoteAntes->fresh()->nome);
        $this->assertEquals($pacoteAntes->codigo, $pacoteAntes->fresh()->codigo);
    }

    public function test_modo_avanco_atualiza_so_progresso_preserva_baseline_e_classificacao(): void
    {
        $planoBaseline = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra, TipoCronogramaImportacao::Baseline);
        $this->importer->aplicar($planoBaseline, $this->obra, $this->user->id, 'sample.xml', TipoCronogramaImportacao::Baseline);

        $at1Antes = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();
        $this->assertEquals(40.0, (float) $at1Antes->baseline_horas);
        $this->assertEquals('Estrutura', $at1Antes->disciplina->nome);
        $this->assertEquals('Atividade 1', $at1Antes->nome);
        $codigoAntes = $at1Antes->codigo_cronograma;

        // O fixture de avanço traz baseline diferente (999H) e nome/critical
        // diferentes pra Atividade 1 — nada disso deve ser gravado em modo Avanço.
        $planoAvanco = $this->importer->analisar($this->fixture('cronograma_avanco_com_nova_tarefa.xml'), $this->obra, TipoCronogramaImportacao::Avanco);
        $this->importer->aplicar($planoAvanco, $this->obra, $this->user->id, 'avanco.xml', TipoCronogramaImportacao::Avanco);

        $at1Depois = $at1Antes->fresh();

        // Baseline, nome, código e classificação continuam intocados.
        $this->assertEquals(40.0, (float) $at1Depois->baseline_horas);
        $this->assertEquals('Atividade 1', $at1Depois->nome);
        $this->assertEquals($codigoAntes, $at1Depois->codigo_cronograma);
        $this->assertEquals('Estrutura', $at1Depois->disciplina->nome);

        // Só os campos de progresso mudaram, conforme o novo XML.
        $this->assertEquals(40.0, (float) $at1Depois->real_horas);
        $this->assertEquals(100.0, (float) $at1Depois->percentual_concluido);
    }

    public function test_fallback_de_importacao_nunca_escolhe_tipo_errado(): void
    {
        $planoBaseline = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra, TipoCronogramaImportacao::Baseline);
        $importacaoBaseline = $this->importer->aplicar($planoBaseline, $this->obra, $this->user->id, 'sample.xml', TipoCronogramaImportacao::Baseline);

        // Uma importação de Avanço mais ANTIGA já existe...
        $planoAvanco = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra, TipoCronogramaImportacao::Avanco);
        $importacaoAvanco = $this->importer->aplicar($planoAvanco, $this->obra, $this->user->id, 'avanco.xml', TipoCronogramaImportacao::Avanco);

        // ...mas uma importação de Baseline mais RECENTE é feita depois —
        // ela nunca deve ser escolhida como fonte de Realizado/Tendência,
        // mesmo sendo "a mais recente de qualquer tipo".
        $planoBaseline2 = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra, TipoCronogramaImportacao::Baseline);
        $this->importer->aplicar($planoBaseline2, $this->obra, $this->user->id, 'sample2.xml', TipoCronogramaImportacao::Baseline);

        $curvaAvanco = app(\App\Services\CurvaAvanco::class);

        // resolverImportacaoId é privado — valida indiretamente via calcular(),
        // conferindo que os pontos de Realizado batem com a importação de
        // Avanço (única com essa série gravada), não com a Baseline mais nova.
        $pontos = $curvaAvanco->calcular($this->obra, \App\Enums\SerieAvanco::Realizado, \App\Enums\GranularidadePeriodo::Semanal);
        $this->assertNotEmpty($pontos, 'Deveria encontrar os dados de Realizado gravados pela importação de Avanço');

        $totalRealizado = array_sum(array_column($pontos, 'horas'));
        $this->assertEquals(24.0, $totalRealizado);
    }

    public function test_importacao_tipo_ambos_serve_como_fonte_de_baseline_e_avanco(): void
    {
        // Simula uma importação antiga, de antes desta separação existir —
        // grava as 3 séries juntas no mesmo registro (tipo default 'ambos').
        $plano = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra, TipoCronogramaImportacao::Ambos);
        $importacao = $this->importer->aplicar($plano, $this->obra, $this->user->id, 'legado.xml', TipoCronogramaImportacao::Ambos);

        $this->assertSame(TipoCronogramaImportacao::Ambos, $importacao->tipo);

        $curvaAvanco = app(\App\Services\CurvaAvanco::class);

        $previsto = $curvaAvanco->calcular($this->obra, \App\Enums\SerieAvanco::Previsto, \App\Enums\GranularidadePeriodo::Semanal);
        $realizado = $curvaAvanco->calcular($this->obra, \App\Enums\SerieAvanco::Realizado, \App\Enums\GranularidadePeriodo::Semanal);

        $this->assertNotEmpty($previsto, 'Importação "ambos" deve servir de fallback pra Previsto');
        $this->assertNotEmpty($realizado, 'Importação "ambos" deve servir de fallback pra Realizado');
    }

    // -------------------------------------------------------------------------
    // Atividade manual não é tocada
    // -------------------------------------------------------------------------

    public function test_atividade_manual_nao_e_alterada_na_reimportacao(): void
    {
        $manual = Atividade::factory()->create([
            'tenant_id'    => $this->user->tenant_id,
            'obra_id'      => $this->obra->id,
            'nome'         => 'Atividade Manual',
            'origem'       => OrigemAtividade::Manual->value,
            'external_uid' => null,
        ]);

        $plano = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'sample.xml');

        // 2 importadas + 1 manual na obra
        $this->assertEquals(3, Atividade::where('obra_id', $this->obra->id)->count());

        // A manual continua intacta
        $manual->refresh();
        $this->assertEquals(OrigemAtividade::Manual, $manual->origem);
        $this->assertNull($manual->external_uid);
    }

    // -------------------------------------------------------------------------
    // Reimportação: arquivamento em vez de deleção
    // -------------------------------------------------------------------------

    public function test_reimportacao_arquiva_atividade_que_sumiu_do_xml(): void
    {
        // v1: importa com Atividade 1 (UID=2) e Atividade 2 (UID=3)
        $planoV1 = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoV1, $this->obra, $this->user->id, 'v1.xml');

        $at2 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '3')->first();
        $this->assertNotNull($at2);
        $this->assertFalse((bool) $at2->fora_do_cronograma);

        // v2: Atividade 2 (UID=3) não está mais no XML
        $planoV2 = $this->importer->analisar($this->fixture('cronograma_v2.xml'), $this->obra);
        $this->assertCount(1, $planoV2->removerIds);
        $this->assertEquals($at2->id, $planoV2->removerIds[0]);

        $this->importer->aplicar($planoV2, $this->obra, $this->user->id, 'v2.xml');

        $at2->refresh();
        $this->assertTrue((bool) $at2->fora_do_cronograma, 'Deve ser arquivada, não apagada');
        $this->assertDatabaseHas('atividades', ['id' => $at2->id]); // nunca apagada
    }

    public function test_arquivamento_preserva_restricoes_da_atividade(): void
    {
        // Importa v1
        $planoV1 = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoV1, $this->obra, $this->user->id, 'v1.xml');

        $at2 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '3')->first();

        // Adiciona restrição vinculada à atividade que será arquivada
        Restricao::factory()->create([
            'tenant_id'    => $this->user->tenant_id,
            'atividade_id' => $at2->id,
        ]);

        // Reimporta v2 (Atividade 2 sumiu)
        $planoV2 = $this->importer->analisar($this->fixture('cronograma_v2.xml'), $this->obra);
        $this->importer->aplicar($planoV2, $this->obra, $this->user->id, 'v2.xml');

        // Atividade arquivada, mas restrição permanece
        $at2->refresh();
        $this->assertTrue((bool) $at2->fora_do_cronograma);
        $this->assertEquals(1, Restricao::where('atividade_id', $at2->id)->count());
    }

    // -------------------------------------------------------------------------
    // Reimportação: segunda importação aparece como "atualizar"
    // -------------------------------------------------------------------------

    public function test_reimportacao_do_mesmo_xml_aparece_como_atualizar(): void
    {
        $planoV1 = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoV1, $this->obra, $this->user->id, 'v1.xml');

        // Segunda análise do mesmo arquivo
        $planoV1b = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->assertCount(0, $planoV1b->criar);
        $this->assertCount(2, $planoV1b->atualizar);
        $this->assertCount(0, $planoV1b->removerIds);
    }

    // -------------------------------------------------------------------------
    // Ciclo 17, A.9.1 — o cronograma importado é a verdade factual; a
    // plataforma preserva o histórico operacional. ConclusaoAutomaticaAtividades
    // não é mais chamada automaticamente pela importação — mesmo com a
    // atividade chegando a 100%, Restrição/AtividadeItemProntidao/
    // comentário/anexo permanecem exatamente como estavam.
    // -------------------------------------------------------------------------

    /** Teste A/C/F/N — 100% preserva Restrição BLOQUEANTE aberta e item de prontidão pendente; conclusão factual (percentual_concluido/real_inicio) continua correta; zero RestricaoAcao automática. */
    public function test_reimportacao_com_atividade_100_preserva_restricao_bloqueante_e_prontidao_pendente(): void
    {
        // v1: Atividade 1 (UID=2) importada com 40% (fixture padrão)
        $planoV1 = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoV1, $this->obra, $this->user->id, 'v1.xml');

        $at1 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at1->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Projeto aprovado', 'ordem' => 0]);
        AtividadeItemProntidao::create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at1->id,
            'item_prontidao_id' => $item->id,
            'concluido' => false,
        ]);

        // v2: mesmo external_uid, agora com PercentWorkComplete=100 e ActualStart preenchido.
        $planoV2 = $this->importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra);
        $this->importer->aplicar($planoV2, $this->obra, $this->user->id, '100pct.xml');

        // Conclusão factual: aceita normalmente, nunca rebaixada/bloqueada.
        $at1->refresh();
        $this->assertEquals(100.0, (float) $at1->percentual_concluido);
        $this->assertEquals('2024-01-01', $at1->real_inicio->toDateString());

        // Pendência operacional: permanece exatamente como estava.
        $restricao->refresh();
        $this->assertEquals(StatusRestricao::Aberta, $restricao->status);
        $this->assertNull($restricao->resolvida_em);
        $this->assertDatabaseMissing('restricao_acoes', [
            'restricao_id' => $restricao->id,
        ]);

        $registro = AtividadeItemProntidao::where('atividade_id', $at1->id)
            ->where('item_prontidao_id', $item->id)
            ->first();
        $this->assertFalse((bool) $registro->concluido);
        $this->assertNull($registro->concluido_em);
        $this->assertNull($registro->concluido_por);
    }

    /** Teste B — mesmo cenário, Restrição NÃO bloqueante também permanece intocada (nenhuma regra especial por tipo nesta etapa). */
    public function test_reimportacao_com_atividade_100_preserva_restricao_nao_bloqueante_aberta(): void
    {
        $planoV1 = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoV1, $this->obra, $this->user->id, 'v1.xml');
        $at1 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at1->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => false,
        ]);

        $planoV2 = $this->importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra);
        $this->importer->aplicar($planoV2, $this->obra, $this->user->id, '100pct.xml');

        $this->assertEquals(100.0, (float) $at1->fresh()->percentual_concluido);
        $this->assertEquals(StatusRestricao::Aberta, $restricao->fresh()->status);
        $this->assertNull($restricao->fresh()->resolvida_em);
    }

    /** Teste D — item de prontidão JÁ concluído permanece concluído, com concluido_por/concluido_em intocados. */
    public function test_reimportacao_com_atividade_100_preserva_item_prontidao_ja_concluido(): void
    {
        $planoV1 = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoV1, $this->obra, $this->user->id, 'v1.xml');
        $at1 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Projeto aprovado', 'ordem' => 0]);
        $marcadoEm = now()->subDays(3);
        AtividadeItemProntidao::create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at1->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
            'concluido_por' => $this->user->id,
            'concluido_em' => $marcadoEm,
        ]);

        $planoV2 = $this->importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra);
        $this->importer->aplicar($planoV2, $this->obra, $this->user->id, '100pct.xml');

        $registro = AtividadeItemProntidao::where('atividade_id', $at1->id)
            ->where('item_prontidao_id', $item->id)
            ->first();
        $this->assertTrue((bool) $registro->concluido);
        $this->assertEquals($this->user->id, $registro->concluido_por);
        $this->assertTrue($registro->concluido_em->isSameSecond($marcadoEm));
    }

    /** Teste E — Restrição já resolvida antes da importação permanece resolvida, sem reabertura nem sobrescrita de timestamp. */
    public function test_reimportacao_com_atividade_100_preserva_restricao_ja_resolvida(): void
    {
        $planoV1 = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoV1, $this->obra, $this->user->id, 'v1.xml');
        $at1 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $resolvidaEm = now()->subDays(5);
        $restricaoResolvida = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at1->id,
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => $resolvidaEm,
        ]);
        $restricaoAberta = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at1->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $planoV2 = $this->importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra);
        $this->importer->aplicar($planoV2, $this->obra, $this->user->id, '100pct.xml');

        $this->assertEquals(StatusRestricao::Resolvida, $restricaoResolvida->fresh()->status);
        $this->assertTrue($restricaoResolvida->fresh()->resolvida_em->isSameSecond($resolvidaEm));
        $this->assertEquals(StatusRestricao::Aberta, $restricaoAberta->fresh()->status);
    }

    /** Teste G — primeira importação de Avanço (nunca houve avanço anterior) já traz a atividade em 100%: pendência criada antes permanece aberta. */
    public function test_primeira_importacao_de_avanco_ja_em_100_preserva_restricao_aberta(): void
    {
        $planoBaseline = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra, TipoCronogramaImportacao::Baseline);
        $this->importer->aplicar($planoBaseline, $this->obra, $this->user->id, 'baseline.xml', TipoCronogramaImportacao::Baseline);

        $at1 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();
        $this->assertNotEquals(100.0, (float) $at1->percentual_concluido);

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at1->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        // Primeira (e única) importação de Avanço da obra — traz UID=2 direto a 100%.
        $planoAvanco = $this->importer->analisar($this->fixture('cronograma_avanco_com_nova_tarefa.xml'), $this->obra, TipoCronogramaImportacao::Avanco);
        $this->importer->aplicar($planoAvanco, $this->obra, $this->user->id, 'avanco.xml', TipoCronogramaImportacao::Avanco);

        $this->assertEquals(100.0, (float) $at1->fresh()->percentual_concluido);
        $this->assertEquals(StatusRestricao::Aberta, $restricao->fresh()->status);
    }

    /** Teste H — reimportação 100%→100%: mesmo já vindo de 100% na importação anterior, nada é autocorrigido (prova que não é lógica de transição, é ausência total de automação). */
    public function test_reimportacao_100_para_100_nao_autocorrige(): void
    {
        $planoV1 = $this->importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra);
        $this->importer->aplicar($planoV1, $this->obra, $this->user->id, 'v1.xml');
        $at1 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();
        $this->assertEquals(100.0, (float) $at1->percentual_concluido);

        // Restrição criada DEPOIS da primeira importação, com a atividade já 100%.
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at1->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        // Reimporta o MESMO XML — continua 100%.
        $planoV2 = $this->importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra);
        $this->importer->aplicar($planoV2, $this->obra, $this->user->id, 'v2.xml');

        $this->assertEquals(100.0, (float) $at1->fresh()->percentual_concluido);
        $this->assertEquals(StatusRestricao::Aberta, $restricao->fresh()->status);
        $this->assertDatabaseMissing('restricao_acoes', ['restricao_id' => $restricao->id]);
    }

    /** Teste J — smoke test de identidade: comentário/anexo/Restrição/item de prontidão permanecem vinculados à MESMA Atividade (mesma PK) após importar avanço com 100%. */
    public function test_historico_operacional_permanece_vinculado_apos_importacao_de_avanco(): void
    {
        Storage::fake(AtividadeAnexo::DISCO);

        $planoV1 = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoV1, $this->obra, $this->user->id, 'v1.xml');
        $at1 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();
        $atividadeIdAntes = $at1->id;

        $comentario = AtividadeComentario::create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at1->id,
            'autor_id' => $this->user->id,
            'comentario' => 'Comentário de teste antes da importação de avanço.',
        ]);
        $anexo = app(\App\Actions\Atividade\AnexarArquivoAtividade::class)->execute(
            $at1,
            UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
            $this->user,
        );
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at1->id,
            'status' => StatusRestricao::Aberta->value,
        ]);
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Projeto aprovado', 'ordem' => 0]);
        $itemProntidao = AtividadeItemProntidao::create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at1->id,
            'item_prontidao_id' => $item->id,
            'concluido' => false,
        ]);

        $planoV2 = $this->importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra);
        $this->importer->aplicar($planoV2, $this->obra, $this->user->id, '100pct.xml');

        $at1->refresh();
        $this->assertEquals($atividadeIdAntes, $at1->id, 'PK da Atividade precisa continuar estável entre importações');
        $this->assertEquals(100.0, (float) $at1->percentual_concluido);

        $this->assertDatabaseHas('atividade_comentarios', ['id' => $comentario->id, 'atividade_id' => $atividadeIdAntes]);
        $this->assertDatabaseHas('atividade_anexos', ['id' => $anexo->id, 'atividade_id' => $atividadeIdAntes]);
        Storage::disk(AtividadeAnexo::DISCO)->assertExists($anexo->caminho_arquivo);
        $this->assertDatabaseHas('restricoes', ['id' => $restricao->id, 'atividade_id' => $atividadeIdAntes, 'status' => StatusRestricao::Aberta->value]);
        $this->assertDatabaseHas('atividade_itens_prontidao', ['id' => $itemProntidao->id, 'atividade_id' => $atividadeIdAntes, 'concluido' => false]);
    }

    public function test_reimportacao_com_atividade_abaixo_de_100_nao_resolve_restricoes(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'v1.xml');

        // Atividade 2 (UID=3) não tem PercentWorkComplete no fixture (fica null)
        $at2 = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '3')->first();
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at2->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        // Reimporta o mesmo XML (continua sem 100%)
        $planoB = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoB, $this->obra, $this->user->id, 'v1b.xml');

        $this->assertEquals(StatusRestricao::Aberta, $restricao->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Curva geral do empreendimento (task-raiz UID=0)
    // -------------------------------------------------------------------------

    public function test_task_raiz_uid_zero_vira_pacote_pai_de_tudo(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_nivel_zero.xml'), $this->obra);
        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'nivel_zero.xml');

        // Agora 2 pacotes: o novo nível 0 + "Pacote A" (nível 1), que virou filho dele.
        $this->assertEquals(2, PacoteTrabalho::where('obra_id', $this->obra->id)->count());

        $raiz = PacoteTrabalho::where('obra_id', $this->obra->id)->where('external_uid', '0')->first();
        $this->assertNotNull($raiz);
        $this->assertNull($raiz->parent_id);
        // <Name> vazio no fixture -> cai no fallback do nome da obra.
        $this->assertSame($this->obra->name, $raiz->nome);
        $this->assertSame('0', $raiz->codigo);

        $pacoteA = PacoteTrabalho::where('obra_id', $this->obra->id)->where('external_uid', '1')->first();
        $this->assertNotNull($pacoteA);
        $this->assertSame($raiz->id, $pacoteA->parent_id);
    }

    public function test_curva_do_pacote_nivel_zero_bate_com_obra_inteira(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_nivel_zero.xml'), $this->obra);
        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'nivel_zero.xml');

        $raiz = PacoteTrabalho::where('obra_id', $this->obra->id)->where('external_uid', '0')->first();

        $totalViaRaiz = $raiz->totalHhBaseline();
        $totalViaObraInteira = app(\App\Services\CurvaAvanco::class)->totalCalculado(
            $this->obra,
            \App\Enums\SerieAvanco::Previsto,
            \App\Enums\GranularidadePeriodo::Mensal
        );

        $this->assertGreaterThan(0.0, $totalViaRaiz);
        $this->assertEqualsWithDelta($totalViaObraInteira, $totalViaRaiz, 0.01);
    }

    public function test_reimportacao_de_obra_existente_reatribui_pacotes_de_nivel_1_pra_novo_pai(): void
    {
        // Primeira importação SEM UID=0 — situação de uma obra já importada
        // antes desta feature existir (pacote de nível 1 fica raiz solta).
        $planoV1 = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $this->obra);
        $this->importer->aplicar($planoV1, $this->obra, $this->user->id, 'v1.xml');

        $pacoteAntigo = PacoteTrabalho::where('obra_id', $this->obra->id)->where('external_uid', '1')->first();
        $this->assertNull($pacoteAntigo->parent_id);

        // Reimportação COM UID=0 — mesmo pacote de nível 1 (mesmo external_uid)
        // agora ganha um pai.
        $planoV2 = $this->importer->analisar($this->fixture('cronograma_nivel_zero.xml'), $this->obra);
        $this->importer->aplicar($planoV2, $this->obra, $this->user->id, 'v2.xml');

        $raiz = PacoteTrabalho::where('obra_id', $this->obra->id)->where('external_uid', '0')->first();
        $this->assertNotNull($raiz);
        $this->assertSame($pacoteAntigo->id, PacoteTrabalho::where('obra_id', $this->obra->id)->where('external_uid', '1')->first()->id);
        $this->assertSame($raiz->id, $pacoteAntigo->fresh()->parent_id);
    }
}
