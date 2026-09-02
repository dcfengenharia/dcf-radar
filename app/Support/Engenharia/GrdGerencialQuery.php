<?php

namespace App\Support\Engenharia;

use App\DTOs\Engenharia\FatoEngenharia;
use App\Enums\SeveridadeSituacao;
use App\Enums\StatusGrd;
use App\Models\Grd;
use App\Models\GrdAceiteEntrega;
use App\Models\GrdDestinatario;
use App\Models\Work;
use App\Support\Grd\DetectorCopiasObsoletasGrd;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Ciclo 22, Etapa 22.1 — fatos gerenciais de GRD. Nunca reimplementa
 * regra do Ciclo 18: "cópia obsoleta pendente de recolhimento" delega
 * 100% pra `DetectorCopiasObsoletasGrd::porObra()` (já batch, já
 * testado). "GRD aguardando aceite" é um fato NOVO nesta etapa — não
 * existia como leitura gerencial em nenhum lugar do Ciclo 18/21 —
 * derivado só de dados já existentes (`GrdDestinatario`/
 * `GrdAceiteEntrega::estaAtivo()`, Ciclo 18.5.9), nunca uma nova coluna.
 *
 * **Achado documentado, não um bug (Seção 12/14 do pedido)**:
 * "distribuição física pendente" como estado PRÓPRIO, distinto de
 * emissão, não existe no domínio — `Grd::estaEmitida()` já É o evento de
 * entrega física (docblock de `GrdDistribuicao`, Ciclo 18.5.1: "emissão
 * == entrega, neste domínio"). Uma GRD ainda não emitida é só pendência
 * DOCUMENTAL (Rascunho, sem impacto operacional imediato — Seção 16),
 * nunca listada aqui como fato acionável.
 */
class GrdGerencialQuery
{
    /** @return Collection<int, FatoEngenharia> */
    public static function aguardandoAceite(Work $obra): Collection
    {
        $destinatarios = GrdDestinatario::query()
            ->whereHas('grd', fn ($q) => $q->where('obra_id', $obra->id)->where('status', StatusGrd::Emitida->value))
            ->with('grd:id,obra_id,numero,emitida_em')
            ->get();

        if ($destinatarios->isEmpty()) {
            return collect();
        }

        $comAceiteAtivo = GrdAceiteEntrega::query()
            ->whereIn('grd_destinatario_id', $destinatarios->pluck('id'))
            ->whereNull('invalidado_em')
            ->pluck('grd_destinatario_id')
            ->all();

        $referencia = Carbon::today();

        return $destinatarios
            ->reject(fn (GrdDestinatario $d) => in_array($d->id, $comAceiteAtivo, true))
            ->map(function (GrdDestinatario $destinatario) use ($obra, $referencia) {
                $grd = $destinatario->grd;
                $diasAguardando = $grd->emitida_em ? (int) $grd->emitida_em->diffInDays($referencia) : null;

                return new FatoEngenharia(
                    tipo: 'grd_aguardando_aceite',
                    severidade: SeveridadeSituacao::Informativa,
                    obraId: $obra->id,
                    entidadeTipo: 'GrdDestinatario',
                    entidadeId: $destinatario->id,
                    descricao: "GRD {$grd->numero} aguarda aceite de recebimento de {$destinatario->nome_snapshot}.",
                    dataRelevante: $grd->emitida_em,
                    diasParaRelevante: $diasAguardando,
                    atividadeId: null,
                    destinatariosPerfis: [['slug' => 'engenharia.pacotes', 'acao' => 'ver']],
                    deepLink: ['rota' => 'engenharia.grds', 'parametros' => ['obra' => $obra->id, 'grd' => $grd->id]],
                    contexto: ['grd_id' => $grd->id, 'destinatario_id' => $destinatario->id],
                );
            })
            ->values();
    }

    /** @return Collection<int, FatoEngenharia> */
    public static function copiasObsoletasPendentes(Work $obra): Collection
    {
        return (new DetectorCopiasObsoletasGrd())->porObra($obra)
            ->map(function (object $linha) use ($obra) {
                $documento = $linha->documento;
                $quantidadePendente = (int) $linha->distribuicao->quantidade - (int) $linha->distribuicao->getAttribute('total_recolhido_batch');

                return new FatoEngenharia(
                    tipo: 'grd_copia_obsoleta_pendente_recolhimento',
                    severidade: SeveridadeSituacao::Atencao,
                    obraId: $obra->id,
                    entidadeTipo: 'GrdDistribuicao',
                    entidadeId: $linha->distribuicao->id,
                    descricao: "Documento {$documento->codigo}: {$quantidadePendente} cópia(s) da revisão"
                        . " {$linha->revisao_entregue->revisao} entregue(s) a {$linha->grd_destinatario->nome_snapshot}"
                        . ' aguardam recolhimento (revisão vigente já é outra).',
                    dataRelevante: null,
                    diasParaRelevante: null,
                    atividadeId: null,
                    destinatariosPerfis: [['slug' => 'engenharia.pacotes', 'acao' => 'ver']],
                    deepLink: ['rota' => 'engenharia.grds', 'parametros' => ['obra' => $obra->id, 'aba' => 'obsoletas']],
                    contexto: [
                        'documento_id' => $documento->id,
                        'revisao_entregue' => $linha->revisao_entregue->revisao,
                        'revisao_vigente' => $linha->revisao_vigente?->revisao,
                        'quantidade_pendente' => $quantidadePendente,
                    ],
                );
            })
            ->values();
    }
}
