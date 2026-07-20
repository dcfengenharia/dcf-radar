<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convites', function (Blueprint $table) {
            $table->foreignUlid('perfil_id')->nullable()->after('papel')->constrained('perfis')->nullOnDelete();
        });

        // Perfis padrão já foram semeados pela migration anterior
        // (add_perfil_id_to_obra_user_table) — só resolve o vínculo aqui.
        DB::table('convites')
            ->join('perfis', function ($join) {
                $join->on('perfis.tenant_id', '=', 'convites.tenant_id')
                    ->on('perfis.slug_padrao', '=', 'convites.papel');
            })
            ->update(['convites.perfil_id' => DB::raw('perfis.id')]);
    }

    public function down(): void
    {
        Schema::table('convites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('perfil_id');
        });
    }
};
