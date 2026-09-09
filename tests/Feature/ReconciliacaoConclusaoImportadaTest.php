<?php

namespace Tests\Feature;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\EntidadeInconsistenciaAvanco;
use App\Enums\OrigemAtividade;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Enums\TipoInconsistenciaAvanco;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\CronogramaImportacao;
use App\Models\InconsistenciaAvanco;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 24 (revisado após auditoria adversarial) — regra de produto
 * aprovada: se uma atividade é IMPORTADA como fisicamente concluída
 * (PercentWorkComplete >= 100% — ÚNICO sinal autoritativo, nunca union
 * com ActualFinish), status/concluido_em são reconciliados e seus itens
 * de prontidão aplicáveis são atendidos automaticamente (origem sempre
 * auditável) — mas Restrição NUNCA é fechada automaticamente.
 * ActualFinish presente com percentual < 100% (ou uma regressão de
 * percentual numa atividade já concluída) NUNCA é silenciosamente
 * ignorado nem silenciosamente tratado como conclusão — vira
 * `InconsistenciaAvanco` explícita. Ver App\Imports\MsProjectImporter::
 * reconciliarItensDeProntidao(), App\Observers\AtividadeObserver e
 * App\Services\DetectorInconsistenciasAvanco.
 */
class ReconciliacaoConclusaoImportadaTest extends TestCase
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

    private function criarAtividadeExistente(string $uid, array $overrides = []): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id'    => $this->user->tenant_id,
            'obra_id'      => $this->obra->id,
            'origem'       => OrigemAtividade::MsProject->value,
            'external_uid' => $uid,
            'status'       => StatusAtividade::Planejado->value,
        ], $overrides));
    }

    private function tarefa(
        string $uid,
        ?float $percentual,
        ?Carbon $realInicio = null,
        ?Carbon $realTermino = null,
    ): TarefaImportada {
        return new TarefaImportada(
            uid: $uid, nome: "Atividade {$uid}", isSummary: false, isMarco: false,
            caminhoCritico: false, parentUid: null, codigo: $uid,
            dataInicio: null, dataTermino: null, baselineInicio: null, baselineTermino: null,
            realInicio: $realInicio, realTermino: $realTermino,
            baselineHoras: 0, workHoras: 0, realHoras: 0, percentualConcluido: $percentual,
            textos: []
        );
    }

    private function aplicarAvanco(array $tarefas, ?Carbon $dataStatus = null): CronogramaImportacao
    {
        $plano = new PlanoImportacao(
            criar: [], atualizar: $tarefas, pacotes: [], removerIds: [], removerNomes: [],
            ignoradasNomes: [], dataStatus: $dataStatus, totalBaselineHh: 0, totalWorkHh: 0, totalRealHh: 0,
            horasPeriodos: []
        );

        return $this->importer->aplicar($plano, $this->obra, $this->user->id, 'avanco.xml', TipoCronogramaImportacao::Avanco);
    }

    private function aplicarAmbos(array $criar): CronogramaImportacao
    {
        $plano = new PlanoImportacao(
            criar: $criar, atualizar: [], pacotes: [], removerIds: [], removerNomes: [],
            ignoradasNomes: [], dataStatus: null, totalBaselineHh: 0, totalWorkHh: 0, totalRealHh: 0,
            horasPeriodos: []
        );

        return $this->importer->aplicar($plano, $this->obra, $this->user->id, 'ambos.xml', TipoCronogramaImportacao::Ambos);
    }

    // ===================== Conclusão física + status/concluido_em =====================

    public function test_atividade_0_para_100_com_actual_finish_e_reconciliada_como_concluida(): void
    {
        $at = $this->criarAtividadeExistente('1');
        $termino = Carbon::parse('2026-08-20');

        $this->aplicarAvanco([$this->tarefa('1', 100.0, null, $termino)]);

        $at->refresh();
        $this->assertEquals(StatusAtividade::Concluido, $at->status);
        $this->assertNotNull($at->concluido_em);
        $this->assertSame('2026-08-20', $at->concluido_em->toDateString());
    }

    public function test_atividade_100_sem_actual_finish_usa_data_status_da_importacao_como_fallback(): void
    {
        $at = $this->criarAtividadeExistente('2');

        $this->aplicarAvanco([$this->tarefa('2', 100.0, null, null)], Carbon::parse('2026-08-15'));

        $at->refresh();
        $this->assertEquals(StatusAtividade::Concluido, $at->status);
        $this->assertSame('2026-08-15', $at->concluido_em->toDateString());
    }

    public function test_c_percentual_80_com_actual_finish_nao_e_concluida_gera_inconsistencia(): void
    {
        // Caso C da auditoria: ActualFinish sozinho NUNCA basta — 80% é
        // uma divergência explícita (Health Check/ActualFinish inválido
        // pra propósito de conclusão), nunca silenciosamente tratada como
        // "concluída" nem silenciosamente ignorada.
        $at = $this->criarAtividadeExistente('3');
        $termino = Carbon::parse('2026-08-10');

        $imp = $this->aplicarAvanco([$this->tarefa('3', 80.0, null, $termino)]);

        $at->refresh();
        $this->assertEquals(StatusAtividade::Planejado, $at->status, 'ActualFinish sozinho, sem 100%, nunca conclui a atividade.');
        $this->assertNull($at->concluido_em);
        $this->assertEquals(80.0, (float) $at->percentual_concluido);
        $this->assertSame('2026-08-10', $at->real_termino->toDateString(), 'O fato ActualFinish continua gravado normalmente — só não sincroniza status.');

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::TerminoRealComPercentualIncompleto->value)
            ->first();
        $this->assertNotNull($inc, 'A divergência ActualFinish+percentual<100 precisa virar evidência explícita.');
        $this->assertEquals(80.0, $inc->detalhes['percentual_concluido']);
    }

    public function test_d_percentual_0_com_actual_finish_nao_e_concluida_gera_inconsistencia(): void
    {
        // Caso D, ainda mais extremo que C — 0% com ActualFinish é a
        // contradição mais forte da matriz e precisa do mesmo tratamento:
        // nunca concluir, sempre evidenciar.
        $at = $this->criarAtividadeExistente('3b');
        $termino = Carbon::parse('2026-08-11');

        $imp = $this->aplicarAvanco([$this->tarefa('3b', 0.0, null, $termino)]);

        $at->refresh();
        $this->assertEquals(StatusAtividade::Planejado, $at->status);
        $this->assertNull($at->concluido_em);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::TerminoRealComPercentualIncompleto->value)
            ->first();
        $this->assertNotNull($inc);
        $this->assertEquals(0.0, $inc->detalhes['percentual_concluido']);
    }

    public function test_atividade_parcial_continua_nao_concluida(): void
    {
        $at = $this->criarAtividadeExistente('4');

        $this->aplicarAvanco([$this->tarefa('4', 45.0, null, null)]);

        $at->refresh();
        $this->assertEquals(StatusAtividade::Planejado, $at->status);
        $this->assertNull($at->concluido_em);
        $this->assertEquals(45.0, (float) $at->percentual_concluido);
    }

    public function test_ambos_branch_tambem_reconcilia_conclusao_preservando_concluido_em_explicito(): void
    {
        // Exercita o caminho updateOrCreate() (dispara AtividadeObserver) —
        // confirma que o Observer preserva o concluido_em explicitamente
        // definido pelo importador em vez de sobrescrever com now().
        $termino = Carbon::parse('2026-07-01');

        $imp = $this->aplicarAmbos([$this->tarefa('500', 100.0, null, $termino)]);

        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '500')->firstOrFail();
        $this->assertEquals(StatusAtividade::Concluido, $at->status);
        $this->assertSame('2026-07-01', $at->concluido_em->toDateString());
        $this->assertNotNull($imp->id);
    }

    // ===================== Regressão de percentual (matriz 100→80 / 100→99 / 100→100 / 80→100) =====================

    public function test_regressao_100_para_80_nao_desfaz_status_e_gera_inconsistencia_explicita(): void
    {
        $at = $this->criarAtividadeExistente('5');
        $termino = Carbon::parse('2026-08-01');

        $this->aplicarAvanco([$this->tarefa('5', 100.0, null, $termino)]);
        $at->refresh();
        $this->assertEquals(StatusAtividade::Concluido, $at->status);
        $concluidoEmOriginal = $at->concluido_em->toDateString();

        // XML posterior regride o percentual (sem trazer real_termino de
        // novo) — status/concluido_em/prontidão NUNCA são revertidos
        // silenciosamente, mas o estado contraditório vira evidência
        // explícita (nunca passa despercebido).
        $imp2 = $this->aplicarAvanco([$this->tarefa('5', 80.0, null, null)]);
        $at->refresh();

        $this->assertEquals(StatusAtividade::Concluido, $at->status, 'Não desfaz automaticamente.');
        $this->assertSame($concluidoEmOriginal, $at->concluido_em->toDateString());
        $this->assertEquals(80.0, (float) $at->percentual_concluido, 'O fato bruto do XML continua sendo gravado — não é um estado impossível, é uma inconsistência sinalizada.');

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp2->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::RegressaoPercentualAposConclusao->value)
            ->first();
        $this->assertNotNull($inc, 'Regressão 100->80 numa atividade já concluída precisa virar evidência explícita.');
        $this->assertEquals(100.0, $inc->detalhes['percentual_antes']);
        $this->assertEquals(80.0, $inc->detalhes['percentual_atual']);
    }

    public function test_regressao_100_para_99_tambem_gera_inconsistencia(): void
    {
        $at = $this->criarAtividadeExistente('5b');
        $this->aplicarAvanco([$this->tarefa('5b', 100.0, null, Carbon::parse('2026-08-01'))]);

        $imp2 = $this->aplicarAvanco([$this->tarefa('5b', 99.0, null, null)]);
        $at->refresh();

        $this->assertEquals(StatusAtividade::Concluido, $at->status);
        $this->assertEquals(99.0, (float) $at->percentual_concluido);

        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp2->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::RegressaoPercentualAposConclusao->value)
            ->first();
        $this->assertNotNull($inc, 'Mesmo uma regressão pequena (100->99) precisa ser sinalizada.');
    }

    public function test_100_para_100_nao_e_regressao_nem_gera_inconsistencia(): void
    {
        $at = $this->criarAtividadeExistente('5c');
        $this->aplicarAvanco([$this->tarefa('5c', 100.0, null, Carbon::parse('2026-08-01'))]);

        $imp2 = $this->aplicarAvanco([$this->tarefa('5c', 100.0, null, Carbon::parse('2026-08-01'))]);
        $at->refresh();

        $this->assertEquals(StatusAtividade::Concluido, $at->status);
        $this->assertEquals(100.0, (float) $at->percentual_concluido);

        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp2->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::RegressaoPercentualAposConclusao->value)
            ->count(), 'Reconfirmar 100% de novo nunca é regressão.');
    }

    public function test_80_para_100_e_transicao_normal_nunca_regressao(): void
    {
        $at = $this->criarAtividadeExistente('5d');

        // Primeira importação: 80% — NÃO concluída (regra canônica revisada).
        $imp1 = $this->aplicarAvanco([$this->tarefa('5d', 80.0, null, null)]);
        $at->refresh();
        $this->assertEquals(StatusAtividade::Planejado, $at->status);

        // Segunda importação: 100% — transição normal de conclusão, nunca
        // uma "regressão" (percentual está SUBINDO, não descendo).
        $imp2 = $this->aplicarAvanco([$this->tarefa('5d', 100.0, null, Carbon::parse('2026-08-02'))]);
        $at->refresh();

        $this->assertEquals(StatusAtividade::Concluido, $at->status);
        $this->assertEquals(0, InconsistenciaAvanco::where('cronograma_importacao_id', $imp2->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::RegressaoPercentualAposConclusao->value)
            ->count());
    }

    // ===================== Preservação de datas reais (skip-if-null) =====================

    public function test_real_inicio_nao_e_apagado_quando_importacao_posterior_nao_traz_o_campo(): void
    {
        $at = $this->criarAtividadeExistente('6');
        $inicio = Carbon::parse('2026-06-01');

        $this->aplicarAvanco([$this->tarefa('6', 30.0, $inicio, null)]);
        $at->refresh();
        $this->assertSame('2026-06-01', $at->real_inicio->toDateString());

        // Segunda importação não traz ActualStart — não pode apagar o fato já conhecido.
        $this->aplicarAvanco([$this->tarefa('6', 45.0, null, null)]);
        $at->refresh();
        $this->assertSame('2026-06-01', $at->real_inicio->toDateString());

        // Terceira importação conclui a atividade (ActualFinish novo) — real_inicio
        // ainda preservado da primeira importação, mesmo várias rodadas depois.
        $termino = Carbon::parse('2026-08-05');
        $this->aplicarAvanco([$this->tarefa('6', 100.0, null, $termino)]);
        $at->refresh();

        $this->assertSame('2026-06-01', $at->real_inicio->toDateString());
        $this->assertEquals(StatusAtividade::Concluido, $at->status);
        $this->assertSame('2026-08-05', $at->concluido_em->toDateString());
    }

    // ===================== Itens de prontidão =====================

    public function test_itens_de_prontidao_pendentes_sao_atendidos_automaticamente_com_origem_auditavel(): void
    {
        $at = $this->criarAtividadeExistente('7');
        for ($i = 1; $i <= 4; $i++) {
            ItemProntidao::create([
                'tenant_id' => $this->user->tenant_id,
                'obra_id'   => $this->obra->id,
                'nome'      => "Item {$i}",
                'ordem'     => $i,
            ]);
        }

        $termino = Carbon::parse('2026-08-20');
        $imp = $this->aplicarAvanco([$this->tarefa('7', 100.0, null, $termino)]);

        $rows = AtividadeItemProntidao::where('atividade_id', $at->id)->get();

        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertTrue($row->concluido);
            $this->assertNull($row->concluido_por, 'Marcação automática nunca simula um usuário humano.');
            $this->assertSame($imp->id, $row->atendido_pela_importacao_id);
            $this->assertSame('2026-08-20', $row->concluido_em->toDateString());
            $this->assertTrue($row->foiAtendidoAutomaticamente());
        }

        $this->assertTrue($at->fresh()->estaPronta(), 'Sem restrições, a atividade concluída com prontidão 100% deve ficar pronta.');
    }

    public function test_item_de_prontidao_ja_marcado_manualmente_e_preservado(): void
    {
        $at = $this->criarAtividadeExistente('8');
        $item1 = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Item 1', 'ordem' => 1]);
        $item2 = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Item 2', 'ordem' => 2]);

        $outroUsuario = User::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $dataManual = Carbon::parse('2026-05-01 10:00:00');

        $rowManual = AtividadeItemProntidao::create([
            'atividade_id'      => $at->id,
            'item_prontidao_id' => $item1->id,
            'concluido'         => true,
            'concluido_por'     => $outroUsuario->id,
            'concluido_em'      => $dataManual,
        ]);

        $termino = Carbon::parse('2026-08-20');
        $imp = $this->aplicarAvanco([$this->tarefa('8', 100.0, null, $termino)]);

        $rowManual->refresh();
        $this->assertSame($outroUsuario->id, $rowManual->concluido_por, 'Marcação manual nunca é reescrita pela reconciliação automática.');
        $this->assertNull($rowManual->atendido_pela_importacao_id);
        $this->assertSame('2026-05-01 10:00:00', $rowManual->concluido_em->format('Y-m-d H:i:s'));

        $rowAutomatica = AtividadeItemProntidao::where('atividade_id', $at->id)
            ->where('item_prontidao_id', $item2->id)->firstOrFail();
        $this->assertTrue($rowAutomatica->concluido);
        $this->assertNull($rowAutomatica->concluido_por);
        $this->assertSame($imp->id, $rowAutomatica->atendido_pela_importacao_id);
    }

    public function test_regressao_de_percentual_nao_desfaz_item_de_prontidao_ja_atendido(): void
    {
        $at = $this->criarAtividadeExistente('9');
        ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Item', 'ordem' => 1]);

        $termino = Carbon::parse('2026-08-01');
        $this->aplicarAvanco([$this->tarefa('9', 100.0, null, $termino)]);

        $row = AtividadeItemProntidao::where('atividade_id', $at->id)->firstOrFail();
        $this->assertTrue($row->concluido);

        $this->aplicarAvanco([$this->tarefa('9', 80.0, null, null)]);

        $row->refresh();
        $this->assertTrue($row->concluido, 'Regressão de percentual nunca desfaz um item de prontidão já atendido.');
    }

    // ===================== Restrição nunca fechada automaticamente =====================

    public function test_restricao_bloqueante_aberta_permanece_aberta_ao_concluir_atividade(): void
    {
        $at = $this->criarAtividadeExistente('10');
        $restricao = Restricao::factory()->create([
            'tenant_id'    => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'bloqueante'   => true,
            'status'       => StatusRestricao::Aberta->value,
        ]);

        $termino = Carbon::parse('2026-08-20');
        $imp = $this->aplicarAvanco([$this->tarefa('10', 100.0, null, $termino)]);

        $restricao->refresh();
        $this->assertEquals(StatusRestricao::Aberta, $restricao->status, 'Importação NUNCA fecha Restrição automaticamente.');
        $this->assertNull($restricao->resolvida_em);

        $at->refresh();
        $this->assertEquals(StatusAtividade::Concluido, $at->status);
        $this->assertFalse($at->estaPronta(), 'Restrição bloqueante aberta continua impedindo scopeProntas(), mesmo já concluída.');

        // Reaproveita o Detector já existente (A.9.4) — a combinação
        // "concluída + restrição pendente" já é evidência de primeira
        // classe, sem precisar de um mecanismo novo.
        $inc = InconsistenciaAvanco::where('cronograma_importacao_id', $imp->id)
            ->where('atividade_id', $at->id)
            ->where('tipo', TipoInconsistenciaAvanco::ConclusaoComRestricaoPendente->value)
            ->first();
        $this->assertNotNull($inc, 'Detector deve registrar a pendência de restrição pra revisão humana.');
        $this->assertSame(EntidadeInconsistenciaAvanco::Restricao->value, $inc->entidade_tipo->value ?? $inc->entidade_tipo);
    }

    public function test_restricao_nao_bloqueante_aberta_tambem_permanece_aberta(): void
    {
        $at = $this->criarAtividadeExistente('11');
        $restricao = Restricao::factory()->create([
            'tenant_id'    => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'bloqueante'   => false,
            'status'       => StatusRestricao::Aberta->value,
        ]);

        $this->aplicarAvanco([$this->tarefa('11', 100.0, null, Carbon::parse('2026-08-20'))]);

        $restricao->refresh();
        $this->assertEquals(StatusRestricao::Aberta, $restricao->status);

        $at->refresh();
        $this->assertEquals(StatusAtividade::Concluido, $at->status);
    }

    // ===================== Isolamento =====================

    public function test_reconciliacao_nunca_atravessa_obras(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $atOutraObra = Atividade::factory()->create([
            'tenant_id'    => $this->user->tenant_id,
            'obra_id'      => $outraObra->id,
            'origem'       => OrigemAtividade::MsProject->value,
            'external_uid' => '1',
            'status'       => StatusAtividade::Planejado->value,
        ]);

        $at = $this->criarAtividadeExistente('1');

        $this->aplicarAvanco([$this->tarefa('1', 100.0, null, Carbon::parse('2026-08-20'))]);

        $at->refresh();
        $atOutraObra->refresh();
        $this->assertEquals(StatusAtividade::Concluido, $at->status);
        $this->assertEquals(StatusAtividade::Planejado, $atOutraObra->status, 'Mesmo external_uid em outra obra nunca é tocado.');
    }
}
