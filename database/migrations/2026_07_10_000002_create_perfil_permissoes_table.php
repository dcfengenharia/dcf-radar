<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfil_permissoes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('perfil_id')->constrained('perfis')->cascadeOnDelete();
            $table->string('funcionalidade');
            $table->string('acao');
            $table->timestamps();

            $table->unique(['perfil_id', 'funcionalidade', 'acao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfil_permissoes');
    }
};
