<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assinatura_faturas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('assinatura_id')->constrained('assinaturas')->cascadeOnDelete();
            $table->string('metodo_pagamento');
            $table->decimal('valor', 10, 2);
            $table->string('status')->default('pendente');
            $table->date('vencimento');
            $table->timestamp('pago_em')->nullable();
            $table->string('mp_payment_id')->nullable();
            $table->string('mp_preapproval_id')->nullable();
            $table->text('link_pagamento')->nullable();
            $table->text('qr_code')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'vencimento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assinatura_faturas');
    }
};
