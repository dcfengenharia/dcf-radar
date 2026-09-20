<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria Pré-Produção A2.2, Seções 3-5 — fecha o risco histórico
 * documentado desde o Ciclo 20.5 ("retorno industrializado idêntico
 * repetido pode criar estoque fantasma"), reproduzido empiricamente
 * ANTES desta correção em
 * tests/Feature/Auditoria/IndustrializacaoReproducaoRiscoTest.php.
 *
 * **Identidade mínima escolhida, após reauditar o fluxo real completo
 * (nunca "adicionar em todas as quatro tabelas" mecanicamente)**: cada
 * uma das 4 tabelas abaixo já É, desde o Ciclo 20.5, a "identidade de
 * operação" — a linha correlata que amarra o(s) `MovimentacaoEstoque`
 * gerados por UM comando de domínio real, exatamente o mesmo padrão já
 * usado e validado em `transferencias_estoque` (A2.1):
 *
 * - `remessas_industrializacao`: 1 linha = 1 comando "enviar" OU
 *   "retornar sobra" (mesma tabela cobre as duas direções — não são
 *   dois comandos distintos, `direcao` já discrimina) — correlaciona a
 *   Saida+Entrada que a remessa cria.
 * - `producoes_industrializadas`: 1 linha = 1 comando "registrar esta
 *   fabricação" — correlaciona a Entrada técnica que cria no terceiro.
 * - `produto_industrializado_consumos`: 1 linha = 1 comando "registrar
 *   este consumo de matéria-prima" — correlaciona a Saida que cria.
 * - `entregas_produto_industrializado`: 1 linha = 1 comando "registrar
 *   esta entrega" — correlaciona Saida(terceiro)+Entrada(destino) e,
 *   quando aplicável, a Saida(campo) real que delega a
 *   RegistrarSaidaEstoque (essa, por sua vez, já tem seu PRÓPRIO
 *   operation_id independente, se o chamador passar um — nunca
 *   reaproveita o desta entrega).
 *
 * As `MovimentacaoEstoque`/`ProdutoIndustrializadoConsumo` internas que
 * cada uma cria NUNCA ganham operation_id próprio — a identidade já
 * vive na linha correlata, mesmo princípio de `transferencias_estoque`.
 *
 * `UNIQUE(tenant_id, operation_id)` — NULL é distinto por natureza no
 * MySQL, chamadas sem operation_id (comportamento legado) nunca colidem.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['remessas_industrializacao', 'producoes_industrializadas', 'produto_industrializado_consumos', 'entregas_produto_industrializado'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) use ($tabela) {
                $table->string('operation_id', 40)->nullable()->after('id');
                $table->unique(['tenant_id', 'operation_id'], substr($tabela, 0, 20) . '_operation_id_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['remessas_industrializacao', 'producoes_industrializadas', 'produto_industrializado_consumos', 'entregas_produto_industrializado'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) use ($tabela) {
                $table->dropUnique(substr($tabela, 0, 20) . '_operation_id_unique');
                $table->dropColumn('operation_id');
            });
        }
    }
};
