<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_curvas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('report_id')->constrained('reports')->cascadeOnDelete();

            // null = curva da obra inteira (pacote raiz "virtual").
            $table->foreignUlid('pacote_trabalho_id')->nullable()->constrained('pacotes_trabalho')->nullOnDelete();

            $table->unsignedInteger('ordem')->default(0);

            // Snapshot do nome/código do pacote no momento da geração — não
            // muda se o pacote for renomeado depois.
            $table->string('titulo_exibicao');

            $table->date('termino_linha_base')->nullable();
            $table->date('termino_tendencia')->nullable();

            // Total de HH da linha de base deste pacote (+ descendentes) —
            // denominador usado pro "peso" dos filhos em report_desvios.
            $table->decimal('total_hh_previsto', 12, 2)->default(0);

            $table->timestamps();

            $table->index(['report_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_curvas');
    }
};
