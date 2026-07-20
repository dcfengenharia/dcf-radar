<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_pontos_atencao', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('report_curva_id')->constrained('report_curvas')->cascadeOnDelete();

            // Texto livre, não enum — o usuário digita "SUPRIMENTOS",
            // "ENGENHARIA" etc. se quiser, sem taxonomia fixa.
            $table->string('categoria')->nullable();
            $table->text('texto');
            $table->unsignedInteger('ordem')->default(0);

            $table->timestamps();

            $table->index(['report_curva_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_pontos_atencao');
    }
};
