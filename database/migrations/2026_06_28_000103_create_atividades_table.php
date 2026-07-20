<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atividades', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('pacote_trabalho_id')->nullable()->constrained('pacotes_trabalho')->nullOnDelete();
            $table->foreignUlid('disciplina_id')->nullable()->constrained('disciplinas')->nullOnDelete();

            // Responsável pela execução (usuário do sistema)
            $table->foreignUlid('responsavel_id')->nullable()->constrained('users')->nullOnDelete();

            // Autoria: quem criou o registro
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('nome');
            $table->unsignedSmallInteger('duracao_dias')->nullable();
            $table->date('inicio_planejado')->nullable();
            $table->date('fim_planejado')->nullable();
            $table->string('status')->default('planejado'); // Valores: StatusAtividade enum
            $table->boolean('caminho_critico')->default(false);

            $table->index(['tenant_id', 'obra_id']);
            $table->index(['tenant_id', 'status']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atividades');
    }
};
