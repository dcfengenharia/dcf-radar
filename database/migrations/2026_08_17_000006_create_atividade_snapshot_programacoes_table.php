<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 17, A.9.5 — Fotografia P: congela, por importação de Avanço/
     * Ambos, se uma atividade que INICIOU ou CONCLUIU nesta importação
     * (Fotografia F) fazia parte da Programação Semanal historicamente
     * aplicável ao instante desse evento. Irmã de F (`atividade_snapshots`,
     * A.9.2) e O (`atividade_snapshot_operacionais`, A.9.3) — nunca as
     * altera, nunca é alterada por elas. 1 linha por (importação, atividade,
     * evento) — até 2 linhas por atividade na mesma importação (início E
     * conclusão são fatos independentes, mesmo princípio já usado pelo
     * Detector).
     *
     * `evento` distingue qual data factual foi usada (`real_inicio` pra
     * `inicio`, `real_termino` pra `conclusao`) — nunca a mesma linha serve
     * pros dois.
     *
     * `programacao_semanal_id`/`programacao_semanal_item_id` são
     * NULLABLE + `nullOnDelete()` — NUNCA `cascadeOnDelete()` (lição da
     * A.9.3.CORREÇÃO aplicada desde o início aqui): editar/remover a
     * Programação Semanal depois não pode destruir silenciosamente esta
     * fotografia. `programacao_semanal_versao` é denormalizado
     * (`unsignedInteger`, não FK) precisamente pra a fotografia continuar
     * explicável mesmo se o cabeçalho referenciado desaparecer.
     *
     * `atividade_estava_na_programacao` nunca é `NULL` — é sempre
     * calculável (existe evidência de comprometimento ou não) uma vez que
     * `programacao_semanal_id` já foi resolvido; quando
     * `programacao_semanal_id` é `NULL` (nenhuma programação existia pra
     * aquela semana), `atividade_estava_na_programacao` é sempre `false`
     * por definição — não existe "estava" sem programação nenhuma pra
     * estar.
     *
     * Nomes de constraint explícitos e curtos em toda parte — o nome
     * completo da tabela (`atividade_snapshot_programacoes`) estoura os 64
     * caracteres do MySQL nos nomes automáticos do Laravel pra várias
     * dessas FKs (mesma classe de problema já documentada no projeto em
     * `feedback_limite_identificador_mysql`).
     */
    public function up(): void
    {
        Schema::create('atividade_snapshot_programacoes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('cronograma_importacao_id');
            $table->ulid('atividade_id');
            $table->string('evento'); // App\Enums\EventoFotografiaProgramacao: inicio|conclusao
            $table->date('data_factual');
            $table->date('semana_inicio_resolvida');
            $table->ulid('programacao_semanal_id')->nullable();
            $table->unsignedInteger('programacao_semanal_versao')->nullable();
            $table->boolean('atividade_estava_na_programacao');
            $table->ulid('programacao_semanal_item_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id', 'aspg_tenant_id_fk')
                ->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('cronograma_importacao_id', 'aspg_importacao_id_fk')
                ->references('id')->on('cronograma_importacoes')->cascadeOnDelete();
            $table->foreign('atividade_id', 'aspg_atividade_id_fk')
                ->references('id')->on('atividades')->cascadeOnDelete();
            $table->foreign('programacao_semanal_id', 'aspg_programacao_id_fk')
                ->references('id')->on('programacoes_semanais')->nullOnDelete();
            $table->foreign('programacao_semanal_item_id', 'aspg_programacao_item_id_fk')
                ->references('id')->on('programacao_semanal_itens')->nullOnDelete();

            $table->unique(
                ['cronograma_importacao_id', 'atividade_id', 'evento'],
                'aspg_importacao_atividade_evento_unique'
            );
            $table->index('atividade_id', 'aspg_atividade_idx');
            $table->index('programacao_semanal_id', 'aspg_programacao_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atividade_snapshot_programacoes');
    }
};
