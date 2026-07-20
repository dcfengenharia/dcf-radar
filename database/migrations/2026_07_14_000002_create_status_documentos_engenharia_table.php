<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_documentos_engenharia', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            $table->string('nome');
            $table->string('cor', 7)->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('conclusivo')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['obra_id', 'nome']);
            $table->index(['tenant_id', 'obra_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_documentos_engenharia');
    }
};
