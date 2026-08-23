<?php

namespace Tests\Feature;

use App\Actions\InconsistenciaAvanco\TratarInconsistenciaAvanco;
use App\Enums\EntidadeInconsistenciaAvanco;
use App\Enums\SeveridadeInconsistenciaAvanco;
use App\Enums\StatusInconsistenciaAvanco;
use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Enums\TipoInconsistenciaAvanco;
use App\Exceptions\InconsistenciaJaTratadaException;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeSnapshot;
use App\Models\AtividadeSnapshotOperacional;
use App\Models\AtividadeSnapshotProgramacao;
use App\Models\CronogramaImportacao;
use App\Models\InconsistenciaAvanco;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 17, A.9.6 — primeiro fluxo humano de tratamento das
 * InconsistenciaAvanco. Cobre a Action (TratarInconsistenciaAvanco), a
 * Policy (InconsistenciaAvancoPolicy) e a UI (⚡inconsistencias-avanco.blade.php),
 * com foco especial em provar que tratar NUNCA toca o domínio operacional
 * original (Restricao/AtividadeItemProntidao/Atividade/Fotografia F/O/P).
 */
class InconsistenciaAvancoTratamentoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private Atividade $atividade;
    private CronogramaImportacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        // 'engenheiro' tem 'editar' em restricoes.lookahead (nível exigido por
        // InconsistenciaAvancoPolicy::tratar()).
        $this->vincularObra($this->obra, $this->user, 'engenheiro');
        $this->actingAs($this->user);

        $this->atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);

        $this->importacao = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now(),
        ]);
    }

    private function criarInconsistencia(array $overrides = []): InconsistenciaAvanco
    {
        return InconsistenciaAvanco::create(array_merge([
            'obra_id' => $this->obra->id,
            'atividade_id' => $this->atividade->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'tipo' => TipoInconsistenciaAvanco::InicioComRestricaoPendente->value,
            'severidade' => SeveridadeInconsistenciaAvanco::Atencao->value,
            'entidade_tipo' => EntidadeInconsistenciaAvanco::Restricao->value,
            'entidade_id' => (string) \Illuminate\Support\Str::ulid(),
            'titulo' => 'Atividade iniciada com Restrição pendente',
            'detalhes' => ['status' => 'aberta', 'bloqueante' => true],
            'detectada_em' => now(),
        ], $overrides));
    }

    // ===================== A: nasce aberta =====================

    public function test_a_ocorrencia_nova_nasce_aberta(): void
    {
        $inc = $this->criarInconsistencia();

        $this->assertEquals(StatusInconsistenciaAvanco::Aberta, $inc->fresh()->status);
        $this->assertTrue($inc->fresh()->estaAberta());
        $this->assertNull($inc->fresh()->tratado_por);
        $this->assertNull($inc->fresh()->tratado_em);
        $this->assertNull($inc->fresh()->justificativa);
    }

    // ===================== B: tratamento autorizado =====================

    public function test_b_usuario_autorizado_trata_com_justificativa(): void
    {
        $inc = $this->criarInconsistencia();

        Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra])
            ->call('abrirModalTratar', $inc->id)
            ->set('justificativaTratamento', 'Serviço executado em campo antes da baixa formal da restrição.')
            ->call('confirmarTratamento')
            ->assertHasNoErrors();

        $inc->refresh();
        $this->assertEquals(StatusInconsistenciaAvanco::Tratada, $inc->status);
        $this->assertEquals($this->user->id, $inc->tratado_por);
        $this->assertNotNull($inc->tratado_em);
        $this->assertEquals('Serviço executado em campo antes da baixa formal da restrição.', $inc->justificativa);
    }

    // ===================== C: justificativa vazia =====================

    public function test_c_justificativa_vazia_e_rejeitada(): void
    {
        $inc = $this->criarInconsistencia();

        Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra])
            ->call('abrirModalTratar', $inc->id)
            ->set('justificativaTratamento', '   ')
            ->call('confirmarTratamento')
            ->assertHasErrors(['justificativaTratamento']);

        $this->assertEquals(StatusInconsistenciaAvanco::Aberta, $inc->fresh()->status);
    }

    // ===================== D: sem permissão =====================

    public function test_d_usuario_sem_editar_nao_consegue_tratar_via_action_ou_ui(): void
    {
        $inc = $this->criarInconsistencia();

        // 'cliente_leitura': só 'ver' em qualquer página, nunca 'editar'.
        $semEditar = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $semEditar, 'cliente_leitura');

        $this->assertFalse(Gate::forUser($semEditar)->allows('tratar', $inc));

        Livewire::actingAs($semEditar);
        Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra])
            ->call('abrirModalTratar', $inc->id)
            ->assertForbidden();

        $this->assertEquals(StatusInconsistenciaAvanco::Aberta, $inc->fresh()->status);
    }

    // ===================== E/F/G: isolamento =====================

    public function test_e_outro_tenant_nao_resolve_ocorrencia(): void
    {
        $inc = $this->criarInconsistencia();

        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->vincularObra($outraObra, $outroUser, 'engenheiro');
        $this->actingAs($outroUser);

        // Global scope de BelongsToTenant já torna a linha invisível pra outro tenant.
        $this->assertNull(InconsistenciaAvanco::find($inc->id));

        // Mesmo chamando a Action DIRETO (bypassando a UI/Policy por completo)
        // com o objeto ainda em memória de antes da troca de tenant, a
        // query condicional dentro de TratarInconsistenciaAvanco também
        // aplica o global scope de BelongsToTenant — afeta 0 linhas (a
        // linha pertence a outro tenant) e vira "já tratada", nunca um
        // tratamento de verdade cross-tenant.
        $this->expectException(InconsistenciaJaTratadaException::class);
        (new TratarInconsistenciaAvanco())->execute($inc, $outroUser, 'Tentativa cross-tenant — não pode funcionar.');
    }

    public function test_f_outra_obra_do_mesmo_tenant_nao_resolve_ocorrencia(): void
    {
        $inc = $this->criarInconsistencia();

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outroUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $outroUser, 'engenheiro');
        $this->actingAs($outroUser);

        try {
            Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $outraObra])
                ->call('abrirModalTratar', $inc->id);
            $this->fail('Esperava ModelNotFoundException.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // esperado
        }

        $this->assertEquals(StatusInconsistenciaAvanco::Aberta, $inc->fresh()->status);
    }

    public function test_g_usuario_com_acesso_as_duas_obras_impedido_de_tratar_ocorrencia_da_obra_b_a_partir_do_contexto_a(): void
    {
        $inc = $this->criarInconsistencia();

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        // Mesmo usuário, com acesso às DUAS obras — a ocorrência pertence à
        // obra original, nunca à outraObra.
        $this->vincularObra($outraObra, $this->user, 'engenheiro');

        try {
            Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $outraObra])
                ->call('abrirModalTratar', $inc->id);
            $this->fail('Esperava ModelNotFoundException.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // esperado
        }

        $this->assertEquals(StatusInconsistenciaAvanco::Aberta, $inc->fresh()->status);
    }

    // ===================== H-L: domínio operacional intacto =====================

    public function test_h_tratamento_nao_altera_restricao(): void
    {
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $this->atividade->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);
        // fresh() antes de capturar — o objeto recém-criado por create() só
        // tem em memória os atributos explicitamente passados (mais PK/
        // timestamps), nunca as colunas nullable com default do banco; sem
        // isso, a comparação acusaria diferença que não existe de verdade.
        $antes = $restricao->fresh()->toArray();

        $inc = $this->criarInconsistencia([
            'entidade_tipo' => EntidadeInconsistenciaAvanco::Restricao->value,
            'entidade_id' => $restricao->id,
        ]);

        $this->tratar($inc);

        $this->assertEquals($antes, $restricao->fresh()->toArray());
    }

    public function test_i_tratamento_nao_altera_prontidao(): void
    {
        $item = ItemProntidao::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Documentação', 'ordem' => 1]);
        $atividadeItem = AtividadeItemProntidao::create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $this->atividade->id,
            'item_prontidao_id' => $item->id,
            'concluido' => false,
        ]);
        $antes = $atividadeItem->fresh()->toArray();

        $inc = $this->criarInconsistencia([
            'tipo' => TipoInconsistenciaAvanco::InicioComProntidaoPendente->value,
            'entidade_tipo' => EntidadeInconsistenciaAvanco::ItemProntidao->value,
            'entidade_id' => $item->id,
            'detalhes' => ['item_prontidao_id' => $item->id, 'atividade_item_prontidao_id' => $atividadeItem->id],
        ]);

        $this->tratar($inc);

        $this->assertEquals($antes, $atividadeItem->fresh()->toArray());
    }

    public function test_j_tratamento_nao_altera_atividade(): void
    {
        $antes = $this->atividade->fresh()->toArray();

        $inc = $this->criarInconsistencia();
        $this->tratar($inc);

        $this->assertEquals($antes, $this->atividade->fresh()->toArray());
    }

    public function test_k_tratamento_nao_altera_fotografias_f_o_p(): void
    {
        $f = AtividadeSnapshot::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $this->atividade->id,
            'percentual_concluido' => 40,
            'real_inicio' => now()->subDay()->toDateString(),
        ]);
        $o = AtividadeSnapshotOperacional::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $this->atividade->id,
            'status' => 'planejado',
            'fora_do_cronograma' => false,
            'pronta' => false,
        ]);
        $p = AtividadeSnapshotProgramacao::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $this->atividade->id,
            'evento' => 'inicio',
            'data_factual' => now()->subDay()->toDateString(),
            'semana_inicio_resolvida' => now()->subDay()->startOfWeek()->toDateString(),
            'atividade_estava_na_programacao' => false,
        ]);
        $antesF = $f->fresh()->toArray();
        $antesO = $o->fresh()->toArray();
        $antesP = $p->fresh()->toArray();

        $inc = $this->criarInconsistencia();
        $this->tratar($inc);

        $this->assertEquals($antesF, $f->fresh()->toArray());
        $this->assertEquals($antesO, $o->fresh()->toArray());
        $this->assertEquals($antesP, $p->fresh()->toArray());
    }

    public function test_l_tratamento_nao_cria_restricao_acao(): void
    {
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $this->atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $inc = $this->criarInconsistencia([
            'entidade_tipo' => EntidadeInconsistenciaAvanco::Restricao->value,
            'entidade_id' => $restricao->id,
        ]);

        $this->tratar($inc);

        $this->assertSame(0, DB::table('restricao_acoes')->where('restricao_id', $restricao->id)->count());
    }

    // ===================== M: continua no banco =====================

    public function test_m_ocorrencia_tratada_continua_no_banco(): void
    {
        $inc = $this->criarInconsistencia();
        $this->tratar($inc);

        $this->assertNotNull(InconsistenciaAvanco::find($inc->id));
        $this->assertSame(1, InconsistenciaAvanco::where('id', $inc->id)->count());
    }

    // ===================== N/O/P: filtros =====================

    public function test_n_filtro_default_mostra_abertas(): void
    {
        $aberta = $this->criarInconsistencia();
        $tratada = $this->criarInconsistencia(['entidade_id' => (string) \Illuminate\Support\Str::ulid()]);
        $this->tratar($tratada);

        $component = Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra]);

        $ids = $component->instance()->inconsistencias->pluck('id');
        $this->assertTrue($ids->contains($aberta->id));
        $this->assertFalse($ids->contains($tratada->id));
    }

    public function test_o_filtro_tratadas_funciona(): void
    {
        $aberta = $this->criarInconsistencia();
        $tratada = $this->criarInconsistencia(['entidade_id' => (string) \Illuminate\Support\Str::ulid()]);
        $this->tratar($tratada);

        $component = Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra])
            ->set('filtroStatus', 'tratada');

        $ids = $component->instance()->inconsistencias->pluck('id');
        $this->assertTrue($ids->contains($tratada->id));
        $this->assertFalse($ids->contains($aberta->id));
    }

    public function test_p_filtro_severidade_e_tipo_funcionam(): void
    {
        $critica = $this->criarInconsistencia([
            'tipo' => TipoInconsistenciaAvanco::ConclusaoComRestricaoPendente->value,
            'severidade' => SeveridadeInconsistenciaAvanco::Critica->value,
        ]);
        $atencao = $this->criarInconsistencia([
            'entidade_id' => (string) \Illuminate\Support\Str::ulid(),
            'tipo' => TipoInconsistenciaAvanco::InicioComRestricaoPendente->value,
            'severidade' => SeveridadeInconsistenciaAvanco::Atencao->value,
        ]);

        $porSeveridade = Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra])
            ->set('filtroStatus', '')
            ->set('filtroSeveridade', SeveridadeInconsistenciaAvanco::Critica->value);
        $ids = $porSeveridade->instance()->inconsistencias->pluck('id');
        $this->assertTrue($ids->contains($critica->id));
        $this->assertFalse($ids->contains($atencao->id));

        $porTipo = Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra])
            ->set('filtroStatus', '')
            ->set('filtroTipo', TipoInconsistenciaAvanco::InicioComRestricaoPendente->value);
        $ids2 = $porTipo->instance()->inconsistencias->pluck('id');
        $this->assertTrue($ids2->contains($atencao->id));
        $this->assertFalse($ids2->contains($critica->id));
    }

    // ===================== Q: causa histórica preservada =====================

    public function test_q_causa_historica_continua_visivel_apos_restricao_atual_ser_resolvida(): void
    {
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $this->atividade->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);

        $inc = $this->criarInconsistencia([
            'entidade_tipo' => EntidadeInconsistenciaAvanco::Restricao->value,
            'entidade_id' => $restricao->id,
            'detalhes' => ['status' => 'aberta', 'bloqueante' => true],
        ]);

        // Hoje a Restrição é resolvida (evento posterior, sem relação com a inconsistência).
        $restricao->update(['status' => StatusRestricao::Resolvida->value, 'resolvida_em' => now()]);

        // A causa CONGELADA continua "aberta" — nunca reclassificada pelo estado atual.
        $this->assertEquals('aberta', $inc->fresh()->detalhes['status']);

        $component = Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra]);
        $texto = $component->instance()->textoEntidade($inc->fresh());
        $this->assertStringContainsString('aberta', $texto);
    }

    // ===================== R: I1 tratada não impede I2 =====================

    public function test_r_i1_tratada_e_i2_mesma_pendencia_nasce_aberta(): void
    {
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $this->atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $i1 = $this->criarInconsistencia([
            'entidade_tipo' => EntidadeInconsistenciaAvanco::Restricao->value,
            'entidade_id' => $restricao->id,
        ]);
        $this->tratar($i1);

        $i2Importacao = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now()->addDay(),
        ]);
        $i2 = $this->criarInconsistencia([
            'cronograma_importacao_id' => $i2Importacao->id,
            'entidade_tipo' => EntidadeInconsistenciaAvanco::Restricao->value,
            'entidade_id' => $restricao->id,
        ]);

        $this->assertEquals(StatusInconsistenciaAvanco::Tratada, $i1->fresh()->status);
        $this->assertEquals(StatusInconsistenciaAvanco::Aberta, $i2->fresh()->status);
    }

    // ===================== S: duplo tratamento =====================

    public function test_s_duplo_tratamento_nao_sobrescreve_primeiro_autor_e_justificativa(): void
    {
        $inc = $this->criarInconsistencia();
        $outroUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $outroUser, 'engenheiro');

        $action = new TratarInconsistenciaAvanco();
        $action->execute($inc, $this->user, 'Primeira justificativa — vale essa.');

        $this->expectException(InconsistenciaJaTratadaException::class);

        try {
            $action->execute($inc, $outroUser, 'Segunda tentativa — não pode vencer.');
        } finally {
            $inc->refresh();
            $this->assertEquals($this->user->id, $inc->tratado_por);
            $this->assertEquals('Primeira justificativa — vale essa.', $inc->justificativa);
        }
    }

    // ===================== T: isolamento da listagem =====================

    public function test_t_isolamento_tenant_obra_da_listagem(): void
    {
        $minha = $this->criarInconsistencia();

        $outroTenant = Tenant::factory()->create();
        $outraObraMesmoTenant = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $atividadeOutraObra = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $outraObraMesmoTenant->id]);
        $importacaoOutraObra = CronogramaImportacao::create(['obra_id' => $outraObraMesmoTenant->id, 'tipo' => TipoCronogramaImportacao::Avanco->value, 'importado_em' => now()]);
        $deOutraObra = InconsistenciaAvanco::create([
            'obra_id' => $outraObraMesmoTenant->id,
            'atividade_id' => $atividadeOutraObra->id,
            'cronograma_importacao_id' => $importacaoOutraObra->id,
            'tipo' => TipoInconsistenciaAvanco::InicioComRestricaoPendente->value,
            'severidade' => SeveridadeInconsistenciaAvanco::Atencao->value,
            'entidade_tipo' => EntidadeInconsistenciaAvanco::Restricao->value,
            'entidade_id' => (string) \Illuminate\Support\Str::ulid(),
            'titulo' => 'x',
            'detalhes' => [],
            'detectada_em' => now(),
        ]);

        $component = Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra])
            ->set('filtroStatus', '');

        $ids = $component->instance()->inconsistencias->pluck('id');
        $this->assertTrue($ids->contains($minha->id));
        $this->assertFalse($ids->contains($deOutraObra->id));
    }

    // ===================== U: N+1 =====================

    /**
     * Medição isolada (mesma cautela já registrada no Ciclo 17: nunca
     * chamar `Livewire::test()` uma segunda vez dentro do MESMO método de
     * teste pra este componente — descoberto empiricamente nesta própria
     * etapa, uma segunda instância corrompe o mecanismo de snapshot da
     * primeira). Uma única renderização com N=30 ocorrências espalhadas
     * por 30 atividades e 30 importações distintas — se a listagem tivesse
     * N+1 (1 query por ocorrência/atividade/importação eager-loaded), o
     * total seria dezenas a mais que o teto abaixo; com eager loading em
     * lote (`with([...])` + as 4 computed properties, cada uma no máximo
     * 1-2 queries pra TODA a página), o total fica bem abaixo disso,
     * independente de N crescer além de `perPage`.
     */
    public function test_u_listagem_nao_gera_n_mais_1(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $at = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
            $imp = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'tipo' => TipoCronogramaImportacao::Avanco->value, 'importado_em' => now()->subMinutes($i)]);
            InconsistenciaAvanco::create([
                'obra_id' => $this->obra->id,
                'atividade_id' => $at->id,
                'cronograma_importacao_id' => $imp->id,
                'tipo' => TipoInconsistenciaAvanco::InicioComRestricaoPendente->value,
                'severidade' => SeveridadeInconsistenciaAvanco::Atencao->value,
                'entidade_tipo' => EntidadeInconsistenciaAvanco::Restricao->value,
                'entidade_id' => (string) \Illuminate\Support\Str::ulid(),
                'titulo' => 'x',
                'detalhes' => [],
                'detectada_em' => now(),
            ]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $component = Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra]);
        $component->instance()->inconsistencias;
        $component->instance()->fotografiaFPorOcorrencia;
        $component->instance()->contadores;
        $component->instance()->importacoesDisponiveis;

        $this->assertLessThan(20, $queries, "Listagem com N=30 gerou {$queries} queries — suspeita de N+1.");
    }

    // ===================== V/W: A.9.6.HARDENING — UX de tratado_por nullOnDelete =====================

    /**
     * `tratado_por` é `nullOnDelete()` (CLAUDE.md) — excluir o usuário que
     * tratou uma ocorrência não pode deixar a autoria em branco na UI nem
     * apagar a evidência do tratamento em si. Usa um usuário DIFERENTE de
     * $this->user (o autenticado do teste) pra poder excluí-lo de verdade
     * sem afetar o resto do cenário; `User` não usa SoftDeletes, então
     * `->delete()` é um DELETE real e dispara a FK.
     */
    public function test_v_usuario_removido_apos_tratamento_mantem_evidencia_e_ui_nao_fica_em_branco(): void
    {
        $tratador = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fulano', 'last_name' => 'Removido']);
        $this->vincularObra($this->obra, $tratador, 'engenheiro');

        $inc = $this->criarInconsistencia();
        (new TratarInconsistenciaAvanco())->execute($inc, $tratador, 'Justificativa antes da exclusão do usuário.');

        $tratador->delete();

        $inc->refresh();
        $this->assertEquals(StatusInconsistenciaAvanco::Tratada, $inc->status);
        $this->assertNull($inc->tratado_por);
        $this->assertNotNull($inc->tratado_em);
        $this->assertEquals('Justificativa antes da exclusão do usuário.', $inc->justificativa);

        // filtroStatus default é 'aberta' — a ocorrência já tratada só
        // aparece na listagem com o filtro explicitamente aberto pra 'tratada'.
        Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra])
            ->set('filtroStatus', 'tratada')
            ->assertOk()
            ->assertSee('Usuário removido')
            ->assertDontSee('Fulano');
    }

    public function test_w_usuario_existente_continua_mostrando_nome_normalmente(): void
    {
        $inc = $this->criarInconsistencia();
        $this->tratar($inc); // trata como $this->user, que continua existindo

        Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra])
            ->set('filtroStatus', 'tratada')
            ->assertOk()
            ->assertSee($this->user->first_name)
            ->assertDontSee('Usuário removido');
    }

    // ===================== X: A.9.6.HARDENING — botão Tratar com proteção de loading =====================

    /**
     * Smoke test de markup, mesmo precedente já usado no projeto pra
     * `wire:loading` (`CurvaSGraficoTest::test_overlay_de_carregamento_esta_presente_no_markup`,
     * via assertSeeHtml) — só confirma que o atributo está presente no
     * HTML renderizado, não simula o estado de loading em si (PHPUnit não
     * executa o ciclo de vida assíncrono do Livewire no browser).
     */
    public function test_x_botao_tratar_possui_protecao_de_loading_escopada_pela_ocorrencia(): void
    {
        $inc = $this->criarInconsistencia();

        Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra])
            ->assertSeeHtml('wire:target="abrirModalTratar(\''.$inc->id.'\')"');
    }

    // ===================== Smoke test de renderização =====================

    public function test_smoke_pagina_renderiza_com_ocorrencias_abertas_e_tratadas(): void
    {
        $aberta = $this->criarInconsistencia();
        $tratada = $this->criarInconsistencia(['entidade_id' => (string) \Illuminate\Support\Str::ulid()]);
        $this->tratar($tratada);

        Livewire::test('pages::radar.inconsistencias-avanco', ['obra' => $this->obra])
            ->assertOk()
            ->assertSee($this->atividade->nome);
    }

    // ===================== Helper =====================

    private function tratar(InconsistenciaAvanco $inc): void
    {
        (new TratarInconsistenciaAvanco())->execute($inc, $this->user, 'Justificativa de teste válida para o cenário.');
    }
}
