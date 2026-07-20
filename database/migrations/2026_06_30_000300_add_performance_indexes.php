<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes para as queries mais frequentes do Quadro de Restrições.
 * Não altera estrutura — apenas acrescenta índices.
 */
return new class extends Migration
{
    public function up(): void
    {
        // restricoes ----------------------------------------------------------
        Schema::table('restricoes', function (Blueprint $table) {
            // Filtro principal: busca de restrições bloqueantes abertas por atividade
            $table->index(['atividade_id', 'status', 'bloqueante'], 'idx_restricoes_atv_status_bloq');
            // Ordenação padrão
            $table->index('prazo_limite', 'idx_restricoes_prazo');
            // Cálculo de risco P×I (usado no ORDER BY da view)
            $table->index(['probabilidade', 'impacto'], 'idx_restricoes_risco');
        });

        // atividades ----------------------------------------------------------
        Schema::table('atividades', function (Blueprint $table) {
            // Query mais comum: todas as atividades de uma obra excluindo arquivadas
            $table->index(['obra_id', 'fora_do_cronograma'], 'idx_atividades_obra_arquivadas');
            // Filtro de datas de execução no Quadro de Restrições
            $table->index(['obra_id', 'inicio_planejado', 'data_termino'], 'idx_atividades_obra_datas');
        });

        // atividade_itens_prontidao -------------------------------------------
        Schema::table('atividade_itens_prontidao', function (Blueprint $table) {
            // Contagem de itens concluídos por atividade (estava causando N+1)
            $table->index(['atividade_id', 'concluido'], 'idx_aip_atv_concluido');
        });
    }

    public function down(): void
    {
        Schema::table('restricoes', function (Blueprint $table) {
            $table->dropIndex('idx_restricoes_atv_status_bloq');
            $table->dropIndex('idx_restricoes_prazo');
            $table->dropIndex('idx_restricoes_risco');
        });

        Schema::table('atividades', function (Blueprint $table) {
            $table->dropIndex('idx_atividades_obra_arquivadas');
            $table->dropIndex('idx_atividades_obra_datas');
        });

        Schema::table('atividade_itens_prontidao', function (Blueprint $table) {
            $table->dropIndex('idx_aip_atv_concluido');
        });
    }
};
