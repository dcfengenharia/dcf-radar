<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.5 — EntregaProdutoIndustrializado: evento append-only
 * de "produto saiu do terceiro rumo a um destino" — sempre uma `Saida`
 * técnica no Local Terceiro (`movimentacao_saida_terceiro_id`) +
 * SEMPRE uma `Entrada` técnica no Local próprio da obra
 * (`movimentacao_entrada_destino_id`, decisão do usuário confirmada:
 * mesmo em entrega direta ao campo, a Entrada é sempre gerada — "a
 * Entrada é técnica/patrimonial, não uma afirmação de que o material
 * foi fisicamente estocado no almoxarifado"). Quando
 * `modalidade=EntregaDiretaCampo`, uma 3ª Movimentação
 * (`movimentacao_saida_campo_id`) reaproveita `App\Actions\Estoque\
 * RegistrarSaidaEstoque` de verdade — `frente_trabalho_id`/
 * `retirado_por`/`retirado_por_externo` denormalizados aqui só pra
 * consulta rápida (a fonte de verdade continua sendo a própria
 * `MovimentacaoEstoque` referenciada).
 *
 * "Entregue" acumulado de um Produto = `SUM(quantidade)` de suas
 * `EntregaProdutoIndustrializado`. "Saldo pronto no terceiro aguardando
 * destino" (Seção 47) = produzido - entregue, ambos derivados — nunca
 * persistido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_produto_industrializado', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->ulid('produto_industrializado_id');
            $table->decimal('quantidade', 14, 3);
            $table->ulid('unidade_estoque_id')->nullable();
            $table->string('modalidade');
            $table->ulid('movimentacao_saida_terceiro_id');
            $table->ulid('movimentacao_entrada_destino_id');
            $table->ulid('movimentacao_saida_campo_id')->nullable();
            $table->ulid('frente_trabalho_id')->nullable();
            $table->ulid('retirado_por')->nullable();
            $table->string('retirado_por_externo')->nullable();
            $table->date('ocorrido_em');
            $table->ulid('registrado_por')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('produto_industrializado_id', 'entrega_industr_produto_fk')
                ->references('id')->on('produtos_industrializados')->restrictOnDelete();
            $table->foreign('unidade_estoque_id', 'entrega_industr_unidade_fk')
                ->references('id')->on('unidades_estoque')->restrictOnDelete();
            $table->foreign('movimentacao_saida_terceiro_id', 'entrega_industr_saida_fk')
                ->references('id')->on('movimentacoes_estoque')->restrictOnDelete();
            $table->foreign('movimentacao_entrada_destino_id', 'entrega_industr_entrada_fk')
                ->references('id')->on('movimentacoes_estoque')->restrictOnDelete();
            $table->foreign('movimentacao_saida_campo_id', 'entrega_industr_saida_campo_fk')
                ->references('id')->on('movimentacoes_estoque')->restrictOnDelete();
            $table->foreign('frente_trabalho_id', 'entrega_industr_frente_fk')
                ->references('id')->on('frentes_trabalho')->restrictOnDelete();
            $table->foreign('retirado_por', 'entrega_industr_retirante_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('registrado_por', 'entrega_industr_autor_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'produto_industrializado_id'], 'entrega_industr_tenant_produto_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_produto_industrializado');
    }
};
