<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 18, Etapa 18.5.1 — item de uma GRD: aponta pra PK EXATA de
     * DocumentoEngenhariaRevisao, nunca pro Documento (que resolveria a
     * revisão vigente dinamicamente) — histórico de GRD nunca muda de
     * significado quando nasce uma revisão nova (decisão central de
     * 18.5.0, seção 18 do briefing 18.5.1).
     *
     * `documento_engenharia_revisao_id` é `restrictOnDelete()` — NUNCA
     * cascade — mesma lição já aplicada em Fotografia O (Ciclo 17,
     * A.9.3.CORREÇÃO) e já citada como precedente direto no docblock de
     * `documento_engenharia_atividades`: FK apontando pra evidência
     * histórica nunca cai com o pai, senão um forceDelete() acidental de
     * Documento/Revisão destruiria silenciosamente uma GRD já emitida.
     * Efeito colateral desejável: como
     * `documento_engenharia_revisoes.documento_engenharia_id` já é
     * cascadeOnDelete, este restrict aqui BLOQUEIA o forceDelete() do
     * Documento inteiro assim que qualquer revisão dele já foi
     * distribuída.
     *
     * `grd_id` É cascadeOnDelete — ao contrário da revisão, o item
     * pertence à própria GRD; apagar uma GRD Rascunho (única situação em
     * que apagar é permitido pelo domínio) deve levar seus itens junto.
     *
     * Snapshots (`*_snapshot`) ficam NULL enquanto Rascunho (não há fato
     * imutável ainda) e são congelados por EmitirGrd no momento da
     * emissão — nunca lidos ao vivo de DocumentoEngenharia/Revisao
     * depois de emitida.
     */
    public function up(): void
    {
        Schema::create('grd_itens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('grd_id')->constrained('grds')->cascadeOnDelete();
            $table->foreignUlid('documento_engenharia_revisao_id')
                ->constrained('documento_engenharia_revisoes')
                ->restrictOnDelete();
            $table->string('codigo_documento_snapshot')->nullable();
            $table->string('descricao_documento_snapshot')->nullable();
            $table->string('revisao_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['grd_id', 'documento_engenharia_revisao_id'], 'grd_itens_grd_revisao_unique');
            $table->index(['tenant_id', 'documento_engenharia_revisao_id'], 'grd_itens_tenant_revisao_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grd_itens');
    }
};
