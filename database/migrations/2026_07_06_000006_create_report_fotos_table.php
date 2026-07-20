<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Galeria única por REPORT (não por curva), conforme pedido do usuário.
        Schema::create('report_fotos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('report_id')->constrained('reports')->cascadeOnDelete();

            $table->string('caminho_arquivo');
            $table->text('legenda')->nullable();
            $table->unsignedInteger('ordem')->default(0);
            $table->foreignUlid('enviado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['report_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_fotos');
    }
};
