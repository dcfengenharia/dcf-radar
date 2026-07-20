<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->decimal('preco_mensal', 10, 2);
            $table->unsignedInteger('max_obras')->nullable();
            $table->unsignedInteger('max_usuarios')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos');
    }
};
