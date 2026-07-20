<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linhas_base', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works');
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->foreignUlid('cronograma_importacao_id')
                  ->constrained('cronograma_importacoes')
                  ->restrictOnDelete();
            $table->foreignUlid('criado_por')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'obra_id']);
            $table->unique(['obra_id', 'cronograma_importacao_id'], 'linha_base_import_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linhas_base');
    }
};
