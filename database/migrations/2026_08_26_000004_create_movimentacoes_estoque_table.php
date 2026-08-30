<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.1 — MovimentacaoEstoque: ledger append-only do fato
 * físico. Mesmo espírito exato de recebimentos_pedido/grd_recolhimentos
 * (Ciclo 19/18) — App\Observers\MovimentacaoEstoqueObserver bloqueia
 * update()/delete() de instância incondicionalmente; só
 * App\Actions\Estoque\RegistrarEntradaEstoque escreve aqui.
 *
 * `tipo` só tem o caso "entrada" nesta fase (instrução explícita do
 * pedido — não antecipar Transferencia/Saida/Industrializacao). Coluna
 * string (não MySQL ENUM físico), então o conjunto de valores cresce em
 * fases futuras sem migration.
 *
 * `obra_id` é DENORMALIZADO a partir de local_estoque_id.obra_id no
 * momento da criação — Material é tenant-scoped, mas toda movimentação
 * acontece dentro de uma obra concreta (via o Local); ter obra_id direto
 * aqui evita joins pra escopar consultas/permissões por obra (mesmo
 * padrão já usado em ItemSuprimento.obra_id).
 *
 * `item_take_off_id` (nullable, restrictOnDelete) é um BREADCRUMB
 * denormalizado — resolvido uma única vez, no momento da entrada, a
 * partir da cadeia RecebimentoPedido→...→ItemTakeOff. Existe só pra 2
 * propósitos: (1) guard de imutabilidade de ItemTakeOff.material_id
 * (App\Observers\ItemTakeOffObserver — uma vez usado por estoque, nunca
 * reescrito) sem precisar de um join profundo a cada validação; (2)
 * responder rápido "quais entradas vieram deste ItemTakeOff" (Seção 6/47
 * da investigação — duas LMs do mesmo Material consolidando saldo).
 * NUNCA duplica a cadeia inteira (RequisicaoCompraItem/Alocacao/RPItem
 * continuam navegáveis só via recebimento_pedido_id, que já é
 * suficiente pra tudo o resto).
 *
 * Sem UPDATED_AT (const na model) — mesmo padrão de recebimentos_pedido:
 * só created_at faz sentido pra um fato que nunca é reescrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimentacoes_estoque', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            $table->string('tipo');

            $table->foreignUlid('material_id');
            $table->foreign('material_id', 'movimentacoes_estoque_material_fk')
                ->references('id')->on('materiais')
                ->restrictOnDelete();

            $table->foreignUlid('local_estoque_id');
            $table->foreign('local_estoque_id', 'movimentacoes_estoque_local_fk')
                ->references('id')->on('locais_estoque')
                ->restrictOnDelete();

            $table->foreignUlid('unidade_estoque_id')->nullable();
            $table->foreign('unidade_estoque_id', 'movimentacoes_estoque_unidade_fk')
                ->references('id')->on('unidades_estoque')
                ->restrictOnDelete();

            $table->foreignUlid('recebimento_pedido_id')->nullable();
            $table->foreign('recebimento_pedido_id', 'movimentacoes_estoque_recebimento_fk')
                ->references('id')->on('recebimentos_pedido')
                ->restrictOnDelete();

            $table->foreignUlid('item_take_off_id')->nullable();
            $table->foreign('item_take_off_id', 'movimentacoes_estoque_take_off_fk')
                ->references('id')->on('itens_take_off')
                ->restrictOnDelete();

            $table->decimal('quantidade', 14, 3);
            $table->date('ocorrido_em');

            $table->foreignUlid('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacao')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'material_id', 'local_estoque_id'], 'movimentacoes_estoque_mat_local_idx');
            $table->index(['tenant_id', 'unidade_estoque_id'], 'movimentacoes_estoque_unidade_idx');
            $table->index(['tenant_id', 'recebimento_pedido_id'], 'movimentacoes_estoque_recebimento_idx');
            $table->index(['tenant_id', 'obra_id'], 'movimentacoes_estoque_tenant_obra_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimentacoes_estoque');
    }
};
