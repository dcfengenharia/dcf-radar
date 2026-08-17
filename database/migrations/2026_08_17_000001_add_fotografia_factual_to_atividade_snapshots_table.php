<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 17, A.9.2 — Fotografia F: o que o cronograma declarou nesta
     * importação (percentual_concluido/real_inicio/real_termino), separado
     * do estado operacional da plataforma (Restricao/AtividadeItemProntidao,
     * nunca tocados aqui). Mesmos tipos/nullability já usados nas colunas
     * equivalentes de `atividades` (2026_06_28_000111 e 2026_07_02_000105).
     * Snapshots já existentes (criados antes desta migration) ficam com os
     * 3 campos novos em NULL — nunca reconstruídos a partir da Atividade
     * viva, que representaria o estado ATUAL, não o fato daquela importação
     * histórica específica.
     */
    public function up(): void
    {
        Schema::table('atividade_snapshots', function (Blueprint $table) {
            $table->decimal('percentual_concluido', 5, 2)->nullable()->after('baseline_termino');
            $table->date('real_inicio')->nullable()->after('percentual_concluido');
            $table->date('real_termino')->nullable()->after('real_inicio');
        });
    }

    public function down(): void
    {
        Schema::table('atividade_snapshots', function (Blueprint $table) {
            $table->dropColumn(['percentual_concluido', 'real_inicio', 'real_termino']);
        });
    }
};
