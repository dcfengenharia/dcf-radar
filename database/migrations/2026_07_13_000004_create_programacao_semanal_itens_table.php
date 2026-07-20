<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programacao_semanal_itens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('programacao_semanal_id')
                  ->constrained('programacoes_semanais')->cascadeOnDelete();
            $table->foreignUlid('atividade_id')
                  ->constrained('atividades')->cascadeOnDelete();
            $table->date('inicio_planejado_congelado')->nullable();
            $table->date('data_termino_congelado')->nullable();
            // null = não havia AvancoPeriodo pra essa atividade nesta semana —
            // distinto de 0.00 (que significa "havia linha, com zero horas").
            $table->decimal('horas_previstas_congeladas', 12, 2)->nullable();
            $table->string('origem'); // App\Enums\OrigemProgramacaoSemanalItem
            $table->foreignUlid('criado_por')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['programacao_semanal_id', 'atividade_id'], 'programacao_semanal_itens_unique');
            $table->index(['atividade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programacao_semanal_itens');
    }
};
