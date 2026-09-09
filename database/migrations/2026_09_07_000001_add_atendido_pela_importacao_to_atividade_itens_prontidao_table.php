<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 24 — origem auditável de um item de prontidão atendido
     * AUTOMATICAMENTE pela reconciliação de importação de avanço (nunca
     * simula uma marcação humana): `atendido_pela_importacao_id` aponta pra
     * qual `CronogramaImportacao` causou o atendimento. Nulo = marcação
     * manual (via `⚡restricoes.blade.php`) ou item ainda pendente. Nunca
     * preenchido junto com `concluido_por` — os dois são mutuamente
     * exclusivos por construção (ver `App\Models\AtividadeItemProntidao`).
     */
    public function up(): void
    {
        Schema::table('atividade_itens_prontidao', function (Blueprint $table) {
            $table->foreignUlid('atendido_pela_importacao_id')
                ->nullable()
                ->after('concluido_em')
                ->constrained('cronograma_importacoes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('atividade_itens_prontidao', function (Blueprint $table) {
            $table->dropForeign(['atendido_pela_importacao_id']);
            $table->dropColumn('atendido_pela_importacao_id');
        });
    }
};
