<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 23, Etapa 23.3 — candidatos a lição aprendida. Um candidato NUNCA
 * é uma `LicaoAprendida` — é um mecanismo de revisão humana sobre um
 * fato operacional real (Restrição/PedidoCompra/AplicacaoMaterialEstoque,
 * via `entidade_tipo`+`entidade_id`, mesmo idioma allowlisted+ULID+
 * snapshot já usado em `licao_aprendida_vinculos` desde a 23.1 — nunca
 * FQCN vindo de request, nunca `withoutGlobalScopes()`).
 *
 * `UNIQUE(tenant_id, obra_id, chave_logica)` — decisão explícita do
 * usuário (diferente de `situacao_ocorrencias`, que só tem
 * `UNIQUE(tenant_id, chave_logica)`): aqui `obra_id` entra na própria
 * constraint porque o candidato pertence estruturalmente a UMA obra
 * (Seção 37 — nunca misturar fatos de obras diferentes), então a
 * garantia de deduplicação deve refletir isso explicitamente.
 *
 * `licao_aprendida_id` é `restrictOnDelete()` — mesma lição de evidência
 * histórica repetida em todo o projeto (nunca cascade sobre um FK que
 * prova de onde uma lição nasceu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licao_aprendida_candidatos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            $table->string('tipo');
            $table->string('chave_logica');
            $table->string('status')->default('pendente');

            $table->string('entidade_tipo');
            $table->string('entidade_id');

            $table->string('titulo');
            $table->text('descricao');
            $table->json('dados_snapshot');
            $table->timestamp('gerado_em');

            $table->foreignUlid('descartado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('descartado_em')->nullable();
            $table->text('motivo_descarte')->nullable();

            $table->timestamp('convertido_em')->nullable();
            $table->foreignUlid('licao_aprendida_id')->nullable()->constrained('licoes_aprendidas')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'obra_id', 'chave_logica'], 'licao_candidatos_tenant_obra_chave_unique');
            $table->index(['obra_id', 'status'], 'licao_candidatos_obra_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licao_aprendida_candidatos');
    }
};
