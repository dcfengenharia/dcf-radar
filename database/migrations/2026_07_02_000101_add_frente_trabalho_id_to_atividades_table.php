<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->foreignUlid('frente_trabalho_id')->nullable()->after('pacote_trabalho_id')
                ->constrained('frentes_trabalho')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('atividades', function (Blueprint $table) {
            $table->dropForeign(['frente_trabalho_id']);
            $table->dropColumn('frente_trabalho_id');
        });
    }
};
