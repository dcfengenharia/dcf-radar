<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapas_fluxo_suprimento', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('fluxo_suprimento_id')->constrained('fluxos_suprimento')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem');
            $table->string('nome');
            $table->unsignedSmallInteger('prazo_dias_uteis');
            $table->timestamps();

            $table->unique(['fluxo_suprimento_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etapas_fluxo_suprimento');
    }
};
