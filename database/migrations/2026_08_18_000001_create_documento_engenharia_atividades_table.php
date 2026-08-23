<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 18, Etapa 18.1 — pivô N:N Documento de Engenharia ↔ Atividade.
     * Nome curto de propósito (mesma razão já documentada em
     * item_suprimento_documentos): "documento_engenharia_atividades" +
     * sufixo automático de FK/unique do Laravel estoura os 64 caracteres
     * do MySQL — por isso todo nome de constraint aqui é explícito e curto
     * (prefixo "dea_").
     *
     * Chave é sempre atividade_id (nunca codigo_cronograma/WBS/
     * cronograma_importacao_id/linha_base_id) — mesmo princípio já usado
     * em item_suprimento_atividades: a Atividade preserva sua PK ULID
     * através de reimportações (reconciliação por external_uid no
     * MsProjectImporter), então o vínculo sobrevive automaticamente.
     *
     * `cascadeOnDelete()` nos dois lados, decisão consciente (não copiada
     * às cegas): reflete o precedente JÁ EXISTENTE nas duas pontas —
     * `documento_engenharia_id` já é cascadeOnDelete tanto em
     * item_suprimento_documentos quanto nas tabelas-filha do próprio
     * DocumentoEngenharia (revisões, reprogramações); `atividade_id` já é
     * cascadeOnDelete em item_suprimento_atividades/restricoes/
     * atividade_anexos/atividade_comentarios. Em produção nenhum dos dois
     * lados é jamais forceDeleted (Documento só usa soft delete;
     * Atividade nunca é removida, só arquivada via `fora_do_cronograma`)
     * — cascade só entraria em jogo numa limpeza manual/QA excepcional,
     * mesma classe de risco já aceita pelos precedentes citados. Isso é
     * DIFERENTE das tabelas de Fotografia O/P do Ciclo 17
     * (restrictOnDelete): aquelas são auditoria histórica que precisa
     * sobreviver à entidade viva desaparecer; este pivô é um vínculo
     * estrutural vivo — sem o Documento ou a Atividade, o vínculo em si
     * não tem mais sentido de existir.
     *
     * Sem `obra_id` na tabela: seria redundante (documento->obra_id e
     * atividade->obra_id já existem e mesma obra é validada em código —
     * ver DocumentoEngenhariaAtividadeController/⚡documentos-engenharia).
     */
    public function up(): void
    {
        Schema::create('documento_engenharia_atividades', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('documento_engenharia_id')->constrained('documentos_engenharia')->cascadeOnDelete();
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['documento_engenharia_id', 'atividade_id'], 'dea_documento_atividade_unique');
            $table->index(['tenant_id', 'atividade_id'], 'dea_tenant_atividade_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_engenharia_atividades');
    }
};
