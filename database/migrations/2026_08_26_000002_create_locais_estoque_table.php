<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.1 — LocalEstoque: posição física/custódia dentro da
 * obra (nunca confundir com FrenteTrabalho, que é aplicação operacional
 * — investigação 20.0, Seção 8). Obra-scoped, mesmo padrão exato de
 * frentes_trabalho/equipes_responsaveis.
 *
 * `tipo` (App\Enums\TipoLocalEstoque) deliberadamente SEM o caso
 * "Terceiro" nesta fase — custódia em fornecedor (industrialização
 * externa) é mudança de CUSTÓDIA, não uma localização física própria da
 * obra; misturar os dois geraria ambiguidade quando a industrialização
 * for implementada (fase futura, não decidida ainda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locais_estoque', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            $table->string('nome');
            $table->string('tipo');
            $table->boolean('ativo')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'obra_id'], 'locais_estoque_tenant_obra_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locais_estoque');
    }
};
