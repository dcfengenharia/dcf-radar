<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_curvas', function (Blueprint $table) {
            $table->unsignedInteger('total_atividades')->default(0)->after('total_hh_previsto');
            $table->unsignedInteger('atividades_concluidas')->default(0)->after('total_atividades');
            $table->unsignedInteger('atividades_atrasadas')->default(0)->after('atividades_concluidas');
        });
    }

    public function down(): void
    {
        Schema::table('report_curvas', function (Blueprint $table) {
            $table->dropColumn(['total_atividades', 'atividades_concluidas', 'atividades_atrasadas']);
        });
    }
};
