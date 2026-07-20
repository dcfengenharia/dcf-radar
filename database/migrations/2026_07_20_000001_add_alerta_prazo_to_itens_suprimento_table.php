<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens_suprimento', function (Blueprint $table) {
            $table->timestamp('alerta_21d_enviado_em')->nullable()->after('status');
            $table->timestamp('alerta_10d_enviado_em')->nullable()->after('alerta_21d_enviado_em');
        });
    }

    public function down(): void
    {
        Schema::table('itens_suprimento', function (Blueprint $table) {
            $table->dropColumn(['alerta_21d_enviado_em', 'alerta_10d_enviado_em']);
        });
    }
};
