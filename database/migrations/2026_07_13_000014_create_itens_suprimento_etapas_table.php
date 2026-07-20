<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_suprimento_etapas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_suprimento_id')->constrained('itens_suprimento')->cascadeOnDelete();
            $table->foreignUlid('etapa_fluxo_suprimento_id')->nullable()
                ->constrained('etapas_fluxo_suprimento')->nullOnDelete();
            $table->unsignedSmallInteger('ordem');
            $table->string('nome');
            $table->unsignedSmallInteger('prazo_dias_uteis');
            $table->boolean('nao_aplicavel')->default(false);
            $table->timestamps();

            $table->unique(['item_suprimento_id', 'ordem'], 'itens_suprimento_etapas_ordem_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_suprimento_etapas');
    }
};
