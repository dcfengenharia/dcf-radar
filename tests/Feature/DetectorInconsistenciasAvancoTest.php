<?php

namespace Tests\Feature;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\EntidadeInconsistenciaAvanco;
use App\Enums\SeveridadeInconsistenciaAvanco;
use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Enums\TipoInconsistenciaAvanco;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeSnapshot;
use App\Models\CronogramaImportacao;
use App\Models\InconsistenciaAvanco;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\DetectorInconsistenciasAvanco;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 17, A.9.4 — Detector de Inconsistências de Avanço: compara
 * Fotografia F × Fotografia O de uma mesma importação e registra
 * divergências como evidência (nunca correção). Ver
 * App\Services\DetectorInconsistenciasAvanco.
 */
class DetectorInconsistenciasAvancoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;
    private ImportadorCronograma $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->importer = app(ImportadorCronograma::class);
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    private function importar(string $arquivo, TipoCronogramaImportacao $tipo): CronogramaImportacao
    {
        $plano = $this->importer->analisar($this->fixture($arquivo), $this->obra, $tipo);

        return $this->importer->aplicar($plano, $this->obra, $this->user->id, $arquivo, $tipo);
    }

    private function atividade(string $uid): Atividade
    {
        return Atividade::where('obra_id', $this->obra->id)->where('external_uid', $uid)->firstOrFail();
    }

    private function tarefaSintetica(string $uid, ?float $percentual, bool $realInicio = false, bool $realTermino = false): TarefaImportada
    {
        return new TarefaImportada(
            uid: $uid, nome: "Atividade {$uid}", isSummary: false, isMarco: false,
            caminhoCritico: false, parentUid: null, codigo: $uid,
            dataInicio: null, dataTermino: null, baselineInicio: null, baselineTermino: null,
            realInicio: $realInicio ? now() : null, realTermino: $realTermino ? now() : null,
            baselineHoras: 0, workHoras: 0, realHoras: 0, percentualConcluido: $percentual,
            textos: []
        );
    }

    // ===================== A-B: início + restrição =====================

    /** A — Restrição bloqueante aberta + início → inconsistência de severidade atenção. */
    public function test_a_restricao_bloqueante_aberta_no_inicio_gera_inconsistencia(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        $r = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);

        $imp = $this->importar('cronograma_fase_a92_70pct.xml', TipoCronogramaImportacao::Avanco);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::InicioComRestricaoPendente->value)
            ->get();
        $this->assertCount(1, $inc);
        $this->assertEquals(SeveridadeInconsistenciaAvanco::Atencao, $inc->first()->severidade);
        $this->assertEquals(EntidadeInconsistenciaAvanco::Restricao, $inc->first()->entidade_tipo);
        $this->assertEquals($r->id, $inc->first()->entidade_id);
        $this->assertTrue($inc->first()->detalhes['bloqueante']);
    }

    /** B — Restrição não bloqueante aberta + início → existe, severidade informativa (menor). */
    public function test_b_restricao_nao_bloqueante_aberta_no_inicio_gera_inconsistencia_severidade_menor(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => false,
        ]);

        $imp = $this->importar('cronograma_fase_a92_70pct.xml', TipoCronogramaImportacao::Avanco);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::InicioComRestricaoPendente->value)
            ->first();
        $this->assertNotNull($inc);
        $this->assertEquals(SeveridadeInconsistenciaAvanco::Informativa, $inc->severidade);
    }

    /** C — ItemProntidao pendente + início → inconsistência de prontidão. */
    public function test_c_item_prontidao_pendente_no_inicio_gera_inconsistencia(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'X', 'ordem' => 0]);

        $imp = $this->importar('cronograma_fase_a92_70pct.xml', TipoCronogramaImportacao::Avanco);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::InicioComProntidaoPendente->value)
            ->first();
        $this->assertNotNull($inc);
        $this->assertEquals(SeveridadeInconsistenciaAvanco::Atencao, $inc->severidade);
        $this->assertEquals($item->id, $inc->entidade_id);
    }

    // ===================== D-F: conclusão =====================

    /** D — Restrição bloqueante aberta + conclusão → crítica. */
    public function test_d_restricao_bloqueante_aberta_na_conclusao_gera_inconsistencia_critica(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        $r = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);

        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::ConclusaoComRestricaoPendente->value)
            ->first();
        $this->assertNotNull($inc);
        $this->assertEquals(SeveridadeInconsistenciaAvanco::Critica, $inc->severidade);
        $this->assertEquals($r->id, $inc->entidade_id);
    }

    /** E — Restrição não bloqueante aberta + conclusão → atenção. */
    public function test_e_restricao_nao_bloqueante_aberta_na_conclusao_gera_inconsistencia(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => false,
        ]);

        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::ConclusaoComRestricaoPendente->value)
            ->first();
        $this->assertNotNull($inc);
        $this->assertEquals(SeveridadeInconsistenciaAvanco::Atencao, $inc->severidade);
    }

    /** F — ItemProntidao pendente + conclusão → crítica. */
    public function test_f_item_prontidao_pendente_na_conclusao_gera_inconsistencia_critica(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'X', 'ordem' => 0]);

        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::ConclusaoComProntidaoPendente->value)
            ->first();
        $this->assertNotNull($inc);
        $this->assertEquals(SeveridadeInconsistenciaAvanco::Critica, $inc->severidade);
        $this->assertEquals($item->id, $inc->entidade_id);
    }

    // ===================== G-I: negativos =====================

    /** G — Restrição já resolvida antes da importação → não gera inconsistência (não capturada em O). */
    public function test_g_restricao_resolvida_antes_nao_gera_inconsistencia(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Resolvida->value, 'bloqueante' => true,
        ]);

        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->whereIn('tipo', [
                TipoInconsistenciaAvanco::InicioComRestricaoPendente->value,
                TipoInconsistenciaAvanco::ConclusaoComRestricaoPendente->value,
            ])->count());
    }

    /** H — ItemProntidao já concluído antes → não gera inconsistência. */
    public function test_h_item_prontidao_concluido_antes_nao_gera_inconsistencia(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'X', 'ordem' => 0]);
        AtividadeItemProntidao::create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'item_prontidao_id' => $item->id, 'concluido' => true,
        ]);

        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at->id)
            ->whereIn('tipo', [
                TipoInconsistenciaAvanco::InicioComProntidaoPendente->value,
                TipoInconsistenciaAvanco::ConclusaoComProntidaoPendente->value,
            ])->count());
    }

    /** I — Atividade não iniciou/não concluiu → não gera nenhum dos 4 tipos. */
    public function test_i_atividade_sem_evidencia_de_avanco_nao_gera_inconsistencia(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at3 = $this->atividade('3'); // sem percentual/actual no fixture 70pct
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at3->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);

        $imp = $this->importar('cronograma_fase_a92_70pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at3->id)->count());
    }

    // ===================== J-K: tipo de importação =====================

    /** J — Baseline pura com 100% → zero inconsistências (não elegível). */
    public function test_j_baseline_pura_com_100_por_cento_nao_gera_inconsistencia(): void
    {
        $at = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id]);
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);

        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Baseline);

        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->count());
    }

    /** K — Primeiro Avanço da obra já com 100% → detector funciona normalmente. */
    public function test_k_primeiro_avanco_ja_com_100_por_cento_funciona(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);

        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertGreaterThan(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->count());
    }

    // ===================== L: idempotência =====================

    /** L — Detector chamado duas vezes pra mesma importação → zero duplicação (unique constraint rejeita). */
    public function test_l_detector_chamado_duas_vezes_nao_duplica(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);

        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);
        $antes = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->count();
        $this->assertGreaterThan(0, $antes);

        $excecao = false;
        try {
            (new DetectorInconsistenciasAvanco())->detectar($imp);
        } catch (QueryException $e) {
            $excecao = true;
        }

        $this->assertTrue($excecao, 'Segunda chamada deveria ter sido rejeitada pelo unique constraint.');
        $this->assertEquals($antes, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->count());
    }

    // ===================== M: I1/I2 =====================

    /** M — I1 e I2 sucessivas, mesma pendência ainda aberta → uma ocorrência histórica em cada importação. */
    public function test_m_i1_e_i2_sucessivas_geram_ocorrencias_independentes(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        $r = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);

        $i1 = $this->importar('cronograma_fase_a92_70pct.xml', TipoCronogramaImportacao::Avanco); // iniciou, não concluiu
        $i2 = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco); // concluiu

        $incI1 = InconsistenciaAvanco::where('cronograma_importacao_id', $i1->id)
            ->where('tipo', TipoInconsistenciaAvanco::InicioComRestricaoPendente->value)->first();
        $incI2 = InconsistenciaAvanco::where('cronograma_importacao_id', $i2->id)
            ->where('tipo', TipoInconsistenciaAvanco::ConclusaoComRestricaoPendente->value)->first();

        $this->assertNotNull($incI1);
        $this->assertNotNull($incI2);
        $this->assertEquals($r->id, $incI1->entidade_id);
        $this->assertEquals($r->id, $incI2->entidade_id);
        $this->assertNotEquals($incI1->id, $incI2->id);
    }

    // ===================== N-O: imutabilidade histórica =====================

    /** N — Restrição muda/é soft-deletada depois → inconsistência histórica preserva bloqueante/status. */
    public function test_n_restricao_alterada_depois_nao_afeta_inconsistencia_historica(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        $r = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);
        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);
        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('tipo', TipoInconsistenciaAvanco::ConclusaoComRestricaoPendente->value)->firstOrFail();

        $r->update(['status' => StatusRestricao::Resolvida->value, 'bloqueante' => false]);
        $r->delete();

        $incDepois = InconsistenciaAvanco::find($inc->id);
        $this->assertNotNull($incDepois);
        $this->assertTrue($incDepois->detalhes['bloqueante']);
        $this->assertEquals('aberta', $incDepois->detalhes['status']);
        $this->assertEquals(SeveridadeInconsistenciaAvanco::Critica, $incDepois->severidade);
    }

    /** O — ItemProntidao soft-deletado depois → inconsistência histórica continua interpretável. */
    public function test_o_item_prontidao_soft_deletado_depois_nao_afeta_inconsistencia_historica(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'X', 'ordem' => 0]);
        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);
        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('tipo', TipoInconsistenciaAvanco::ConclusaoComProntidaoPendente->value)->firstOrFail();

        $item->delete();

        $incDepois = InconsistenciaAvanco::find($inc->id);
        $this->assertNotNull($incDepois);
        $this->assertEquals($item->id, $incDepois->detalhes['item_prontidao_id']);
    }

    // ===================== P: atividade nova =====================

    /**
     * P — Atividade nova nesta própria importação (Ambos): sem falso
     * positivo de pendência de RESTRIÇÃO/PRONTIDÃO (Fotografia O) que a
     * plataforma não poderia conhecer — guarda `status === null` de O
     * continua intacta e testada aqui, sem alteração de escopo.
     *
     * Ciclo 17, A.9.5 — decisão deliberada e DIFERENTE pra Fotografia P
     * (Programação Semanal): uma atividade nova iniciada/concluída
     * legitimamente PODE gerar inconsistência de programação (ela nunca
     * poderia estar em nenhuma Programação Semanal pré-existente, já que
     * `ProgramacaoSemanalItem.atividade_id` só referencia atividade que já
     * existia no momento do comprometimento — ver investigação da A.9.5 no
     * CLAUDE.md, seção "atividade nova"). Por isso a asserção de "zero
     * inconsistências" deste teste, que datava de antes da A.9.5, foi
     * reescopada pros 4 tipos O-based (o que este teste sempre existiu pra
     * provar) — não enfraquecida, só corrigida pra não colidir com um
     * comportamento novo e intencional de uma fotografia diferente.
     */
    public function test_p_atividade_nova_na_importacao_nao_gera_falso_positivo(): void
    {
        TenantContext::actingAs($this->user->tenant, function () {
            // Obra com checklist configurado — geraria TIPO D pra qualquer
            // atividade pré-existente concluída sem o item marcado.
            ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'X', 'ordem' => 0]);

            $plano = new PlanoImportacao(
                criar: [$this->tarefaSintetica('900', 100.0, realInicio: true)],
                atualizar: [], pacotes: [], removerIds: [], removerNomes: [],
                ignoradasNomes: [], dataStatus: null, totalBaselineHh: 0, totalWorkHh: 0, totalRealHh: 0,
                horasPeriodos: []
            );
            $imp = $this->importer->aplicar($plano, $this->obra, $this->user->id, 'nova.xml', TipoCronogramaImportacao::Ambos);

            $atNova = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '900')->firstOrFail();

            $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
                ->where('atividade_id', $atNova->id)
                ->whereIn('tipo', [
                    TipoInconsistenciaAvanco::InicioComRestricaoPendente->value,
                    TipoInconsistenciaAvanco::InicioComProntidaoPendente->value,
                    TipoInconsistenciaAvanco::ConclusaoComRestricaoPendente->value,
                    TipoInconsistenciaAvanco::ConclusaoComProntidaoPendente->value,
                ])->count(), 'Fotografia O (restrição/prontidão) não pode gerar falso positivo pra atividade nova.');

            // A.9.5, comportamento novo e intencional: SEM nenhuma
            // Programação Semanal criada nesta obra, a atividade nova
            // INICIADA (`tarefaSintetica` aqui só marca realInicio, nunca
            // realTermino — sem data factual de conclusão, Fotografia P
            // nunca avalia esse evento, mesma regra de qualquer outra
            // atividade) gera "inicio_sem_programacao_semanal" — nunca
            // "fora" (não existia programação nenhuma pra aquela semana) e
            // nunca silenciosamente ignorada.
            $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
                ->where('atividade_id', $atNova->id)
                ->where('tipo', TipoInconsistenciaAvanco::InicioSemProgramacaoSemanal->value)
                ->first();
            $this->assertNotNull($inc);
            $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
                ->where('atividade_id', $atNova->id)
                ->where('tipo', TipoInconsistenciaAvanco::ConclusaoSemProgramacaoSemanal->value)
                ->count(), 'Sem realTermino, não há data factual pra avaliar conclusão — nenhuma linha de P/inconsistência pra esse evento.');
        });
    }

    // ===================== Q: rollback =====================

    /** Q — Falha após o detector, dentro da mesma transação → importação + F + O + inconsistências rollbackam juntas. */
    public function test_q_rollback_apos_detector_desfaz_tudo(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);

        $plano = $this->importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra, TipoCronogramaImportacao::Avanco);

        $lancado = null;
        try {
            DB::transaction(function () use ($plano) {
                $this->importer->aplicar($plano, $this->obra, $this->user->id, 'x.xml', TipoCronogramaImportacao::Avanco);
                throw new \RuntimeException('Falha simulada APÓS aplicar() real (detector incluso), dentro da mesma transação.');
            });
        } catch (\RuntimeException $e) {
            $lancado = $e->getMessage();
        }

        $this->assertNotNull($lancado);
        $this->assertEquals(1, CronogramaImportacao::where('obra_id', $this->obra->id)->count()); // só a Baseline
        $this->assertEquals(0, InconsistenciaAvanco::count());
    }

    // ===================== R: isolamento =====================

    /** R — Outro tenant nunca enxerga inconsistências de outro. */
    public function test_r_isolamento_de_tenant(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);
        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);
        $incId = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->firstOrFail()->id;

        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($outroUser);

        $this->assertNull(InconsistenciaAvanco::find($incId));
    }

    // ===================== S: N+1 =====================

    /** S — Detector isolado: quantidade de queries não cresce linearmente com N atividades (5/20/100). */
    public function test_s_quantidade_de_queries_do_detector_nao_cresce_linearmente(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        foreach ([5, 20, 100] as $n) {
            $tenant = Tenant::factory()->create();
            $user = User::factory()->create(['tenant_id' => $tenant->id]);
            $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

            TenantContext::actingAs($tenant, function () use ($tenant, $user, $obra, $n, &$queries) {
                $imp = CronogramaImportacao::create([
                    'tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'user_id' => $user->id,
                    'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
                    'tipo' => TipoCronogramaImportacao::Avanco->value, 'importado_em' => now(),
                ]);
                $item = ItemProntidao::create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'nome' => 'X', 'ordem' => 0]);
                $agora = now();

                for ($i = 1; $i <= $n; $i++) {
                    $at = Atividade::factory()->create([
                        'tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'origem' => 'ms_project', 'status' => 'planejado',
                    ]);
                    DB::table('atividade_snapshots')->insert([
                        'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $tenant->id,
                        'cronograma_importacao_id' => $imp->id, 'atividade_id' => $at->id,
                        'percentual_concluido' => 100, 'real_inicio' => null, 'real_termino' => null,
                        'created_at' => $agora, 'updated_at' => $agora,
                    ]);
                    DB::table('atividade_snapshot_operacionais')->insert([
                        'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $tenant->id,
                        'cronograma_importacao_id' => $imp->id, 'atividade_id' => $at->id,
                        'status' => 'planejado', 'fora_do_cronograma' => false, 'pronta' => false,
                        'created_at' => $agora, 'updated_at' => $agora,
                    ]);
                    DB::table('atividade_snapshot_prontidao')->insert([
                        'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $tenant->id,
                        'cronograma_importacao_id' => $imp->id, 'atividade_id' => $at->id,
                        'item_prontidao_id' => $item->id, 'atividade_item_prontidao_id' => null,
                        'created_at' => $agora, 'updated_at' => $agora,
                    ]);
                }

                $antes = $queries;
                (new DetectorInconsistenciasAvanco())->detectar($imp);
                $depois = $queries - $antes;

                $this->assertLessThan(10, $depois, "N={$n}: detector gerou {$depois} queries — suspeita de N+1.");
            });
        }
    }

    // ===================== A.9.4.HARDENING: guarda de tipo própria =====================

    /**
     * T — DetectorInconsistenciasAvanco::detectar() é seguro por si só
     * contra importação Baseline, mesmo com Fotografia F/O sintéticas que
     * SEM a guarda de tipo gerariam inconsistência de verdade (prova que a
     * defesa é do SERVIÇO, não só ausência natural de O no fluxo real de
     * Baseline — que nunca grava O em primeiro lugar).
     */
    public function test_t_detector_chamado_direto_numa_importacao_baseline_nao_gera_nada_mesmo_com_o_sintetica(): void
    {
        TenantContext::actingAs($this->user->tenant, function () {
            $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
            $at = $this->atividade('2');

            $importacaoBaseline = CronogramaImportacao::create([
                'tenant_id' => $this->user->tenant_id,
                'obra_id' => $this->obra->id,
                'user_id' => $this->user->id,
                'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
                'tipo' => TipoCronogramaImportacao::Baseline->value,
                'importado_em' => now(),
            ]);

            // Fotografia F sintética: concluiu (100%) e iniciou.
            DB::table('atividade_snapshots')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $this->user->tenant_id,
                'cronograma_importacao_id' => $importacaoBaseline->id, 'atividade_id' => $at->id,
                'percentual_concluido' => 100, 'real_inicio' => now()->toDateString(), 'real_termino' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // Fotografia O sintética: status NÃO nulo (bypassa o guard de
            // "atividade nova") — se a guarda de tipo não existisse, isso
            // seria suficiente pra gerar inconsistência de verdade.
            DB::table('atividade_snapshot_operacionais')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $this->user->tenant_id,
                'cronograma_importacao_id' => $importacaoBaseline->id, 'atividade_id' => $at->id,
                'status' => 'planejado', 'fora_do_cronograma' => false, 'pronta' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $r = Restricao::factory()->create([
                'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
                'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
            ]);
            DB::table('atividade_snapshot_restricoes')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $this->user->tenant_id,
                'cronograma_importacao_id' => $importacaoBaseline->id, 'atividade_id' => $at->id,
                'restricao_id' => $r->id, 'bloqueante' => true, 'status' => 'aberta',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            (new DetectorInconsistenciasAvanco())->detectar($importacaoBaseline);

            $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $importacaoBaseline->id)->count());
        });
    }

    /** U — A mesma Fotografia F/O sintética, numa importação tipo Avanco, PRODUZ inconsistência (controle: prova que o cenário do teste T de fato dispararia sem a guarda). */
    public function test_u_controle_mesma_fotografia_sintetica_numa_importacao_avanco_gera_inconsistencia(): void
    {
        TenantContext::actingAs($this->user->tenant, function () {
            $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
            $at = $this->atividade('2');

            $importacaoAvanco = CronogramaImportacao::create([
                'tenant_id' => $this->user->tenant_id,
                'obra_id' => $this->obra->id,
                'user_id' => $this->user->id,
                'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
                'tipo' => TipoCronogramaImportacao::Avanco->value,
                'importado_em' => now(),
            ]);

            DB::table('atividade_snapshots')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $this->user->tenant_id,
                'cronograma_importacao_id' => $importacaoAvanco->id, 'atividade_id' => $at->id,
                'percentual_concluido' => 100, 'real_inicio' => now()->toDateString(), 'real_termino' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('atividade_snapshot_operacionais')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $this->user->tenant_id,
                'cronograma_importacao_id' => $importacaoAvanco->id, 'atividade_id' => $at->id,
                'status' => 'planejado', 'fora_do_cronograma' => false, 'pronta' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $r = Restricao::factory()->create([
                'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
                'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
            ]);
            DB::table('atividade_snapshot_restricoes')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $this->user->tenant_id,
                'cronograma_importacao_id' => $importacaoAvanco->id, 'atividade_id' => $at->id,
                'restricao_id' => $r->id, 'bloqueante' => true, 'status' => 'aberta',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            (new DetectorInconsistenciasAvanco())->detectar($importacaoAvanco);

            $this->assertGreaterThan(0, InconsistenciaAvanco::where('cronograma_importacao_id', $importacaoAvanco->id)->count());
        });
    }

    // ===================== Não-autocorreção de Restrição / reconciliação de prontidão (Ciclo 24) =====================

    /**
     * Ciclo 24 — decisão de produto revisada: conclusão com Restrição
     * pendente nunca autocorrige a Restrição (A.9.1 continua intacta pra
     * ela), mas o item de prontidão pendente É atendido automaticamente
     * (origem sempre auditável via `atendido_pela_importacao_id`, nunca
     * simulando marcação humana) — as duas coisas evidenciadas pelo
     * Detector continuam intactas.
     */
    public function test_conclusao_nunca_autocorrige_restricao_mas_reconcilia_prontidao_com_origem_auditavel(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = $this->atividade('2');
        $r = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'X', 'ordem' => 0]);

        $imp = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $snapshotF = AtividadeSnapshot::where('cronograma_importacao_id', $imp->id)->where('atividade_id', $at->id)->first();
        $this->assertEquals(100.0, (float) $snapshotF->percentual_concluido);

        // Restrição: nunca autocorrigida (A.9.1 continua valendo pra ela).
        $this->assertEquals(StatusRestricao::Aberta, $r->fresh()->status);
        $this->assertNull($r->fresh()->resolvida_em);
        $this->assertDatabaseMissing('restricao_acoes', ['restricao_id' => $r->id]);

        // Prontidão: Ciclo 24 — atendida automaticamente, nunca simulando um usuário.
        $registroProntidao = AtividadeItemProntidao::where('atividade_id', $at->id)->where('item_prontidao_id', $item->id)->first();
        $this->assertNotNull($registroProntidao);
        $this->assertTrue($registroProntidao->concluido);
        $this->assertNull($registroProntidao->concluido_por);
        $this->assertSame($imp->id, $registroProntidao->atendido_pela_importacao_id);

        $this->assertGreaterThan(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->count());
    }
}
