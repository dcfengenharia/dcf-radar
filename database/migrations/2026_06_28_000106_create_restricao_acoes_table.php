<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restricao_acoes', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('restricao_id')->constrained('restricoes')->cascadeOnDelete();
            $table->foreignUlid('autor_id')->constrained('users')->restrictOnDelete();

            $table->text('descricao');

            $table->index(['tenant_id', 'restricao_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restricao_acoes');
    }
};
