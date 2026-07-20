<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feriados', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->date('data');
            $table->string('descricao')->nullable();
            $table->timestamps();

            $table->unique(['obra_id', 'data']);
            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feriados');
    }
};
