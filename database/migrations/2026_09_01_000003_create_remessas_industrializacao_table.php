<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.5 — RemessaIndustrializacao: evento FÍSICO
 * append-only de transferência de matéria-prima entre o Local próprio
 * da obra e o Local Terceiro de uma Ordem — nunca editado/apagado
 * (`App\Observers\RemessaIndustrializacaoObserver`, mesmo padrão de
 * `MovimentacaoEstoque`/`RecebimentoPedido`).
 *
 * **É a "identidade de operação" pedida pelo usuário** (Seção 10 da
 * investigação: "não usar Saída+Entrada desconectadas") — correlaciona
 * `movimentacao_saida_id`+`movimentacao_entrada_id` (ambas
 * `restrictOnDelete()`, sempre criadas na MESMA transação por
 * `App\Actions\Estoque\RegistrarRemessaIndustrializacao`) como um único
 * fato de negócio. `direcao` (`App\Enums\DirecaoRemessaIndustrializacao`:
 * Envio|RetornoSobra) decide qual ponta é Saida e qual é Entrada —
 * Envio: Saida no Local próprio + Entrada no Local Terceiro; RetornoSobra:
 * o inverso (matéria-prima nunca consumida volta fisicamente à obra).
 *
 * `unidade_estoque_id` nullable — só populado pra Material Lote/Serial.
 * 1 Ordem pode ter N remessas (Seção 34/35 — "não assumir 1 Ordem=1
 * remessa").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remessas_industrializacao', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->ulid('ordem_industrializacao_id');
            $table->ulid('material_id');
            $table->ulid('unidade_estoque_id')->nullable();
            $table->string('direcao');
            $table->decimal('quantidade', 14, 3);
            $table->date('ocorrido_em');
            $table->ulid('movimentacao_saida_id');
            $table->ulid('movimentacao_entrada_id');
            $table->ulid('registrado_por')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('ordem_industrializacao_id', 'remessa_industr_ordem_fk')
                ->references('id')->on('ordens_industrializacao')->restrictOnDelete();
            $table->foreign('material_id', 'remessa_industr_material_fk')
                ->references('id')->on('materiais')->restrictOnDelete();
            $table->foreign('unidade_estoque_id', 'remessa_industr_unidade_fk')
                ->references('id')->on('unidades_estoque')->restrictOnDelete();
            $table->foreign('movimentacao_saida_id', 'remessa_industr_saida_fk')
                ->references('id')->on('movimentacoes_estoque')->restrictOnDelete();
            $table->foreign('movimentacao_entrada_id', 'remessa_industr_entrada_fk')
                ->references('id')->on('movimentacoes_estoque')->restrictOnDelete();
            $table->foreign('registrado_por', 'remessa_industr_autor_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'ordem_industrializacao_id'], 'remessa_industr_tenant_ordem_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remessas_industrializacao');
    }
};
