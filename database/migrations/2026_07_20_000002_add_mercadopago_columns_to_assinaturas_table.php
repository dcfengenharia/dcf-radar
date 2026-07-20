<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assinaturas', function (Blueprint $table) {
            $table->string('origem')->default('manual')->after('status');
            $table->string('metodo_pagamento')->nullable()->after('origem');
            $table->string('mp_preapproval_id')->nullable()->after('metodo_pagamento');
            $table->date('renovar_em')->nullable()->after('fim_trial');
        });
    }

    public function down(): void
    {
        Schema::table('assinaturas', function (Blueprint $table) {
            $table->dropColumn(['origem', 'metodo_pagamento', 'mp_preapproval_id', 'renovar_em']);
        });
    }
};
