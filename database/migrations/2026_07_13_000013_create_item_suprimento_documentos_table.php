<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nome curto de propósito: "item_suprimento_documentos_engenharia"
        // gera nomes de índice/FK automáticos que passam do limite de 64
        // caracteres do MySQL.
        Schema::create('item_suprimento_documentos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_suprimento_id')->constrained('itens_suprimento')->cascadeOnDelete();
            $table->foreignUlid('documento_engenharia_id')->constrained('documentos_engenharia')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['item_suprimento_id', 'documento_engenharia_id'], 'item_suprimento_documentos_unique');
            $table->index(['tenant_id', 'documento_engenharia_id'], 'item_suprimento_documentos_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_suprimento_documentos');
    }
};
