<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atividade_itens_prontidao', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();
            $table->foreignUlid('item_prontidao_id')->constrained('itens_prontidao')->cascadeOnDelete();
            $table->boolean('concluido')->default(false);
            $table->foreignUlid('concluido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();

            $table->unique(['atividade_id', 'item_prontidao_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atividade_itens_prontidao');
    }
};
