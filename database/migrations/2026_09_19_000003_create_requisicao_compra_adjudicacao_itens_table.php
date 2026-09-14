<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 — composição quantitativa de uma Adjudicação (Seção 5/6 do
 * pedido: "não tratar a adjudicação apenas como cabeçalho sem
 * composição"). Uma linha = "esta quantidade deste RCItem/parcela foi
 * atribuída a este fornecedor, nesta decisão".
 *
 * **Granularidade — decisão de arquitetura tomada nesta etapa, não
 * ambígua**: se o `RequisicaoCompraItem` alvo JÁ tem alguma
 * `RequisicaoCompraItemParcela` (distribuição por Atividade, Etapa 1),
 * a adjudicação SEMPRE aponta pra uma parcela específica
 * (`requisicao_compra_item_parcela_id` obrigatório nesse caso) — nunca
 * mistura adjudicação "no item inteiro" com adjudicação "por parcela"
 * pro MESMO item, o que tornaria a guarda de saldo ambígua. Se o item
 * NUNCA foi detalhado por Atividade, a adjudicação aponta direto pro
 * item (`requisicao_compra_item_parcela_id = null`) — "sem detalhamento
 * de atividade" (Seção 7/22 do pedido), nunca inventando uma parcela.
 * Validado em `App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra::
 * garantirGranularidadeCoerente()`.
 *
 * `requisicao_compra_item_id` sempre presente (mesmo quando há parcela
 * — redundante com `parcela->requisicao_compra_item_id`, mas mantido
 * explícito por 2 motivos: permite listar/agrupar adjudicações por item
 * sem precisar de JOIN condicional, e é o próprio alvo quando não há
 * parcela). `restrictOnDelete()` nos dois — evidência cross-aggregate,
 * nunca perdida silenciosamente.
 *
 * `requisicao_compra_adjudicacao_id` é `cascadeOnDelete()` — item é
 * compositional ao cabeçalho (mesmo padrão de `requisicao_compra_anexos`).
 * Como `RequisicaoCompraAdjudicacaoObserver` bloqueia delete/forceDelete
 * do cabeçalho incondicionalmente, este cascade nunca é exercido na
 * prática — mantido só por consistência estrutural.
 *
 * `UNIQUE` sobre o trio (adjudicação, item, parcela) — protege
 * corretamente o caso com parcela (não-nula); o caso sem parcela
 * (`NULL`, múltiplos NULL nunca colidem no MySQL) é protegido por
 * checagem explícita na Action (mesma classe de limitação já aceita e
 * documentada em outras tabelas do projeto, ex.:
 * `destinacoes_planejadas_material`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicao_compra_adjudicacao_itens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('requisicao_compra_adjudicacao_id');
            $table->foreign('requisicao_compra_adjudicacao_id', 'rc_adj_item_adjudicacao_fk')
                ->references('id')->on('requisicao_compra_adjudicacoes')
                ->cascadeOnDelete();

            $table->foreignUlid('requisicao_compra_item_id');
            $table->foreign('requisicao_compra_item_id', 'rc_adj_item_rcitem_fk')
                ->references('id')->on('requisicao_compra_itens')
                ->restrictOnDelete();

            $table->foreignUlid('requisicao_compra_item_parcela_id')->nullable();
            $table->foreign('requisicao_compra_item_parcela_id', 'rc_adj_item_parcela_fk')
                ->references('id')->on('requisicao_compra_item_parcelas')
                ->restrictOnDelete();

            $table->decimal('quantidade', 14, 3);

            $table->timestamps();

            $table->unique(
                ['requisicao_compra_adjudicacao_id', 'requisicao_compra_item_id', 'requisicao_compra_item_parcela_id'],
                'rc_adj_item_unique'
            );
            $table->index(['tenant_id', 'requisicao_compra_item_id'], 'rc_adj_item_tenant_rcitem_idx');
            $table->index(['tenant_id', 'requisicao_compra_item_parcela_id'], 'rc_adj_item_tenant_parcela_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicao_compra_adjudicacao_itens');
    }
};
