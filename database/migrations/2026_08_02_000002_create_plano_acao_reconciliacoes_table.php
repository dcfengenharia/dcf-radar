<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_acao_reconciliacoes', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('plano_acao_id')->constrained('planos_acao')->cascadeOnDelete();

            // Importação que provocou esta reconciliação — mesma convenção
            // cascadeOnDelete de toda FK pra cronograma_importacoes no projeto.
            $table->foreignUlid('cronograma_importacao_id')
                ->constrained('cronograma_importacoes')
                ->cascadeOnDelete();

            $table->string('resultado'); // Valores: ResultadoReconciliacaoPlanoAcao enum
            $table->string('status_anterior'); // Valores: StatusPlanoAcao enum
            $table->string('status_novo');     // Valores: StatusPlanoAcao enum

            // Conjuntos de external_uid antes/depois desta reconciliação —
            // permite reconstruir exatamente o que mudou sem reexecutar nada.
            $table->json('uids_anteriores');
            $table->json('uids_atuais');

            $table->unsignedInteger('quantidade_anterior');
            $table->unsignedInteger('quantidade_atual');

            // Nullable de propósito: o Mapa de Ações (AcaoRecomendada) não
            // carrega o conjunto de uids de cada ocorrência, então quando uma
            // regra gera múltiplas ocorrências na mesma importação (ex.: 3
            // ciclos STRUCT-005) não há como casar com certeza qual impacto
            // pertence a qual ocorrência sem alterar ScoreCalculator/
            // AcaoRecomendada (fora do escopo da Fase 4.1) — ficam null até
            // essa limitação ser resolvida numa fase futura.
            $table->float('impacto_anterior')->nullable();
            $table->float('impacto_atual')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'plano_acao_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_acao_reconciliacoes');
    }
};
