<?php

namespace App\Actions\LicoesAprendidas;

use App\Exceptions\LicaoAprendidaImutavelException;
use App\Models\LicaoAprendidaEvidencia;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo 23, Etapa 23.2 — remoção segura de uma evidência: registro e
 * arquivo físico sempre saem juntos (mesma ordem/filosofia de
 * `App\Actions\Atividade\RemoverAnexoAtividade` — registro primeiro,
 * arquivo depois; falha na remoção física vira `report()`, nunca deixa
 * o registro em pé apontando pra um arquivo já sumido).
 *
 * Bloqueado quando a lição é imutável (Publicada/Arquivada) — Seção 27/28
 * do pedido: nenhuma evidência é removível depois de publicada.
 */
class RemoverEvidenciaDaLicao
{
    public function execute(LicaoAprendidaEvidencia $evidencia): void
    {
        if ($evidencia->licao->estaImutavel()) {
            throw new LicaoAprendidaImutavelException(
                'Não é possível remover evidências de uma lição publicada ou arquivada.'
            );
        }

        $caminho = $evidencia->caminho_arquivo;

        $evidencia->delete();

        if (! Storage::disk(LicaoAprendidaEvidencia::DISCO)->delete($caminho)) {
            report(new \RuntimeException("Falha ao remover arquivo físico da evidência removida (caminho: {$caminho})."));
        }
    }
}
