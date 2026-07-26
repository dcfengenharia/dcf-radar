<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aditiva: só adiciona colunas novas, nenhuma existente é alterada.
 * "HH Realizado" lançado manualmente pelo usuário na Programação Semanal
 * (distinto do AvancoPeriodo serie=Realizado, que vem só de reimportação
 * do XML e não é escopado a uma programação específica) — ver
 * CLAUDE.md, seção "Programação Semanal — HH Realizado".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programacao_semanal_itens', function (Blueprint $table) {
            $table->decimal('hh_realizado', 12, 2)->nullable()->after('horas_previstas_congeladas');
            $table->foreignUlid('realizado_por')->nullable()->after('hh_realizado')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('realizado_em')->nullable()->after('realizado_por');
        });
    }

    public function down(): void
    {
        Schema::table('programacao_semanal_itens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('realizado_por');
            $table->dropColumn(['hh_realizado', 'realizado_em']);
        });
    }
};
