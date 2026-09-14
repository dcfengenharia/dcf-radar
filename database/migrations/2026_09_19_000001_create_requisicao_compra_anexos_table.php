<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 — Dossiê Documental da RC. Toda `RequisicaoCompra` pode ter
 * documentos/anexos (Proposta/Contrato/Parecer/MapaComparativo/
 * Correspondencia/Outro), independentemente do status da RC (Rascunho,
 * Emitida ou Concluída — Seção 14 do pedido: propostas/negociações
 * podem existir antes mesmo da RC ser formalmente emitida) e
 * independentemente do Fluxo de Suprimento configurável.
 *
 * `requisicao_compra_id` é `cascadeOnDelete()` — anexo é compositional
 * à RC (mesmo padrão de `atividade_anexos.atividade_id`), nunca uma
 * referência cross-aggregate. Como `RequisicaoCompraObserver` só
 * permite excluir a RC enquanto Rascunho, este cascade só é exercido
 * nesse caso (RC Emitida/Concluída nunca é excluída, então seus anexos
 * nunca são apagados por cascade).
 *
 * `fornecedor_id` é opcional (Seção 14 — "Outro"/"Parecer" interno pode
 * não ter fornecedor) e `nullOnDelete()` — perder o fornecedor associado
 * (soft-delete real é o único caminho hoje) nunca apaga a evidência,
 * só desassocia o rótulo.
 *
 * `substitui_anexo_id` (self-FK, Seção 16 — versionamento): a versão
 * NOVA aponta pra versão ANTERIOR, nunca o contrário. `restrictOnDelete()`
 * — nunca permite apagar fisicamente uma versão anterior enquanto uma
 * versão mais nova a referenciar (defesa final; o Observer dá a
 * mensagem amigável antes de chegar aqui).
 *
 * Metadados de Proposta V1 (Seção 17) ficam NO PRÓPRIO anexo — sempre
 * nullable, nunca obrigatórios, só fazem sentido quando
 * `tipo_documento = proposta` mas nunca são validados como obrigatórios
 * mesmo nesse caso (decisão do usuário na fase de decisão: nunca inferir
 * "menor preço" automaticamente, tudo é registro livre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicao_compra_anexos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('requisicao_compra_id');
            $table->foreign('requisicao_compra_id', 'rc_anexo_rc_fk')
                ->references('id')->on('requisicoes_compra')
                ->cascadeOnDelete();

            $table->string('tipo_documento');

            $table->foreignUlid('fornecedor_id')->nullable();
            $table->foreign('fornecedor_id', 'rc_anexo_fornecedor_fk')
                ->references('id')->on('fornecedores')
                ->nullOnDelete();

            $table->string('nome_original');
            $table->string('caminho_arquivo');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('tamanho_bytes');
            $table->text('descricao')->nullable();

            $table->foreignUlid('enviado_por_id')->nullable();
            $table->foreign('enviado_por_id', 'rc_anexo_enviado_por_fk')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->foreignUlid('substitui_anexo_id')->nullable();
            $table->foreign('substitui_anexo_id', 'rc_anexo_substitui_fk')
                ->references('id')->on('requisicao_compra_anexos')
                ->restrictOnDelete();

            // Proposta V1 leve (Seção 17) — sempre opcionais.
            $table->decimal('valor_total_referencia', 14, 2)->nullable();
            $table->string('prazo_referencia')->nullable();
            $table->date('validade_ate')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'requisicao_compra_id'], 'rc_anexo_tenant_rc_idx');
            $table->index(['tenant_id', 'fornecedor_id'], 'rc_anexo_tenant_fornecedor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicao_compra_anexos');
    }
};
