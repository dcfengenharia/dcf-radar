<?php

namespace Tests\Feature;

use App\Actions\ProgramacaoSemanal\CriarRevisaoProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal;
use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\EntidadeInconsistenciaAvanco;
use App\Enums\EventoFotografiaProgramacao;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\TipoCronogramaImportacao;
use App\Enums\TipoInconsistenciaAvanco;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\AtividadeSnapshotProgramacao;
use App\Models\CronogramaImportacao;
use App\Models\InconsistenciaAvanco;
use App\Models\ProgramacaoSemanal;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Restricao;
use App\Enums\StatusRestricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 17, A.9.5 — Fotografia P: se uma atividade que iniciou/concluiu
 * NESTA importação fazia parte da Programação Semanal historicamente
 * aplicável ao instante do evento. Ver App\Models\AtividadeSnapshotProgramacao,
 * App\Services\DetectorInconsistenciasAvanco.
 */
class FotografiaProgramacaoSemanalTest extends TestCase
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

    private function tarefa(string $uid, ?float $percentual = null, ?Carbon $realInicio = null, ?Carbon $realTermino = null): TarefaImportada
    {
        return new TarefaImportada(
            uid: $uid, nome: "Atividade {$uid}", isSummary: false, isMarco: false,
            caminhoCritico: false, parentUid: null, codigo: $uid,
            dataInicio: null, dataTermino: null, baselineInicio: null, baselineTermino: null,
            realInicio: $realInicio, realTermino: $realTermino,
            baselineHoras: 0, workHoras: 0, realHoras: 0, percentualConcluido: $percentual,
            textos: []
        );
    }

    /** Cria N atividades pré-existentes via Baseline sintética (sem datas reais nenhuma). */
    private function criarAtividadesExistentes(array $uids): CronogramaImportacao
    {
        $plano = new PlanoImportacao(
            criar: array_map(fn ($uid) => $this->tarefa($uid), $uids),
            atualizar: [], pacotes: [], removerIds: [], removerNomes: [],
            ignoradasNomes: [], dataStatus: null, totalBaselineHh: 0, totalWorkHh: 0, totalRealHh: 0,
            horasPeriodos: []
        );

        return $this->importer->aplicar($plano, $this->obra, $this->user->id, 'baseline.xml', TipoCronogramaImportacao::Baseline);
    }

    private function atividade(string $uid): Atividade
    {
        return Atividade::where('obra_id', $this->obra->id)->where('external_uid', $uid)->firstOrFail();
    }

    private function importarAvanco(array $tarefas, TipoCronogramaImportacao $tipo = TipoCronogramaImportacao::Avanco): CronogramaImportacao
    {
        $plano = new PlanoImportacao(
            criar: [], atualizar: $tarefas, pacotes: [], removerIds: [], removerNomes: [],
            ignoradasNomes: [], dataStatus: null, totalBaselineHh: 0, totalWorkHh: 0, totalRealHh: 0,
            horasPeriodos: []
        );

        return $this->importer->aplicar($plano, $this->obra, $this->user->id, 'avanco.xml', $tipo);
    }

    /**
     * `RegistrarComprometimentoSemanal` carimba `congelada_em`/`created_at`
     * dos itens com o `now()` REAL do momento em que o teste roda -- não
     * com a semana fictícia (ex.: "2026-01-05") usada nos cenários. Sem
     * backdatar explicitamente, uma Programação criada "agora" (ex.:
     * 2026-08-17) nunca seria considerada vigente pra um evento fictício
     * de "2026-01-06" (`congelada_em <= instante` falharia sempre, já que
     * `congelada_em` seria POSTERIOR ao instante fictício) -- mesma classe
     * de cuidado já documentada no projeto pra testes que precisam de
     * ordem temporal garantida. Default: início do dia da própria semana
     * (segunda-feira 00:00) -- qualquer evento fictício depois disso na
     * mesma semana já resolve corretamente sem precisar de override.
     */
    private function comprometer(string $semanaInicio, Collection $atividades, ?Carbon $congeladaEm = null): ProgramacaoSemanal
    {
        $prog = (new RegistrarComprometimentoSemanal())->execute(
            $this->obra, $semanaInicio, $atividades, OrigemProgramacaoSemanalItem::Manual
        );

        $instante = $congeladaEm ?? Carbon::parse($semanaInicio)->startOfDay();
        $prog->update(['congelada_em' => $instante]);
        ProgramacaoSemanalItem::where('programacao_semanal_id', $prog->id)->update(['created_at' => $instante]);

        return $prog->fresh();
    }

    private function fechar(ProgramacaoSemanal $p): ProgramacaoSemanal
    {
        return (new FecharProgramacaoSemanal())->execute($p);
    }

    private function revisar(ProgramacaoSemanal $p): ProgramacaoSemanal
    {
        return (new CriarRevisaoProgramacaoSemanal())->execute($p);
    }

    private function pFotografia(CronogramaImportacao $imp, string $atividadeId, EventoFotografiaProgramacao $evento): ?AtividadeSnapshotProgramacao
    {
        return AtividadeSnapshotProgramacao::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $atividadeId)
            ->where('evento', $evento->value)
            ->first();
    }

    // ===================== A/B: início =====================

    /** A — iniciou e estava programada -> sem inconsistência. */
    public function test_a_iniciou_e_estava_programada_nao_gera_inconsistencia(): void
    {
        $this->criarAtividadesExistentes(['x1']);
        $at = $this->atividade('x1');
        $segunda = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString(); // segunda-feira
        $this->comprometer($segunda, collect([$at]));

        $inicio = Carbon::parse('2026-01-06'); // terca da mesma semana
        $imp = $this->importarAvanco([$this->tarefa('x1', 40.0, realInicio: $inicio)]);

        $p = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio);
        $this->assertNotNull($p);
        $this->assertTrue($p->atividade_estava_na_programacao);
        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->count());
    }

    /** B — iniciou e NÃO estava (mas havia programação da semana) -> inicio_fora_programacao_semanal, severidade atencao. */
    public function test_b_iniciou_fora_da_programacao_gera_inconsistencia_atencao(): void
    {
        $this->criarAtividadesExistentes(['x1', 'placeholder']);
        $at = $this->atividade('x1');
        $placeholder = $this->atividade('placeholder');
        $segunda = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();
        $prog = $this->comprometer($segunda, collect([$placeholder])); // x1 NÃO está na programação

        $inicio = Carbon::parse('2026-01-06');
        $imp = $this->importarAvanco([$this->tarefa('x1', 40.0, realInicio: $inicio)]);

        $p = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio);
        $this->assertNotNull($p);
        $this->assertFalse($p->atividade_estava_na_programacao);
        $this->assertEquals($prog->id, $p->programacao_semanal_id);
        $this->assertEquals(1, $p->programacao_semanal_versao);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->first();
        $this->assertNotNull($inc);
        $this->assertEquals(TipoInconsistenciaAvanco::InicioForaProgramacaoSemanal, $inc->tipo);
        $this->assertEquals('atencao', $inc->severidade->value);
        $this->assertEquals(EntidadeInconsistenciaAvanco::ProgramacaoSemanal, $inc->entidade_tipo);
        $this->assertEquals($prog->id, $inc->entidade_id);
        $this->assertEquals($segunda, $inc->detalhes['semana_inicio_resolvida']);
    }

    // ===================== C/D: conclusão =====================

    /** C — concluiu e estava programada -> sem inconsistência. */
    public function test_c_concluiu_e_estava_programada_nao_gera_inconsistencia(): void
    {
        $this->criarAtividadesExistentes(['x1']);
        $at = $this->atividade('x1');
        $segunda = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->comprometer($segunda, collect([$at]));

        $termino = Carbon::parse('2026-01-08');
        $imp = $this->importarAvanco([$this->tarefa('x1', 100.0, realTermino: $termino)]);

        $p = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Conclusao);
        $this->assertNotNull($p);
        $this->assertTrue($p->atividade_estava_na_programacao);
        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->count());
    }

    /** D — concluiu e NÃO estava -> conclusao_fora_programacao_semanal, severidade critica. */
    public function test_d_concluiu_fora_da_programacao_gera_inconsistencia_critica(): void
    {
        $this->criarAtividadesExistentes(['x1', 'placeholder']);
        $at = $this->atividade('x1');
        $placeholder = $this->atividade('placeholder');
        $segunda = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->comprometer($segunda, collect([$placeholder]));

        $termino = Carbon::parse('2026-01-08');
        $imp = $this->importarAvanco([$this->tarefa('x1', 100.0, realTermino: $termino)]);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->first();
        $this->assertNotNull($inc);
        $this->assertEquals(TipoInconsistenciaAvanco::ConclusaoForaProgramacaoSemanal, $inc->tipo);
        $this->assertEquals('critica', $inc->severidade->value);
    }

    // ===================== E: início/conclusão em semanas diferentes =====================

    /** E — começou na semana X, terminou na semana Y -> cada fato usa sua própria semana. */
    public function test_e_inicio_e_conclusao_em_semanas_diferentes_usam_cada_semana_correta(): void
    {
        $this->criarAtividadesExistentes(['x1']);
        $at = $this->atividade('x1');

        $semana1 = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();
        $semana2 = Carbon::parse('2026-01-12')->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->assertNotEquals($semana1, $semana2);

        $this->comprometer($semana1, collect([$at])); // x1 programada na semana 1
        // Semana 2 NÃO tem nenhuma programação.

        $inicio = Carbon::parse('2026-01-06');  // semana 1
        $termino = Carbon::parse('2026-01-13'); // semana 2
        $imp = $this->importarAvanco([$this->tarefa('x1', 100.0, realInicio: $inicio, realTermino: $termino)]);

        $pInicio = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio);
        $pConclusao = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Conclusao);

        $this->assertEquals($semana1, $pInicio->semana_inicio_resolvida->toDateString());
        $this->assertTrue($pInicio->atividade_estava_na_programacao);

        $this->assertEquals($semana2, $pConclusao->semana_inicio_resolvida->toDateString());
        $this->assertFalse($pConclusao->atividade_estava_na_programacao);
        $this->assertNull($pConclusao->programacao_semanal_id); // nenhuma programação na semana 2

        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('tipo', TipoInconsistenciaAvanco::InicioSemProgramacaoSemanal->value)->count());
        $this->assertEquals(1, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('tipo', TipoInconsistenciaAvanco::ConclusaoSemProgramacaoSemanal->value)->count());
    }

    // ===================== F: múltiplas versões — o cenário crítico da investigação =====================

    /**
     * F — V1 criada segunda, fechada, V2 (revisão) criada quarta; a
     * atividade começou TERÇA (antes de V2 existir) mas só foi
     * comprometida em V2 na QUINTA (depois de já ter começado). Resolução
     * histórica correta: vigente-em-terça = V1 (V2 nem existia ainda);
     * atividade não estava em V1 -> fora_programacao_semanal. Se o
     * detector usasse ingenuamente "a versão mais recente hoje" (V2, que
     * por acaso JÁ contém a atividade na quinta), diria erroneamente
     * "estava programada" -- exatamente o falso positivo que a
     * investigação da A.9.5 identificou e que superseded_at evita.
     */
    public function test_f_multiplas_versoes_resolve_a_versao_historicamente_correta(): void
    {
        $this->criarAtividadesExistentes(['x1', 'placeholder']);
        $at = $this->atividade('x1');
        $placeholder = $this->atividade('placeholder');
        $semana = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();

        $segunda = Carbon::parse('2026-01-05 09:00:00');
        $terca = Carbon::parse('2026-01-06 09:00:00');
        $quarta = Carbon::parse('2026-01-07 09:00:00');
        $quinta = Carbon::parse('2026-01-08 09:00:00');

        // V1: criada e fechada segunda-feira, só com o placeholder.
        $v1 = $this->comprometer($semana, collect([$placeholder]));
        $v1->update(['congelada_em' => $segunda, 'created_at' => $segunda]);
        $v1 = $this->fechar($v1->fresh());
        $v1->update(['fechada_em' => $segunda]);

        // V2: revisão criada quarta-feira (recaptura só o placeholder, igual V1).
        $v2 = $this->revisar($v1->fresh());
        $v2->update(['congelada_em' => $quarta, 'created_at' => $quarta]);
        $v1->fresh()->update(['superseded_at' => $quarta]);
        ProgramacaoSemanalItem::where('programacao_semanal_id', $v2->id)->update(['created_at' => $quarta]);

        // x1 é comprometida em V2 só na QUINTA — depois de já ter começado.
        $v2 = $v2->fresh();
        if ($v2->estaFechada()) {
            $v2->update(['status' => 'aberta']); // reabre pra permitir o comprometimento tardio simulado
        }
        $novoItem = (new RegistrarComprometimentoSemanal())->execute($this->obra, $semana, collect([$at]), OrigemProgramacaoSemanalItem::Manual);
        ProgramacaoSemanalItem::where('programacao_semanal_id', $v2->id)->where('atividade_id', $at->id)
            ->update(['created_at' => $quinta]);

        // x1 realmente começou TERÇA — antes de V2 sequer existir.
        $imp = $this->importarAvanco([$this->tarefa('x1', 30.0, realInicio: $terca)]);

        $p = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio);
        $this->assertNotNull($p);
        $this->assertEquals($v1->id, $p->programacao_semanal_id, 'Deveria resolver V1 (vigente em terca), nunca V2 (só nasceu quarta).');
        $this->assertEquals(1, $p->programacao_semanal_versao);
        $this->assertFalse($p->atividade_estava_na_programacao, 'x1 não estava em V1 -- não pode ser considerada programada.');

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->first();
        $this->assertNotNull($inc);
        $this->assertEquals(TipoInconsistenciaAvanco::InicioForaProgramacaoSemanal, $inc->tipo);
    }

    // ===================== G: imutabilidade =====================

    /** G — edição posterior da Programação Semanal não altera a fotografia já gravada. */
    public function test_g_edicao_posterior_da_programacao_nao_altera_fotografia_antiga(): void
    {
        $this->criarAtividadesExistentes(['x1']);
        $at = $this->atividade('x1');
        $semana = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();
        $prog = $this->comprometer($semana, collect([$at]));

        $inicio = Carbon::parse('2026-01-06');
        $imp = $this->importarAvanco([$this->tarefa('x1', 40.0, realInicio: $inicio)]);

        $antes = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio);
        $this->assertTrue($antes->atividade_estava_na_programacao);

        // Edição posterior: remove o item da programação (ex.: reprogramação manual).
        ProgramacaoSemanalItem::where('programacao_semanal_id', $prog->id)->where('atividade_id', $at->id)->delete();

        $depois = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio);
        $this->assertTrue($depois->atividade_estava_na_programacao, 'A fotografia já gravada não pode mudar retroativamente.');
        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->count());
    }

    // ===================== H: atividade nova =====================

    /** H — atividade nova nesta própria importação, já iniciada: não pode estar em NENHUMA programação pré-existente -> fora_programacao_semanal (existia programação da semana, com outra atividade). */
    public function test_h_atividade_nova_ja_iniciada_gera_inconsistencia_fora_da_programacao(): void
    {
        $this->criarAtividadesExistentes(['placeholder']);
        $placeholder = $this->atividade('placeholder');
        $semana = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();
        $prog = $this->comprometer($semana, collect([$placeholder]));

        $inicio = Carbon::parse('2026-01-06');
        $plano = new PlanoImportacao(
            criar: [$this->tarefa('nova1', 100.0, realInicio: $inicio, realTermino: $inicio)],
            atualizar: [], pacotes: [], removerIds: [], removerNomes: [],
            ignoradasNomes: [], dataStatus: null, totalBaselineHh: 0, totalWorkHh: 0, totalRealHh: 0,
            horasPeriodos: []
        );
        $imp = $this->importer->aplicar($plano, $this->obra, $this->user->id, 'nova.xml', TipoCronogramaImportacao::Ambos);
        $atNova = Atividade::where('obra_id', $this->obra->id)->where('external_uid', 'nova1')->firstOrFail();

        $pInicio = $this->pFotografia($imp, $atNova->id, EventoFotografiaProgramacao::Inicio);
        $this->assertNotNull($pInicio);
        $this->assertFalse($pInicio->atividade_estava_na_programacao);
        $this->assertEquals($prog->id, $pInicio->programacao_semanal_id, 'Existia programação da semana -- atividade nova só não estava nela.');

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $atNova->id)
            ->where('tipo', TipoInconsistenciaAvanco::InicioForaProgramacaoSemanal->value)->first();
        $this->assertNotNull($inc);
    }

    // ===================== I: nenhuma programação da semana =====================

    /** I — nenhuma Programação Semanal existia pra aquela semana -> sem_programacao_semanal (fato diferente de "fora"). */
    public function test_i_nenhuma_programacao_da_semana_gera_inconsistencia_sem_programacao(): void
    {
        $this->criarAtividadesExistentes(['x1']);
        $at = $this->atividade('x1');
        // Nenhuma ProgramacaoSemanal criada em lugar nenhum.

        $inicio = Carbon::parse('2026-01-06');
        $imp = $this->importarAvanco([$this->tarefa('x1', 40.0, realInicio: $inicio)]);

        $p = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio);
        $this->assertNotNull($p);
        $this->assertNull($p->programacao_semanal_id);
        $this->assertNull($p->programacao_semanal_versao);
        $this->assertFalse($p->atividade_estava_na_programacao);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->first();
        $this->assertEquals(TipoInconsistenciaAvanco::InicioSemProgramacaoSemanal, $inc->tipo);
        $this->assertEquals(EntidadeInconsistenciaAvanco::Atividade, $inc->entidade_tipo);
        $this->assertEquals($at->id, $inc->entidade_id);
    }

    // ===================== J/K/L: tipo de importação =====================

    /** J — Baseline pura: zero Fotografia P, zero inconsistência de programação. */
    public function test_j_importacao_baseline_nao_gera_fotografia_p(): void
    {
        $semana = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();
        $imp = $this->criarAtividadesExistentes(['x1']); // Baseline, sem datas reais -- mas mesmo com, não gravaria P
        $at = $this->atividade('x1');

        $this->assertEquals(0, AtividadeSnapshotProgramacao::where('cronograma_importacao_id', $imp->id)->count());
        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->count());
    }

    /** K — Avanço: Fotografia P funciona normalmente (já coberto pelos testes A-I, este é smoke test dedicado). */
    public function test_k_importacao_avanco_gera_fotografia_p(): void
    {
        $this->criarAtividadesExistentes(['x1']);
        $at = $this->atividade('x1');
        $inicio = Carbon::parse('2026-01-06');
        $imp = $this->importarAvanco([$this->tarefa('x1', 40.0, realInicio: $inicio)], TipoCronogramaImportacao::Avanco);

        $this->assertNotNull($this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio));
    }

    /** L — Ambos: Fotografia P também funciona. */
    public function test_l_importacao_ambos_gera_fotografia_p(): void
    {
        $inicio = Carbon::parse('2026-01-06');
        $plano = new PlanoImportacao(
            criar: [$this->tarefa('x1', 40.0, realInicio: $inicio)],
            atualizar: [], pacotes: [], removerIds: [], removerNomes: [],
            ignoradasNomes: [], dataStatus: null, totalBaselineHh: 0, totalWorkHh: 0, totalRealHh: 0,
            horasPeriodos: []
        );
        $imp = $this->importer->aplicar($plano, $this->obra, $this->user->id, 'ambos.xml', TipoCronogramaImportacao::Ambos);
        $at = $this->atividade('x1');

        $this->assertNotNull($this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio));
    }

    // ===================== M: I1/I2 independentes =====================

    /** M — I1 e I2 sucessivas preservam histórico independente da Fotografia P. */
    public function test_m_i1_e_i2_preservam_fotografia_p_independente(): void
    {
        $this->criarAtividadesExistentes(['x1']);
        $at = $this->atividade('x1');
        $semana = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->comprometer($semana, collect([$at]));

        $inicio = Carbon::parse('2026-01-06');
        $i1 = $this->importarAvanco([$this->tarefa('x1', 40.0, realInicio: $inicio)]);
        $i2 = $this->importarAvanco([$this->tarefa('x1', 70.0, realInicio: $inicio)]);

        $p1 = $this->pFotografia($i1, $at->id, EventoFotografiaProgramacao::Inicio);
        $p2 = $this->pFotografia($i2, $at->id, EventoFotografiaProgramacao::Inicio);
        $this->assertNotNull($p1);
        $this->assertNotNull($p2);
        $this->assertNotEquals($p1->id, $p2->id);
        $this->assertTrue($p1->atividade_estava_na_programacao);
        $this->assertTrue($p2->atividade_estava_na_programacao);
    }

    // ===================== N: rollback =====================

    /** N — falha depois do detector, dentro da mesma transação -> nenhuma Fotografia P/inconsistência de programação persiste. */
    public function test_n_rollback_desfaz_fotografia_p_junto(): void
    {
        $this->criarAtividadesExistentes(['x1', 'placeholder']);
        $at = $this->atividade('x1');
        $placeholder = $this->atividade('placeholder');
        $semana = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->comprometer($semana, collect([$placeholder]));

        $inicio = Carbon::parse('2026-01-06');
        $plano = new PlanoImportacao(
            criar: [], atualizar: [$this->tarefa('x1', 40.0, realInicio: $inicio)],
            pacotes: [], removerIds: [], removerNomes: [], ignoradasNomes: [], dataStatus: null,
            totalBaselineHh: 0, totalWorkHh: 0, totalRealHh: 0, horasPeriodos: []
        );

        $lancado = null;
        try {
            DB::transaction(function () use ($plano) {
                $this->importer->aplicar($plano, $this->obra, $this->user->id, 'x.xml', TipoCronogramaImportacao::Avanco);
                throw new \RuntimeException('falha simulada pos-aplicar');
            });
        } catch (\RuntimeException $e) {
            $lancado = $e->getMessage();
        }

        $this->assertNotNull($lancado);
        $this->assertEquals(0, AtividadeSnapshotProgramacao::where('atividade_id', $at->id)->count());
        $this->assertEquals(0, InconsistenciaAvanco::where('atividade_id', $at->id)->count());
    }

    // ===================== O/P: isolamento =====================

    /** O — isolamento de tenant: Fotografia P de um tenant nunca aparece pra outro. */
    public function test_o_isolamento_de_tenant(): void
    {
        $this->criarAtividadesExistentes(['x1']);
        $at = $this->atividade('x1');
        $inicio = Carbon::parse('2026-01-06');
        $imp = $this->importarAvanco([$this->tarefa('x1', 40.0, realInicio: $inicio)]);
        $pId = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio)->id;

        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($outroUser);

        $this->assertNull(AtividadeSnapshotProgramacao::find($pId));
    }

    /** P — isolamento de obra: programação de uma obra nunca é usada pra outra do mesmo tenant. */
    public function test_p_isolamento_de_obra(): void
    {
        $this->criarAtividadesExistentes(['x1']);
        $at = $this->atividade('x1');
        $semana = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();

        $outraObra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        // Programação criada na OUTRA obra, mesma semana -- não pode contar pra esta.
        $outraAtividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $outraObra->id]);
        (new RegistrarComprometimentoSemanal())->execute($outraObra, $semana, collect([$outraAtividade]), OrigemProgramacaoSemanalItem::Manual);

        $inicio = Carbon::parse('2026-01-06');
        $imp = $this->importarAvanco([$this->tarefa('x1', 40.0, realInicio: $inicio)]);

        $p = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio);
        $this->assertNull($p->programacao_semanal_id, 'Programação de outra obra nunca deve ser resolvida aqui.');
        $this->assertFalse($p->atividade_estava_na_programacao);
    }

    // ===================== Q: performance =====================

    /**
     * Q — N=5/20/100: custo MARGINAL da Fotografia P não cresce
     * linearmente. Medido por delta (mesmas N atividades, mesmo import,
     * COM Programação Semanal relevante vs SEM nenhuma) -- o custo total
     * bruto de aplicar() já é linear em N por um motivo PRÉ-EXISTENTE e
     * não relacionado a esta feature (o ramo Avanço faz update()+find()
     * por atividade, mesmo padrão já documentado na medição da
     * A.9.4.HARDENING) -- medir o total mascararia o efeito real da
     * Fotografia P.
     */
    public function test_q_custo_marginal_da_fotografia_p_nao_cresce_linearmente(): void
    {
        foreach ([5, 20, 100] as $n) {
            $semPCusto = $this->rodarImportacaoMedida($n, comProgramacao: false);
            $comPCusto = $this->rodarImportacaoMedida($n, comProgramacao: true);
            $delta = $comPCusto - $semPCusto;

            $this->assertLessThan(10, $delta, "N={$n}: delta de {$delta} queries -- suspeita de N+1 na Fotografia P.");
        }
    }

    private function rodarImportacaoMedida(int $n, bool $comProgramacao): int
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $uids = array_map(fn ($i) => "u{$i}", range(1, $n));
        $plano = new PlanoImportacao(
            criar: array_map(fn ($uid) => $this->tarefa($uid), $uids),
            atualizar: [], pacotes: [], removerIds: [], removerNomes: [],
            ignoradasNomes: [], dataStatus: null, totalBaselineHh: 0, totalWorkHh: 0, totalRealHh: 0,
            horasPeriodos: []
        );
        $this->importer->aplicar($plano, $obra, $user->id, 'base.xml', TipoCronogramaImportacao::Baseline);

        $semana = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();
        if ($comProgramacao) {
            $atividades = Atividade::where('obra_id', $obra->id)->get();
            (new RegistrarComprometimentoSemanal())->execute($obra, $semana, $atividades->take((int) ($n / 2)), OrigemProgramacaoSemanalItem::Manual);
        }

        $inicio = Carbon::parse('2026-01-06');
        $tarefasAvanco = array_map(fn ($uid) => $this->tarefa($uid, 40.0, realInicio: $inicio), $uids);
        $planoAvanco = new PlanoImportacao(
            criar: [], atualizar: $tarefasAvanco, pacotes: [], removerIds: [], removerNomes: [],
            ignoradasNomes: [], dataStatus: null, totalBaselineHh: 0, totalWorkHh: 0, totalRealHh: 0,
            horasPeriodos: []
        );

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $this->importer->aplicar($planoAvanco, $obra, $user->id, 'avanco.xml', TipoCronogramaImportacao::Avanco);

        return $queries;
    }

    // ===================== R: zero autocorreção =====================

    /** R — Fotografia P nunca altera ProgramacaoSemanal/ProgramacaoSemanalItem/Atividade. */
    public function test_r_fotografia_p_nunca_altera_programacao_semanal(): void
    {
        $this->criarAtividadesExistentes(['x1', 'placeholder']);
        $at = $this->atividade('x1');
        $placeholder = $this->atividade('placeholder');
        $semana = Carbon::parse('2026-01-05')->startOfWeek(Carbon::MONDAY)->toDateString();
        $prog = $this->comprometer($semana, collect([$placeholder]));

        $itensAntes = ProgramacaoSemanalItem::where('programacao_semanal_id', $prog->id)->count();
        $versaoAntes = $prog->fresh()->versao;

        $inicio = Carbon::parse('2026-01-06');
        $this->importarAvanco([$this->tarefa('x1', 40.0, realInicio: $inicio)]);

        $this->assertEquals($itensAntes, ProgramacaoSemanalItem::where('programacao_semanal_id', $prog->id)->count(), 'A importação nunca deve adicionar a atividade retroativamente à programação.');
        $this->assertEquals($versaoAntes, $prog->fresh()->versao);
        $this->assertNull(ProgramacaoSemanalItem::where('programacao_semanal_id', $prog->id)->where('atividade_id', $at->id)->first());
    }

    // ===================== S: coexistência =====================

    /** S — a mesma atividade pode gerar inconsistência de programação + restrição + prontidão simultaneamente, sem conflito. */
    public function test_s_coexistencia_com_inconsistencia_de_restricao_e_prontidao(): void
    {
        $this->criarAtividadesExistentes(['x1']);
        $at = $this->atividade('x1');
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);
        // Nenhuma programação semanal criada -- vira "sem programação".

        $inicio = Carbon::parse('2026-01-06');
        $imp = $this->importarAvanco([$this->tarefa('x1', 100.0, realInicio: $inicio, realTermino: $inicio)]);

        $tipos = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at->id)->pluck('tipo')->map(fn ($t) => $t->value)->all();

        $this->assertContains(TipoInconsistenciaAvanco::InicioComRestricaoPendente->value, $tipos);
        $this->assertContains(TipoInconsistenciaAvanco::ConclusaoComRestricaoPendente->value, $tipos);
        $this->assertContains(TipoInconsistenciaAvanco::InicioSemProgramacaoSemanal->value, $tipos);
        $this->assertContains(TipoInconsistenciaAvanco::ConclusaoSemProgramacaoSemanal->value, $tipos);
        $this->assertGreaterThanOrEqual(4, count($tipos));
    }

    // ===================== T/U: A.9.5.HARDENING — item vs versão =====================

    /**
     * T (crítico, A.9.5.HARDENING) — item adicionado à MESMA versão
     * DEPOIS do fato não pode contar como "estava programada". X não
     * fazia parte de V1 quando realmente iniciou (terça); só foi
     * comprometida em V1 na quarta — depois do fato. Header resolvido
     * é a MESMA V1 o tempo todo (nenhuma revisão/V2 envolvida aqui —
     * distingue este teste do teste F, que prova resolução de VERSÃO
     * via superseded_at; este prova a checagem de created_at do ITEM
     * dentro da versão já corretamente resolvida). O item existe DE
     * VERDADE em V1 hoje — é só o timing dele que o invalida.
     */
    public function test_t_item_adicionado_a_mesma_versao_depois_do_fato_nao_conta_como_programada(): void
    {
        $this->criarAtividadesExistentes(['x1', 'placeholder']);
        $at = $this->atividade('x1');
        $placeholder = $this->atividade('placeholder');
        $semana = Carbon::parse('2026-02-02')->startOfWeek(Carbon::MONDAY)->toDateString();

        $segunda = Carbon::parse('2026-02-02 08:00:00');
        $terca = Carbon::parse('2026-02-03');
        $quarta = Carbon::parse('2026-02-04 08:00:00');

        // V1 criada segunda, só com o placeholder — x1 NÃO faz parte ainda.
        $v1 = $this->comprometer($semana, collect([$placeholder]), $segunda);

        // Quarta: x1 é adicionada à MESMA V1 (header já existe, aberta — só acrescenta item, nunca cria V2).
        (new RegistrarComprometimentoSemanal())->execute($this->obra, $semana, collect([$at]), OrigemProgramacaoSemanalItem::Manual);
        $itemX1 = ProgramacaoSemanalItem::where('programacao_semanal_id', $v1->id)->where('atividade_id', $at->id)->firstOrFail();
        $itemX1->update(['created_at' => $quarta]);

        // Sexta: importa Avanço informando que x1 REALMENTE começou terça — antes de ter sido comprometida.
        $imp = $this->importarAvanco([$this->tarefa('x1', 30.0, realInicio: $terca)]);

        $p = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio);
        $this->assertNotNull($p);

        // Versão resolvida: a MESMA V1 (só existe uma versão neste teste).
        $this->assertEquals($v1->id, $p->programacao_semanal_id);
        $this->assertEquals(1, $p->programacao_semanal_versao);
        $this->assertEquals($terca->toDateString(), $p->data_factual->toDateString());

        // O item existe FISICAMENTE em V1 agora — é o created_at dele, posterior ao fato, que o invalida.
        $itemAtual = ProgramacaoSemanalItem::where('programacao_semanal_id', $v1->id)->where('atividade_id', $at->id)->first();
        $this->assertNotNull($itemAtual, 'O item existe em V1 no momento da consulta — não é ausência de item que gera a inconsistência.');
        $this->assertTrue($itemAtual->created_at->gt($terca->copy()->endOfDay()), 'O item foi criado DEPOIS do instante do fato (fim do dia de terça).');

        // ...mas Fotografia P nunca pode considerar isso "estava programada" retroativamente.
        $this->assertFalse($p->atividade_estava_na_programacao);
        $this->assertNull($p->programacao_semanal_item_id);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)->first();
        $this->assertNotNull($inc);
        $this->assertEquals(TipoInconsistenciaAvanco::InicioForaProgramacaoSemanal, $inc->tipo);
        $this->assertEquals('atencao', $inc->severidade->value);
        $this->assertEquals($v1->id, $inc->entidade_id);
    }

    /**
     * U (controle de T) — item já existia na versão ANTES do fato:
     * deve ser considerado programado. Datas e obra deliberadamente
     * distintas de T pra este teste não passar "por coincidência" de
     * reaproveitar estado do cenário anterior.
     */
    public function test_u_item_ja_existia_na_versao_antes_do_fato_conta_como_programada(): void
    {
        $this->criarAtividadesExistentes(['x2']);
        $at = $this->atividade('x2');
        $semana = Carbon::parse('2026-03-09')->startOfWeek(Carbon::MONDAY)->toDateString();

        $segunda = Carbon::parse('2026-03-09 08:00:00');
        $terca = Carbon::parse('2026-03-10'); // fato: real_inicio

        // V1 criada segunda JÁ com x2 dentro.
        $v1 = $this->comprometer($semana, collect([$at]), $segunda);

        // x2 realmente inicia terça — depois que já estava em V1.
        $imp = $this->importarAvanco([$this->tarefa('x2', 25.0, realInicio: $terca)]);

        $p = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio);
        $this->assertNotNull($p);
        $this->assertEquals($v1->id, $p->programacao_semanal_id);
        $this->assertTrue($p->atividade_estava_na_programacao);
        $this->assertNotNull($p->programacao_semanal_item_id);

        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('tipo', TipoInconsistenciaAvanco::InicioForaProgramacaoSemanal->value)->count());
        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('tipo', TipoInconsistenciaAvanco::InicioSemProgramacaoSemanal->value)->count());
    }

    // ===================== V: A.9.5.HARDENING — granularidade diária (mesmo dia) =====================

    /**
     * V (A.9.5.HARDENING) — política de granularidade diária: uma
     * revisão criada MAIS TARDE NO MESMO DIA do fato ainda é
     * considerada vigente, porque a comparação usa endOfDay() da data
     * factual (sem hora) contra congelada_em (com hora). V1 fechada às
     * 09h; V2 (revisão) criada às 15h do MESMO dia; o fato (real_inicio)
     * ocorre nesse mesmo dia, sem hora. Resultado esperado pela política
     * já documentada (CLAUDE.md): V2 vence, mesmo tendo nascido depois
     * do instante em que o dia começou — limitação consciente de
     * precisão (real_inicio é DATE, nunca DATETIME), não corrigida
     * nesta etapa. Relógio congelado via Carbon::setTestNow() — nenhuma
     * data deste teste depende de now() real.
     */
    public function test_v_revisao_criada_mais_tarde_no_mesmo_dia_do_fato_ainda_e_considerada_vigente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-06 20:00:00'));

        try {
            $this->criarAtividadesExistentes(['x3', 'placeholder2']);
            $at = $this->atividade('x3');
            $placeholder = $this->atividade('placeholder2');
            $semana = Carbon::parse('2026-04-06')->startOfWeek(Carbon::MONDAY)->toDateString();

            $mesmoDia9h = Carbon::parse('2026-04-06 09:00:00');
            $mesmoDia15h = Carbon::parse('2026-04-06 15:00:00');

            $v1 = $this->comprometer($semana, collect([$placeholder]), $mesmoDia9h);
            $v1 = $this->fechar($v1->fresh());
            $v1->update(['fechada_em' => $mesmoDia9h]);

            $v2 = $this->revisar($v1->fresh());
            $v2->update(['congelada_em' => $mesmoDia15h]);
            ProgramacaoSemanalItem::where('programacao_semanal_id', $v2->id)->update(['created_at' => $mesmoDia15h]);
            $v1->fresh()->update(['superseded_at' => $mesmoDia15h]);

            // x3 nunca fez parte de V1 nem de V2 (só o placeholder foi recapturado na revisão)
            // — o que este teste verifica é qual VERSÃO é resolvida como vigente, não o item.
            $imp = $this->importarAvanco([$this->tarefa('x3', 20.0, realInicio: Carbon::parse('2026-04-06'))]);

            $p = $this->pFotografia($imp, $at->id, EventoFotografiaProgramacao::Inicio);
            $this->assertNotNull($p);
            $this->assertEquals($v2->id, $p->programacao_semanal_id, 'Granularidade diária: revisão criada mais tarde NO MESMO DIA do fato ainda vence (comparação via endOfDay()).');
            $this->assertEquals(2, $p->programacao_semanal_versao);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ===================== W: A.9.5.HARDENING — superseded_at legado =====================

    /**
     * W — legado: DUAS versões com superseded_at=NULL (nunca setado —
     * simula dado criado ANTES da coluna existir, período anterior à
     * A.9.5), distinguidas só por congelada_em. `vigenteEm()` consultada
     * num instante ENTRE as duas datas de congelamento deve degradar
     * sozinha pra "versão mais recente cuja congelada_em já tinha
     * passado" (V1), nunca inventar um instante de substituição que
     * ninguém registrou. Teste focado direto em
     * `ProgramacaoSemanal::vigenteEm()` — já provado, na auditoria
     * anterior, que este é o caso realmente sem cobertura equivalente.
     */
    public function test_w_superseded_at_nulo_nas_duas_versoes_legado_resolve_pela_congelada_em(): void
    {
        $this->criarAtividadesExistentes(['placeholder3']);
        $placeholder = $this->atividade('placeholder3');
        $semana = Carbon::parse('2026-05-04')->startOfWeek(Carbon::MONDAY)->toDateString();

        $v1CongeladaEm = Carbon::parse('2026-05-04 08:00:00');
        $v2CongeladaEm = Carbon::parse('2026-05-06 08:00:00');
        $instanteEntreAsDuas = Carbon::parse('2026-05-05 12:00:00');

        $v1 = $this->comprometer($semana, collect([$placeholder]), $v1CongeladaEm);
        $v1 = $this->fechar($v1->fresh());
        // `CriarRevisaoProgramacaoSemanal::execute()` SEMPRE carimba
        // `superseded_at` no original ao revisar (comportamento real da
        // A.9.5) — pra simular dado LEGADO (criado antes dessa coluna
        // existir, nunca backfilled), forçamos `null` de volta logo
        // depois, deliberadamente contrariando o que a Action real faria.
        $v2 = $this->revisar($v1->fresh());
        $v2->update(['congelada_em' => $v2CongeladaEm, 'superseded_at' => null]);
        ProgramacaoSemanalItem::where('programacao_semanal_id', $v2->id)->update(['created_at' => $v2CongeladaEm]);
        $v1->fresh()->update(['superseded_at' => null]);

        $this->assertNull($v1->fresh()->superseded_at);
        $this->assertNull($v2->fresh()->superseded_at);

        $resolvida = ProgramacaoSemanal::vigenteEm($this->obra, $semana, $instanteEntreAsDuas);

        $this->assertNotNull($resolvida);
        $this->assertEquals($v1->id, $resolvida->id, 'Sem superseded_at, degrada pra "mais recente cuja congelada_em já tinha passado" — V1, nunca V2 (que só existe a partir de quarta).');
        $this->assertEquals(1, $resolvida->versao);
    }
}
