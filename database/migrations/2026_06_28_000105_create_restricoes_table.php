<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restricoes', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();
            $table->foreignUlid('categoria_id')->nullable()->constrained('categorias_restricao')->nullOnDelete();

            // Responsável pela resolução
            $table->foreignUlid('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsavel_externo')->nullable(); // Para responsáveis fora do sistema

            // Autoria
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('descricao');
            $table->boolean('bloqueante')->default(true);

            // Matriz de risco P×I (0 a 10)
            $table->unsignedTinyInteger('probabilidade')->nullable();
            $table->unsignedTinyInteger('impacto')->nullable();

            $table->date('prazo_limite')->nullable();
            $table->string('status')->default('aberta'); // Valores: StatusRestricao enum
            $table->timestamp('aberta_em')->nullable();
            $table->timestamp('resolvida_em')->nullable();

            $table->index(['tenant_id', 'atividade_id']);
            $table->index(['tenant_id', 'status']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restricoes');
    }
};
