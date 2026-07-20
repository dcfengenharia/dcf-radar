<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->unsignedInteger('ordem_manual')->nullable()->after('percentual_concluido');
        });
    }

    public function down(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->dropColumn('ordem_manual');
        });
    }
};
