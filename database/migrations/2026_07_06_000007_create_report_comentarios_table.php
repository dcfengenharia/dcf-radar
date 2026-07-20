<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Espelha atividade_comentarios exatamente.
        Schema::create('report_comentarios', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('report_id')->constrained('reports')->cascadeOnDelete();
            $table->foreignUlid('autor_id')->constrained('users')->restrictOnDelete();
            $table->text('comentario');

            $table->timestamps();

            $table->index(['tenant_id', 'report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_comentarios');
    }
};
