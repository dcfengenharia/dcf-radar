<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `convites.papel` (string legada) vira coluna morta a partir de agora —
 * o fluxo de convite passa a gravar só `perfil_id`. Sem doctrine/dbal no
 * projeto, `Schema::table(...)->string(...)->nullable()->change()` não
 * está disponível, daí o ALTER direto (MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE convites MODIFY papel VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE convites MODIFY papel VARCHAR(255) NOT NULL DEFAULT 'encarregado'");
    }
};
