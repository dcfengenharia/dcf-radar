<?php

namespace App\Actions\Engenharia;

use App\Exceptions\GrdImutavelException;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Destinatario;
use App\Models\Grd;
use App\Models\GrdDestinatario;
use App\Models\GrdDistribuicao;
use App\Models\GrdItem;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 18, Etapa 18.5.1 — todas as mutações de conteúdo permitidas
 * enquanto uma Grd está em Rascunho (item, destinatário, distribuição,
 * quantidade, observação). Cada método reafirma o guard de status
 * server-side — nunca confia em botão escondido (briefing 18.5.1, seção
 * 5): qualquer tentativa sobre uma Grd Emitida lança GrdImutavelException,
 * mesmo que o chamador já tenha uma instância `$grd` em memória com
 * status desatualizado — sempre relê a GRD com lock antes de decidir.
 *
 * **Idempotência (18.5.1.HARDENING)**: `removerItem()`/
 * `removerDestinatario()`/`desmarcarDistribuicao()` sobre algo que já não
 * existe mais é um NO-OP silencioso (comportamento intencional, não
 * corrigido — `delete()` numa linha inexistente afeta 0 linhas, sem
 * lançar exceção), nunca uma duplicação. `marcarDistribuicao()`/
 * `alterarQuantidade()` pro mesmo valor já atual também são idempotentes
 * (via `firstOrNew`/reatribuição simples). Chamar essas mutações duas
 * vezes em sequência é seguro.
 */
class AtualizarRascunhoGrd
{
    private function grdRascunhoTravada(Grd $grd): Grd
    {
        $atual = Grd::whereKey($grd->id)->lockForUpdate()->firstOrFail();

        if (! $atual->estaRascunho()) {
            throw new GrdImutavelException('Esta GRD já foi emitida e não pode mais ser editada.');
        }

        return $atual;
    }

    public function adicionarItem(Grd $grd, DocumentoEngenhariaRevisao $revisao): GrdItem
    {
        return DB::transaction(function () use ($grd, $revisao) {
            $grd = $this->grdRascunhoTravada($grd);

            $documento = $revisao->documento;
            if ($documento === null || $documento->obra_id !== $grd->obra_id) {
                throw new \InvalidArgumentException('Esta revisão não pertence à obra desta GRD.');
            }

            return GrdItem::firstOrCreate([
                'grd_id' => $grd->id,
                'documento_engenharia_revisao_id' => $revisao->id,
            ]);
        });
    }

    public function removerItem(Grd $grd, GrdItem $item): void
    {
        DB::transaction(function () use ($grd, $item) {
            $grd = $this->grdRascunhoTravada($grd);

            if ($item->grd_id !== $grd->id) {
                throw new \InvalidArgumentException('Este item não pertence a esta GRD.');
            }

            $item->delete();
        });
    }

    public function adicionarDestinatario(Grd $grd, Destinatario $destinatario): GrdDestinatario
    {
        return DB::transaction(function () use ($grd, $destinatario) {
            $grd = $this->grdRascunhoTravada($grd);

            if ($destinatario->obra_id !== $grd->obra_id) {
                throw new \InvalidArgumentException('Este destinatário não pertence à obra desta GRD.');
            }

            return GrdDestinatario::firstOrCreate([
                'grd_id' => $grd->id,
                'destinatario_id' => $destinatario->id,
            ]);
        });
    }

    public function removerDestinatario(Grd $grd, GrdDestinatario $grdDestinatario): void
    {
        DB::transaction(function () use ($grd, $grdDestinatario) {
            $grd = $this->grdRascunhoTravada($grd);

            if ($grdDestinatario->grd_id !== $grd->id) {
                throw new \InvalidArgumentException('Este destinatário não pertence a esta GRD.');
            }

            $grdDestinatario->delete();
        });
    }

    public function marcarDistribuicao(Grd $grd, GrdItem $item, GrdDestinatario $grdDestinatario, int $quantidade = 1): GrdDistribuicao
    {
        return DB::transaction(function () use ($grd, $item, $grdDestinatario, $quantidade) {
            $grd = $this->grdRascunhoTravada($grd);

            $this->garantirPertencemAGrd($grd, $item, $grdDestinatario);

            if ($quantidade < 1) {
                throw new \InvalidArgumentException('Quantidade precisa ser maior ou igual a 1.');
            }

            $distribuicao = GrdDistribuicao::firstOrNew([
                'grd_item_id' => $item->id,
                'grd_destinatario_id' => $grdDestinatario->id,
            ]);
            $distribuicao->quantidade = $quantidade;
            $distribuicao->save();

            return $distribuicao;
        });
    }

    public function desmarcarDistribuicao(Grd $grd, GrdItem $item, GrdDestinatario $grdDestinatario): void
    {
        DB::transaction(function () use ($grd, $item, $grdDestinatario) {
            $grd = $this->grdRascunhoTravada($grd);

            $this->garantirPertencemAGrd($grd, $item, $grdDestinatario);

            GrdDistribuicao::where('grd_item_id', $item->id)
                ->where('grd_destinatario_id', $grdDestinatario->id)
                ->delete();
        });
    }

    public function alterarQuantidade(Grd $grd, GrdDistribuicao $distribuicao, int $quantidade): GrdDistribuicao
    {
        return DB::transaction(function () use ($grd, $distribuicao, $quantidade) {
            $grd = $this->grdRascunhoTravada($grd);

            $item = GrdItem::whereKey($distribuicao->grd_item_id)->firstOrFail();
            if ($item->grd_id !== $grd->id) {
                throw new \InvalidArgumentException('Esta distribuição não pertence a esta GRD.');
            }

            if ($quantidade < 1) {
                throw new \InvalidArgumentException('Quantidade precisa ser maior ou igual a 1.');
            }

            $distribuicao->quantidade = $quantidade;
            $distribuicao->save();

            return $distribuicao;
        });
    }

    public function atualizarObservacao(Grd $grd, ?string $observacao): Grd
    {
        return DB::transaction(function () use ($grd, $observacao) {
            $grd = $this->grdRascunhoTravada($grd);

            $grd->observacao = $observacao;
            $grd->save();

            return $grd;
        });
    }

    private function garantirPertencemAGrd(Grd $grd, GrdItem $item, GrdDestinatario $grdDestinatario): void
    {
        if ($item->grd_id !== $grd->id || $grdDestinatario->grd_id !== $grd->id) {
            throw new \InvalidArgumentException('Item ou destinatário não pertence a esta GRD.');
        }
    }
}
