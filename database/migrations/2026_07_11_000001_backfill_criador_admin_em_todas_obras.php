<?php

use App\Models\Work;
use Illuminate\Database\Migrations\Migration;

/**
 * Quem criou o tenant passa a ser Admin, automaticamente e de forma
 * imutável, em TODAS as obras do tenant (não só nas que criou) — regra
 * de negócio nova. Esta migration garante isso pras obras que já
 * existiam antes da regra (obras novas já são cobertas por
 * Work::garantirCriadorDoTenantComoAdmin(), chamado no created() do
 * model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Work::all()->each(fn (Work $work) => $work->garantirCriadorDoTenantComoAdmin());
    }

    public function down(): void {}
};
