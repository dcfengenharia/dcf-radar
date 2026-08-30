<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.1 — catálogo mestre de Material/SKU (decisão do
 * usuário, investigação 20.0: ItemTakeOff é ocorrência documental de
 * necessidade, nunca o material físico — duas LMs do mesmo cabo em
 * revisões diferentes nunca se somavam sozinhas sem esta entidade).
 *
 * Tenant-scoped (não obra-scoped) — mesmo escopo já usado por
 * FamiliaMaterial/UnidadeMedida (catálogos irmãos de classificação do
 * mesmo domínio de Take Off): o mesmo SKU pode ser comprado/aplicado em
 * várias obras do mesmo tenant, e nenhum precedente do projeto contraria
 * esse escopo pra este tipo de catálogo.
 *
 * `codigo` é OBRIGATÓRIO (diferente de ItemTakeOff.codigo, que é
 * opcional) — Material é o catálogo mestre, então precisa de uma
 * identidade estrutural real: unique(tenant_id, codigo). descricao,
 * familia_material_id e unidade_medida_id nunca são identidade, só
 * classificação/exibição.
 *
 * `unidade_medida_id` é OBRIGATÓRIO e restrictOnDelete (diferente de
 * ItemTakeOff.unidade_medida_id, nullable) — o Material precisa de uma
 * unidade estável pra toda a matemática de saldo em MovimentacaoEstoque
 * fazer sentido; nunca pode ficar "sem unidade" depois de criado.
 *
 * `modo_rastreabilidade` (App\Enums\ModoRastreabilidadeMaterial) decide
 * só quais campos de UnidadeEstoque são exigidos numa entrada — nunca
 * gera tabelas de estoque separadas por modo.
 *
 * SoftDeletes (Seção 25 da investigação): Material histórico nunca pode
 * desaparecer da cadeia — inativação (soft-delete OU ativo=false, ambos
 * suportados) só impede NOVA entrada, nunca apaga histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materiais', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('codigo');
            $table->string('descricao');

            $table->foreignUlid('unidade_medida_id');
            $table->foreign('unidade_medida_id', 'materiais_unidade_medida_fk')
                ->references('id')->on('unidades_medida')
                ->restrictOnDelete();

            $table->foreignUlid('familia_material_id')->nullable()->constrained('familias_material')->nullOnDelete();

            $table->string('modo_rastreabilidade');
            $table->boolean('ativo')->default(true);

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'codigo'], 'materiais_tenant_codigo_unique');
            $table->index(['tenant_id', 'ativo'], 'materiais_tenant_ativo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materiais');
    }
};
