<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 18, Etapa 18.5.1 — cabeçalho de GRD (Guia de Remessa de
     * Documentos), o fato de distribuição física de revisões de
     * Documento de Engenharia. Rascunho/Emitida (mesmo espírito de
     * Report::rascunho/emitido, dupla trava): `numero` fica NULL
     * enquanto Rascunho (não consome sequência) e é atribuído só na
     * emissão (App\Actions\Engenharia\EmitirGrd), com proteção de
     * concorrência via lock em linha estável da própria obra (Work) —
     * `UNIQUE(obra_id, numero)` é a defesa FINAL, não o mecanismo
     * principal (MySQL trata múltiplos NULL como não-colidentes nesse
     * índice, então vários rascunhos da mesma obra coexistem livremente).
     *
     * SoftDeletes: mesmo padrão de Report — nunca desaparece de verdade
     * (auditoria). Cancelamento fica fora de escopo desta fase (só
     * Rascunho|Emitida existem); delete/forceDelete de uma GRD Emitida
     * pela Action de domínio é proibido (guard em EmitirGrd), mas o
     * schema em si não impõe isso — é regra de aplicação, não de banco
     * (nenhum precedente no projeto de status imutável reforçado por
     * trigger de banco).
     */
    public function up(): void
    {
        Schema::create('grds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->unsignedInteger('numero')->nullable();
            $table->string('status')->default('rascunho');
            $table->timestamp('emitida_em')->nullable();
            $table->foreignUlid('emitida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacao')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['obra_id', 'numero'], 'grds_obra_numero_unique');
            $table->index(['tenant_id', 'obra_id', 'status'], 'grds_tenant_obra_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grds');
    }
};
