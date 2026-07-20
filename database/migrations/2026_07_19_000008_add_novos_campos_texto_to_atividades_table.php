<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->boolean('faturamento_direto')->nullable()->after('disciplina_id');
            $table->foreignUlid('entregavel_id')->nullable()->after('faturamento_direto')
                ->constrained('entregaveis')->nullOnDelete();
            $table->foreignUlid('equipe_responsavel_id')->nullable()->after('entregavel_id')
                ->constrained('equipes_responsaveis')->nullOnDelete();
            $table->foreignUlid('personalizado_1_id')->nullable()->after('equipe_responsavel_id')
                ->constrained('personalizados_1')->nullOnDelete();
            $table->foreignUlid('personalizado_2_id')->nullable()->after('personalizado_1_id')
                ->constrained('personalizados_2')->nullOnDelete();
            $table->foreignUlid('personalizado_3_id')->nullable()->after('personalizado_2_id')
                ->constrained('personalizados_3')->nullOnDelete();
            $table->foreignUlid('personalizado_4_id')->nullable()->after('personalizado_3_id')
                ->constrained('personalizados_4')->nullOnDelete();
            $table->foreignUlid('personalizado_5_id')->nullable()->after('personalizado_4_id')
                ->constrained('personalizados_5')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->dropForeign(['entregavel_id']);
            $table->dropForeign(['equipe_responsavel_id']);
            $table->dropForeign(['personalizado_1_id']);
            $table->dropForeign(['personalizado_2_id']);
            $table->dropForeign(['personalizado_3_id']);
            $table->dropForeign(['personalizado_4_id']);
            $table->dropForeign(['personalizado_5_id']);
            $table->dropColumn([
                'faturamento_direto',
                'entregavel_id',
                'equipe_responsavel_id',
                'personalizado_1_id',
                'personalizado_2_id',
                'personalizado_3_id',
                'personalizado_4_id',
                'personalizado_5_id',
            ]);
        });
    }
};
