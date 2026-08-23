<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 17, A.9.3 — Fotografia O: o que a PLATAFORMA sabia sobre uma
     * atividade no instante em que uma importação de Avanço/Ambos foi
     * aplicada. Irmã da Fotografia F (`atividade_snapshots`, A.9.2) — nunca
     * confundida com ela, nunca a altera. F = o que o arquivo declarou;
     * O = o que a plataforma tinha registrado até aquele momento
     * (Restrição/Prontidão/status operacional). Só gravada para tipo
     * Avanço/Ambos (Baseline pura não recebe Fotografia O — ver
     * MsProjectImporter::aplicar() e CLAUDE.md).
     *
     * 3 tabelas, sempre por (cronograma_importacao_id, atividade_id):
     * - atividade_snapshot_operacionais: 1 linha por atividade tocada,
     *   sempre criada (mesmo sem nenhuma pendência) — status/
     *   fora_do_cronograma capturados ANTES desta importação tocar a
     *   atividade (null para atividade recém-criada nesta própria
     *   importação, que genuinamente não tinha "antes"); `pronta` é o
     *   resultado congelado da MESMA regra canônica de
     *   Atividade::scopeProntas(), nunca reimplementada.
     * - atividade_snapshot_restricoes: 1 linha POR restrição que estava
     *   ABERTA (aberta/em_tratamento/aguardando_terceiros) naquele
     *   instante — nunca todas as restrições históricas (só pendências,
     *   decisão registrada no relatório da A.9.3). Preserva restricao_id
     *   original.
     * - atividade_snapshot_prontidao: 1 linha POR item de prontidão que
     *   estava PENDENTE (sem row concluído=true) naquele instante — nunca
     *   todos os itens. Preserva item_prontidao_id e, quando existia,
     *   atividade_item_prontidao_id (nullable — pode nunca ter existido
     *   uma row pra esse par atividade×item).
     */
    public function up(): void
    {
        Schema::create('atividade_snapshot_operacionais', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cronograma_importacao_id')->constrained('cronograma_importacoes')->cascadeOnDelete();
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();
            $table->string('status')->nullable();
            $table->boolean('fora_do_cronograma')->nullable();
            $table->boolean('pronta');
            $table->timestamps();

            $table->unique(['cronograma_importacao_id', 'atividade_id'], 'asop_importacao_atividade_unique');
            $table->index('atividade_id', 'asop_atividade_idx');
        });

        Schema::create('atividade_snapshot_restricoes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cronograma_importacao_id')->constrained('cronograma_importacoes')->cascadeOnDelete();
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();
            $table->foreignUlid('restricao_id')->constrained('restricoes')->cascadeOnDelete();
            $table->boolean('bloqueante');
            $table->string('status');
            $table->timestamps();

            $table->unique(['cronograma_importacao_id', 'atividade_id', 'restricao_id'], 'asor_importacao_atividade_restricao_unique');
            $table->index('atividade_id', 'asor_atividade_idx');
            $table->index('restricao_id', 'asor_restricao_idx');
        });

        Schema::create('atividade_snapshot_prontidao', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cronograma_importacao_id')->constrained('cronograma_importacoes')->cascadeOnDelete();
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();
            $table->foreignUlid('item_prontidao_id')->constrained('itens_prontidao')->cascadeOnDelete();
            $table->foreignUlid('atividade_item_prontidao_id')->nullable()->constrained('atividade_itens_prontidao')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cronograma_importacao_id', 'atividade_id', 'item_prontidao_id'], 'aspr_importacao_atividade_item_unique');
            $table->index('atividade_id', 'aspr_atividade_idx');
            $table->index('item_prontidao_id', 'aspr_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atividade_snapshot_prontidao');
        Schema::dropIfExists('atividade_snapshot_restricoes');
        Schema::dropIfExists('atividade_snapshot_operacionais');
    }
};
