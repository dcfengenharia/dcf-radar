<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cronograma_importacoes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('arquivo')->nullable();
            $table->date('data_status')->nullable();
            $table->string('metodo_distribuicao')->nullable();
            $table->unsignedInteger('criadas')->default(0);
            $table->unsignedInteger('atualizadas')->default(0);
            $table->unsignedInteger('removidas')->default(0);
            $table->timestamp('importado_em');
            $table->timestamps();
            $table->index(['tenant_id', 'obra_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cronograma_importacoes');
    }
};
