<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.2 — linha de uma Requisição do Planejamento (RP).
 * A ORIGEM canônica de descrição/unidade/família/lista/documento/revisão
 * continua sendo `item_take_off_id → ItemTakeOff → ListaEngenharia →
 * DocumentoEngenhariaRevisao` — este item NÃO duplica nenhum desses
 * campos como fonte viva de negócio, só `quantidade_requisitada`.
 *
 * `item_take_off_id` é `restrictOnDelete()`, nunca `cascadeOnDelete()` —
 * mesma lição já aplicada em toda FK de evidência histórica do projeto
 * (GrdItem.documento_engenharia_revisao_id, listas_engenharia.
 * documento_engenharia_revisao_id, grd_recolhimentos.grd_distribuicao_id):
 * a RP referencia o ItemTakeOff EXATO que requisitou, mesmo que ele
 * pertença a uma revisão que depois fique histórica (D1/19.1.HARDENING —
 * ItemTakeOff de revisão superada nunca é apagado, só congelado).
 *
 * Colunas `*_snapshot` (todas nullable): ficam vazias enquanto a RP é
 * Rascunho — só são congeladas por
 * App\Actions\Suprimentos\EmitirRequisicaoPlanejamento no instante da
 * emissão (mesmo padrão de GrdItem: emitir = tirar a fotografia). Uma RP
 * emitida nunca volta a ler o ItemTakeOff/Lista/Documento ao vivo pra
 * exibir esses campos — só a FK continua viva, e só pra fins de
 * CONCILIAÇÃO quantitativa (somar saldo requisitado), nunca pra exibição
 * de texto histórico.
 *
 * Sem SoftDeletes própria: enquanto a RP é Rascunho, um item pode ser
 * removido de verdade (delete físico, mesmo padrão de GrdItem — não há
 * necessidade de histórico de "o que foi removido durante a edição do
 * rascunho"); depois de Emitida, a RP inteira (e portanto seus itens)
 * fica imutável — a garantia real vem do guard na Action
 * (App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento,
 * checando `$rp->estaRascunho()` antes de qualquer mutação), mesmo
 * padrão de App\Actions\Engenharia\AtualizarRascunhoGrd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicao_planejamento_itens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('requisicao_planejamento_id')
                ->constrained('requisicoes_planejamento')
                ->cascadeOnDelete();
            $table->foreignUlid('item_take_off_id')
                ->constrained('itens_take_off')
                ->restrictOnDelete();

            $table->decimal('quantidade_requisitada', 14, 3);

            $table->string('codigo_item_snapshot')->nullable();
            $table->string('descricao_snapshot')->nullable();
            $table->string('unidade_snapshot')->nullable();
            $table->string('lista_codigo_snapshot')->nullable();
            $table->string('tipo_lista_snapshot')->nullable();
            $table->string('documento_codigo_snapshot')->nullable();
            $table->string('revisao_snapshot')->nullable();

            $table->timestamps();

            $table->unique(['requisicao_planejamento_id', 'item_take_off_id'], 'req_planejamento_item_unique');
            $table->index(['tenant_id', 'item_take_off_id'], 'req_planejamento_item_tenant_ito_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicao_planejamento_itens');
    }
};
