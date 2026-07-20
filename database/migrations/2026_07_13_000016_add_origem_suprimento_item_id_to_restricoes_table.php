<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restricoes', function (Blueprint $table) {
            $table->foreignUlid('origem_suprimento_item_id')->nullable()
                ->after('categoria_id')
                ->constrained('itens_suprimento')->nullOnDelete();

            $table->index(['tenant_id', 'origem_suprimento_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('restricoes', function (Blueprint $table) {
            $table->dropForeign(['origem_suprimento_item_id']);
            $table->dropColumn('origem_suprimento_item_id');
        });
    }
};
