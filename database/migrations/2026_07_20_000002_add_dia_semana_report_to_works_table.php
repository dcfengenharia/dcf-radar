<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            // 0 (domingo) a 6 (sábado), igual Carbon::dayOfWeek. null =
            // geração automática de report desligada (comportamento atual).
            $table->unsignedTinyInteger('dia_semana_report')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropColumn('dia_semana_report');
        });
    }
};
