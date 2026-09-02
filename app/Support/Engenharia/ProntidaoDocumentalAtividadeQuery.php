<?php

namespace App\Support\Engenharia;

use App\DTOs\Engenharia\AtividadeProntidaoDocumental;
use App\DTOs\Engenharia\DocumentoDependenciaAtividade;
use App\Enums\EstadoProntidaoEngenharia;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\Work;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Ciclo 22, Etapa 22.1 — read model de prontidão DOCUMENTAL por
 * Atividade, dentro de um horizonte. Nunca duplica a regra de liberação
 * (`DocumentoEngenharia::estaLiberadoParaConstrucao()`/
 * `motivoLiberacao()`, Ciclo 18.4) — só agrega o resultado dela por
 * atividade, com granularidade mais rica que o booleano duro de
 * `Atividade::scopeProntas()` (Seção 8: 4/5 documentos liberados nunca
 * pode ser reportado como "totalmente liberada").
 *
 * Filtro de horizonte idêntico ao já usado em
 * `CoberturaMaterialAtividadeQuery::porObra()`/`SituacoesGerenciaisQuery::
 * documentoBloqueante()` (Ciclo 21) — `fora_do_cronograma=false`,
 * `status != Concluido`, `inicio_planejado` inclusivo nos dois extremos
 * — nunca uma segunda convenção de horizonte no projeto.
 */
class ProntidaoDocumentalAtividadeQuery
{
    public static function porObra(Work $obra, int $horizonteDias, ?CarbonInterface $referencia = null): Collection
    {
        $referencia = $referencia ? Carbon::instance($referencia) : Carbon::today();
        $fimHorizonte = $referencia->copy()->addDays($horizonteDias);

        $atividades = Atividade::query()
            ->where('obra_id', $obra->id)
            ->where('fora_do_cronograma', false)
            ->where('status', '!=', StatusAtividade::Concluido->value)
            ->whereBetween('inicio_planejado', [$referencia->toDateString(), $fimHorizonte->toDateString()])
            ->with([
                'pacoteTrabalho:id,nome',
                'frenteTrabalho:id,nome',
                'documentosEngenharia' => fn ($q) => $q->where('documentos_engenharia.obra_id', $obra->id)
                    ->withCount('revisoes'),
                'documentosEngenharia.latestRevisao.ultimaLiberacao',
            ])
            ->get();

        return $atividades->map(function (Atividade $atividade) use ($referencia) {
            $documentos = $atividade->documentosEngenharia->map(
                fn (DocumentoEngenharia $doc) => new DocumentoDependenciaAtividade(
                    documentoId: $doc->id,
                    codigo: $doc->codigo,
                    revisaoId: $doc->revisaoVigente()?->id,
                    revisaoTexto: $doc->revisaoVigente()?->revisao,
                    liberado: $doc->estaLiberadoParaConstrucao(),
                    motivoLiberacao: $doc->motivoLiberacao(),
                    liberadoEm: $doc->revisaoVigente()?->liberadaParaConstrucaoEm(),
                    totalRevisoesDocumento: (int) $doc->revisoes_count,
                )
            );

            $diasParaInicio = $atividade->inicio_planejado
                ? (int) $referencia->diffInDays($atividade->inicio_planejado, false)
                : null;

            return new AtividadeProntidaoDocumental(
                atividadeId: $atividade->id,
                codigo: $atividade->codigo_cronograma ?? $atividade->nome,
                nome: $atividade->nome,
                inicioPlanejado: $atividade->inicio_planejado,
                frenteNome: $atividade->frenteTrabalho?->nome,
                pacoteNome: $atividade->pacoteTrabalho?->nome,
                documentos: $documentos,
                estado: EstadoProntidaoEngenharia::calcular($documentos->count(), $documentos->filter(fn ($d) => $d->liberado)->count()),
                diasParaInicio: $diasParaInicio,
            );
        })->values();
    }
}
