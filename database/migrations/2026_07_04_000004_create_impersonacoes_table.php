<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonacoes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamp('iniciado_em');
            $table->timestamp('finalizado_em')->nullable();
            $table->string('ip')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonacoes');
    }
};
