<?php

namespace App\Support\Grd;

use App\Enums\StatusGrd;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Work;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 18, Etapa 18.5.1 — query somente-leitura, em lote, de "quem já
 * recebeu uma revisão antiga de um Documento mas ainda não recebeu a
 * revisão vigente" — uma RECOMENDAÇÃO derivada, nunca uma obrigação
 * persistida (decisão central de 18.5.0/18.5.1).
 */
class CandidatosNovaEntregaGrd
{
    /**
     * Um destinatário é candidato a receber uma nova entrega de um
     * Documento quando:
     * - já recebeu ALGUMA revisão anterior desse Documento via GRD
     *   Emitida;
     * - a revisão vigente do Documento está LIBERADA para construção
     *   (se não estiver liberada, NENHUM candidato é gerado pra esse
     *   Documento — nunca recomendar distribuir algo ainda não liberado);
     * - ainda NÃO recebeu a revisão vigente via GRD Emitida.
     *
     * Entregar R2 nunca "fecha" pendência de recolhimento de R1 — os dois
     * fatos são inteiramente independentes por construção (recolhimento é
     * escopado por GrdDistribuicao, uma linha por item×destinatário; uma
     * nova distribuição de R2 nunca toca a linha de R1).
     *
     * **18.5.1.HARDENING — semântica explícita de Destinatario soft-deletado**:
     * ao contrário de DetectorCopiasObsoletasGrd, aqui um Destinatario
     * soft-deletado é EXCLUÍDO da lista de candidatos — cadastro inativo
     * não é candidato operacional pra receber uma cópia nova. Isso não é
     * um filtro explícito no código: `Destinatario::query()->whereIn(...)`
     * já respeita o global scope de SoftDeletes (`deleted_at IS NULL`) por
     * padrão, então um `destinatario_id` soft-deletado nunca aparece no
     * mapa `$destinatarios` — o `if ($destinatario === null) { continue; }`
     * abaixo descarta o grupo inteiro nesse caso. Documentado aqui de
     * propósito pra não ficar um efeito colateral implícito e não
     * explicado do SoftDeletingScope.
     *
     * @return Collection<int, object>
     */
    public function porObra(Work $obra): Collection
    {
        $documentos = DocumentoEngenharia::query()
            ->where('obra_id', $obra->id)
            ->with('latestRevisao.ultimaLiberacao')
            ->get()
            ->keyBy('id');

        $linhas = DB::table('grd_distribuicoes')
            ->join('grd_itens', 'grd_itens.id', '=', 'grd_distribuicoes.grd_item_id')
            ->join('grds', 'grds.id', '=', 'grd_itens.grd_id')
            ->join('grd_destinatarios', 'grd_destinatarios.id', '=', 'grd_distribuicoes.grd_destinatario_id')
            ->join('documento_engenharia_revisoes as revisao_entregue', 'revisao_entregue.id', '=', 'grd_itens.documento_engenharia_revisao_id')
            ->where('grds.obra_id', $obra->id)
            ->where('grds.status', StatusGrd::Emitida->value)
            ->select([
                'revisao_entregue.documento_engenharia_id as documento_id',
                'grd_destinatarios.destinatario_id as destinatario_id',
                'revisao_entregue.id as revisao_id',
            ])
            ->distinct()
            ->get();

        if ($linhas->isEmpty()) {
            return collect();
        }

        $porDocumentoDestinatario = $linhas->groupBy(fn ($l) => $l->documento_id . '|' . $l->destinatario_id);

        $destinatarioIds = $linhas->pluck('destinatario_id')->unique()->values();
        $destinatarios = Destinatario::query()->whereIn('id', $destinatarioIds)->get()->keyBy('id');

        $revisaoIds = $linhas->pluck('revisao_id')->unique()->values();
        $revisoes = DocumentoEngenhariaRevisao::query()->whereIn('id', $revisaoIds)->get()->keyBy('id');

        $candidatos = collect();

        foreach ($porDocumentoDestinatario as $chave => $grupo) {
            [$documentoId, $destinatarioId] = explode('|', $chave, 2);

            $documento = $documentos->get($documentoId);
            if ($documento === null) {
                continue;
            }

            $revisaoVigente = $documento->latestRevisao;
            if ($revisaoVigente === null || ! ($revisaoVigente->ultimaLiberacao?->liberada_para_construcao)) {
                continue;
            }

            $revisoesRecebidasIds = $grupo->pluck('revisao_id')->unique()->values();

            if ($revisoesRecebidasIds->contains($revisaoVigente->id)) {
                continue;
            }

            $destinatario = $destinatarios->get($destinatarioId);
            if ($destinatario === null) {
                continue;
            }

            $candidatos->push((object) [
                'documento' => $documento,
                'revisao_vigente' => $revisaoVigente,
                'destinatario' => $destinatario,
                'revisoes_anteriores_recebidas' => $revisoesRecebidasIds
                    ->map(fn ($id) => $revisoes->get($id))
                    ->filter()
                    ->values(),
            ]);
        }

        return $candidatos->values();
    }
}
