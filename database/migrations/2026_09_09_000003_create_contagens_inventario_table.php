<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.7 — **decisão do usuário (STOP-and-ask)**: cada
 * contagem/recontagem de um `InventarioItem` é um EVENTO APPEND-ONLY
 * próprio, nunca um campo mutável sobrescrito — mesmo padrão já usado em
 * `RecebimentoPedido`/`GrdRecolhimento`/`PlanoAcaoReconciliacao` (Ciclos
 * 18/19/20). 1 `InventarioItem` → N `ContagemInventario`; a MAIS RECENTE
 * (por `created_at DESC, id DESC` — mesma ordem de registro canônica já
 * usada em `GrdDistribuicao::estado()`, nunca `contado_em`, que é a data
 * informada e pode ser retroativa) é sempre a "contagem adotada" pra fins
 * de divergência/Ajuste — derivada em `App\Models\InventarioItem::
 * ultimaContagem()`, nunca uma coluna/flag "adotada" persistida.
 *
 * `contagem_cega` (Seção 9): não é coluna aqui — é herdada do
 * `InventarioEstoque` (uma decisão por sessão, não por contagem
 * individual); a UI só consulta `$inventario->contagem_cega` antes de
 * decidir se mostra `quantidade_sistema_snapshot` ao contador.
 *
 * `const UPDATED_AT = null` no model — nunca editado, `App\Observers\
 * ContagemInventarioObserver` bloqueia `updating()`/`deleting()`
 * incondicionalmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contagens_inventario', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('inventario_item_id')
                ->constrained('inventario_itens')
                ->cascadeOnDelete();
            $table->decimal('quantidade_contada', 14, 3);
            $table->date('contado_em');
            $table->foreignUlid('contador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacao')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'inventario_item_id'], 'contagem_inv_tenant_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contagens_inventario');
    }
};
