<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.5 — genealogia quantitativa N:N ProdutoIndustrializado
 * ↔ RemessaIndustrializacao (decisão do usuário, confirmada mesmo
 * sabendo do custo de schema): cada linha registra QUANTO de UMA
 * remessa de ENVIO específica foi efetivamente consumido na fabricação
 * de UM Produto. Append-only (`App\Observers\
 * ProdutoIndustrializadoConsumoObserver`) — histórico de genealogia
 * nunca é reescrito; múltiplos eventos de consumo ao longo do tempo
 * pro MESMO par são permitidos (produção em lotes), por isso NENHUM
 * `unique(produto, remessa)`.
 *
 * `movimentacao_consumo_id` (`restrictOnDelete()`) é a `Saida` LIVRE
 * (sem destino) criada no Local Terceiro no mesmo instante — é isso
 * que reduz de verdade o saldo físico da matéria-prima em custódia do
 * terceiro (Seção 13: "material enviado não é necessariamente todo
 * consumido" — consumir É uma transformação física real, precisa
 * refletir no ledger).
 *
 * Over-consumo bloqueado por `App\Actions\Estoque\
 * RegistrarConsumoIndustrializacao`: `SUM(quantidade_consumida)` de
 * uma `RemessaIndustrializacao` nunca pode ultrapassar sua própria
 * `quantidade` — lock na Remessa antes do SUM, mesmo total order já
 * usado em toda a cadeia do Ciclo 19/20.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto_industrializado_consumos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->ulid('produto_industrializado_id');
            $table->ulid('remessa_industrializacao_id');
            $table->decimal('quantidade_consumida', 14, 3);
            $table->date('ocorrido_em');
            $table->ulid('movimentacao_consumo_id');
            $table->ulid('registrado_por')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('produto_industrializado_id', 'consumo_industr_produto_fk')
                ->references('id')->on('produtos_industrializados')->restrictOnDelete();
            $table->foreign('remessa_industrializacao_id', 'consumo_industr_remessa_fk')
                ->references('id')->on('remessas_industrializacao')->restrictOnDelete();
            $table->foreign('movimentacao_consumo_id', 'consumo_industr_mov_fk')
                ->references('id')->on('movimentacoes_estoque')->restrictOnDelete();
            $table->foreign('registrado_por', 'consumo_industr_autor_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'produto_industrializado_id'], 'consumo_industr_tenant_produto_idx');
            $table->index(['tenant_id', 'remessa_industrializacao_id'], 'consumo_industr_tenant_remessa_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_industrializado_consumos');
    }
};
