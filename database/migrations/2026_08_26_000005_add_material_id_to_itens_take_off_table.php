<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.1 — associação opcional de ItemTakeOff ao catálogo
 * mestre de Material. Nullable, sem backfill automático (instrução
 * explícita do pedido — nunca inferir Material por descrição/texto).
 * ItemTakeOff antigo continua existindo exatamente como estava, sem
 * Material, até que um usuário autorizado associe manualmente.
 *
 * restrictOnDelete (não nullOnDelete): um Material referenciado por
 * QUALQUER ItemTakeOff nunca pode ser fisicamente apagado (Seção 27 da
 * investigação) — inativação (soft-delete/ativo=false) continua livre,
 * só o DELETE físico é bloqueado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens_take_off', function (Blueprint $table) {
            $table->foreignUlid('material_id')->nullable()->after('familia_material_id');
            $table->foreign('material_id', 'itens_take_off_material_fk')
                ->references('id')->on('materiais')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'material_id'], 'itens_take_off_tenant_material_idx');
        });
    }

    public function down(): void
    {
        Schema::table('itens_take_off', function (Blueprint $table) {
            $table->dropForeign('itens_take_off_material_fk');
            $table->dropIndex('itens_take_off_tenant_material_idx');
            $table->dropColumn('material_id');
        });
    }
};
