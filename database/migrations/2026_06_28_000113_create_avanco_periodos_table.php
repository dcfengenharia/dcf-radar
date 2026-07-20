<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avanco_periodos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cronograma_importacao_id')
                  ->constrained('cronograma_importacoes')
                  ->cascadeOnDelete();
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();
            $table->string('granularidade');
            $table->string('serie');
            $table->date('periodo_inicio');
            $table->decimal('horas', 12, 2);
            $table->timestamps();

            $table->index(['cronograma_importacao_id'], 'avanco_periodos_importacao_idx');
            $table->index(
                ['atividade_id', 'granularidade', 'serie', 'periodo_inicio'],
                'avanco_periodos_atividade_periodo_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avanco_periodos');
    }
};
