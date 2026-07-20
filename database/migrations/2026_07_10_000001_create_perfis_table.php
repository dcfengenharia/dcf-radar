<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfis', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('nome');
            // Nunca muda mesmo se "nome" for renomeado pelo tenant — é o que
            // permite localizar "o perfil equivalente a Gerente de
            // Planejamento deste tenant" (ex.: auto-atribuição do criador
            // de uma obra nova). Null para perfis 100% customizados.
            $table->string('slug_padrao')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfis');
    }
};
