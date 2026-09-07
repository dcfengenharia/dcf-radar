<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 23, Etapa 23.2 — semântica de vínculo de ORIGEM vs.
 * COMPLEMENTAR (Seção 9 do pedido). Investigado antes de alterar schema
 * se dava pra representar sem migration ("o vínculo com o menor ID/
 * created_at é a origem", já que a origem é sempre criada primeiro na
 * mesma transação) — descartado: se o vínculo de origem for removido
 * depois (já permitido em Rascunho/EmValidacao desde a 23.1), a
 * "origem" mudaria silenciosamente pra outro vínculo qualquer, violando
 * "o vínculo de origem deve continuar distinguível" (Seção 9/29/30).
 *
 * `e_origem` (boolean, default false) marca explicitamente qual vínculo
 * é a origem. Garantia ESTRUTURAL de "no máximo 1 origem por lição" —
 * mesmo truque já usado em `grd_aceites_entrega.ativo_unico_destinatario`
 * (Ciclo 18.5.9): coluna gerada `origem_unica_da_licao` que só assume
 * valor não-nulo quando `e_origem = true`; MySQL trata cada NULL como
 * distinto num índice único, então só uma 2ª linha com `e_origem=true`
 * PRA MESMA LIÇÃO colide de verdade. `storedAs()` do Laravel não cobre
 * CASE/coluna-a-partir-de-outra-coluna de forma portável — adicionada
 * via SQL cru, mesma convenção já aceita no projeto (GRD 18.5.9/Health
 * Check/etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licao_aprendida_vinculos', function (Blueprint $table) {
            $table->boolean('e_origem')->default(false)->after('titulo_snapshot');
        });

        DB::statement(
            'ALTER TABLE licao_aprendida_vinculos '
            . 'ADD COLUMN origem_unica_da_licao CHAR(26) '
            . 'GENERATED ALWAYS AS (CASE WHEN e_origem THEN licao_aprendida_id ELSE NULL END) STORED, '
            . 'ADD UNIQUE KEY licao_vinculos_origem_unica (origem_unica_da_licao)'
        );
    }

    public function down(): void
    {
        Schema::table('licao_aprendida_vinculos', function (Blueprint $table) {
            $table->dropColumn('origem_unica_da_licao');
            $table->dropColumn('e_origem');
        });
    }
};
