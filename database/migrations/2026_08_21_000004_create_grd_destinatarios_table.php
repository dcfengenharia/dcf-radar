<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 18, Etapa 18.5.1 — destinatário incluído numa GRD específica
     * (junção Grd↔Destinatario), com snapshot de identidade próprio.
     *
     * `destinatario_id` é `restrictOnDelete()` (pedido explícito do
     * usuário) — protege o cadastro mestre contra forceDelete() enquanto
     * referenciado por qualquer GRD (rascunho ou emitida); soft-delete do
     * Destinatario continua livre (nunca dispara FK).
     *
     * `grd_id` é cascadeOnDelete — mesmo raciocínio de grd_itens.grd_id:
     * apagar uma GRD Rascunho leva seus destinatários-na-GRD junto.
     *
     * Snapshots ficam NULL em Rascunho, congelados por EmitirGrd na
     * emissão — a GRD emitida nunca mais lê o cadastro vivo pra exibir
     * nome/empresa/setor (mesma filosofia de Fotografia F/O do Ciclo 17:
     * "o que era verdade no momento", não "o que é verdade hoje").
     */
    public function up(): void
    {
        Schema::create('grd_destinatarios', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('grd_id')->constrained('grds')->cascadeOnDelete();
            $table->foreignUlid('destinatario_id')->constrained('destinatarios')->restrictOnDelete();
            $table->string('nome_snapshot')->nullable();
            $table->string('empresa_snapshot')->nullable();
            $table->string('setor_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['grd_id', 'destinatario_id'], 'grd_destinatarios_grd_dest_unique');
            $table->index(['tenant_id', 'destinatario_id'], 'grd_destinatarios_tenant_dest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grd_destinatarios');
    }
};
