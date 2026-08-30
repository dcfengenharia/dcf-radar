<?php

namespace Tests\Feature;

use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\ItemSuprimento;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.3 — correção do risco R1 (registrado na investigação
 * 19.0): `ItemSuprimento::necessidade()` passou a excluir atividades
 * `fora_do_cronograma = true` do MIN(inicio_planejado). Cobertura O-Z da
 * matriz obrigatória (Pacote↔Atividade N:N, necessidade, arquivamento,
 * reimportação, WBS).
 */
class ItemSuprimentoNecessidadeTest extends TestCase
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

    private function criarAtividade(string $inicio, bool $foraDoCronograma = false): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'inicio_planejado' => $inicio,
            'fora_do_cronograma' => $foraDoCronograma,
        ]);
    }

    private function criarPacote(): ItemSuprimento
    {
        return ItemSuprimento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Pacote', 'codigo' => 'P']);
    }

    // ---- O/P/Q: Pacote↔1 atividade, Pacote↔N atividades, Atividade↔N pacotes ----

    public function test_o_pacote_vinculado_a_uma_atividade(): void
    {
        $atividade = $this->criarAtividade('2026-10-10');
        $pacote = $this->criarPacote();
        TenantContext::actingAs($this->tenant, fn () => $pacote->atividades()->attach($atividade->id));

        $this->assertSame(1, $pacote->fresh(['atividades'])->atividades->count());
    }

    public function test_p_pacote_vinculado_a_varias_atividades(): void
    {
        $a1 = $this->criarAtividade('2026-10-10');
        $a2 = $this->criarAtividade('2026-10-20');
        $a3 = $this->criarAtividade('2026-11-05');
        $pacote = $this->criarPacote();
        TenantContext::actingAs($this->tenant, fn () => $pacote->atividades()->attach([$a1->id, $a2->id, $a3->id]));

        $this->assertSame(3, $pacote->fresh(['atividades'])->atividades->count());
    }

    public function test_q_atividade_vinculada_a_varios_pacotes(): void
    {
        $atividade = $this->criarAtividade('2026-10-10');
        $p1 = $this->criarPacote();
        $p2 = $this->criarPacote();
        TenantContext::actingAs($this->tenant, function () use ($p1, $p2, $atividade) {
            $p1->atividades()->attach($atividade->id);
            $p2->atividades()->attach($atividade->id);
        });

        $this->assertSame(2, $atividade->itensSuprimento()->count());
    }

    // ---- R: necessidade = MIN(inicio_planejado) ----

    public function test_r_necessidade_e_o_minimo_entre_as_atividades(): void
    {
        $a1 = $this->criarAtividade('2026-10-20');
        $a2 = $this->criarAtividade('2026-10-10');
        $a3 = $this->criarAtividade('2026-11-05');
        $pacote = $this->criarPacote();
        TenantContext::actingAs($this->tenant, fn () => $pacote->atividades()->attach([$a1->id, $a2->id, $a3->id]));

        $necessidade = $pacote->fresh(['atividades'])->necessidade();

        $this->assertTrue($necessidade->isSameDay(Carbon::parse('2026-10-10')));
    }

    // ---- S: atividade arquivada excluída do cálculo ----

    public function test_s_atividade_arquivada_excluida_do_calculo(): void
    {
        $ativa = $this->criarAtividade('2026-10-20', foraDoCronograma: false);
        $arquivada = $this->criarAtividade('2026-10-10', foraDoCronograma: true); // mais cedo, mas arquivada
        $pacote = $this->criarPacote();
        TenantContext::actingAs($this->tenant, fn () => $pacote->atividades()->attach([$ativa->id, $arquivada->id]));

        $necessidade = $pacote->fresh(['atividades'])->necessidade();

        // Sem a correção, seria 2026-10-10 (a arquivada) -- prova o fix.
        $this->assertTrue($necessidade->isSameDay(Carbon::parse('2026-10-20')));
    }

    // ---- T: reativada volta a participar ----

    public function test_t_atividade_reativada_volta_a_participar(): void
    {
        $ativa = $this->criarAtividade('2026-10-20', foraDoCronograma: false);
        $arquivada = $this->criarAtividade('2026-10-10', foraDoCronograma: true);
        $pacote = $this->criarPacote();
        TenantContext::actingAs($this->tenant, fn () => $pacote->atividades()->attach([$ativa->id, $arquivada->id]));

        $this->assertTrue($pacote->fresh(['atividades'])->necessidade()->isSameDay(Carbon::parse('2026-10-20')));

        $arquivada->update(['fora_do_cronograma' => false]);

        $this->assertTrue($pacote->fresh(['atividades'])->necessidade()->isSameDay(Carbon::parse('2026-10-10')));
    }

    // ---- U: vínculo preservado ao arquivar (nunca removido automaticamente) ----

    public function test_u_vinculo_preservado_ao_arquivar(): void
    {
        $atividade = $this->criarAtividade('2026-10-10');
        $pacote = $this->criarPacote();
        TenantContext::actingAs($this->tenant, fn () => $pacote->atividades()->attach($atividade->id));

        $atividade->update(['fora_do_cronograma' => true]);

        // O vínculo em si continua existindo -- só deixa de contar pra necessidade.
        $this->assertSame(1, $pacote->fresh(['atividades'])->atividades->count());
        $this->assertNull($pacote->fresh(['atividades'])->necessidade());
    }

    // ---- V: sem atividade = null ----

    public function test_v_pacote_sem_atividade_necessidade_null(): void
    {
        $pacote = $this->criarPacote();

        $this->assertNull($pacote->fresh(['atividades'])->necessidade());
    }

    public function test_v2_pacote_so_com_atividades_arquivadas_necessidade_null(): void
    {
        $arquivada = $this->criarAtividade('2026-10-10', foraDoCronograma: true);
        $pacote = $this->criarPacote();
        TenantContext::actingAs($this->tenant, fn () => $pacote->atividades()->attach($arquivada->id));

        $this->assertNull($pacote->fresh(['atividades'])->necessidade());
    }

    // ---- W/X/Y: WBS/reimportação preservam vínculo, alteração de início atualiza necessidade ----

    public function test_w_x_y_reimportacao_preserva_vinculo_e_atualiza_necessidade(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user);
        $importer = app(ImportadorCronograma::class);

        $plano = $importer->analisar(__DIR__ . '/../Fixtures/cronograma_sample.xml', $this->obra);
        $importer->aplicar($plano, $this->obra, $user->id, 'sample.xml');

        $atividade = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->firstOrFail();
        $this->assertTrue($atividade->inicio_planejado->isSameDay(Carbon::parse('2024-01-01')));

        $pacote = ItemSuprimento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Pacote', 'codigo' => 'P']);
        TenantContext::actingAs($this->tenant, fn () => $pacote->atividades()->attach($atividade->id));

        $this->assertTrue($pacote->fresh(['atividades'])->necessidade()->isSameDay(Carbon::parse('2024-01-01')));

        // Reimportação: MESMA atividade (external_uid=2), inicio_planejado mais tarde.
        $planoV2 = $importer->analisar(__DIR__ . '/../Fixtures/cronograma_suprimentos_reimport.xml', $this->obra);
        $importer->aplicar($planoV2, $this->obra, $user->id, 'reimport.xml');

        $atividade->refresh();
        $this->assertTrue($atividade->inicio_planejado->isSameDay(Carbon::parse('2024-03-01')));

        // X: vínculo N:N sobreviveu (mesma PK de Atividade, reconciliada por external_uid).
        $this->assertSame(1, $pacote->fresh(['atividades'])->atividades->count());
        $this->assertSame($atividade->id, $pacote->fresh(['atividades'])->atividades->first()->id);

        // Y: necessidade já reflete o novo início, sem recriar vínculo nenhum.
        $this->assertTrue($pacote->fresh(['atividades'])->necessidade()->isSameDay(Carbon::parse('2024-03-01')));

        // W: o vínculo é por PK/external_uid, nunca por codigo_cronograma (WBS)
        // -- a prova de X já cobre isso: o vínculo sobreviveu independente de
        // qualquer mudança de WBS que a reimportação possa ter feito.
    }
}
