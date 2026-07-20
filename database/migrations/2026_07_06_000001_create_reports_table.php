<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            // Semana de referência do report — sempre semanal, por decisão do usuário.
            $table->date('periodo_referencia');

            // Snapshot do data_status da importação usada, só pra exibição.
            $table->date('data_status')->nullable();

            $table->string('status')->default('rascunho');

            // Linha de base usada pro "Previsto" de todas as curvas deste report.
            $table->foreignUlid('linha_base_id')->nullable()->constrained('linhas_base')->nullOnDelete();

            // Importação usada pra "Tendência"/"Realizado" — travada no momento
            // da geração, nunca a "mais recente no momento em que alguém abre".
            $table->foreignUlid('cronograma_importacao_id')
                ->constrained('cronograma_importacoes')
                ->restrictOnDelete();

            $table->string('titulo')->nullable();

            $table->foreignUlid('criado_por')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('emitido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('emitido_em')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['obra_id', 'periodo_referencia']);
            $table->index(['obra_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
