<?php

use App\Models\Perfil;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obra_user', function (Blueprint $table) {
            $table->foreignUlid('perfil_id')->nullable()->after('papel')->constrained('perfis')->nullOnDelete();
        });

        // Semeia os 5 perfis padrão pra qualquer tenant que ainda não os
        // tenha (tenants criados antes desta migration) e resolve
        // perfil_id em obra_user por slug_padrao — ninguém perde acesso.
        Tenant::withTrashed()->each(function (Tenant $tenant) {
            if (Perfil::where('tenant_id', $tenant->id)->doesntExist()) {
                Perfil::seedPadrao($tenant);
            }
        });

        DB::table('obra_user')
            ->join('works', 'works.id', '=', 'obra_user.work_id')
            ->join('perfis', function ($join) {
                $join->on('perfis.tenant_id', '=', 'works.tenant_id')
                    ->on('perfis.slug_padrao', '=', 'obra_user.papel');
            })
            ->update(['obra_user.perfil_id' => DB::raw('perfis.id')]);
    }

    public function down(): void
    {
        Schema::table('obra_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('perfil_id');
        });
    }
};
