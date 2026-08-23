<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 17, A.9.6 — primeiro fluxo humano de tratamento das
     * InconsistenciaAvanco (A.9.4/A.9.5). Campos direto na própria tabela
     * (decisão registrada em CLAUDE.md, seção A.9.6): o produto exige só
     * UMA baixa definitiva por ocorrência (ABERTA -> TRATADA, sem
     * reabertura nesta fase), então uma tabela filha de histórico de
     * múltiplas ações seria overengineering — mesmo critério explícito do
     * pedido.
     *
     * Todas as colunas novas são nullable/com default seguro — nenhum
     * backfill necessário, ocorrências já persistidas nascem ABERTA por
     * omissão do `default('aberta')`.
     */
    public function up(): void
    {
        Schema::table('inconsistencias_avanco', function (Blueprint $table) {
            $table->string('status')->default('aberta')->after('detectada_em');
            $table->foreignUlid('tratado_por')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('tratado_em')->nullable()->after('tratado_por');
            $table->text('justificativa')->nullable()->after('tratado_em');

            $table->index(['obra_id', 'status'], 'incav_obra_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inconsistencias_avanco', function (Blueprint $table) {
            $table->dropIndex('incav_obra_status_idx');
            $table->dropConstrainedForeignId('tratado_por');
            $table->dropColumn(['status', 'tratado_em', 'justificativa']);
        });
    }
};
