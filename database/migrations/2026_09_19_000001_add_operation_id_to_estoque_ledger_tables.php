<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria Pré-Produção A2.1, Seções 5-9 — idempotência real (retry/
 * double-submit da MESMA intenção), nunca confundida com concorrência de
 * saldo (lockForUpdate, já garantida desde o Ciclo 20). `operation_id`
 * (ULID opaco gerado UMA VEZ pela UI/chamador quando a intenção nasce,
 * nunca inferido de campos de negócio) é NULLABLE — ausência preserva
 * 100% o comportamento pré-existente (cada chamada sempre cria um fato
 * novo, como sempre foi).
 *
 * `UNIQUE(tenant_id, operation_id)` é a garantia REAL e durável — o
 * MySQL trata cada NULL como distinto num índice único (mesmo mecanismo
 * já usado em `codigo_lote`/`serial_unico`/`item_take_off.codigo` em
 * todo o projeto desde os Ciclos 18-20), então chamadas sem operation_id
 * nunca colidem entre si; duas chamadas com o MESMO operation_id sempre
 * colidem, mesmo sob corrida concorrente real (a defesa não depende de
 * um `exists()` em PHP, que teria uma janela de corrida).
 *
 * **`movimentacoes_estoque`** cobre Entrada e Saída standalone — cada
 * uma cria exatamente 1 linha por chamada, então 1 operation_id por
 * chamada é suficiente.
 *
 * **`transferencias_estoque`** ganha seu PRÓPRIO `operation_id`
 * (nunca reaproveita o de `movimentacoes_estoque`) — uma Transferência
 * já É a "identidade de operação" da dupla Saída+Entrada que ela cria
 * (mesmo princípio já documentado no domínio desde a 20.6: a correlação
 * vive na tabela de identidade, nunca nas linhas de fato cru). As 2
 * `MovimentacaoEstoque` que uma Transferência cria internamente
 * continuam com `operation_id` NULL — não precisam de identidade
 * própria, a da Transferência já é suficiente pra detectar retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentacoes_estoque', function (Blueprint $table) {
            $table->string('operation_id', 40)->nullable()->after('id');
            $table->unique(['tenant_id', 'operation_id'], 'movimentacoes_estoque_operation_id_unique');
        });

        Schema::table('transferencias_estoque', function (Blueprint $table) {
            $table->string('operation_id', 40)->nullable()->after('id');
            $table->unique(['tenant_id', 'operation_id'], 'transf_estoque_operation_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('movimentacoes_estoque', function (Blueprint $table) {
            $table->dropUnique('movimentacoes_estoque_operation_id_unique');
            $table->dropColumn('operation_id');
        });

        Schema::table('transferencias_estoque', function (Blueprint $table) {
            $table->dropUnique('transf_estoque_operation_id_unique');
            $table->dropColumn('operation_id');
        });
    }
};
