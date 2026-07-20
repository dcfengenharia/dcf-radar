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
        Schema::create('clients', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // CHAVE ESTRANGEIRA (Isolamento Multi-tenant)
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            // DADOS DE IDENTIFICAÇÃO
            $table->string('name'); // Razão Social / Nome Completo
            $table->string('trading_name')->nullable(); // Nome Fantasia
            $table->string('cnpj', 18)->nullable(); // CNPJ com máscara (00.000.000/0000-00)

            // ARQUIVO VISUAL (Logo)
            // $table->string('logo_path')->nullable(); // Caminho do arquivo da logo no storage

            // CONTATOS
            $table->string('email')->nullable();
            $table->string('phone', 15)->nullable();

            // ÍNDICE DE PERFORMANCE
            // Otimiza a busca de clientes que pertencem à empresa logada
            $table->index(['tenant_id', 'id']);

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
