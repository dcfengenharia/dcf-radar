<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aviso promocional (só imagem em tela cheia + botão) pode não ter
 * mensagem em texto — sem doctrine/dbal no projeto (mesmo motivo de
 * database/migrations/2026_07_10_000005_make_papel_nullable_on_convites_table.php),
 * ALTER direto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE avisos_plataforma MODIFY mensagem TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE avisos_plataforma MODIFY mensagem TEXT NOT NULL');
    }
};
