<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacotes_engenharia', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'obra_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacotes_engenharia');
    }
};
