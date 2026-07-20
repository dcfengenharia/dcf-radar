<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rename em passo separado para que os after() a seguir referenciem data_termino
        Schema::table('atividades', function (Blueprint $table) {
            $table->renameColumn('fim_planejado', 'data_termino');
        });

        Schema::table('atividades', function (Blueprint $table) {
            $table->string('external_uid')->nullable()->after('nome');
            $table->string('origem')->nullable()->after('external_uid');
            $table->boolean('fora_do_cronograma')->default(false)->after('origem');
            $table->boolean('is_marco')->default(false)->after('fora_do_cronograma');
            $table->timestamp('external_synced_at')->nullable()->after('is_marco');

            $table->date('baseline_inicio')->nullable()->after('data_termino');
            $table->date('baseline_termino')->nullable()->after('baseline_inicio');
            $table->date('real_inicio')->nullable()->after('baseline_termino');
            $table->date('real_termino')->nullable()->after('real_inicio');
            $table->decimal('baseline_horas', 12, 2)->nullable()->after('real_termino');
            $table->decimal('work_horas', 12, 2)->nullable()->after('baseline_horas');
            $table->decimal('real_horas', 12, 2)->nullable()->after('work_horas');
            $table->json('textos')->nullable()->after('real_horas');

            $table->unique(['obra_id', 'external_uid'], 'atividades_obra_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->dropUnique('atividades_obra_external_unique');
            $table->dropColumn([
                'external_uid', 'origem', 'fora_do_cronograma', 'is_marco',
                'external_synced_at', 'baseline_inicio', 'baseline_termino',
                'real_inicio', 'real_termino', 'baseline_horas', 'work_horas',
                'real_horas', 'textos',
            ]);
        });

        Schema::table('atividades', function (Blueprint $table) {
            $table->renameColumn('data_termino', 'fim_planejado');
        });
    }
};
