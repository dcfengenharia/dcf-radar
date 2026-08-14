<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5, Etapa C2 — Impacto de Restrições. Snapshot 1:1 por ReportDesvio
 * (nível pai ou filha), congelado no momento da geração do Report — nunca
 * relido do estado vivo de Restricao depois (que é mutável: reabertura
 * apaga resolvida_em). Ver App\Services\ImpactoRestricoesGerador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_desvio_restricoes', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('report_desvio_id')->unique()->constrained('report_desvios')->cascadeOnDelete();

            $table->unsignedInteger('total_abertas')->default(0);
            $table->unsignedInteger('total_vencidas')->default(0);
            $table->unsignedInteger('total_criticas')->default(0);
            $table->json('detalhes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_desvio_restricoes');
    }
};
