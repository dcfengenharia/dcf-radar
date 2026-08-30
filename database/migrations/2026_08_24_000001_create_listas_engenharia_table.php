<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.1.CORREÇÃO — entidade própria de Lista (LM/LI), que
 * a 19.1 original não tinha (itens penduravam direto na revisão, sem
 * agrupamento — duas LMs de Material na mesma revisão eram indistinguíveis
 * estruturalmente). `tipo` (Material|Instrumento) na PRÓPRIA lista, não
 * duplicado no item — LM-001/LM-002/LI-001 convivem na mesma revisão sem
 * duas tabelas paralelas.
 *
 * `documento_engenharia_revisao_id` é `restrictOnDelete()` (nunca
 * cascade) — mesmo padrão de `GrdItem.documento_engenharia_revisao_id`
 * (evidência documental pendurada na revisão nunca some silenciosamente
 * se a revisão for removida; hoje não existe nenhum fluxo de exclusão de
 * revisão no projeto, mas o schema não deve assumir isso pra sempre).
 *
 * `codigo` é OBRIGATÓRIO aqui (diferente do código do ITEM, que é
 * opcional) — é o que distingue LM-001 de LM-002; sem código não há como
 * o domínio diferenciar duas listas do mesmo tipo na mesma revisão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listas_engenharia', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('documento_engenharia_revisao_id')
                ->constrained('documento_engenharia_revisoes')
                ->restrictOnDelete();

            $table->string('tipo');
            $table->string('codigo');
            $table->string('titulo')->nullable();
            $table->foreignUlid('disciplina_id')->nullable()->constrained('disciplinas')->nullOnDelete();
            $table->text('observacao')->nullable();

            $table->timestamps();

            $table->unique(['documento_engenharia_revisao_id', 'tipo', 'codigo'], 'listas_engenharia_revisao_tipo_codigo_unique');
            $table->index(['tenant_id', 'documento_engenharia_revisao_id'], 'listas_engenharia_tenant_revisao_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listas_engenharia');
    }
};
