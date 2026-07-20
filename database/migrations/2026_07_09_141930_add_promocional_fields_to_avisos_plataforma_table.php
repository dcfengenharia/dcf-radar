<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avisos_plataforma', function (Blueprint $table) {
            $table->string('imagem_promocional')->nullable()->after('mensagem');
            $table->string('link_url')->nullable()->after('imagem_promocional');
            $table->string('link_texto')->nullable()->after('link_url');
        });
    }

    public function down(): void
    {
        Schema::table('avisos_plataforma', function (Blueprint $table) {
            $table->dropColumn(['imagem_promocional', 'link_url', 'link_texto']);
        });
    }
};
