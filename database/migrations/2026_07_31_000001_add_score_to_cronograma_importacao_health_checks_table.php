<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Todas nullable, sem default — registros de CronogramaImportacaoHealthCheck
        // criados ANTES da Fase 3 (Score) ficam com essas 7 colunas em NULL, de
        // propósito (sem backfill, sem Score retroativo inventado). Só
        // importações concluídas a partir de agora passam a gravar Score.
        Schema::table('cronograma_importacao_health_checks', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->nullable()->after('findings');
            $table->string('faixa_score')->nullable()->after('score');
            $table->unsignedTinyInteger('cobertura')->nullable()->after('faixa_score');
            $table->json('score_por_dimensao')->nullable()->after('cobertura');
            $table->json('mapa_acoes')->nullable()->after('score_por_dimensao');
            $table->unsignedTinyInteger('potencial_recuperavel')->nullable()->after('mapa_acoes');
            $table->string('versao_score')->nullable()->after('potencial_recuperavel');
        });
    }

    public function down(): void
    {
        Schema::table('cronograma_importacao_health_checks', function (Blueprint $table) {
            $table->dropColumn([
                'score',
                'faixa_score',
                'cobertura',
                'score_por_dimensao',
                'mapa_acoes',
                'potencial_recuperavel',
                'versao_score',
            ]);
        });
    }
};
