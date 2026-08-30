<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.5 — ProdutoIndustrializado: 1 Ordem → N produtos
 * previstos (Seção 3, decisão já aprovada — nunca 1 Ordem por
 * documento). `material_id` OBRIGATÓRIO — decisão do usuário
 * (identidade Material/SKU, Opção B): o produto é sempre a
 * FAMÍLIA/TIPO de peça (o Material mestre); cada unidade física
 * fabricada é uma `UnidadeEstoque` (serial/lote) desse Material,
 * nunca um Material novo por peça. `documento_engenharia_revisao_id`
 * nullable e `restrictOnDelete()` — a revisão EXATA usada na
 * fabricação, congelada pra sempre (R2 nascer depois nunca reescreve
 * um Produto já criado com R1 — Seção 31).
 *
 * `quantidade_prevista` só é editável enquanto a Ordem dona é Rascunho
 * (guard na Action, nunca reescrita depois — Seção 17: "não sobrescrever
 * quantidade prevista"). Produzido/entregue são sempre DERIVADOS de
 * `producoes_industrializadas`/`entregas_produto_industrializado`,
 * nunca colunas aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produtos_industrializados', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->ulid('ordem_industrializacao_id');
            $table->ulid('material_id');
            $table->ulid('documento_engenharia_revisao_id')->nullable();
            $table->decimal('quantidade_prevista', 14, 3);
            $table->text('observacao')->nullable();
            $table->ulid('created_by_id')->nullable();
            $table->timestamps();

            $table->foreign('ordem_industrializacao_id', 'prod_industr_ordem_fk')
                ->references('id')->on('ordens_industrializacao')->restrictOnDelete();
            $table->foreign('material_id', 'prod_industr_material_fk')
                ->references('id')->on('materiais')->restrictOnDelete();
            $table->foreign('documento_engenharia_revisao_id', 'prod_industr_doc_rev_fk')
                ->references('id')->on('documento_engenharia_revisoes')->restrictOnDelete();
            $table->foreign('created_by_id', 'prod_industr_autor_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'ordem_industrializacao_id'], 'prod_industr_tenant_ordem_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produtos_industrializados');
    }
};
