<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.4 — linha de consumo de uma RC sobre uma
 * `AlocacaoRequisicaoPacote` específica (N:N + quantidade — nunca
 * assume consumo total da alocação; várias RCs podem consumir frações
 * distintas da MESMA alocação, desde que a soma nunca ultrapasse
 * `quantidade_alocada`).
 *
 * `alocacao_requisicao_pacote_id` é `restrictOnDelete()` — mesma lição
 * de evidência histórica já aplicada em toda a árvore GED/Take Off/RP:
 * uma RC nunca fica órfã por uma alocação apagada. Como a alocação
 * também ganha, nesta mesma etapa, uma trava de "não pode ser
 * reduzida/removida enquanto há consumo de RC" (`AlocarRequisicaoAoPacote`,
 * seções 44-46 do pedido), na prática essa FK nunca é exercida por um
 * delete concorrente — é defesa em profundidade, não o mecanismo
 * principal.
 *
 * `UNIQUE(requisicao_compra_id, alocacao_requisicao_pacote_id)` — no
 * máximo 1 linha por par RC×Alocação (mesmo padrão de
 * `requisicao_planejamento_itens`/`alocacoes_requisicao_pacote`):
 * alterar quantidade SUBSTITUI a linha existente, nunca soma uma
 * segunda — elimina "contar a própria linha duas vezes" por construção.
 *
 * Colunas `*_snapshot` (nullable, vazias em Rascunho, congeladas só na
 * emissão por `EmitirRequisicaoCompra`) espelham EXATAMENTE
 * `requisicao_planejamento_itens` — cópia direta da cadeia
 * ItemTakeOff/Lista/Documento no instante da emissão da RC, nunca
 * dependente de reler `RequisicaoPlanejamentoItem.*_snapshot` (entidade
 * diferente, ainda que também congelada) — mantém a RC 100%
 * autocontida.
 *
 * Sem SoftDeletes própria: enquanto Rascunho, um item pode ser removido
 * de verdade (mesmo padrão de `requisicao_planejamento_itens`); depois
 * de Emitida, a RC inteira fica imutável — garantia real vem do guard
 * na Action (`AtualizarRascunhoRequisicaoCompra`, checando
 * `$rc->estaRascunho()`), mesmo padrão de
 * `AtualizarRascunhoRequisicaoPlanejamento`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicao_compra_itens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('requisicao_compra_id');
            $table->foreign('requisicao_compra_id', 'req_compra_item_rc_fk')
                ->references('id')->on('requisicoes_compra')
                ->cascadeOnDelete();

            $table->foreignUlid('alocacao_requisicao_pacote_id');
            $table->foreign('alocacao_requisicao_pacote_id', 'req_compra_item_alocacao_fk')
                ->references('id')->on('alocacoes_requisicao_pacote')
                ->restrictOnDelete();

            $table->decimal('quantidade', 14, 3);

            $table->string('codigo_item_snapshot')->nullable();
            $table->string('descricao_snapshot')->nullable();
            $table->string('unidade_snapshot')->nullable();
            $table->string('lista_codigo_snapshot')->nullable();
            $table->string('tipo_lista_snapshot')->nullable();
            $table->string('documento_codigo_snapshot')->nullable();
            $table->string('revisao_snapshot')->nullable();

            $table->timestamps();

            $table->unique(['requisicao_compra_id', 'alocacao_requisicao_pacote_id'], 'req_compra_item_rc_alocacao_unique');
            $table->index(['tenant_id', 'alocacao_requisicao_pacote_id'], 'req_compra_item_tenant_alocacao_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicao_compra_itens');
    }
};
