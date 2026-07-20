<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_suprimento', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('fluxo_suprimento_id')->nullable()
                ->constrained('fluxos_suprimento')->nullOnDelete();
            $table->foreignUlid('fornecedor_id')->nullable()
                ->constrained('fornecedores')->nullOnDelete();
            $table->foreignUlid('responsavel_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('nome');
            $table->string('codigo')->nullable();
            $table->string('status')->default('no_inicio'); // Valores: StatusItemSuprimento enum
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'obra_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_suprimento');
    }
};
