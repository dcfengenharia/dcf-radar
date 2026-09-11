<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastreabilidade Quantitativa — Etapa 1. Mesma ponte de
 * `requisicao_compra_item_parcelas`, um nível abaixo: qual parcela de
 * necessidade cada quantidade de um `PedidoCompraItem` atende.
 *
 * **NUNCA proporcional/inferida — sempre explícita** (Revisão
 * Arquitetural 2, Seção 2, decisão fechada pelo usuário): um Pedido só
 * pode distribuir exatamente o que a RC de origem já detalhou pra ele —
 * a garantia disso é de aplicação (`App\Actions\Suprimentos\
 * AtualizarDistribuicaoParcelaPedidoCompra`, que exige uma
 * `RequisicaoCompraItemParcela` correspondente já existente antes de
 * aceitar qualquer linha aqui), não desta migration.
 *
 * `atividade_necessidade_material_id` é mantido aqui de propósito
 * (denormalizado, mas nunca uma segunda verdade) — permite consultar
 * "quais Pedidos atendem esta parcela" sem precisar atravessar
 * `RequisicaoCompraItemParcela` a cada leitura; a QUOTA de quanto este
 * Pedido pode consumir dessa parcela é sempre resolvida via JOIN contra
 * `requisicao_compra_item_parcelas` no par
 * (`pedido_compra_itens.requisicao_compra_item_id`,
 * `atividade_necessidade_material_id`) — nunca uma FK direta a uma
 * `RequisicaoCompraItemParcela` específica (o par já é suficiente e
 * inequívoco, graças ao UNIQUE da tabela irmã).
 *
 * `pedido_compra_item_id` é `cascadeOnDelete()` — mesmo raciocínio de
 * `requisicao_compra_item_parcelas`: um `PedidoCompraItem` só é removível
 * enquanto seu Pedido ainda é Rascunho, estado em que este detalhamento
 * nunca representou compromisso comercial real.
 *
 * `atividade_necessidade_material_id` é `restrictOnDelete()` — mesma
 * lição de evidência histórica de sempre.
 *
 * `UNIQUE(pedido_compra_item_id, atividade_necessidade_material_id)` —
 * mesmo padrão de toda a árvore: editar substitui, nunca soma uma
 * segunda linha pro mesmo par.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_compra_item_parcelas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('pedido_compra_item_id');
            $table->foreign('pedido_compra_item_id', 'pedido_item_parcela_item_fk')
                ->references('id')->on('pedido_compra_itens')
                ->cascadeOnDelete();

            $table->foreignUlid('atividade_necessidade_material_id');
            $table->foreign('atividade_necessidade_material_id', 'pedido_item_parcela_necessidade_fk')
                ->references('id')->on('atividade_necessidades_material')
                ->restrictOnDelete();

            $table->decimal('quantidade', 14, 3);

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['pedido_compra_item_id', 'atividade_necessidade_material_id'], 'pedido_item_parcela_item_necessidade_unique');
            $table->index(['tenant_id', 'atividade_necessidade_material_id'], 'pedido_item_parcela_tenant_necessidade_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_compra_item_parcelas');
    }
};
