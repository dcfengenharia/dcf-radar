<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aviso_plataforma_dispensas', function (Blueprint $table) {
            $table->foreignUlid('aviso_plataforma_id')->constrained('avisos_plataforma')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['aviso_plataforma_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aviso_plataforma_dispensas');
    }
};
