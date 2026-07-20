<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_engenharia', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('pacote_engenharia_id')->constrained('pacotes_engenharia')->cascadeOnDelete();
            $table->string('nome');
            $table->date('data_planejada')->nullable();
            $table->date('data_realizada')->nullable();
            $table->string('status')->default('em_elaboracao'); // Valores: StatusDocumentoEngenharia enum
            $table->unsignedSmallInteger('ordem')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'pacote_engenharia_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_engenharia');
    }
};
