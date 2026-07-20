<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['tenant_id', 'user_id']);
            $table->index('user_id');
        });

        // Backfill: todo usuário existente ganha uma linha de pivô pro seu
        // tenant "casa" atual, pra não ver o switcher vazio depois do deploy.
        DB::table('users')->select('id', 'tenant_id')->orderBy('id')->chunkById(500, function ($usuarios) {
            $agora = now();
            $linhas = $usuarios->map(fn ($u) => [
                'tenant_id' => $u->tenant_id,
                'user_id' => $u->id,
                'created_at' => $agora,
                'updated_at' => $agora,
            ])->all();
            DB::table('tenant_user')->insert($linhas);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
    }
};
