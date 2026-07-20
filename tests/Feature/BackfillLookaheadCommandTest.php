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

    public function test_resolve_pendencias_de_atividades_ja_100_no_banco(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'percentual_concluido' => 100,
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

        $this->assertEquals(StatusRestricao::Resolvida, $restricao->fresh()->status);

        $registro = AtividadeItemProntidao::where('atividade_id', $atividade->id)
            ->where('item_prontidao_id', $item->id)
            ->first();
        $this->assertTrue((bool) $registro->concluido);
    }
}
