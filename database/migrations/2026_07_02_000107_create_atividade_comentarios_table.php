<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atividade_comentarios', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();
            $table->foreignUlid('autor_id')->constrained('users')->restrictOnDelete();

            $table->text('comentario');

            $table->index(['tenant_id', 'atividade_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atividade_comentarios');
    }
};
