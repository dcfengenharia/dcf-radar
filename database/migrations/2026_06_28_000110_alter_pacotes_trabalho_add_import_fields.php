<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pacotes_trabalho', function (Blueprint $table) {
            $table->string('codigo')->nullable()->after('nome');
            $table->string('external_uid')->nullable()->after('codigo');
            $table->index(['obra_id', 'external_uid'], 'pacotes_trabalho_obra_external_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pacotes_trabalho', function (Blueprint $table) {
            $table->dropIndex('pacotes_trabalho_obra_external_idx');
            $table->dropColumn(['codigo', 'external_uid']);
        });
    }
};
