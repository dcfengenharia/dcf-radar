<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.1.CORREÇÃO — migration incremental (a de 19.1
 * já criou a tabela; não reescrita, mesmo estando pré-commit — decisão
 * explícita do usuário de tratar 19.1 como já "histórica"). Item passa a
 * pertencer à LISTA (`lista_engenharia_id`), não mais diretamente à
 * revisão — revisão continua acessível via item->lista->revisao, sem
 * duplicar a FK no item.
 *
 * `tipo` sai do item — vira propriedade EXCLUSIVA da lista
 * (`listas_engenharia.tipo`), evitando o risco de um item "instrumento"
 * dentro de uma lista "material" por drift de dado (a 19.1 original
 * guardava tipo em duas tabelas, sem nada garantindo consistência).
 *
 * `itens_take_off.lista_engenharia_id` é `cascadeOnDelete()` (filho
 * direto do container, mesmo padrão de `GrdItem.grd_id`) — diferente de
 * `listas_engenharia.documento_engenharia_revisao_id`, que é
 * `restrictOnDelete()` (evidência documental referenciada, mesmo padrão
 * de `GrdItem.documento_engenharia_revisao_id`).
 *
 * Tabela confirmada vazia em produção/dev antes desta migration (feature
 * recém-criada, nenhum dado real ainda) — seguro adicionar
 * `lista_engenharia_id` já como NOT NULL, sem passo intermediário
 * nullable+backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ordem crítica descoberta empiricamente: `itens_take_off_tenant_
        // revisao_index` (tenant_id, documento_engenharia_revisao_id) é a
        // ÚNICA cobertura de índice tanto da FK de documento_engenharia_
        // revisao_id QUANTO da FK de tenant_id (tenant_id é o primeiro
        // membro do composto) — dropar esse índice antes de existir outro
        // cobrindo tenant_id quebra com erro 1553. Por isso a nova coluna/
        // índice (que também cobre tenant_id) precisa nascer ANTES do
        // índice antigo cair, nunca depois.
        Schema::table('itens_take_off', function (Blueprint $table) {
            $table->dropForeign(['documento_engenharia_revisao_id']);
            $table->dropUnique('itens_take_off_revisao_tipo_codigo_unique');
            $table->dropColumn('tipo');
        });

        Schema::table('itens_take_off', function (Blueprint $table) {
            $table->foreignUlid('lista_engenharia_id')
                ->after('tenant_id')
                ->constrained('listas_engenharia')
                ->cascadeOnDelete();

            $table->unique(['lista_engenharia_id', 'codigo'], 'itens_take_off_lista_codigo_unique');
            $table->index(['tenant_id', 'lista_engenharia_id'], 'itens_take_off_tenant_lista_index');
        });

        Schema::table('itens_take_off', function (Blueprint $table) {
            $table->dropIndex('itens_take_off_tenant_revisao_index');
            $table->dropColumn('documento_engenharia_revisao_id');
        });
    }

    public function down(): void
    {
        Schema::table('itens_take_off', function (Blueprint $table) {
            $table->dropForeign(['lista_engenharia_id']);
            $table->dropUnique('itens_take_off_lista_codigo_unique');
        });

        Schema::table('itens_take_off', function (Blueprint $table) {
            $table->string('tipo')->after('tenant_id');
            $table->foreignUlid('documento_engenharia_revisao_id')
                ->after('tenant_id')
                ->constrained('documento_engenharia_revisoes')
                ->cascadeOnDelete();

            $table->unique(['documento_engenharia_revisao_id', 'tipo', 'codigo'], 'itens_take_off_revisao_tipo_codigo_unique');
            $table->index(['tenant_id', 'documento_engenharia_revisao_id'], 'itens_take_off_tenant_revisao_index');
        });

        Schema::table('itens_take_off', function (Blueprint $table) {
            $table->dropIndex('itens_take_off_tenant_lista_index');
            $table->dropColumn('lista_engenharia_id');
        });
    }
};
