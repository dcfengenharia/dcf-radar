<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atividade_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cronograma_importacao_id')->constrained('cronograma_importacoes')->cascadeOnDelete();
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();
            $table->date('inicio_planejado')->nullable();
            $table->date('data_termino')->nullable();
            $table->date('baseline_inicio')->nullable();
            $table->date('baseline_termino')->nullable();
            $table->timestamps();

            $table->unique(['cronograma_importacao_id', 'atividade_id'], 'atividade_snapshots_import_atividade_unique');
            $table->index(['atividade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atividade_snapshots');
    }
};
