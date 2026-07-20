<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_suprimento_etapa_datas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_suprimento_etapa_id')->constrained('itens_suprimento_etapas')->cascadeOnDelete();
            $table->string('serie'); // Valores: App\Enums\SerieAvanco (reaproveitado, não duplicado)
            $table->date('data')->nullable();
            $table->foreignUlid('atualizado_por')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_suprimento_etapa_id', 'serie'], 'itens_suprimento_etapa_datas_serie_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_suprimento_etapa_datas');
    }
};
