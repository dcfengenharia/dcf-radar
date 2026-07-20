<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obra_user', function (Blueprint $table) {
            $table->foreignUlid('work_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('papel')->default('engenheiro');
            $table->timestamps();

            $table->primary(['work_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obra_user');
    }
};
