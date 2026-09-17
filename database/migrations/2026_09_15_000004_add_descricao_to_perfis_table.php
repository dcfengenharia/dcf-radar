<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 2C, Seção 4/37 — a listagem redesenhada de Perfis de Acesso exige
 * "nome/descrição" por perfil; `perfis` só tinha `nome` até aqui. Coluna
 * nova, nullable, sem default — puramente aditiva (nenhuma migration
 * existente tocada). Registros já existentes (perfis padrão semeados
 * antes desta fase) ficam com `descricao = null`; `Perfil::seedPadrao()`/
 * `TemplatesEspecialistas::criar()` passam a preencher um texto curto
 * pra todo perfil NOVO a partir de agora — nunca um backfill retroativo
 * inventando descrição pra perfil já customizado pelo tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfis', function (Blueprint $table) {
            $table->text('descricao')->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('perfis', function (Blueprint $table) {
            $table->dropColumn('descricao');
        });
    }
};
