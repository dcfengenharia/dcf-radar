<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convites', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            $table->string('email');
            $table->string('papel');
            $table->string('token', 64)->unique();
            $table->foreignUlid('convidado_por_id')->constrained('users')->restrictOnDelete();

            $table->string('status')->default('pendente'); // pendente|aceito|cancelado
            $table->timestamp('expira_em')->nullable();
            $table->timestamp('aceito_em')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'obra_id']);
            $table->unique(['obra_id', 'email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convites');
    }
};
