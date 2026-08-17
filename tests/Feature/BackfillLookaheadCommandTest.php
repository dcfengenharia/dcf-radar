<?php

namespace Tests\Feature;

use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeSnapshot;
use App\Models\CronogramaImportacao;
use App\Models\Disciplina;
use App\Models\Etapa;
use App\Models\FrenteTrabalho;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillLookaheadCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifica_atividades_antigas_a_partir_do_textos_ja_salvo(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        // Simula uma atividade importada ANTES da feature de classificação existir:
        // textos já salvo, mas disciplina/frente/etapa nunca foram preenchidas.
        $atividade = Atividade::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'textos' => ['Texto20' => 'FUNDAÇÃO', 'Texto21' => 'ESTRUTURA', 'Texto22' => 'Berço 1'],
        ]);

        $this->artisan('lookahead:backfill', ['obra' => $obra->id])->assertSuccessful();

        $atividade->refresh();
        $this->assertNotNull($atividade->etapa_id);
        $this->assertEquals('FUNDAÇÃO', Etapa::find($atividade->etapa_id)->nome);
        $this->assertNotNull($atividade->disciplina_id);
        $this->assertEquals('ESTRUTURA', Disciplina::find($atividade->disciplina_id)->nome);
        $this->assertNotNull($atividade->frente_trabalho_id);
        $this->assertEquals('Berço 1', FrenteTrabalho::find($atividade->frente_trabalho_id)->nome);
    }

    public function test_nao_sobrescreve_classificacao_ja_existente(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $disciplinaManual = Disciplina::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'disciplina_id' => $disciplinaManual->id,
            'textos' => ['Texto21' => 'OUTRA DISCIPLINA'],
        ]);

        $this->artisan('lookahead:backfill', ['obra' => $obra->id])->assertSuccessful();

        $this->assertEquals($disciplinaManual->id, $atividade->fresh()->disciplina_id);
    }

    public function test_cria_snapshot_da_importacao_mais_recente_para_atividades_sem_snapshot(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $importacao = CronogramaImportacao::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'importado_em' => now(),
        ]);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'origem' => 'ms_project',
            'inicio_planejado' => now()->addDays(5),
            'data_termino' => now()->addDays(10),
            'baseline_inicio' => now()->addDays(5),
            'baseline_termino' => now()->addDays(10),
        ]);

        $this->assertEquals(0, AtividadeSnapshot::where('atividade_id', $atividade->id)->count());

        $this->artisan('lookahead:backfill', ['obra' => $obra->id])->assertSuccessful();

        $snapshot = AtividadeSnapshot::where('atividade_id', $atividade->id)
            ->where('cronograma_importacao_id', $importacao->id)
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->inicio_planejado->isSameDay($atividade->inicio_planejado));
    }

    /**
     * Ciclo 17, A.9.2 — Fotografia F: o Backfill cria snapshot retroativo
     * pra importação ANTIGA (etapa 2, já existente) — mas NUNCA inventa
     * percentual_concluido/real_inicio/real_termino históricos copiando o
     * estado ATUAL (ao vivo) da Atividade. "É melhor snapshot histórico
     * antigo com Fotografia F = null do que inventar fotografia histórica
     * usando o estado atual" — mesmo se a Atividade viva já estiver 100%
     * com datas reais preenchidas, o snapshot retroativo tem que ficar null
     * nesses 3 campos (não existe fonte confiável do que o cronograma
     * declarou NAQUELA importação específica).
     */
    public function test_backfill_nao_inventa_percentual_e_datas_reais_historicas_a_partir_da_atividade_viva(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $importacao = CronogramaImportacao::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'importado_em' => now(),
        ]);

        // Atividade viva já 100%, com datas reais preenchidas — mas NENHUM
        // snapshot existe pra essa importação antiga (cenário pré-A.9.2).
        $atividade = Atividade::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'origem' => 'ms_project',
            'percentual_concluido' => 100,
            'real_inicio' => now()->subDays(10),
            'real_termino' => now()->subDays(2),
        ]);

        $this->artisan('lookahead:backfill', ['obra' => $obra->id])->assertSuccessful();

        $snapshot = AtividadeSnapshot::where('atividade_id', $atividade->id)
            ->where('cronograma_importacao_id', $importacao->id)
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertNull($snapshot->percentual_concluido);
        $this->assertNull($snapshot->real_inicio);
        $this->assertNull($snapshot->real_termino);
    }

    /**
     * Ciclo 17, A.9.1 — o Backfill padrão NÃO resolve mais restrições nem
     * conclui itens de prontidão automaticamente (removido de
     * concluirPendenciasDeAtividadesCompletas(), que deixou de ser chamada).
     * Classificação e snapshot retroativos (etapas 1/2) continuam
     * funcionando normalmente — só a autocorreção gerencial saiu do
     * caminho padrão.
     */
    public function test_backfill_padrao_preserva_restricao_e_prontidao_de_atividades_ja_100_no_banco(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'percentual_concluido' => 100,
            'textos' => ['Texto21' => 'ESTRUTURA'],
        ]);

        $restricao = Restricao::factory()->create([
            'tenant_id' => $tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $item = ItemProntidao::create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'nome' => 'Projeto aprovado', 'ordem' => 0]);
        AtividadeItemProntidao::create([
            'tenant_id' => $tenant->id,
            'atividade_id' => $atividade->id,
            'item_prontidao_id' => $item->id,
            'concluido' => false,
        ]);

        $this->artisan('lookahead:backfill', ['obra' => $obra->id])->assertSuccessful();

        // Classificação legítima (etapa 1) continua funcionando.
        $this->assertEquals('ESTRUTURA', Disciplina::find($atividade->fresh()->disciplina_id)?->nome);

        // Pendências operacionais: intocadas.
        $this->assertEquals(StatusRestricao::Aberta, $restricao->fresh()->status);
        $this->assertNull($restricao->fresh()->resolvida_em);
        $this->assertDatabaseMissing('restricao_acoes', ['restricao_id' => $restricao->id]);

        $registro = AtividadeItemProntidao::where('atividade_id', $atividade->id)
            ->where('item_prontidao_id', $item->id)
            ->first();
        $this->assertFalse((bool) $registro->concluido);
        $this->assertNull($registro->concluido_em);
    }
}
