<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Enums\TipoCronogramaImportacao;
use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\LinhaBase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.4.CORREÇÃO — prova que "pronta para comprometimento"
 * passou a ser UMA regra canônica única (Atividade::scopeProntas()),
 * considerando GED, e que Plano Semanal/Lookahead/estaPronta() nunca mais
 * divergem da Central de Prontidão. Cobertura A-X do pedido.
 */
class DocumentoEngenhariaProntidaoOperacionalTest extends TestCase
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
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        // Lookahead exige >=1 LinhaBase ativa da obra (temLinhaBaseAtiva()) —
        // sem isso, atividades() sempre retorna vazio por design.
        $importacaoPadrao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subYears(10),
        ]);
        $linhaBasePadrao = LinhaBase::create([
            'obra_id' => $this->obra->id, 'nome' => 'LB padrão do teste',
            'cronograma_importacao_id' => $importacaoPadrao->id, 'criado_por' => $this->user->id,
        ]);
        $linhaBasePadrao->forceFill(['created_at' => now()->subYears(10)])->save();
    }

    private function doc(array $overrides = []): DocumentoEngenharia
    {
        return DocumentoEngenharia::create(array_merge([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'codigo' => 'DOC-' . uniqid(), 'descricao' => 'x',
        ], $overrides));
    }

    private function rev(DocumentoEngenharia $d, string $texto = 'R1', array $extra = []): DocumentoEngenhariaRevisao
    {
        $r = $d->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => $texto, 'descricao' => 'x'] + $extra);
        if (array_key_exists('created_at', $extra)) {
            $r->forceFill(['created_at' => $extra['created_at']])->save();
        }
        return $r->fresh();
    }

    private function liberar(DocumentoEngenhariaRevisao $r): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->liberar($r, $this->user);
    }

    private function revogar(DocumentoEngenhariaRevisao $r): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->revogar($r, $this->user);
    }

    private function at(array $overrides = []): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'fora_do_cronograma' => false,
            'status' => StatusAtividade::Planejado, 'inicio_planejado' => now()->addDays(2), 'percentual_concluido' => 0,
        ], $overrides));
    }

    private function docBloqueanteEm(Atividade $at): DocumentoEngenharia
    {
        $d = $this->doc();
        $this->rev($d, 'R1');
        $at->documentosEngenharia()->attach($d->id, ['tenant_id' => $this->tenant->id]);
        return $d;
    }

    /**
     * Etapa 18.4.CORREÇÃO — prova que o achado C da auditoria era real:
     * reproduz inline a lógica ANTIGA de `estaPronta()` (restrições
     * bloqueantes + checklist, SEM NENHUMA menção a GED — cópia fiel do
     * método antes desta correção, não uma versão artificialmente
     * diferente) e mostra que ela retornaria `true` (errado) pro cenário
     * exato provado pela auditoria, enquanto o código atual retorna
     * `false` (correto).
     */
    public function test_prova_bug_pre_fix_logica_antiga_ignorava_ged(): void
    {
        $at = $this->at();
        $this->docBloqueanteEm($at);

        $logicaAntiga = function () use ($at): bool {
            if ($at->restricoes()
                    ->where('bloqueante', true)
                    ->whereIn('status', ['aberta', 'em_tratamento', 'aguardando_terceiros'])
                    ->exists()) {
                return false;
            }

            $totalItens = \App\Models\ItemProntidao::where('obra_id', $at->obra_id)->count();
            if ($totalItens > 0) {
                $ok = \App\Models\AtividadeItemProntidao::where('atividade_id', $at->id)
                    ->where('concluido', true)->count();
                if ($ok < $totalItens) {
                    return false;
                }
            }

            return true;
        };

        $this->assertTrue($logicaAntiga(), 'A lógica antiga (sem GED) consideraria esta atividade pronta -- o bug que a 18.4.CORREÇÃO fecha.');
        $this->assertFalse($at->estaPronta(), 'A lógica atual (com GED) corrige o bug.');
    }

    // ===================== A-B: fonte canônica =====================

    public function test_a_raw_estapronta_false_com_ged_bloqueante(): void
    {
        $at = $this->at();
        $this->docBloqueanteEm($at);

        $this->assertFalse($at->estaPronta());
    }

    public function test_b_scopeprontas_exclui_ged_bloqueante(): void
    {
        $at = $this->at();
        $this->docBloqueanteEm($at);

        $this->assertFalse(Atividade::query()->whereKey($at->id)->prontas()->exists());
        $this->assertTrue(Atividade::query()->whereKey($at->id)->naoProntas()->exists());
    }

    // ===================== C-D: Plano Semanal =====================

    public function test_c_plano_semanal_idsselecionaveis_exclui_ged_bloqueante(): void
    {
        $at = $this->at(['codigo_cronograma' => '1.1']);
        $this->docBloqueanteEm($at);

        $componente = Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra])
            ->set('semanaInicio', now()->startOfWeek()->toDateString());

        $this->assertNotContains($at->id, $componente->instance()->idsSelecionaveis());
    }

    public function test_d_manipulacao_direta_selecionados_nao_compromete(): void
    {
        $at = $this->at(['codigo_cronograma' => '1.2']);
        $this->docBloqueanteEm($at);

        $componente = Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra])
            ->set('semanaInicio', now()->startOfWeek()->toDateString())
            ->set('selecionadas', [$at->id]) // bypass do checkbox/UI
            ->call('comprometerSelecionadas');

        $this->assertFalse(DB::table('programacao_semanal_itens')->where('atividade_id', $at->id)->exists());
        $componente->assertSet('erroSelecao', 'Nenhuma atividade liberada selecionada — atividades bloqueadas ou já concluídas no cronograma não podem ser programadas.');
    }

    /**
     * Achado de microauditoria (regressão pós-18.5.2.HARDENING, provado
     * empiricamente com Carbon::setTestNow variando o dia da semana):
     * `at()` usa `inicio_planejado = now()->addDays(2)`, e este teste usa
     * `semanaInicio = now()->startOfWeek()` — nos dias em que "hoje" é
     * sábado ou domingo, `+2 dias` empurra `inicio_planejado` pra
     * SEGUNDA da semana SEGUINTE, caindo fora da janela `[semanaInicio,
     * semanaFim]` que `idsSelecionaveis()` exige — nada a ver com GED/
     * prontidão (`estaPronta()` continua `true` em 100% dos cenários
     * testados; só a seleção temporal do Plano Semanal diverge). Congela
     * o relógio numa segunda-feira estável para eliminar essa fragilidade
     * de vez, sem alterar a semântica testada (uma atividade planejada
     * pra depois de amanhã, dentro da mesma semana civil, deve ser
     * selecionável).
     */
    public function test_e_documento_liberado_permite_compromisso(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-08-17 10:00:00')); // segunda-feira fixa

        try {
            $at = $this->at(['codigo_cronograma' => '1.3']);
            $d = $this->doc();
            $r = $this->rev($d, 'R1');
            $this->liberar($r);
            $at->documentosEngenharia()->attach($d->id, ['tenant_id' => $this->tenant->id]);

            $componente = Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra])
                ->set('semanaInicio', now()->startOfWeek()->toDateString())
                ->set('selecionadas', [$at->id])
                ->call('comprometerSelecionadas');

            $this->assertTrue(DB::table('programacao_semanal_itens')->where('atividade_id', $at->id)->exists());
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    // ===================== F-H: Lookahead =====================

    public function test_f_lookahead_tabela_nao_mostra_como_pronta(): void
    {
        $at = $this->at();
        $this->docBloqueanteEm($at);

        $componente = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->set('janelaDias', 0); // 0 = todo o cronograma, sem filtro de janela de datas
        $linha = $componente->instance()->atividades()->first(fn ($row) => $row['atividade']->id === $at->id);

        $this->assertNotNull($linha, 'Atividade deveria aparecer na tabela (GED não filtra visibilidade, só readiness).');
        $this->assertFalse($linha['pronta']);
    }

    public function test_g_lookahead_popup_nao_diz_pode_ser_comprometida(): void
    {
        $at = $this->at();
        $this->docBloqueanteEm($at);

        $html = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('verAtividade', $at->id)
            ->html();

        $this->assertStringNotContainsString('✅ Pode ser comprometida no Plano Semanal', $html);
        $this->assertStringContainsString('⚠ Pendências impedem o comprometimento', $html);
        $this->assertStringContainsString('Documentos de Engenharia Pendentes', $html);
    }

    public function test_h_lookahead_bulk_nao_inclui_ged_bloqueante(): void
    {
        $atBloqueada = $this->at(['codigo_cronograma' => '2.1']);
        $this->docBloqueanteEm($atBloqueada);

        $atLivre = $this->at(['codigo_cronograma' => '2.2']);

        $componente = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->call('gerarPlanoSemanal');

        $this->assertFalse(DB::table('programacao_semanal_itens')->where('atividade_id', $atBloqueada->id)->exists());
        $this->assertTrue($atBloqueada->fresh()->status !== StatusAtividade::Comprometido);
    }

    // ===================== I-K: reatividade =====================

    public function test_i_nova_revisao_volta_a_bloquear_em_todos(): void
    {
        $at = $this->at();
        $d = $this->doc();
        $r1 = $this->rev($d, 'R1');
        $this->liberar($r1);
        $at->documentosEngenharia()->attach($d->id, ['tenant_id' => $this->tenant->id]);

        $this->assertTrue($at->estaPronta());

        $this->rev($d, 'R2'); // nasce nao liberada

        $this->assertFalse($at->fresh()->estaPronta());
        $this->assertFalse(Atividade::query()->whereKey($at->id)->prontas()->exists());
    }

    public function test_j_liberar_nova_revisao_libera_em_todos(): void
    {
        $at = $this->at();
        $d = $this->doc();
        $r2 = $this->rev($d, 'R1');
        $at->documentosEngenharia()->attach($d->id, ['tenant_id' => $this->tenant->id]);
        $this->assertFalse($at->estaPronta());

        $this->liberar($r2);

        $this->assertTrue($at->fresh()->estaPronta());
    }

    public function test_k_revogar_bloqueia_em_todos(): void
    {
        $at = $this->at();
        $d = $this->doc();
        $r = $this->rev($d, 'R1');
        $this->liberar($r);
        $at->documentosEngenharia()->attach($d->id, ['tenant_id' => $this->tenant->id]);
        $this->assertTrue($at->estaPronta());

        $this->revogar($r);

        $this->assertFalse($at->fresh()->estaPronta());
    }

    public function test_l_retroativa_nao_bloqueia(): void
    {
        $at = $this->at();
        $d = $this->doc();
        $r1 = $this->rev($d, 'R1', ['data_emissao' => '2026-08-10']);
        $r2 = $this->rev($d, 'R2', ['data_emissao' => '2026-08-20']);
        $this->liberar($r2);
        $at->documentosEngenharia()->attach($d->id, ['tenant_id' => $this->tenant->id]);
        $this->assertTrue($at->estaPronta());

        $this->rev($d, 'R0', ['data_emissao' => '2026-08-01', 'created_at' => now()->addDay()]);

        $this->assertTrue($at->fresh()->estaPronta(), 'R0 retroativa nao pode reassumir vigencia sobre R2.');
    }

    // ===================== M: N:N =====================

    public function test_m_n_n(): void
    {
        $a1 = $this->at();
        $a2 = $this->at();
        $a3 = $this->at();
        $d = $this->doc();
        $r = $this->rev($d, 'R1');
        $d->atividades()->attach([$a1->id, $a2->id, $a3->id], ['tenant_id' => $this->tenant->id]);

        $ids = [$a1->id, $a2->id, $a3->id];
        $this->assertCount(0, Atividade::query()->whereIn('id', $ids)->prontas()->pluck('id'));

        $this->liberar($r);
        $this->assertCount(3, Atividade::query()->whereIn('id', $ids)->prontas()->pluck('id'));
    }

    // ===================== N-O: isolamento =====================

    public function test_n_cross_obra_pivot_corrompido_nao_bloqueia(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $at = $this->at();
        $docObraB = DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $obraB->id, 'codigo' => 'DOC-B', 'descricao' => 'x']);
        $this->rev($docObraB, 'R1');

        DB::table('documento_engenharia_atividades')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $this->tenant->id,
            'documento_engenharia_id' => $docObraB->id, 'atividade_id' => $at->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue($at->fresh()->estaPronta());
        $this->assertTrue(Atividade::query()->whereKey($at->id)->prontas()->exists());
    }

    public function test_o_cross_tenant_nao_bloqueia(): void
    {
        $at = $this->at();
        $outroTenant = Tenant::factory()->create();
        TenantContext::actingAs($outroTenant, function () use ($outroTenant, $at) {
            $obOutro = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $atOutro = Atividade::factory()->create(['tenant_id' => $outroTenant->id, 'obra_id' => $obOutro->id, 'fora_do_cronograma' => false]);
            $dOutro = DocumentoEngenharia::create(['tenant_id' => $outroTenant->id, 'obra_id' => $obOutro->id, 'codigo' => 'DOC-T', 'descricao' => 'x']);
            $dOutro->revisoes()->create(['tenant_id' => $outroTenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
            $dOutro->atividades()->attach($atOutro->id, ['tenant_id' => $outroTenant->id]);
        });

        $this->assertTrue($at->estaPronta());
    }

    // ===================== P-Q: soft-delete/restore =====================

    public function test_p_q_soft_delete_restore(): void
    {
        $at = $this->at();
        $d = $this->docBloqueanteEm($at);

        $this->assertFalse($at->estaPronta());

        $d->delete();
        $this->assertTrue($at->fresh()->estaPronta());

        $d->restore();
        $this->assertFalse($at->fresh()->estaPronta());
    }

    // ===================== R-S: reimportação =====================

    public function test_r_reimportacao_cronograma_preserva_vinculo_e_bloqueio(): void
    {
        $at = $this->at(['external_uid' => 'UID-R18-4']);
        $this->docBloqueanteEm($at);

        $at->update(['nome' => 'Reconciliada']); // simula update por reconciliacao (mesma PK)

        $this->assertFalse($at->fresh()->estaPronta());
    }

    public function test_s_reimportacao_ld_preserva(): void
    {
        $at = $this->at();
        $d = $this->doc();
        $r1 = $this->rev($d, 'R1');
        $this->liberar($r1);
        $at->documentosEngenharia()->attach($d->id, ['tenant_id' => $this->tenant->id]);

        $importer = new \App\Imports\DocumentoEngenhariaImporter();
        $linha = ['linha' => 2, 'disciplina' => null, 'codigo' => $d->codigo, 'revisao' => 'R1', 'titulo' => $d->descricao, 'status' => null, 'data_prevista' => null, 'data_real' => null];
        $importer->aplicar([$linha], $this->obra->id, $this->user->id);

        $this->assertTrue($at->fresh()->estaPronta());

        $linha2 = $linha;
        $linha2['revisao'] = 'R2';
        $importer->aplicar([$linha2], $this->obra->id, $this->user->id);

        $this->assertFalse($at->fresh()->estaPronta(), 'R2 nova via LD deve voltar a bloquear.');
    }

    // ===================== T: avanço factual =====================

    public function test_t_avanco_factual_100_gravado_sem_autocorrecao_ged(): void
    {
        $at = $this->at();
        $d = $this->docBloqueanteEm($at); // GED bloqueante

        $at->update(['percentual_concluido' => 100, 'real_inicio' => now()->subDays(5), 'real_termino' => now()]);

        $this->assertEquals(100, (float) $at->fresh()->percentual_concluido, 'Percentual factual deve ser gravado normalmente.');
        $this->assertNotNull($at->fresh()->real_termino);
        $this->assertFalse($at->fresh()->estaPronta(), 'GED continua bloqueando prontidao mesmo com avanco factual 100%.');
        $this->assertFalse($d->fresh()->estaLiberadoParaConstrucao(), 'Documento nunca e autocorrigido/liberado por causa do avanco.');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('restricoes')->count(), 'Nenhuma Restricao criada por conta do avanco/GED.');
    }

    // ===================== U: contador/notificação =====================

    public function test_u_central_e_scopeprontas_concordam(): void
    {
        $atBloqueada = $this->at();
        $this->docBloqueanteEm($atBloqueada);
        $atLivre = $this->at();

        $query = new \App\Support\CentralProntidao\CentralProntidaoQuery();
        $views = $query->paraObra($this->obra);

        $viewBloqueada = $views->first(fn ($v) => $v->atividadeId === $atBloqueada->id);
        $viewLivre = $views->first(fn ($v) => $v->atividadeId === $atLivre->id);

        $this->assertEquals(\App\Support\CentralProntidao\StatusOperacionalProntidao::NaoPronta, $viewBloqueada->statusOperacional);
        $this->assertFalse($viewBloqueada->pronta);
        $this->assertFalse($atBloqueada->estaPronta());

        $this->assertEquals(\App\Support\CentralProntidao\StatusOperacionalProntidao::Pronta, $viewLivre->statusOperacional);
        $this->assertTrue($viewLivre->pronta);
        $this->assertTrue($atLivre->estaPronta());
    }

    // ===================== V-X: performance =====================

    public function test_v_performance_scopeprontas_n_100(): void
    {
        collect(range(1, 100))->each(function (int $i) {
            $at = $this->at();
            $d = $this->doc(['codigo' => "PERFV-{$i}"]);
            $r = $this->rev($d, 'R1');
            if ($i % 3 === 0) {
                $this->liberar($r);
            }
            $at->documentosEngenharia()->attach($d->id, ['tenant_id' => $this->tenant->id]);
        });

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $count = Atividade::query()->where('obra_id', $this->obra->id)->prontas()->count();

        $this->assertLessThan(5, $queries, "scopeProntas() com 100 atividades gerou {$queries} queries.");
    }

    /** Mesma fragilidade de calendário do test_e (ver docblock lá) — mesma correção. */
    public function test_w_performance_plano_semanal_n_30(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-08-17 10:00:00')); // segunda-feira fixa

        try {
            collect(range(1, 30))->each(function (int $i) {
                $at = $this->at(['codigo_cronograma' => "3.{$i}"]);
                $d = $this->doc(['codigo' => "PSPERF-{$i}"]);
                $r = $this->rev($d, 'R1');
                if ($i % 2 === 0) {
                    $this->liberar($r);
                }
                $at->documentosEngenharia()->attach($d->id, ['tenant_id' => $this->tenant->id]);
            });

            $queries = 0;
            DB::listen(function () use (&$queries) { $queries++; });
            $componente = Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra])
                ->set('semanaInicio', now()->startOfWeek()->toDateString());
            $ids = $componente->instance()->idsSelecionaveis();

            $this->assertLessThan(60, $queries, "Plano Semanal com 30 atividades+GED gerou {$queries} queries.");
            $this->assertCount(15, $ids);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_x_performance_lookahead_n_30(): void
    {
        collect(range(1, 30))->each(function (int $i) {
            $at = $this->at(['codigo_cronograma' => "4.{$i}"]);
            $d = $this->doc(['codigo' => "LHPERF-{$i}"]);
            $r = $this->rev($d, 'R1');
            if ($i % 2 === 0) {
                $this->liberar($r);
            }
            $at->documentosEngenharia()->attach($d->id, ['tenant_id' => $this->tenant->id]);
        });

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $componente = Livewire::test('pages::radar.lookahead', ['obra' => $this->obra])
            ->set('janelaDias', 0);
        $atividades = $componente->instance()->atividades();

        $this->assertLessThan(80, $queries, "Lookahead com 30 atividades+GED gerou {$queries} queries.");
        $this->assertCount(30, $atividades);
    }
}
