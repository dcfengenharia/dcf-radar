<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->decimal('percentual_concluido', 5, 2)->nullable()->after('caminho_critico');
        });
    }

    public function down(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->dropColumn('percentual_concluido');
        });
    }
};
