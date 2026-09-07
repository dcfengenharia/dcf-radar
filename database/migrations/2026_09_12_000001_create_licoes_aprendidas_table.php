<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 23, Etapa 23.1 — núcleo da Memória Operacional Corporativa
 * (Lições Aprendidas). Domínio novo, sem nenhuma alteração em tabela
 * existente.
 *
 * `obra_origem_id` é `restrictOnDelete()` (nunca `cascadeOnDelete()`) —
 * mesma lição de evidência histórica já aplicada em toda a árvore GED/
 * Fotografia O/GRD: uma lição publicada é conhecimento corporativo, não
 * pode desaparecer só porque a obra que a originou foi removida.
 * `disciplina_id` idem — reaproveita `App\Models\Disciplina` já
 * existente (tenant-scoped), nunca disciplina como texto livre.
 *
 * Campos de conteúdo em blocos separados (nunca um textarea único),
 * todos exceto título/situação/recomendação NULLABLE — a validação de
 * completude pra PUBLICAR é feita em `App\Actions\LicoesAprendidas\
 * PublicarLicaoAprendida`, nunca no schema (Boa Prática pode não ter
 * "causa negativa").
 *
 * `status` string (não MySQL ENUM físico) — mesmo padrão de todo o
 * projeto, backed por `App\Enums\StatusLicaoAprendida`.
 *
 * Autoria: `created_by_id`/`publicado_por_id`/`arquivado_por_id` sempre
 * `nullOnDelete()` — preserva a linha mesmo se o usuário for desativado/
 * removido, mesmo padrão de GRD/RC/RP.
 *
 * Índices desenhados a partir das consultas reais da biblioteca
 * corporativa (Seção 30 do pedido): `(tenant_id, status)` pra listar
 * "Publicadas" tenant-wide; `(tenant_id, obra_origem_id)` pra "Esta
 * obra"; `tipo`/`area_funcional`/`criticidade` como filtros simples
 * (índice único por coluna, não composto — poucos valores possíveis
 * cada, não justificam um índice composto maior agora).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licoes_aprendidas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_origem_id')->constrained('works')->restrictOnDelete();
            $table->foreignUlid('disciplina_id')->nullable()->constrained('disciplinas')->restrictOnDelete();

            $table->string('titulo');
            $table->text('situacao_observada');
            $table->text('causa')->nullable();
            $table->text('impacto')->nullable();
            $table->text('acao_adotada')->nullable();
            $table->text('resultado')->nullable();
            $table->text('recomendacao_futura');

            $table->string('tipo');
            $table->string('criticidade');
            $table->string('area_funcional');
            $table->string('status')->default('rascunho');

            $table->date('data_ocorrencia')->nullable();
            $table->date('data_ocorrencia_fim')->nullable();
            $table->text('observacoes_internas')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_validacao_em')->nullable();
            $table->foreignUlid('publicado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('publicado_em')->nullable();
            $table->foreignUlid('arquivado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('arquivado_em')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status'], 'licoes_aprendidas_tenant_status_idx');
            $table->index(['tenant_id', 'obra_origem_id'], 'licoes_aprendidas_tenant_obra_idx');
            $table->index('tipo', 'licoes_aprendidas_tipo_idx');
            $table->index('area_funcional', 'licoes_aprendidas_area_idx');
            $table->index('criticidade', 'licoes_aprendidas_criticidade_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licoes_aprendidas');
    }
};
