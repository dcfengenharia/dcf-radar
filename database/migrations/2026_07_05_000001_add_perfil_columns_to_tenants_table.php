<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
            $table->string('cnpj')->nullable();
            $table->string('razao_social')->nullable();
            $table->string('telefone')->nullable();
            $table->string('email_comercial')->nullable();
            $table->foreignUlid('criado_por_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // Backfill: cada tenant existente recebe como "criador" o usuário
        // mais antigo daquele tenant (ULID é ordenável por tempo).
        DB::table('tenants')->orderBy('id')->get(['id'])->each(function ($tenant) {
            $primeiro = DB::table('users')->where('tenant_id', $tenant->id)->orderBy('id')->first(['id']);
            if ($primeiro) {
                DB::table('tenants')->where('id', $tenant->id)->update(['criado_por_id' => $primeiro->id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('criado_por_id');
            $table->dropColumn(['logo_path', 'cnpj', 'razao_social', 'telefone', 'email_comercial']);
        });
    }
};
