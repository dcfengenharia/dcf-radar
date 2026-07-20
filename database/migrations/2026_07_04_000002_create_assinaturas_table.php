<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assinaturas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('plano_id')->constrained('planos')->restrictOnDelete();
            $table->string('status')->default('trial');
            $table->date('inicio');
            $table->date('fim_trial')->nullable();
            $table->timestamp('cancelada_em')->nullable();
            $table->string('motivo_cancelamento')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assinaturas');
    }
};
