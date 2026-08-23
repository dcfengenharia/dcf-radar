<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 17, A.9.4 — núcleo persistente do Detector de Inconsistências de
     * Avanço: compara Fotografia F (o que o cronograma declarou NESTA
     * importação) com Fotografia O (o que a plataforma sabia IMEDIATAMENTE
     * ANTES dela) e registra as divergências como evidência, nunca como
     * correção automática. Ver App\Services\DetectorInconsistenciasAvanco.
     *
     * `entidade_id` é histórico e deliberadamente SEM FK física — como pode
     * apontar tanto para `restricoes.id` quanto `itens_prontidao.id`
     * (discriminado por `entidade_tipo`), uma FK real não é possível de
     * qualquer forma (span de 2 tabelas), e mesmo se fosse, a A.9.3.CORREÇÃO
     * acabou de estabelecer que referência histórica não pode usar
     * `cascadeOnDelete()` — o ULID puro, sem FK, elimina esse risco por
     * construção.
     *
     * `entidade_tipo`/`entidade_id` são NOT NULL (nunca nullable) de
     * propósito: todos os 4 tipos desta fase (A-D) estão sempre ligados a
     * uma entidade concreta (Restricao ou ItemProntidao) — deixar essas
     * colunas nullable abriria a brecha clássica do MySQL de múltiplos NULL
     * "iguais" dentro do unique, permitindo duplicação silenciosa.
     */
    public function up(): void
    {
        Schema::create('inconsistencias_avanco', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();
            $table->foreignUlid('cronograma_importacao_id')->constrained('cronograma_importacoes')->cascadeOnDelete();
            $table->string('tipo');
            $table->string('severidade');
            $table->string('entidade_tipo');
            $table->ulid('entidade_id');
            $table->string('titulo');
            $table->json('detalhes');
            $table->timestamp('detectada_em');
            $table->timestamps();

            $table->unique(
                ['cronograma_importacao_id', 'atividade_id', 'tipo', 'entidade_tipo', 'entidade_id'],
                'incav_importacao_atividade_tipo_entidade_unique'
            );
            $table->index('atividade_id', 'incav_atividade_idx');
            $table->index('obra_id', 'incav_obra_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inconsistencias_avanco');
    }
};
