<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_prontidao', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->string('nome');
            $table->integer('ordem')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'obra_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_prontidao');
    }
};
