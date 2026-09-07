<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 23, Etapa 23.2 — evidências/anexos de uma lição. Espelha
 * `atividade_anexos` (Ciclo 17, A.7.1) — mesmo padrão de disco privado
 * (`local`, nunca público), mesmos campos (nome original/mime/tamanho/
 * quem enviou). `licao_aprendida_id` é `cascadeOnDelete()` (a evidência
 * é parte da própria lição, morre com ela — só Rascunho/EmValidacao são
 * excluíveis, então nunca cascateia sobre uma lição Publicada/Arquivada
 * na prática, já bloqueado por `LicaoAprendidaObserver`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licao_aprendida_evidencias', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('licao_aprendida_id')->constrained('licoes_aprendidas')->cascadeOnDelete();

            $table->string('nome_original');
            $table->string('caminho_arquivo');
            $table->string('mime_type');
            $table->unsignedBigInteger('tamanho_bytes');

            $table->foreignUlid('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'licao_aprendida_id'], 'licao_evidencias_tenant_licao_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licao_aprendida_evidencias');
    }
};
