<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_suprimento_atividades', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_suprimento_id')->constrained('itens_suprimento')->cascadeOnDelete();
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['item_suprimento_id', 'atividade_id'], 'item_suprimento_atividades_unique');
            $table->index(['tenant_id', 'atividade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_suprimento_atividades');
    }
};
