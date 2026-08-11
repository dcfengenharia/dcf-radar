<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos_acao', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            // Importação cuja análise de Health Check originou esta ação —
            // mesma convenção de cascadeOnDelete já usada por toda FK pra
            // cronograma_importacoes (AvancoPeriodo, LinhaBase, AtividadeSnapshot,
            // Report, CronogramaImportacaoHealthCheck).
            $table->foreignUlid('cronograma_importacao_origem_id')
                ->constrained('cronograma_importacoes')
                ->cascadeOnDelete();

            // Regra do Health Check que gerou o problema (ex.: 'STRUCT-005').
            $table->string('regra_id');

            // Snapshot de texto no momento da criação — o Plano de Ação nunca
            // relê a regra ao vivo pra exibir título/recomendação (Fase 4
            // diagnóstico, Etapa 8: "referencia o texto do Mapa de Ações, nunca
            // duplica" — aqui é uma cópia congelada por continuidade de exibição,
            // não uma segunda fonte de verdade).
            $table->string('titulo');
            $table->text('recomendacao');

            // Responsável pela correção
            $table->foreignUlid('responsavel_id')->nullable()->constrained('users')->nullOnDelete();

            // Autoria (HasAuthorship carimba automaticamente no create)
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('prazo')->nullable();
            $table->string('status')->default('aberta'); // Valores: StatusPlanoAcao enum

            // Identidade pra reconciliação entre importações (Fase 4 diagnóstico,
            // Etapa 5) — conjunto de external_uid (TarefaImportada::$uid) mais
            // recentemente conhecido pra esta ação. Atualizado pelo
            // PlanoAcaoReconciliador a cada reconciliação (nunca fica preso ao
            // valor da criação) — é o que permite comparar contra a PRÓXIMA
            // importação, não sempre contra a primeira.
            $table->json('uids_referencia');

            $table->timestamp('resolvida_em')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'obra_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos_acao');
    }
};
