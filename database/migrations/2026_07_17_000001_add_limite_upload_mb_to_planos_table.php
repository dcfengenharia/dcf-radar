<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planos', function (Blueprint $table) {
            $table->unsignedInteger('limite_upload_mb')->default(100)->after('max_usuarios');
        });

        // Planos já em uso hoje passam a comportar arquivos maiores (até o
        // novo teto técnico de 300 MB) — desbloqueia importações reais que
        // já estavam em andamento. Planos criados depois desta migration
        // nascem com o default de 100 MB (coluna acima).
        DB::table('planos')->update(['limite_upload_mb' => 300]);
    }

    public function down(): void
    {
        Schema::table('planos', function (Blueprint $table) {
            $table->dropColumn('limite_upload_mb');
        });
    }
};
