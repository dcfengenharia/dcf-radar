<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastreabilidade Quantitativa — Etapa 1 (Revisão Arquitetural 2, Opção
 * E aprovada): a ponte que faltava entre `App\Models\
 * AtividadeNecessidadeMaterial` (já a parcela canônica de necessidade
 * por Atividade, desde a Melhoria "Posto Operacional") e a cadeia
 * comercial de Suprimentos. Uma `RequisicaoCompraItem` pode reunir
 * várias parcelas (merge — ex.: 300 de A + 300 de B numa mesma RC); uma
 * mesma parcela pode ser dividida entre várias RCItens de RCs diferentes
 * (split — ex.: 150 de B numa RC, 250 de C noutra). Nunca uma FK de
 * Atividade direta em nenhuma tabela da cadeia comercial — a Atividade
 * continua sempre transitiva, via esta parcela.
 *
 * **`requisicao_compra_item_id` é `cascadeOnDelete()`** — diferente da
 * lição de evidência histórica usual, aqui é o caso correto: um
 * `RequisicaoCompraItem` só pode ser removido enquanto sua RC ainda é
 * Rascunho (`AtualizarRascunhoRequisicaoCompra::removerItem()`), estado
 * em que esta linha de detalhamento nunca chegou a representar nenhum
 * compromisso comercial real — não há nada a preservar.
 *
 * **`atividade_necessidade_material_id` é `restrictOnDelete()`** — mesma
 * lição de evidência histórica de sempre: uma parcela referenciada por
 * algum detalhamento de RC nunca pode desaparecer silenciosamente (a
 * própria Action de remoção de `AtividadeNecessidadeMaterial` também já
 * teria de lidar com isso — mas nem chega a esse ponto, o banco barra
 * primeiro).
 *
 * **`UNIQUE(requisicao_compra_item_id, atividade_necessidade_material_id)`**
 * — no máximo 1 linha por par (mesmo padrão de toda a árvore Ciclo
 * 19/20: `alocacoes_requisicao_pacote`, `requisicao_compra_itens`,
 * `pedido_compra_itens`) — editar a distribuição SUBSTITUI a quantidade
 * existente, nunca soma uma segunda linha pro mesmo par.
 *
 * **Saldo sempre derivado, nunca uma coluna aqui** — as duas guardas
 * quantitativas (soma por RCItem <= RCItem.quantidade; soma por parcela,
 * em RCs comercialmente válidas — Emitida/Concluida —, <=
 * quantidade_necessaria) vivem em
 * `App\Actions\Suprimentos\AtualizarDistribuicaoParcelaRequisicaoCompra`/
 * `App\Models\AtividadeNecessidadeMaterial::quantidadeDetalhadaOficialEmRc()`,
 * nunca persistidas nesta tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicao_compra_item_parcelas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('requisicao_compra_item_id');
            $table->foreign('requisicao_compra_item_id', 'rc_item_parcela_item_fk')
                ->references('id')->on('requisicao_compra_itens')
                ->cascadeOnDelete();

            $table->foreignUlid('atividade_necessidade_material_id');
            $table->foreign('atividade_necessidade_material_id', 'rc_item_parcela_necessidade_fk')
                ->references('id')->on('atividade_necessidades_material')
                ->restrictOnDelete();

            $table->decimal('quantidade', 14, 3);

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['requisicao_compra_item_id', 'atividade_necessidade_material_id'], 'rc_item_parcela_item_necessidade_unique');
            $table->index(['tenant_id', 'atividade_necessidade_material_id'], 'rc_item_parcela_tenant_necessidade_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicao_compra_item_parcelas');
    }
};
