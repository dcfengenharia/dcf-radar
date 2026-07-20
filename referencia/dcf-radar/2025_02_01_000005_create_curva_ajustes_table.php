<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes manuais da curva, no nível em que o usuário a enxerga
 * (obra × série × granularidade × período). Sobrepõe o valor calculado
 * sem destruí-lo, para o usuário "bater" exatamente o número do MS Project.
 *
 * Guarda também o valor calculado no momento do ajuste, para detectar
 * quando uma reimportação muda a base e o ajuste pode ter ficado obsoleto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curva_ajustes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained()->cascadeOnDelete();
            $table->string('serie');          // previsto | tendencia | realizado
            $table->string('granularidade');  // semanal | mensal
            $table->date('periodo_inicio');
            $table->decimal('valor_ajustado', 12, 2);
            $table->decimal('valor_calculado_no_ajuste', 12, 2)->nullable();
            $table->string('motivo')->nullable();
            $table->foreignUlid('ajustado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['obra_id', 'serie', 'granularidade', 'periodo_inicio'], 'curva_ajuste_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curva_ajustes');
    }
};
