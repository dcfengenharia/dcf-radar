<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_restricao', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('nome');
            $table->string('pilar_lean'); // Valores: PilarLean enum

            $table->index(['tenant_id', 'id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_restricao');
    }
};
