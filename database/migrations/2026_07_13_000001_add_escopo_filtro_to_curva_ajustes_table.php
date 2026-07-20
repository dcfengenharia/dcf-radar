<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curva_ajustes', function (Blueprint $table) {
            $table->foreignUlid('pacote_trabalho_id')->nullable()->after('periodo_inicio')
                ->constrained('pacotes_trabalho')->cascadeOnDelete();
            $table->foreignUlid('etapa_id')->nullable()->after('pacote_trabalho_id')
                ->constrained('etapas')->cascadeOnDelete();
            $table->foreignUlid('disciplina_id')->nullable()->after('etapa_id')
                ->constrained('disciplinas')->cascadeOnDelete();
            $table->foreignUlid('frente_trabalho_id')->nullable()->after('disciplina_id')
                ->constrained('frentes_trabalho')->cascadeOnDelete();

            // A ordem importa: 'curva_ajuste_unico' (obra_id, ...) é hoje o
            // único índice cobrindo a FK de obra_id — o MySQL não deixa
            // derrubá-lo antes de existir outro índice que sirva de suporte
            // pra essa FK. Como o novo índice também começa por obra_id,
            // criar ele PRIMEIRO resolve isso; só depois dropa o antigo.
            $table->unique(
                ['obra_id', 'serie', 'granularidade', 'periodo_inicio', 'pacote_trabalho_id', 'etapa_id', 'disciplina_id', 'frente_trabalho_id'],
                'curva_ajuste_escopo_unico'
            );
            $table->dropUnique('curva_ajuste_unico');
        });
    }

    public function down(): void
    {
        Schema::table('curva_ajustes', function (Blueprint $table) {
            $table->unique(
                ['obra_id', 'serie', 'granularidade', 'periodo_inicio'],
                'curva_ajuste_unico'
            );
            $table->dropUnique('curva_ajuste_escopo_unico');

            $table->dropForeign(['pacote_trabalho_id']);
            $table->dropForeign(['etapa_id']);
            $table->dropForeign(['disciplina_id']);
            $table->dropForeign(['frente_trabalho_id']);
            $table->dropColumn(['pacote_trabalho_id', 'etapa_id', 'disciplina_id', 'frente_trabalho_id']);
        });
    }
};
