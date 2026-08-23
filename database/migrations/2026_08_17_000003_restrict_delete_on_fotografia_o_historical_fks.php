<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 17, A.9.3.CORREÇÃO — blindagem de imutabilidade da Fotografia O.
     *
     * A auditoria adversarial da A.9.3 provou empiricamente (forceDelete()
     * real dentro de uma transação com rollback) que `cascadeOnDelete()` em
     * `restricao_id`/`item_prontidao_id` apaga a linha histórica
     * correspondente se a Restrição/ItemProntidao original for hard-deleted
     * — incompatível com o propósito da Fotografia O ("registrar de forma
     * histórica e imutável o que a plataforma sabia no instante de uma
     * importação"). Troca SÓ essas 2 FKs pra `restrictOnDelete()`: soft
     * delete continua funcionando normalmente (Restricao/ItemProntidao usam
     * SoftDeletes, que nunca dispara a FK — só um UPDATE deleted_at); um
     * hard delete (`forceDelete()`) de uma entidade ainda referenciada por
     * alguma Fotografia O passa a ser REJEITADO pelo banco
     * (QueryException 1451), preservando tanto a linha histórica quanto o
     * ID original — nenhum campo vira nullable.
     *
     * Migration puramente de constraint — nenhuma coluna, índice, unique ou
     * dado é tocado ou recriado. Não altera `atividade_id`/
     * `cronograma_importacao_id`/`tenant_id` (mesmo padrão dormente já
     * usado em Fotografia F/`atividade_snapshots` e no resto do projeto,
     * fora de escopo desta correção pontual) nem
     * `atividade_item_prontidao_id` (`nullOnDelete()` continua correto —
     * é só um ponteiro opcional de rastreabilidade, não a identidade do
     * fato registrado).
     */
    public function up(): void
    {
        Schema::table('atividade_snapshot_restricoes', function (Blueprint $table) {
            $table->dropForeign('atividade_snapshot_restricoes_restricao_id_foreign');
            $table->foreign('restricao_id', 'atividade_snapshot_restricoes_restricao_id_foreign')
                ->references('id')->on('restricoes')
                ->restrictOnDelete();
        });

        Schema::table('atividade_snapshot_prontidao', function (Blueprint $table) {
            $table->dropForeign('atividade_snapshot_prontidao_item_prontidao_id_foreign');
            $table->foreign('item_prontidao_id', 'atividade_snapshot_prontidao_item_prontidao_id_foreign')
                ->references('id')->on('itens_prontidao')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('atividade_snapshot_restricoes', function (Blueprint $table) {
            $table->dropForeign('atividade_snapshot_restricoes_restricao_id_foreign');
            $table->foreign('restricao_id', 'atividade_snapshot_restricoes_restricao_id_foreign')
                ->references('id')->on('restricoes')
                ->cascadeOnDelete();
        });

        Schema::table('atividade_snapshot_prontidao', function (Blueprint $table) {
            $table->dropForeign('atividade_snapshot_prontidao_item_prontidao_id_foreign');
            $table->foreign('item_prontidao_id', 'atividade_snapshot_prontidao_item_prontidao_id_foreign')
                ->references('id')->on('itens_prontidao')
                ->cascadeOnDelete();
        });
    }
};
