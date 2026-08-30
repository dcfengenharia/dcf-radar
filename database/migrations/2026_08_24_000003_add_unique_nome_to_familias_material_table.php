<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.1.HARDENING — `familias_material.nome` era a
 * identidade real (código é opcional) sem NENHUMA garantia estrutural,
 * permitindo "Tubulacao"/"TUBULACAO"/"tubulacao" coexistirem como
 * registros distintos (achado da auditoria da 19.1.CORREÇÃO).
 *
 * `UNIQUE(tenant_id, nome)` puro — verificado empiricamente ANTES desta
 * migration que a collation da coluna (`utf8mb4_unicode_ci`, mesma de
 * toda a tabela) já é case- e accent-insensitive na comparação, então
 * o unique nativo já rejeita as variantes sozinho, sem precisar de
 * coluna computada/normalizada em paralelo (App\Models\FamiliaMaterial::
 * setNomeAttribute() só faz trim(), nunca mexe em maiúscula/acento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('familias_material', function (Blueprint $table) {
            $table->unique(['tenant_id', 'nome'], 'familias_material_tenant_nome_unique');
        });
    }

    public function down(): void
    {
        Schema::table('familias_material', function (Blueprint $table) {
            $table->dropUnique('familias_material_tenant_nome_unique');
        });
    }
};
