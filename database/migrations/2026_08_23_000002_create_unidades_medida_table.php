<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('codigo');
            $table->string('nome');
            $table->boolean('ativo')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'codigo'], 'unidades_medida_tenant_codigo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_medida');
    }
};
