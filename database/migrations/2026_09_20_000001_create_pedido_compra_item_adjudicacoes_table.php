<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2.CORREÇÃO (Achado C da auditoria adversarial — gap de
 * proveniência) — a ponte EXPLÍCITA que faltava entre o consumo de um
 * Pedido e a decisão de adjudicação específica que o originou. Sem esta
 * tabela, a guarda de saldo (Etapa 2 original) só sabia responder "o
 * fornecedor X ainda tem quota suficiente NO AGREGADO", nunca "esta
 * quantidade especificamente veio de QUAL adjudicação" — irrelevante
 * pra controle de saldo, mas insuficiente pra proveniência histórica
 * quando o MESMO fornecedor tem 2+ adjudicações Ativas/Canceladas sobre
 * o MESMO alvo ao longo do tempo (cenário provado na auditoria).
 *
 * Mesma filosofia das duas pontes já existentes no domínio
 * (`RequisicaoCompraItemParcela`/`PedidoCompraItemParcela`): nunca
 * proporcional/inferida, sempre uma escolha EXPLÍCITA do usuário no
 * momento em que o Pedido é montado, validada contra o saldo real da
 * `RequisicaoCompraAdjudicacaoItem` de origem.
 *
 * `pedido_compra_item_id` sempre presente (mesmo quando há parcela —
 * mesmo padrão de `requisicao_compra_adjudicacao_itens.
 * requisicao_compra_item_id`, redundante com `parcela->
 * pedido_compra_item_id` mas explícito por permitir agregação sem JOIN
 * condicional). `pedido_compra_item_parcela_id` nulo = a adjudicação
 * item-level (sem detalhamento de Atividade) sendo consumida
 * diretamente; não-nulo = a adjudicação por parcela correspondente.
 *
 * `restrictOnDelete()` nas 3 FKs — evidência histórica cross-aggregate,
 * nunca perdida silenciosamente, mesmo padrão de toda a árvore RC/Pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_compra_item_adjudicacoes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('pedido_compra_item_id');
            $table->foreign('pedido_compra_item_id', 'ped_item_adj_pedido_item_fk')
                ->references('id')->on('pedido_compra_itens')
                ->restrictOnDelete();

            $table->foreignUlid('pedido_compra_item_parcela_id')->nullable();
            $table->foreign('pedido_compra_item_parcela_id', 'ped_item_adj_parcela_fk')
                ->references('id')->on('pedido_compra_item_parcelas')
                ->restrictOnDelete();

            $table->foreignUlid('requisicao_compra_adjudicacao_item_id');
            $table->foreign('requisicao_compra_adjudicacao_item_id', 'ped_item_adj_adjudicacao_item_fk')
                ->references('id')->on('requisicao_compra_adjudicacao_itens')
                ->restrictOnDelete();

            $table->decimal('quantidade', 14, 3);

            $table->foreignUlid('created_by_id')->nullable();
            $table->foreign('created_by_id', 'ped_item_adj_autor_fk')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['pedido_compra_item_id', 'pedido_compra_item_parcela_id', 'requisicao_compra_adjudicacao_item_id'],
                'ped_item_adj_unique'
            );
            $table->index(['tenant_id', 'requisicao_compra_adjudicacao_item_id'], 'ped_item_adj_tenant_adj_idx');
            $table->index(['tenant_id', 'pedido_compra_item_parcela_id'], 'ped_item_adj_tenant_parcela_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_compra_item_adjudicacoes');
    }
};
