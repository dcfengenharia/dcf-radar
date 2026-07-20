<?php

use App\Models\DocumentoEngenharia;
use Illuminate\Database\Migrations\Migration;

/**
 * Status deixou de ser campo do documento e passou a ser da emissão
 * (revisão) — o status "atual" exibido agora é sempre o da revisão mais
 * recente. Para não perder o status já registrado nos documentos reais
 * existentes, copia o status_documento_id do documento pra revisão mais
 * recente dele (mesma ordenação de DocumentoEngenharia::revisoes()) —
 * só quando a revisão ainda não tiver status próprio, o que torna esta
 * migration segura de rodar mais de uma vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        DocumentoEngenharia::whereNotNull('status_documento_id')
            ->has('revisoes')
            ->with('revisoes')
            ->chunkById(200, function ($documentos) {
                foreach ($documentos as $documento) {
                    $revisaoMaisRecente = $documento->revisoes->first();

                    if ($revisaoMaisRecente && $revisaoMaisRecente->status_documento_id === null) {
                        $revisaoMaisRecente->update(['status_documento_id' => $documento->status_documento_id]);
                    }
                }
            });
    }

    public function down(): void {}
};
