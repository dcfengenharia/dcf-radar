<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documento_engenharia_reprogramacoes', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('documento_engenharia_id')
                ->constrained('documentos_engenharia', 'id', 'doc_engenharia_reprogramacoes_documento_fk')
                ->cascadeOnDelete();

            $table->date('data_anterior')->nullable();
            $table->date('data_nova');

            $table->foreignUlid('criado_por_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'documento_engenharia_id'], 'doc_engenharia_reprogramacoes_tenant_doc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_engenharia_reprogramacoes');
    }
};
