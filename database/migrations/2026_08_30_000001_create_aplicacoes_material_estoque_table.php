<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.4 — AplicacaoMaterialEstoque: onde uma Saída física
 * (MovimentacaoEstoque tipo Saida) foi EFETIVAMENTE utilizada. Camada
 * GERENCIAL nova, nunca reescreve os fatos já congelados (Saída, Reserva,
 * Destinação) — ver docblock do model para as regras completas.
 *
 * `item_suprimento_id` é NULLABLE e vive por LINHA (decisão do usuário,
 * resolvendo as Seções 17/18/19 da investigação): uma Saída sem Pacote
 * definido pode ser conciliada posteriormente em N Pacotes diferentes,
 * cada linha de Aplicação carregando o seu — sem nunca reescrever
 * `movimentacoes_estoque.item_suprimento_id`.
 *
 * FKs de evidência histórica são `restrictOnDelete()` — mesma lição já
 * aplicada em toda a árvore GED/GRD/Estoque desde a A.9.3.CORREÇÃO
 * (Ciclo 17): uma Aplicação nunca pode ficar órfã de sua Saída/Frente/
 * Pacote por um `forceDelete()` em cascata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aplicacoes_material_estoque', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('movimentacao_estoque_id')->constrained('movimentacoes_estoque')->restrictOnDelete();
            $table->foreignUlid('frente_trabalho_id')->constrained('frentes_trabalho')->restrictOnDelete();
            $table->foreignUlid('item_suprimento_id')->nullable()->constrained('itens_suprimento')->restrictOnDelete();
            $table->decimal('quantidade', 14, 3);
            $table->date('aplicado_em');
            $table->foreignUlid('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('atualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'movimentacao_estoque_id'], 'aplic_mat_est_tenant_mov_idx');
            $table->index(['tenant_id', 'obra_id', 'frente_trabalho_id'], 'aplic_mat_est_tenant_obra_frente_idx');
            $table->index(['tenant_id', 'item_suprimento_id'], 'aplic_mat_est_tenant_pacote_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicacoes_material_estoque');
    }
};
