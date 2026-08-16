<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ciclo 17, A.7.1 — anexos PDF pertencem à Atividade (identidade
        // lógica), nunca a LinhaBase/CronogramaImportacao/Avanço: nova
        // baseline, novo avanço, mudança de WBS ou reimportação do mesmo
        // external_uid nunca tocam esta tabela.
        Schema::create('atividade_anexos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            // Mesma convenção já usada por TODA tabela filha de atividades
            // (causas_nao_cumprimento, restricoes, avanco_periodos,
            // atividade_itens_prontidao, atividade_snapshots,
            // atividade_comentarios, item_suprimento_atividades) —
            // cascadeOnDelete. Atividade usa SoftDeletes e nenhum fluxo do
            // app hoje chama forceDelete() nela (confirmado por grep), então
            // este cascade fica dormente na prática — mantido só por
            // consistência com as 7 tabelas irmãs, não por conveniência.
            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();

            $table->string('nome_original');
            $table->string('caminho_arquivo');
            $table->string('mime_type');
            $table->unsignedBigInteger('tamanho_bytes');
            // Mesmo padrão de report_fotos.enviado_por — nullable +
            // nullOnDelete, pra não travar futura anonimização/exclusão de
            // usuário; o anexo continua existindo mesmo sem autor.
            $table->foreignUlid('enviado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atividade_anexos');
    }
};
