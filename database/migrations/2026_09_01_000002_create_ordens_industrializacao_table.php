<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.5 — OrdemIndustrializacao: o processo/contrato
 * operacional com UM Fornecedor pra fabricar/industrializar material
 * enviado pela obra. `fornecedor_id` + `local_terceiro_id` (o
 * LocalEstoque tipo Terceiro específico usado nesta Ordem — validado
 * em `App\Actions\Estoque\CriarOrdemIndustrializacao` como pertencente
 * ao MESMO Fornecedor) — ambos `restrictOnDelete()`, evidência
 * histórica de sempre. `item_suprimento_id` (Pacote) é NULLABLE — nunca
 * obrigatório, mesmo padrão de toda a cadeia do Ciclo 19/20.
 *
 * `numero` segue o MESMO padrão de RC/Pedido/GRD (lock em `Work`,
 * `MAX(numero)+1`, `UNIQUE(obra_id, numero)`) — nullable em Rascunho,
 * atribuído só na emissão.
 *
 * `status` (`App\Enums\StatusOrdemIndustrializacao`: Rascunho|Emitida|
 * Concluida) — Concluida é transição DERIVADA (todos os Produtos
 * plenamente entregues), nunca setada manualmente, mesmo padrão de
 * `StatusRequisicaoCompra`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordens_industrializacao', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->ulid('fornecedor_id');
            $table->ulid('local_terceiro_id');
            $table->ulid('item_suprimento_id')->nullable();
            $table->unsignedInteger('numero')->nullable();
            $table->string('status')->default('rascunho');
            $table->text('observacao')->nullable();
            $table->ulid('created_by_id')->nullable();
            $table->timestamp('emitida_em')->nullable();
            $table->ulid('emitida_por')->nullable();
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();

            $table->foreign('fornecedor_id', 'ord_industr_fornecedor_fk')
                ->references('id')->on('fornecedores')->restrictOnDelete();
            $table->foreign('local_terceiro_id', 'ord_industr_local_fk')
                ->references('id')->on('locais_estoque')->restrictOnDelete();
            $table->foreign('item_suprimento_id', 'ord_industr_pacote_fk')
                ->references('id')->on('itens_suprimento')->restrictOnDelete();
            $table->foreign('created_by_id', 'ord_industr_autor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('emitida_por', 'ord_industr_emissor_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->unique(['obra_id', 'numero'], 'ord_industr_obra_numero_unique');
            $table->index(['tenant_id', 'obra_id', 'status'], 'ord_industr_tenant_obra_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordens_industrializacao');
    }
};
