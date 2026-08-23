<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 18, Etapa 18.5.1 — matriz explícita item×destinatário: cada
     * linha é o fato atômico "este destinatário recebeu este item nesta
     * GRD, nesta quantidade" (nunca um produto cartesiano implícito —
     * decisão central de cardinalidade de 18.5.0). `quantidade` default 1
     * (pedido explícito do usuário).
     *
     * `grd_item_id`/`grd_destinatario_id` são cascadeOnDelete — a linha
     * de distribuição só existe "dentro" de uma GRD e não tem sentido
     * isolado; apagar o item ou o destinatário-na-GRD (ambos só possíveis
     * enquanto Rascunho, ver Actions de domínio) leva a distribuição
     * junto. A proteção de histórico real fica nas FKs de nível abaixo
     * (revisão/destinatário, ambas restrictOnDelete) e em
     * grd_recolhimentos.grd_distribuicao_id (também restrictOnDelete,
     * ver migration seguinte) — uma vez que exista QUALQUER recolhimento
     * registrado, a cadeia inteira fica protegida transitivamente contra
     * forceDelete().
     */
    public function up(): void
    {
        Schema::create('grd_distribuicoes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('grd_item_id')->constrained('grd_itens')->cascadeOnDelete();
            $table->foreignUlid('grd_destinatario_id')->constrained('grd_destinatarios')->cascadeOnDelete();
            $table->unsignedInteger('quantidade')->default(1);
            $table->timestamps();

            $table->unique(['grd_item_id', 'grd_destinatario_id'], 'grd_distribuicoes_item_dest_unique');
            $table->index(['tenant_id', 'grd_destinatario_id'], 'grd_distribuicoes_tenant_dest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grd_distribuicoes');
    }
};
