<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 2D — governança/auditoria de acesso. Log append-only (mesmo
 * espírito de `grd_recolhimentos`/`plano_acao_reconciliacoes`:
 * `created_at` sem `updated_at`, nunca editado/apagado — ver
 * `App\Observers\HistoricoAcessoObserver`).
 *
 * FKs de evidência histórica são sempre `nullOnDelete()`, nunca
 * `cascadeOnDelete()` (Seção 23: "FK não pode destruir histórico") —
 * `obra_id`/`ator_user_id`/`usuario_afetado_id`/`perfil_id` podem
 * desaparecer com o tempo (obra excluída, usuário removido, Perfil
 * excluído), mas a LINHA do histórico sobrevive sempre, porque todo
 * dado necessário pra exibição já está congelado nas colunas
 * `*_snapshot`/`resumo`/`detalhes`. Só `tenant_id` cascateia (mesma
 * convenção de TODA tabela do projeto — excluir o tenant inteiro
 * elimina tudo dele, histórico incluso).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historico_acessos', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->nullable()->constrained('works')->nullOnDelete();

            $table->string('tipo_evento');
            $table->string('origem');

            // Ator — Seção 25/26: sempre o usuário humano real, nunca
            // inferido. `ator_platform_admin`/`ator_impersonando`
            // preservam o contexto de impersonation (Seção 26) — o
            // histórico nunca finge que foi o tenant impersonado quem
            // agiu.
            $table->foreignUlid('ator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ator_nome_snapshot')->nullable();
            $table->boolean('ator_platform_admin')->default(false);
            $table->boolean('ator_impersonando')->default(false);

            $table->foreignUlid('usuario_afetado_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario_afetado_nome_snapshot')->nullable();

            $table->foreignUlid('perfil_id')->nullable()->constrained('perfis')->nullOnDelete();
            $table->string('perfil_nome_snapshot')->nullable();

            // Resumo humano de 1 linha (Seção 4/36 — nunca JSON cru na
            // UI) + detalhes estruturados (diff/impacto/contexto extra
            // por tipo de evento — Seção 10/11).
            $table->text('resumo');
            $table->json('detalhes')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at'], 'historico_acessos_tenant_data_idx');
            $table->index(['tenant_id', 'obra_id'], 'historico_acessos_tenant_obra_idx');
            $table->index(['tenant_id', 'usuario_afetado_id'], 'historico_acessos_tenant_afetado_idx');
            $table->index(['tenant_id', 'perfil_id'], 'historico_acessos_tenant_perfil_idx');
            $table->index(['tenant_id', 'ator_user_id'], 'historico_acessos_tenant_ator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historico_acessos');
    }
};
