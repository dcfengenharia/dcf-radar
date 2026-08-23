<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 18, Etapa 18.5.1 — log append-only de tentativas/eventos de
     * recolhimento sobre UMA GrdDistribuicao (mesmo espírito de
     * PlanoAcaoReconciliacao/DocumentoEngenhariaReprogramacao: nunca
     * editado nem apagado, `updated_at` desabilitado no model via
     * `UPDATED_AT = null`). `resultado` é `recolhido` ou `nao_localizado`
     * (App\Enums\ResultadoRecolhimento) — nao_localizado NUNCA reduz a
     * quantidade pendente (regra de domínio em RegistrarRecolhimento, não
     * imposta pelo schema).
     *
     * `grd_distribuicao_id` é `restrictOnDelete()` — mesma lição de
     * proteção de evidência histórica já usada em toda a árvore GED do
     * Ciclo 17/18 (Fotografia O, grd_itens.documento_engenharia_revisao_id
     * acima): um evento de recolhimento já registrado nunca pode
     * desaparecer como efeito colateral de apagar a distribuição que ele
     * documenta.
     */
    public function up(): void
    {
        Schema::create('grd_recolhimentos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('grd_distribuicao_id')
                ->constrained('grd_distribuicoes')
                ->restrictOnDelete();
            $table->string('resultado');
            $table->unsignedInteger('quantidade');
            $table->timestamp('ocorrido_em');
            $table->foreignUlid('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacao')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'grd_distribuicao_id'], 'grd_recolhimentos_tenant_dist_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grd_recolhimentos');
    }
};
