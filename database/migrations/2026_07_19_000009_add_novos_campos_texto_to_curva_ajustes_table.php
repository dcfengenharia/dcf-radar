<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curva_ajustes', function (Blueprint $table) {
            $table->foreignUlid('entregavel_id')->nullable()->after('frente_trabalho_id')
                ->constrained('entregaveis')->cascadeOnDelete();
            $table->foreignUlid('equipe_responsavel_id')->nullable()->after('entregavel_id')
                ->constrained('equipes_responsaveis')->cascadeOnDelete();
            $table->foreignUlid('personalizado_1_id')->nullable()->after('equipe_responsavel_id')
                ->constrained('personalizados_1')->cascadeOnDelete();
            $table->foreignUlid('personalizado_2_id')->nullable()->after('personalizado_1_id')
                ->constrained('personalizados_2')->cascadeOnDelete();
            $table->foreignUlid('personalizado_3_id')->nullable()->after('personalizado_2_id')
                ->constrained('personalizados_3')->cascadeOnDelete();
            $table->foreignUlid('personalizado_4_id')->nullable()->after('personalizado_3_id')
                ->constrained('personalizados_4')->cascadeOnDelete();
            $table->foreignUlid('personalizado_5_id')->nullable()->after('personalizado_4_id')
                ->constrained('personalizados_5')->cascadeOnDelete();
            $table->boolean('faturamento_direto')->nullable()->after('personalizado_5_id');

            // As 8 dimensões novas (7 FKs + booleano) não cabem cruas no índice
            // único junto das 8 colunas que já existiam: MySQL recusa com
            // "Specified key was too long; max key length is 3072 bytes"
            // (cada FK ULID em utf8mb4 pesa 104 bytes, e 'serie'/'granularidade'
            // já são strings largas). Em vez de indexar as 8 colunas novas
            // direto, guarda um hash MD5 (32 hex) da combinação delas — o
            // CurvaAjuste::booted() calcula esse hash sozinho a cada
            // save, então nunca precisa ser setado à mão.
            $table->string('escopo_extra_hash', 32)->nullable()->after('faturamento_direto');

            // A ordem importa (ver 2026_07_13_000001): cria o índice novo
            // (que também começa por obra_id) ANTES de derrubar o antigo.
            $table->unique([
                'obra_id', 'serie', 'granularidade', 'periodo_inicio',
                'pacote_trabalho_id', 'etapa_id', 'disciplina_id', 'frente_trabalho_id',
                'escopo_extra_hash',
            ], 'curva_ajuste_escopo_completo_unico');
            $table->dropUnique('curva_ajuste_escopo_unico');
        });
    }

    public function down(): void
    {
        Schema::table('curva_ajustes', function (Blueprint $table) {
            $table->unique(
                ['obra_id', 'serie', 'granularidade', 'periodo_inicio', 'pacote_trabalho_id', 'etapa_id', 'disciplina_id', 'frente_trabalho_id'],
                'curva_ajuste_escopo_unico'
            );
            $table->dropUnique('curva_ajuste_escopo_completo_unico');

            $table->dropForeign(['entregavel_id']);
            $table->dropForeign(['equipe_responsavel_id']);
            $table->dropForeign(['personalizado_1_id']);
            $table->dropForeign(['personalizado_2_id']);
            $table->dropForeign(['personalizado_3_id']);
            $table->dropForeign(['personalizado_4_id']);
            $table->dropForeign(['personalizado_5_id']);
            $table->dropColumn([
                'entregavel_id',
                'equipe_responsavel_id',
                'personalizado_1_id',
                'personalizado_2_id',
                'personalizado_3_id',
                'personalizado_4_id',
                'personalizado_5_id',
                'faturamento_direto',
                'escopo_extra_hash',
            ]);
        });
    }
};
