<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('programacoes_semanais', function (Blueprint $table) {
            $table->string('status')->default('aberta')->after('congelada_em');
            $table->timestamp('fechada_em')->nullable()->after('status');
            $table->foreignUlid('fechada_por')->nullable()->after('fechada_em')
                  ->constrained('users')->nullOnDelete();
            $table->unsignedInteger('versao')->default(1)->after('fechada_por');
            $table->foreignUlid('revisao_de_id')->nullable()->after('versao')
                  ->constrained('programacoes_semanais')->nullOnDelete();

            // A unique nova precisa existir ANTES de derrubar a antiga: as
            // duas têm obra_id como coluna líder, e o InnoDB recusa
            // dropar um índice único enquanto ele é o único suporte pra
            // FK de obra_id (erro 1553) — cria a nova primeiro, ela vira
            // o novo suporte, só então a antiga pode sair.
            $table->unique(['obra_id', 'semana_inicio', 'versao'], 'programacoes_semanais_obra_semana_versao_unique');
            $table->dropUnique('programacoes_semanais_obra_semana_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('programacoes_semanais', function (Blueprint $table) {
            $table->dropUnique('programacoes_semanais_obra_semana_versao_unique');
            $table->dropConstrainedForeignId('fechada_por');
            $table->dropConstrainedForeignId('revisao_de_id');
            $table->dropColumn(['status', 'fechada_em', 'versao']);

            $table->unique(['obra_id', 'semana_inicio'], 'programacoes_semanais_obra_semana_unique');
        });
    }
};
