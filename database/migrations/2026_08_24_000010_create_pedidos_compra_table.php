<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.5 — Pedido/Ordem de Compra: compromisso comercial
 * formal gerado a partir de UMA `RequisicaoCompra` (nunca cruza RCs).
 * 1 RC pode gerar N Pedidos (fracionamento por fornecedor/lote/prazo/
 * negociação/complementação). Schema espelha `requisicoes_compra`
 * (19.4) — mesmo fato documental Rascunho→Emitido, mesma numeração via
 * lock em linha estável (Work), mesmo UNIQUE(obra_id, numero).
 *
 * `requisicao_compra_id` é `restrictOnDelete()` — mesma lição de
 * evidência histórica de sempre. Só é possível criar Pedido sobre uma RC
 * `Emitida`/`Concluida` (nunca Rascunho — validado na Action, nunca no
 * schema, mesmo padrão de "só RP Emitida é alocável" da 19.3) — o que
 * torna a proteção de exclusão da RC redundante-mas-segura: uma RC com
 * Pedido já é sempre Emitida/Concluida, e essas já são permanentemente
 * imutáveis desde 19.4 (nunca voltam a Rascunho, nunca são excluídas).
 *
 * `fornecedor_id` também `restrictOnDelete()` — o cadastro do
 * `Fornecedor` nunca pode ser destruído enquanto referenciado, mas o
 * HISTÓRICO do Pedido nunca depende dele ao vivo depois da emissão:
 * `fornecedor_nome_snapshot`/`fornecedor_cnpj_snapshot` (nullable, só
 * congelados na emissão) garantem que um Fornecedor soft-deletado ou
 * editado depois nunca muda o que um Pedido já emitido exibe.
 *
 * **Contrato (investigação da 19.5, decisão do usuário)**: sem
 * requisito adicional além de número/data/fornecedor — em vez de uma
 * entidade própria, `numero_contrato`/`data_contrato` são campos
 * OPCIONAIS diretamente no Pedido (Fornecedor já é compartilhado com o
 * próprio Pedido, não precisa de FK extra).
 *
 * `status`: Rascunho|Emitido (`App\Enums\StatusPedidoCompra`) — sem
 * Cancelado/Concluido nesta fase (sem precedente/requisito real
 * confirmado, mesma decisão já tomada pra `StatusGrd`/
 * `StatusRequisicaoPlanejamento`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_compra', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            $table->foreignUlid('requisicao_compra_id');
            $table->foreign('requisicao_compra_id', 'pedido_compra_rc_fk')
                ->references('id')->on('requisicoes_compra')
                ->restrictOnDelete();

            $table->foreignUlid('fornecedor_id');
            $table->foreign('fornecedor_id', 'pedido_compra_fornecedor_fk')
                ->references('id')->on('fornecedores')
                ->restrictOnDelete();
            $table->string('fornecedor_nome_snapshot')->nullable();
            $table->string('fornecedor_cnpj_snapshot')->nullable();

            $table->unsignedInteger('numero')->nullable();
            $table->string('status')->default('rascunho');

            $table->date('data_prevista_entrega')->nullable();
            $table->string('numero_contrato')->nullable();
            $table->date('data_contrato')->nullable();
            $table->text('observacao')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('emitido_em')->nullable();
            $table->foreignUlid('emitido_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['obra_id', 'numero'], 'pedido_compra_obra_numero_unique');
            $table->index(['tenant_id', 'requisicao_compra_id'], 'pedido_compra_tenant_rc_idx');
            $table->index(['tenant_id', 'obra_id', 'status'], 'pedido_compra_tenant_obra_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_compra');
    }
};
