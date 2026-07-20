<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_suprimento_comentarios', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_suprimento_id')->constrained('itens_suprimento')->cascadeOnDelete();
            $table->foreignUlid('autor_id')->constrained('users')->restrictOnDelete();

            $table->text('comentario');

            $table->index(['tenant_id', 'item_suprimento_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_suprimento_comentarios');
    }
};
