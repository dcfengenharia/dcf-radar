<?php

namespace Tests\Unit;

use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\ConclusaoAutomaticaAtividades;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 17, A.9.1 — ConclusaoAutomaticaAtividades virou LEGADO: não é mais
 * chamada automaticamente por MsProjectImporter nem por
 * BackfillLookaheadCommand (ver CronogramaImportacaoTest/
 * BackfillLookaheadCommandTest), mas a classe em si continua existindo e
 * funcionando exatamente como antes quando invocada deliberadamente — este
 * teste prova que a remoção dos chamadores automáticos não quebrou a
 * classe, só o disparo automático.
 */
class ConclusaoAutomaticaAtividadesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
    }

    public function test_chamada_explicita_resolve_restricoes_abertas_e_cria_restricao_acao_com_autor(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        ConclusaoAutomaticaAtividades::aplicar([$atividade->id], $this->obra->id, $this->user->id);

        $restricao->refresh();
        $this->assertEquals(StatusRestricao::Resolvida, $restricao->status);
        $this->assertNotNull($restricao->resolvida_em);
        $this->assertDatabaseHas('restricao_acoes', [
            'restricao_id' => $restricao->id,
            'autor_id' => $this->user->id,
            'descricao' => 'Restrição concluída automaticamente: atividade atingiu 100% no cronograma.',
        ]);
    }

    public function test_chamada_explicita_sem_userid_resolve_restricao_mas_nao_cria_acao(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        ConclusaoAutomaticaAtividades::aplicar([$atividade->id], $this->obra->id, null);

        $this->assertEquals(StatusRestricao::Resolvida, $restricao->fresh()->status);
        $this->assertDatabaseMissing('restricao_acoes', ['restricao_id' => $restricao->id]);
    }

    public function test_chamada_explicita_marca_itens_de_prontidao_como_concluidos(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $item = ItemProntidao::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Projeto aprovado', 'ordem' => 0]);
        AtividadeItemProntidao::create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'item_prontidao_id' => $item->id,
            'concluido' => false,
        ]);

        ConclusaoAutomaticaAtividades::aplicar([$atividade->id], $this->obra->id, $this->user->id);

        $registro = AtividadeItemProntidao::where('atividade_id', $atividade->id)
            ->where('item_prontidao_id', $item->id)
            ->first();
        $this->assertTrue((bool) $registro->concluido);
        $this->assertEquals($this->user->id, $registro->concluido_por);
        $this->assertNotNull($registro->concluido_em);
    }

    public function test_lista_vazia_de_atividades_nao_faz_nada(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        ConclusaoAutomaticaAtividades::aplicar([], $this->obra->id, $this->user->id);

        $this->assertEquals(StatusRestricao::Aberta, $restricao->fresh()->status);
    }
}
