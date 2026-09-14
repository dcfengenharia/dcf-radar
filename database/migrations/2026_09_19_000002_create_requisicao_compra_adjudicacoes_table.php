<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 — cabeçalho de uma decisão comercial de adjudicação: "o
 * fornecedor X ganhou determinada(s) quantidade(s) desta RC". NUNCA um
 * `fornecedor_vencedor_id` único na própria `requisicoes_compra` (Seção
 * 3 do pedido — uma RC pode ser legitimamente dividida entre vários
 * fornecedores) — várias linhas desta tabela podem existir pra UMA
 * mesma RC, cada uma com seu próprio fornecedor.
 *
 * `requisicao_compra_id` é `restrictOnDelete()` — evidência histórica
 * cross-aggregate (mesmo padrão de `pedidos_compra.requisicao_compra_id`
 * implícito via a própria RC nunca ser fisicamente excluída depois de
 * Emitida). Como uma adjudicação só pode ser criada sobre uma RC já
 * Emitida/Concluída (nunca Rascunho — `RequisicaoCompraAdjudicacaoInvalidaException`
 * bloqueia isso na Action), e essas duas RCs nunca são fisicamente
 * excluídas (`RequisicaoCompraObserver`), este `restrictOnDelete()` é
 * defesa em profundidade, nunca o mecanismo principal.
 *
 * `fornecedor_id` é `restrictOnDelete()` — nunca perder silenciosamente
 * "quem ganhou o quê" por causa de um `forceDelete()` futuro do
 * Fornecedor (evidência de decisão comercial, mesma filosofia de sempre).
 *
 * `status` (Ativa|Cancelada) é o ÚNICO mecanismo de "reconsideração"
 * desta V1 — nunca UPDATE silencioso de fornecedor/quantidade num
 * registro já existente representando uma decisão diferente. Cancelar
 * preserva a linha pra sempre (`App\Observers\
 * RequisicaoCompraAdjudicacaoObserver` bloqueia delete/forceDelete
 * incondicionalmente — mesmo padrão de `ListaEngenhariaObserver`).
 *
 * `anexo_id` (nullable, Seção 19 — vínculo OPCIONAL à evidência que
 * suportou a decisão) é `restrictOnDelete()`: uma vez que um anexo
 * suporta uma decisão registrada, ele nunca pode ser apagado por baixo
 * dela (a mensagem amigável vem do `RequisicaoCompraAnexoObserver`,
 * este FK é só a defesa final).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicao_compra_adjudicacoes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('requisicao_compra_id');
            $table->foreign('requisicao_compra_id', 'rc_adjudicacao_rc_fk')
                ->references('id')->on('requisicoes_compra')
                ->restrictOnDelete();

            $table->foreignUlid('fornecedor_id');
            $table->foreign('fornecedor_id', 'rc_adjudicacao_fornecedor_fk')
                ->references('id')->on('fornecedores')
                ->restrictOnDelete();

            $table->string('status')->default('ativa');

            $table->foreignUlid('decidido_por_id')->nullable();
            $table->foreign('decidido_por_id', 'rc_adjudicacao_decidido_por_fk')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->timestamp('decidido_em');
            $table->text('justificativa');
            $table->text('observacao')->nullable();

            $table->foreignUlid('anexo_id')->nullable();
            $table->foreign('anexo_id', 'rc_adjudicacao_anexo_fk')
                ->references('id')->on('requisicao_compra_anexos')
                ->restrictOnDelete();

            $table->foreignUlid('cancelado_por_id')->nullable();
            $table->foreign('cancelado_por_id', 'rc_adjudicacao_cancelado_por_fk')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->timestamp('cancelado_em')->nullable();
            $table->text('motivo_cancelamento')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'requisicao_compra_id'], 'rc_adjudicacao_tenant_rc_idx');
            $table->index(['tenant_id', 'fornecedor_id'], 'rc_adjudicacao_tenant_fornecedor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicao_compra_adjudicacoes');
    }
};
