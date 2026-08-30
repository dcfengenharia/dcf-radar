<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.5 — ProducaoIndustrializada: evento append-only de
 * "produto passa a existir fisicamente, em custódia do terceiro" — a
 * fabricação em si, distinta de ENTREGA (Seção 17: "produzido" ≠
 * "entregue"). `movimentacao_entrada_id` (`restrictOnDelete()`) é uma
 * `Entrada` técnica no Local Terceiro da Ordem, sobre o `material_id`
 * do `ProdutoIndustrializado` — reaproveita 100% do ledger existente
 * (nunca um saldo paralelo). `unidade_estoque_id` nullable — só
 * populado quando o produto é Serializado (cada peça produzida ganha
 * seu próprio serial) ou Lote.
 *
 * "Produzido" acumulado de um Produto = `SUM(quantidade)` de suas
 * `ProducaoIndustrializada` (nunca uma coluna redundante em
 * `produtos_industrializados`). Sem limite de over-produção nesta fase
 * (decisão de implementação: nenhum requisito claro no pedido —
 * variação real de encomenda maior que o previsto é plausível; UI só
 * exibe a divergência, nunca bloqueia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producoes_industrializadas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->ulid('produto_industrializado_id');
            $table->decimal('quantidade', 14, 3);
            $table->ulid('unidade_estoque_id')->nullable();
            $table->date('ocorrido_em');
            $table->ulid('movimentacao_entrada_id');
            $table->ulid('registrado_por')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('produto_industrializado_id', 'producao_industr_produto_fk')
                ->references('id')->on('produtos_industrializados')->restrictOnDelete();
            $table->foreign('unidade_estoque_id', 'producao_industr_unidade_fk')
                ->references('id')->on('unidades_estoque')->restrictOnDelete();
            $table->foreign('movimentacao_entrada_id', 'producao_industr_mov_fk')
                ->references('id')->on('movimentacoes_estoque')->restrictOnDelete();
            $table->foreign('registrado_por', 'producao_industr_autor_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'produto_industrializado_id'], 'producao_industr_tenant_produto_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producoes_industrializadas');
    }
};
