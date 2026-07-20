<?php

namespace Tests\Feature;

use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\ItemProntidao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ItemProntidaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User   $user;
    private Work   $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra   = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
    }

    private function criarAtividade(): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id'   => $this->obra->id,
        ]);
    }

    private function criarItem(string $nome = 'Projeto disponível'): ItemProntidao
    {
        return ItemProntidao::create([
            'obra_id' => $this->obra->id,
            'nome'    => $nome,
            'ordem'   => 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // CRUD básico
    // -------------------------------------------------------------------------

    public function test_criar_item_prontidao(): void
    {
        $item = $this->criarItem('Materiais no canteiro');

        $this->assertDatabaseHas('itens_prontidao', [
            'obra_id'   => $this->obra->id,
            'tenant_id' => $this->tenant->id,
            'nome'      => 'Materiais no canteiro',
        ]);
    }

    public function test_soft_delete_item(): void
    {
        $item = $this->criarItem();
        $item->delete();

        $this->assertSoftDeleted('itens_prontidao', ['id' => $item->id]);
        $this->assertEquals(0, ItemProntidao::where('obra_id', $this->obra->id)->count());
    }

    // -------------------------------------------------------------------------
    // estaPronta() com itens de prontidão
    // -------------------------------------------------------------------------

    public function test_atividade_sem_itens_na_obra_esta_pronta(): void
    {
        $atividade = $this->criarAtividade();

        // Sem itens na obra → prontidão depende só de restrições (não tem → pronta)
        $this->assertTrue($atividade->estaPronta());
    }

    public function test_atividade_com_item_pendente_nao_esta_pronta(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarItem('Projeto executivo');

        // Item existe mas não foi marcado → não pronta
        $this->assertFalse($atividade->estaPronta());
    }

    public function test_atividade_com_todos_os_itens_marcados_esta_pronta(): void
    {
        $atividade = $this->criarAtividade();
        $item1     = $this->criarItem('Projeto executivo');
        $item2     = $this->criarItem('Materiais no canteiro');

        AtividadeItemProntidao::create([
            'atividade_id'      => $atividade->id,
            'item_prontidao_id' => $item1->id,
            'concluido'         => true,
        ]);
        AtividadeItemProntidao::create([
            'atividade_id'      => $atividade->id,
            'item_prontidao_id' => $item2->id,
            'concluido'         => true,
        ]);

        $this->assertTrue($atividade->estaPronta());
    }

    public function test_atividade_com_item_parcialmente_marcado_nao_esta_pronta(): void
    {
        $atividade = $this->criarAtividade();
        $item1     = $this->criarItem('Projeto');
        $item2     = $this->criarItem('Materiais');

        // Só o primeiro marcado
        AtividadeItemProntidao::create([
            'atividade_id'      => $atividade->id,
            'item_prontidao_id' => $item1->id,
            'concluido'         => true,
        ]);

        $this->assertFalse($atividade->estaPronta());
    }

    // -------------------------------------------------------------------------
    // Observer: não pode comprometer atividade com item pendente
    // -------------------------------------------------------------------------

    public function test_atividade_com_item_pendente_nao_pode_ser_comprometida(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarItem('Frente de serviço liberada');

        $this->expectException(ValidationException::class);

        $atividade->update(['status' => StatusAtividade::Comprometido->value]);
    }

    public function test_atividade_com_todos_itens_marcados_pode_ser_comprometida(): void
    {
        $atividade = $this->criarAtividade();
        $item      = $this->criarItem();

        AtividadeItemProntidao::create([
            'atividade_id'      => $atividade->id,
            'item_prontidao_id' => $item->id,
            'concluido'         => true,
        ]);

        $atividade->update(['status' => StatusAtividade::Comprometido->value]);
        $this->assertEquals(StatusAtividade::Comprometido, $atividade->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Scopes prontas / naoProntas
    // -------------------------------------------------------------------------

    public function test_scope_prontas_exclui_atividade_com_item_pendente(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarItem();

        $prontas = Atividade::where('obra_id', $this->obra->id)->prontas()->get();

        $this->assertCount(0, $prontas);
    }

    public function test_scope_prontas_inclui_atividade_sem_itens(): void
    {
        $atividade = $this->criarAtividade();
        // Obra sem itens de prontidão

        $prontas = Atividade::where('obra_id', $this->obra->id)->prontas()->get();

        $this->assertCount(1, $prontas);
        $this->assertEquals($atividade->id, $prontas->first()->id);
    }

    // -------------------------------------------------------------------------
    // Isolamento de tenant
    // -------------------------------------------------------------------------

    public function test_itens_de_uma_obra_nao_aparecem_em_outra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->criarItem('Item da obra A');

        $itensOutraObra = ItemProntidao::where('obra_id', $outraObra->id)->count();
        $this->assertEquals(0, $itensOutraObra);
    }
}
