<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curva_ajustes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->string('serie');
            $table->string('granularidade');
            $table->date('periodo_inicio');
            $table->decimal('valor_ajustado', 12, 2);
            $table->decimal('valor_calculado_no_ajuste', 12, 2)->nullable();
            $table->string('motivo')->nullable();
            $table->foreignUlid('ajustado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['obra_id', 'serie', 'granularidade', 'periodo_inicio'],
                'curva_ajuste_unico'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curva_ajustes');
    }
};
