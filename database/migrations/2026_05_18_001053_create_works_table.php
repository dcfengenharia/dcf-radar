<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('works', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // CHAVES ESTRANGEIRAS (Segurança de Relacionamento)
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete(); // Isolamento Multi-tenant
            $table->foreignUlid('client_id')->constrained()->cascadeOnDelete(); // Dono da obra (Incorporadora/Cliente)

            // DADOS GERAIS DA OBRA
            $table->string('name'); // Nome do Empreendimento / Obra
            $table->string('location')->nullable(); // Localização / Endereço / Cidade

            // INFORMAÇÕES VITAIS DE ENGENHARIA (Para cruzamento com o MS Project)
            $table->decimal('budget_total', 15, 2)->nullable(); // Orçamento total previsto em contrato
            $table->date('start_date_baseline')->nullable(); // Data de Início Planejada (Linha de Base)
            $table->date('end_date_baseline')->nullable(); // Data de Término Planejada (Linha de Base)

            // STATUS DO PROJETO
            $table->enum('status', ['planejamento', 'em_andamento', 'paralisada', 'concluida'])->default('planejamento');

            $table->timestamps();

            // Índices para otimizar buscas complexas que cruzam empresa e cliente
            $table->index(['tenant_id', 'client_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('works');
    }
};
