<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_indicadores_semana', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('report_id')->constrained('reports')->cascadeOnDelete();

            $table->string('categoria'); // 'restricoes'|'engenharia'|'suprimentos'
            $table->string('janela'); // 'semana_anterior'|'semana_proxima'
            $table->date('periodo_inicio');
            $table->date('periodo_fim');

            $table->unsignedInteger('total_previsto');
            $table->unsignedInteger('total_concluido')->nullable(); // null quando janela=semana_proxima
            $table->json('detalhes')->nullable(); // linhas individuais congeladas, pra tabela

            $table->timestamps();

            $table->index(['report_id', 'categoria', 'janela']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_indicadores_semana');
    }
};
