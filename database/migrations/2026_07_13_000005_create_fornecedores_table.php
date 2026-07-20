<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fornecedores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->string('nome');
            $table->string('cnpj')->nullable();
            $table->string('contato_nome')->nullable();
            $table->string('contato_email')->nullable();
            $table->string('contato_telefone')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'obra_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fornecedores');
    }
};
