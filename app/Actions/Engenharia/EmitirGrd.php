<?php

namespace App\Actions\Engenharia;

use App\Enums\StatusGrd;
use App\Exceptions\GrdEmissaoInvalidaException;
use App\Exceptions\GrdImutavelException;
use App\Models\Grd;
use App\Models\GrdDistribuicao;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 18, Etapa 18.5.1 — transição Rascunho -> Emitida. Congela
 * snapshots, valida revisão exata/vigente/liberada, atribui o número
 * sequencial da obra (com lock transacional numa linha estável — Work —
 * pra serializar concorrência; o UNIQUE(obra_id, numero) é a defesa
 * FINAL, não o mecanismo principal). Tudo dentro de UMA transação: se
 * qualquer validação falhar, nada é escrito.
 */
class EmitirGrd
{
    public function execute(Grd $grd, User $usuario): Grd
    {
        return DB::transaction(function () use ($grd, $usuario) {
            // Lock em linha estável da obra: serializa emissões concorrentes
            // da MESMA obra, garantindo que o MAX(numero)+1 abaixo nunca
            // colida — nunca um max()+1 desprotegido.
            Work::whereKey($grd->obra_id)->lockForUpdate()->firstOrFail();

            $grd = Grd::whereKey($grd->id)->lockForUpdate()->firstOrFail();

            if (! $grd->estaRascunho()) {
                throw new GrdImutavelException('Esta GRD já foi emitida e não pode ser emitida novamente.');
            }

            $itens = $grd->itens()->with(['revisao.documento', 'revisao.ultimaLiberacao'])->get();
            if ($itens->isEmpty()) {
                throw new GrdEmissaoInvalidaException('A GRD precisa ter ao menos 1 documento antes de ser emitida.');
            }

            $grdDestinatarios = $grd->destinatarios()->with('destinatario')->get();
            if ($grdDestinatarios->isEmpty()) {
                throw new GrdEmissaoInvalidaException('A GRD precisa ter ao menos 1 destinatário antes de ser emitida.');
            }

            $distribuicoes = GrdDistribuicao::whereIn('grd_item_id', $itens->pluck('id'))->get();
            if ($distribuicoes->isEmpty()) {
                throw new GrdEmissaoInvalidaException('A GRD precisa ter ao menos 1 distribuição (documento x destinatário) marcada antes de ser emitida.');
            }

            $idsItensValidos = $itens->pluck('id')->all();
            $idsDestinatariosValidos = $grdDestinatarios->pluck('id')->all();

            foreach ($distribuicoes as $distribuicao) {
                if (! in_array($distribuicao->grd_item_id, $idsItensValidos, true)
                    || ! in_array($distribuicao->grd_destinatario_id, $idsDestinatariosValidos, true)) {
                    throw new GrdEmissaoInvalidaException('Distribuição inválida encontrada — não pertence a esta GRD.');
                }
            }

            foreach ($grdDestinatarios as $grdDestinatario) {
                $destinatario = $grdDestinatario->destinatario;
                if ($destinatario === null || $destinatario->obra_id !== $grd->obra_id) {
                    throw new GrdEmissaoInvalidaException('Destinatário inválido — não pertence à obra desta GRD.');
                }
            }

            foreach ($itens as $item) {
                $revisao = $item->revisao;
                $documento = $revisao?->documento;

                if ($revisao === null || $documento === null || $documento->obra_id !== $grd->obra_id) {
                    throw new GrdEmissaoInvalidaException('Documento inválido — não pertence à obra desta GRD.');
                }

                $revisaoVigente = $documento->revisaoVigente();
                if ($revisaoVigente === null || $revisaoVigente->id !== $revisao->id) {
                    throw new GrdEmissaoInvalidaException(
                        "O documento {$documento->codigo} não está na revisão vigente — a GRD só pode distribuir a revisão vigente do documento."
                    );
                }

                if (! $revisao->estaLiberadaParaConstrucao()) {
                    throw new GrdEmissaoInvalidaException(
                        "O documento {$documento->codigo}, revisão {$revisao->revisao}, não está liberado para construção."
                    );
                }
            }

            foreach ($grdDestinatarios as $grdDestinatario) {
                $destinatario = $grdDestinatario->destinatario;
                $grdDestinatario->forceFill([
                    'nome_snapshot' => $destinatario->nome,
                    'empresa_snapshot' => $destinatario->empresa,
                    'setor_snapshot' => $destinatario->setor,
                ])->save();
            }

            foreach ($itens as $item) {
                $documento = $item->revisao->documento;
                $item->forceFill([
                    'codigo_documento_snapshot' => $documento->codigo,
                    'descricao_documento_snapshot' => $documento->descricao,
                    'revisao_snapshot' => $item->revisao->revisao,
                ])->save();
            }

            $proximoNumero = (int) Grd::withTrashed()->where('obra_id', $grd->obra_id)->max('numero') + 1;

            $grd->forceFill([
                'numero' => $proximoNumero,
                'status' => StatusGrd::Emitida,
                'emitida_em' => now(),
                'emitida_por' => $usuario->id,
            ])->save();

            return $grd->fresh(['itens', 'destinatarios']);
        });
    }
}
