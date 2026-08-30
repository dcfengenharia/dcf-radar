<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.5 — custódia em terceiro (Opção A confirmada pelo
 * usuário): um LocalEstoque tipo Terceiro representa a posição física/
 * jurídica do material em poder de um Fornecedor. `fornecedor_id` é
 * nullable (só preenchido quando `tipo=Terceiro`, coerência garantida
 * por `App\Observers\LocalEstoqueObserver`) e `restrictOnDelete()` —
 * mesma lição de evidência histórica de sempre: um Fornecedor soft-
 * deletado depois de já ter Locais/remessas nunca pode ser apagado
 * fisicamente enquanto o histórico existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locais_estoque', function (Blueprint $table) {
            $table->foreignUlid('fornecedor_id')->nullable()->after('tipo');
        });

        Schema::table('locais_estoque', function (Blueprint $table) {
            $table->foreign('fornecedor_id', 'locais_estoque_fornecedor_fk')
                ->references('id')->on('fornecedores')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'fornecedor_id'], 'locais_estoque_fornecedor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('locais_estoque', function (Blueprint $table) {
            $table->dropForeign('locais_estoque_fornecedor_fk');
            $table->dropIndex('locais_estoque_fornecedor_idx');
            $table->dropColumn('fornecedor_id');
        });
    }
};
