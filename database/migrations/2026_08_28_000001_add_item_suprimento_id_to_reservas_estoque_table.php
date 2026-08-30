<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.2.CORREÇÃO — fecha o Achado B1 da auditoria
 * adversarial: uma `ReservaEstoque` sem `destinacao_planejada_material_id`
 * (Frente ainda não detalhada — caso legítimo, Seção 25 da investigação
 * original) não tinha NENHUMA referência à demanda de origem — nem
 * Material+Local/Unidade dizem "para qual Pacote". Decisão do usuário:
 * toda `ReservaEstoque` passa a pertencer obrigatoriamente a um
 * `ItemSuprimento` (Pacote) — `destinacao_planejada_material_id`
 * continua OPCIONAL (ausência = "Pacote conhecido, Frente ainda não
 * detalhada", nunca uma Frente fake). Uma Reserva genérica NÃO precisa
 * ser inteiramente consumida por uma única Frente depois (futuras
 * saídas parciais podem atender várias Frentes de uma mesma Reserva).
 *
 * `NOT NULL` direto (sem passo intermediário nullable+backfill):
 * confirmado por leitura direta do banco de dev antes desta migration
 * que `reservas_estoque` está vazia (0 linhas) — nenhum dado real
 * existente que exigisse reconstrução de origem.
 *
 * `restrictOnDelete()` — mesma política de evidência histórica de toda
 * FK do projeto que aponta pra um fato de negócio já registrado (mesmo
 * padrão de `destinacoes_planejadas_material.item_suprimento_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas_estoque', function (Blueprint $table) {
            $table->foreignUlid('item_suprimento_id')->after('obra_id');
        });

        Schema::table('reservas_estoque', function (Blueprint $table) {
            $table->foreign('item_suprimento_id', 'reservas_estoque_pacote_fk')
                ->references('id')->on('itens_suprimento')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'item_suprimento_id'], 'reservas_estoque_pacote_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reservas_estoque', function (Blueprint $table) {
            $table->dropForeign('reservas_estoque_pacote_fk');
            $table->dropIndex('reservas_estoque_pacote_idx');
            $table->dropColumn('item_suprimento_id');
        });
    }
};
