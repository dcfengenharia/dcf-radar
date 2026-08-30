<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.1 — UnidadeEstoque: identidade opcional de uma
 * unidade física rastreável (bobina/lote/heat/serial). NÃO é criada pra
 * todo material — Material em modo Quantitativo nunca precisa de uma
 * linha aqui (MovimentacaoEstoque.unidade_estoque_id fica null e o saldo
 * é agregado direto por material_id+local_estoque_id). Só Lote e
 * Serializado usam esta tabela — uma entidade só, nunca 3 tabelas de
 * estoque por modo (investigação 20.0, Seção 8/9).
 *
 * `codigo_lote`: identidade dentro do MESMO Material (unique(tenant_id,
 * material_id, codigo_lote) quando não nulo — MySQL trata cada NULL como
 * distinto, então Quantitativo/Serializado nunca colidem por terem
 * codigo_lote sempre nulo). `serial_unico`: identidade única por tenant
 * inteiro (unique(tenant_id, serial_unico) quando não nulo) — um serial
 * físico não pode existir duas vezes no mesmo tenant, mesmo em obras
 * diferentes (Seção 10 da investigação).
 *
 * `recebimento_pedido_id` (nullable, restrictOnDelete) é a origem
 * histórica desta unidade quando ela nasceu de uma entrada vinda de
 * Pedido/Recebimento — nunca cascadeOnDelete (evidência histórica, mesma
 * lição já aplicada em toda FK de auditoria do projeto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_estoque', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('material_id');
            $table->foreign('material_id', 'unidades_estoque_material_fk')
                ->references('id')->on('materiais')
                ->restrictOnDelete();

            $table->foreignUlid('local_estoque_id');
            $table->foreign('local_estoque_id', 'unidades_estoque_local_fk')
                ->references('id')->on('locais_estoque')
                ->restrictOnDelete();

            $table->string('codigo_lote')->nullable();
            $table->string('serial_unico')->nullable();
            $table->string('identificador_logistico')->nullable();

            $table->foreignUlid('recebimento_pedido_id')->nullable();
            $table->foreign('recebimento_pedido_id', 'unidades_estoque_recebimento_fk')
                ->references('id')->on('recebimentos_pedido')
                ->restrictOnDelete();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'material_id', 'codigo_lote'], 'unidades_estoque_material_lote_unique');
            $table->unique(['tenant_id', 'serial_unico'], 'unidades_estoque_serial_unique');
            $table->index(['tenant_id', 'local_estoque_id'], 'unidades_estoque_tenant_local_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_estoque');
    }
};
