<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.7 — sessão formal de Inventário Físico, sempre
 * escopada a Obra + LocalEstoque (Seção 4 do pedido: nunca saldo global
 * do Material quando o inventário é de um Local). `local_estoque_id` é
 * `restrictOnDelete()` — evidência histórica, mesma lição de sempre
 * (nunca cascadeOnDelete numa FK que preserva um fato já ocorrido).
 *
 * **Decisão do usuário (STOP-and-ask, Etapa 20.7)**: Inventário só é
 * permitido em Locais PRÓPRIOS nesta etapa — `LocalEstoque.tipo=Terceiro`
 * é bloqueado na Action (`CriarInventarioEstoque`), mesma decisão já
 * tomada pra Transferência genérica (20.6): o fluxo de Industrialização
 * já cobre custódia em Terceiro com semântica própria.
 *
 * `status` (`App\Enums\StatusInventarioEstoque`): Rascunho|EmContagem|
 * EmAnalise|Concluido|Cancelado — nomes do próprio pedido, sem
 * convenção melhor já existente no projeto pra um workflow de 5 estágios
 * com aprovação.
 *
 * `numero` (nullable, `unique(obra_id, numero)`): mesmo padrão de
 * Grd/RequisicaoPlanejamento/RequisicaoCompra/PedidoCompra/
 * OrdemIndustrializacao — Rascunho nunca consome número; atribuído só em
 * `IniciarInventarioEstoque` via lock na linha do `Work` +
 * `MAX(numero)+1`.
 *
 * `contagem_cega` (boolean, default false, Seção 9 do pedido): decidido
 * na criação (Rascunho) — quando true, a UI de contagem não mostra
 * `quantidade_sistema_snapshot` ao contador antes de ele registrar a
 * quantidade encontrada. Não é uma configuração global do tenant — é por
 * sessão de inventário, exatamente como a Seção 9 permite.
 *
 * **Decisão do usuário (STOP-and-ask)**: movimentação física durante o
 * inventário (Entrada/Saída/Transferência no mesmo Local) NUNCA é
 * bloqueada — o snapshot de cada item (`inventario_itens.
 * quantidade_sistema_snapshot`) fica congelado no instante de
 * `IniciarInventarioEstoque`, e a aprovação de qualquer Ajuste sempre
 * revalida o saldo FRESCO sob lock (nunca o snapshot antigo) antes de
 * escrever no ledger — ver `App\Actions\Estoque\AprovarAjusteInventario`.
 *
 * Imutabilidade: `App\Observers\InventarioEstoqueObserver` bloqueia
 * `deleting()`/`forceDelete()` incondicionalmente (cancelamento é
 * SEMPRE status, nunca DELETE — Seção 20) e bloqueia `updating()` de
 * qualquer instância cujo status já seja Concluido/Cancelado (Seção 21).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventarios_estoque', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('local_estoque_id')
                ->constrained('locais_estoque')
                ->restrictOnDelete();
            $table->unsignedInteger('numero')->nullable();
            $table->string('status')->default('rascunho');
            $table->string('titulo')->nullable();
            $table->boolean('contagem_cega')->default(false);
            $table->timestamp('iniciado_em')->nullable();
            $table->foreignUlid('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('concluido_em')->nullable();
            $table->foreignUlid('concluido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelado_em')->nullable();
            $table->foreignUlid('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_cancelamento')->nullable();
            $table->text('observacao')->nullable();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['obra_id', 'numero'], 'inv_estoque_obra_numero_unique');
            $table->index(['tenant_id', 'obra_id', 'local_estoque_id'], 'inv_estoque_tenant_obra_local_idx');
            $table->index(['tenant_id', 'status'], 'inv_estoque_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventarios_estoque');
    }
};
