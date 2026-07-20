<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programacoes_semanais', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->date('semana_inicio');
            $table->date('semana_fim');
            $table->timestamp('congelada_em');
            $table->foreignUlid('criado_por')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'obra_id']);
            $table->unique(['obra_id', 'semana_inicio'], 'programacoes_semanais_obra_semana_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programacoes_semanais');
    }
};
