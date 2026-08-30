<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.6 — TransferenciaEstoque: evento FÍSICO
 * append-only de transferência de material entre dois Locais PRÓPRIOS
 * da MESMA obra (nunca envolve Local tipo Terceiro — esse fluxo já é
 * coberto por RemessaIndustrializacao/Ordem de Industrialização, Ciclo
 * 20.5; permitir uma segunda rota genérica pro mesmo movimento físico
 * criaria uma forma de mover material de/para um Terceiro sem
 * nenhum vínculo com uma Ordem, quebrando a rastreabilidade que a
 * Industrialização já garante).
 *
 * **É a "identidade de operação" da Transferência** (mesmo padrão já
 * estabelecido por `remessas_industrializacao`, Ciclo 20.5) —
 * correlaciona `movimentacao_saida_id`+`movimentacao_entrada_id` (ambas
 * `restrictOnDelete()`, sempre criadas na MESMA transação por
 * `App\Actions\Estoque\RegistrarTransferenciaEstoque`) como um único
 * fato de negócio, nunca duas Movimentacoes desconectadas.
 *
 * `unidade_estoque_id` nullable — só populado pra Material Lote/Serial.
 * `UnidadeEstoque.local_estoque_id` NUNCA é atualizado por uma
 * Transferência (mesma correção já aplicada na 20.5.CORREÇÃO) — "onde
 * a unidade está" continua sempre derivado do ledger via
 * `App\Support\Estoque\SaldoEstoque::porUnidadeLocal()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferencias_estoque', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->ulid('material_id');
            $table->ulid('unidade_estoque_id')->nullable();
            $table->ulid('local_origem_id');
            $table->ulid('local_destino_id');
            $table->decimal('quantidade', 14, 3);
            $table->date('ocorrido_em');
            $table->ulid('movimentacao_saida_id');
            $table->ulid('movimentacao_entrada_id');
            $table->ulid('registrado_por')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('material_id', 'transf_estoque_material_fk')
                ->references('id')->on('materiais')->restrictOnDelete();
            $table->foreign('unidade_estoque_id', 'transf_estoque_unidade_fk')
                ->references('id')->on('unidades_estoque')->restrictOnDelete();
            $table->foreign('local_origem_id', 'transf_estoque_origem_fk')
                ->references('id')->on('locais_estoque')->restrictOnDelete();
            $table->foreign('local_destino_id', 'transf_estoque_destino_fk')
                ->references('id')->on('locais_estoque')->restrictOnDelete();
            $table->foreign('movimentacao_saida_id', 'transf_estoque_saida_fk')
                ->references('id')->on('movimentacoes_estoque')->restrictOnDelete();
            $table->foreign('movimentacao_entrada_id', 'transf_estoque_entrada_fk')
                ->references('id')->on('movimentacoes_estoque')->restrictOnDelete();
            $table->foreign('registrado_por', 'transf_estoque_autor_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'obra_id'], 'transf_estoque_tenant_obra_idx');
            $table->index(['obra_id', 'material_id'], 'transf_estoque_obra_material_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferencias_estoque');
    }
};
