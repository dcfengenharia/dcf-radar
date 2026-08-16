<?php

namespace App\Http\Controllers;

use App\Actions\Atividade\RemoverAnexoAtividade;
use App\Models\AtividadeAnexo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo 17, A.7.1 — download protegido e remoção de anexo. Rotas vivem fora
 * do grupo obra.context (ver routes/web.php): o ID do anexo já é suficiente
 * pra resolver tenant (via BelongsToTenant/route-model-binding) e obra
 * (via $anexo->atividade->obra_id), então a autorização não depende de
 * nenhuma "obra ativa" na sessão — um link de anexo funciona mesmo se o
 * usuário estiver navegando noutra obra no momento.
 *
 * Ciclo 17, A.7.1.CORREÇÃO — auditoria adversarial encontrou que
 * `download()` autorizava via `AtividadePolicy::view()`, que checa só
 * `temAcessoAObra()` (qualquer vínculo com a obra) — NÃO a permissão
 * granular `restricoes.lookahead|ver`. Como "Ver" é toggleável por perfil
 * em Perfis de Acesso (⚡perfis-acesso.blade.php, sem proteção especial pra
 * essa ação), um perfil com "Ver Lookahead" explicitamente revogado ainda
 * conseguia baixar qualquer anexo — mais permissivo que a própria página
 * que originou o documento. `download()` passou a checar
 * `temPermissaoNaObra(..., 'restricoes.lookahead', 'ver')` diretamente,
 * sem passar por `AtividadePolicy::view()`. `AtividadePolicy::view()` NÃO
 * foi alterada — ela é usada em outros fluxos com a semântica "tem acesso
 * à obra" já estabelecida, e mudar sua definição ali teria efeito
 * colateral fora do escopo desta correção.
 *
 * `destroy()` já usava a checagem certa desde a A.7.1 —
 * `AtividadePolicy::delete()` → `restricoes.lookahead|excluir` — não
 * alterado.
 */
class AtividadeAnexoController extends Controller
{
    public function download(Request $request, AtividadeAnexo $anexo)
    {
        abort_unless(
            $request->user()->temPermissaoNaObra($anexo->atividade->obra_id, 'restricoes.lookahead', 'ver'),
            403
        );

        abort_unless(Storage::disk(AtividadeAnexo::DISCO)->exists($anexo->caminho_arquivo), 404);

        return Storage::disk(AtividadeAnexo::DISCO)->download($anexo->caminho_arquivo, $anexo->nome_original);
    }

    public function destroy(AtividadeAnexo $anexo, RemoverAnexoAtividade $action)
    {
        $this->authorize('delete', $anexo->atividade);

        $action->execute($anexo);

        return response()->noContent();
    }
}
