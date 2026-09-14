<?php

namespace App\Actions\Suprimentos;

use App\Models\RequisicaoCompraAnexo;
use Illuminate\Support\Facades\Storage;

/**
 * Etapa 2 — remoção de um anexo já não referenciado como evidência
 * (guard real vive em `App\Observers\RequisicaoCompraAnexoObserver`,
 * disparado por `$anexo->delete()`). Mesmo padrão de
 * `App\Actions\Atividade\RemoverAnexoAtividade`: apaga o arquivo físico
 * DEPOIS de o registro ser removido com sucesso — nunca antes (uma
 * falha na exclusão do registro nunca deve deixar o arquivo já
 * removido do disco sem o registro correspondente também sumir).
 */
class RemoverAnexoRequisicaoCompra
{
    public function execute(RequisicaoCompraAnexo $anexo): void
    {
        $caminho = $anexo->caminho_arquivo;

        $anexo->delete();

        Storage::disk(RequisicaoCompraAnexo::DISCO)->delete($caminho);
    }
}
