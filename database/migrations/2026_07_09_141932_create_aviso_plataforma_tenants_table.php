<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aviso_plataforma_tenants', function (Blueprint $table) {
            $table->foreignUlid('aviso_plataforma_id')->constrained('avisos_plataforma')->cascadeOnDelete();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['aviso_plataforma_id', 'tenant_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aviso_plataforma_tenants');
    }
};
