<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restricoes', function (Blueprint $table) {
            $table->index('status');
            $table->index('atividade_id');
            $table->index('categoria_id');
            $table->index('bloqueante');
        });

        Schema::table('atividades', function (Blueprint $table) {
            $table->index('obra_id');
            $table->index('fora_do_cronograma');
            $table->index('inicio_planejado');
            $table->index('data_termino');
        });
    }

    public function down(): void
    {
        Schema::table('restricoes', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['atividade_id']);
            $table->dropIndex(['categoria_id']);
            $table->dropIndex(['bloqueante']);
        });

        Schema::table('atividades', function (Blueprint $table) {
            $table->dropIndex(['obra_id']);
            $table->dropIndex(['fora_do_cronograma']);
            $table->dropIndex(['inicio_planejado']);
            $table->dropIndex(['data_termino']);
        });
    }
};
