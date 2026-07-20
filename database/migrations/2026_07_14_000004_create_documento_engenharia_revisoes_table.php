<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documento_engenharia_revisoes', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('documento_engenharia_id')->constrained('documentos_engenharia')->cascadeOnDelete();

            $table->string('revisao');
            $table->date('data_emissao');
            $table->string('descricao');
            $table->text('comentarios')->nullable();
            $table->string('anexo_path')->nullable();
            $table->string('anexo_nome_original')->nullable();

            $table->foreignUlid('criado_por_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['documento_engenharia_id', 'revisao'], 'doc_engenharia_revisoes_doc_revisao_unique');
            $table->index(['tenant_id', 'documento_engenharia_id'], 'doc_engenharia_revisoes_tenant_doc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_engenharia_revisoes');
    }
};
