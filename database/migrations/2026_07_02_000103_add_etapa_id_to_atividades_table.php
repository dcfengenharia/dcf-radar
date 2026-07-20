<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->foreignUlid('etapa_id')->nullable()->after('frente_trabalho_id')
                ->constrained('etapas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->dropForeign(['etapa_id']);
            $table->dropColumn('etapa_id');
        });
    }
};
