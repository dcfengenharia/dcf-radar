<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.5 — linha de consumo de um Pedido sobre um
 * `RequisicaoCompraItem` (nunca aponta direto a `ItemTakeOff` — a
 * cadeia histórica completa é PedidoItem → RCItem → AlocacaoRequisicaoPacote
 * → RPItem → ItemTakeOff → Lista → Revisão → Documento).
 *
 * `requisicao_compra_item_id` é `restrictOnDelete()` — mesma lição de
 * evidência histórica de sempre; na prática nunca é exercida por um
 * delete concorrente porque `RequisicaoCompraItem` só existe depois da
 * RC ser Emitida, e a partir daí é permanentemente imutável (nenhum
 * caminho de código jamais deleta um `RequisicaoCompraItem`).
 *
 * `UNIQUE(pedido_compra_id, requisicao_compra_item_id)` — no máximo 1
 * linha por par Pedido×RCItem (mesmo padrão de todas as camadas
 * anteriores: editar SUBSTITUI o valor, nunca soma uma segunda linha).
 *
 * Colunas `*_snapshot` (nullable, vazias em Rascunho, congeladas só na
 * emissão) espelham EXATAMENTE `requisicao_compra_itens` — cópia direta
 * da cadeia no instante da emissão do Pedido, mantendo-o 100%
 * autocontido (nunca depende de reler `RequisicaoCompraItem.*_snapshot`,
 * entidade diferente, ainda que também congelada).
 *
 * Sem SoftDeletes própria: enquanto Rascunho, um item pode ser removido
 * de verdade (mesmo padrão de `requisicao_compra_itens`); depois de
 * Emitido, o Pedido inteiro fica imutável.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_compra_itens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('pedido_compra_id');
            $table->foreign('pedido_compra_id', 'pedido_compra_item_pedido_fk')
                ->references('id')->on('pedidos_compra')
                ->cascadeOnDelete();

            $table->foreignUlid('requisicao_compra_item_id');
            $table->foreign('requisicao_compra_item_id', 'pedido_compra_item_rcitem_fk')
                ->references('id')->on('requisicao_compra_itens')
                ->restrictOnDelete();

            $table->decimal('quantidade_pedida', 14, 3);

            $table->string('codigo_item_snapshot')->nullable();
            $table->string('descricao_snapshot')->nullable();
            $table->string('unidade_snapshot')->nullable();
            $table->string('lista_codigo_snapshot')->nullable();
            $table->string('tipo_lista_snapshot')->nullable();
            $table->string('documento_codigo_snapshot')->nullable();
            $table->string('revisao_snapshot')->nullable();

            $table->timestamps();

            $table->unique(['pedido_compra_id', 'requisicao_compra_item_id'], 'pedido_compra_item_pedido_rcitem_unique');
            $table->index(['tenant_id', 'requisicao_compra_item_id'], 'pedido_compra_item_tenant_rcitem_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_compra_itens');
    }
};
