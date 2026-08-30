<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.6 — recebimento físico de material sobre um
 * `PedidoCompraItem`. Fato histórico, append-only (mesmo espírito de
 * `grd_recolhimentos`, Ciclo 18 — `UPDATED_AT = null`, nunca editado nem
 * apagado, só `App\Actions\Suprimentos\RegistrarRecebimentoPedido` escreve
 * aqui).
 *
 * **Cardinalidade — decisão de arquitetura, investigada antes desta
 * migration (seções 4/5 do pedido)**: SEM cabeçalho de "evento físico"
 * (`RecebimentoCompra`) — esta tabela é headerless, diretamente escopada
 * a `pedido_compra_item_id`, mesmo padrão exato de `grd_recolhimentos` →
 * `grd_distribuicoes` (que também não tem cabeçalho de "evento de
 * recolhimento"). Justificativa: nenhum requisito funcional do pedido
 * (testes A-AL, probes P1-P10) exige consultar "o que mais chegou na
 * mesma remessa/caminhão" como entidade de primeira classe — um caminhão
 * trazendo itens de 2+ Pedidos vira simplesmente N linhas independentes
 * nesta tabela, criadas numa única ação de UI (conveniência de tela, não
 * requisito de schema). Isso também elimina por completo a pergunta "o
 * cabeçalho pode cruzar Pedidos?" (STOP condition da seção 52) — não há
 * cabeçalho cujo escopo precise ser decidido. Consistente com a instrução
 * explícita do pedido: "não criar cabeçalho se não houver utilidade
 * real".
 *
 * `pedido_compra_item_id` é `restrictOnDelete()` — mesma lição de
 * evidência histórica de sempre (nunca `cascadeOnDelete()` numa FK que
 * referencia um fato físico auditável). Na prática nunca é exercida por
 * um delete concorrente, porque `PedidoCompraItem` só existe de verdade
 * (com saldo positivo pra receber) depois do Pedido ser Emitido — e a
 * partir daí o Pedido inteiro é permanentemente imutável.
 *
 * `registrado_por` é `nullOnDelete()` — "usuário removido" preserva o
 * fato, mesmo padrão de `emitido_por`/`registrado_por` em toda a árvore
 * GRD/RC/RP.
 *
 * Sem `unique` — múltiplos eventos de recebimento sobre o MESMO item ao
 * longo do tempo são o comportamento normal (entrega parcial), nunca uma
 * violação. A soma acumulada é sempre DERIVADA em tempo de leitura
 * (`PedidoCompraItem::quantidadeRecebida()`), nunca uma coluna própria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recebimentos_pedido', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('pedido_compra_item_id');
            $table->foreign('pedido_compra_item_id', 'recebimento_pedido_item_fk')
                ->references('id')->on('pedido_compra_itens')
                ->restrictOnDelete();

            $table->decimal('quantidade_recebida', 14, 3);
            $table->date('recebido_em');

            $table->foreignUlid('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('local_recebimento')->nullable();
            $table->text('observacao')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'pedido_compra_item_id'], 'recebimento_pedido_tenant_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recebimentos_pedido');
    }
};
