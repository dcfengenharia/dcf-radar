<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->string('codigo_cronograma')->nullable()->after('ordem_manual');
        });
    }

    public function down(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->dropColumn('codigo_cronograma');
        });
    }
};
