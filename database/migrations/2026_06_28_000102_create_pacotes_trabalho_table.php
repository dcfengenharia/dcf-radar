<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacotes_trabalho', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            // Auto-aninhamento: um pacote pode ter um pacote pai (EAP)
            $table->char('parent_id', 26)->nullable()->index();

            $table->string('nome');

            $table->index(['tenant_id', 'obra_id']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacotes_trabalho');
    }
};
