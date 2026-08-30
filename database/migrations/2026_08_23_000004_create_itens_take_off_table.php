<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.1 — Take Off (LM/LI). Pendura direto em
 * `documento_engenharia_revisao_id` (D1: pertence à Revisão, nunca ao
 * Documento) — mesmo padrão de `GrdItem`, sem entidade "ListaMaterial"
 * intermediária: `tipo` (Material|Instrumento) distingue LM/LI dentro da
 * MESMA revisão, evitando duplicar schema pra duas listas que sempre
 * tiveram o mesmo formato de linha.
 *
 * Cardinalidade entre revisões: nenhuma automática. Uma revisão nova
 * nasce sem nenhum item de Take Off — mesmo princípio já aplicado 3x no
 * projeto (PDF/status/liberação nunca copiados de revisão pra revisão).
 * `documento_engenharia_revisao_id` é `cascadeOnDelete()` (não é
 * evidência histórica cross-entidade como GRD/Fotografia O — é filho
 * direto da própria revisão, apaga junto).
 *
 * Identidade: `unique(documento_engenharia_revisao_id, tipo, codigo)` —
 * código é opcional; MySQL trata cada NULL como distinto num índice
 * único, então itens sem código nunca colidem entre si (mesmo mecanismo
 * já usado em GRD/Fotografia O).
 *
 * Sem coluna de quantidade requisitada/saldo aqui — isso é 19.2 (RP),
 * sempre calculado por agregação sobre RPItem, nunca armazenado
 * redundante nesta tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_take_off', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('documento_engenharia_revisao_id')
                ->constrained('documento_engenharia_revisoes')
                ->cascadeOnDelete();

            $table->string('tipo');
            $table->string('codigo')->nullable();
            $table->string('descricao');

            $table->foreignUlid('unidade_medida_id')->nullable()->constrained('unidades_medida')->nullOnDelete();
            $table->foreignUlid('familia_material_id')->nullable()->constrained('familias_material')->nullOnDelete();
            $table->foreignUlid('disciplina_id')->nullable()->constrained('disciplinas')->nullOnDelete();

            $table->decimal('quantidade', 14, 3);
            $table->text('observacoes')->nullable();
            $table->string('origem')->default('manual');

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['documento_engenharia_revisao_id', 'tipo', 'codigo'], 'itens_take_off_revisao_tipo_codigo_unique');
            $table->index(['tenant_id', 'documento_engenharia_revisao_id'], 'itens_take_off_tenant_revisao_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_take_off');
    }
};
