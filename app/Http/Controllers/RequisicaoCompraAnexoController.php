<?php

namespace App\Http\Controllers;

use App\Models\RequisicaoCompraAnexo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Etapa 2 — download protegido de um anexo do dossiê documental da RC.
 * Rota fora do grupo `obra.context` (mesmo padrão de
 * `AtividadeAnexoController`/`DocumentoEngenhariaRevisaoController`) —
 * o ID do anexo já basta pra resolver tenant (route-model-binding +
 * `BelongsToTenant`) e obra (via `$anexo->requisicaoCompra->obra_id`),
 * então não depende de nenhuma "obra ativa" na sessão. Autorização
 * EXPLÍCITA e contextual à obra da RC — nunca
 * `temPermissaoEmAlgumaObraDoTenant()`.
 */
class RequisicaoCompraAnexoController extends Controller
{
    public function download(Request $request, RequisicaoCompraAnexo $anexo)
    {
        $rc = $anexo->requisicaoCompra;
        abort_unless($rc !== null, 404);

        abort_unless(
            $request->user()->temPermissaoNaObra($rc->obra_id, 'suprimentos.mapa', 'ver'),
            403
        );

        abort_unless(Storage::disk(RequisicaoCompraAnexo::DISCO)->exists($anexo->caminho_arquivo), 404);

        return Storage::disk(RequisicaoCompraAnexo::DISCO)->download($anexo->caminho_arquivo, $anexo->nome_original);
    }
}
