<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.2 — cabeçalho de Requisição do Planejamento (RP): a
 * demanda formal do Planejamento pra Suprimentos, sobre quantidades do
 * Take Off (LM/LI). Schema deliberadamente espelha `grds`
 * (2026_08_21_000002_create_grds_table.php) — mesmo formato de fato
 * documental Rascunho→Emitida, mesma mecânica de numeração/imutabilidade
 * já aprovada e testada:
 *
 * - `numero` fica NULL enquanto Rascunho (não consome sequência — MySQL
 *   trata múltiplos NULL como não-colidentes no unique(obra_id, numero),
 *   vários rascunhos da mesma obra coexistem livremente) e só é atribuído
 *   na emissão (App\Actions\Suprimentos\EmitirRequisicaoPlanejamento),
 *   com lock transacional numa linha estável (Work) — mesmo mecanismo de
 *   App\Actions\Engenharia\EmitirGrd — pra serializar concorrência; o
 *   UNIQUE(obra_id, numero) é a defesa FINAL, não o mecanismo principal.
 * - SoftDeletes: nunca desaparece de verdade (auditoria) — Rascunho pode
 *   ser descartado (delete/forceDelete livres); Emitida é bloqueada por
 *   Observer (App\Observers\RequisicaoPlanejamentoObserver), mesmo padrão
 *   de GrdObserver.
 * - Só Rascunho|Emitida nesta fase (sem Cancelada) — mesma decisão já
 *   tomada pra StatusGrd: cancelamento não tem necessidade real
 *   confirmada em 19.2 (ver CLAUDE.md, Etapa 19.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicoes_planejamento', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->unsignedInteger('numero')->nullable();
            $table->string('status')->default('rascunho');
            $table->timestamp('emitida_em')->nullable();
            $table->foreignUlid('emitida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacao')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['obra_id', 'numero'], 'req_planejamento_obra_numero_unique');
            $table->index(['tenant_id', 'obra_id', 'status'], 'req_planejamento_tenant_obra_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicoes_planejamento');
    }
};
