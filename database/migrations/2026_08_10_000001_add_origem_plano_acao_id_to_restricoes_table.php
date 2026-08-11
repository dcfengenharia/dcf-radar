<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restricoes', function (Blueprint $table) {
            $table->foreignUlid('origem_plano_acao_id')->nullable()
                ->after('origem_suprimento_item_id')
                ->constrained('planos_acao')->nullOnDelete();

            // Protege só a origem PlanoAcao: uma mesma combinação
            // (obra/tenant, PlanoAcao, Atividade) nunca gera 2 Restricoes.
            // NULL nunca colide entre si no MySQL, então Restrições manuais
            // e de Suprimento (origem_plano_acao_id sempre null) continuam
            // livres para coexistir na mesma atividade sem serem afetadas
            // por este índice (Ciclo 11, Etapa B).
            $table->unique(
                ['tenant_id', 'origem_plano_acao_id', 'atividade_id'],
                'restricoes_origem_plano_acao_atividade_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('restricoes', function (Blueprint $table) {
            $table->dropUnique('restricoes_origem_plano_acao_atividade_unique');
            $table->dropForeign(['origem_plano_acao_id']);
            $table->dropColumn('origem_plano_acao_id');
        });
    }
};
