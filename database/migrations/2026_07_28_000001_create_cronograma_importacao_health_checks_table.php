<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nome da tabela é longo — nomes de constraint/index default do
        // Laravel estourariam o limite de 64 chars do MySQL (mesmo problema
        // já documentado no projeto), por isso os nomes abaixo são explícitos.
        Schema::create('cronograma_importacao_health_checks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cronograma_importacao_id');
            $table->unsignedInteger('total_criticos')->default(0);
            $table->unsignedInteger('total_altos')->default(0);
            $table->unsignedInteger('total_medios')->default(0);
            $table->unsignedInteger('total_baixos')->default(0);
            $table->unsignedInteger('total_informativos')->default(0);
            $table->unsignedInteger('total_ocorrencias')->default(0);
            $table->boolean('importado_com_alertas')->default(false);
            $table->string('versao_regras')->default('1.0');
            $table->json('findings');
            $table->timestamp('analisado_em');
            $table->timestamps();

            $table->foreign('cronograma_importacao_id', 'cih_importacao_id_fk')
                ->references('id')->on('cronograma_importacoes')->cascadeOnDelete();
            $table->unique('cronograma_importacao_id', 'cih_importacao_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cronograma_importacao_health_checks');
    }
};
