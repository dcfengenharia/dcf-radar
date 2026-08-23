<?php

namespace App\Http\Controllers;

use App\Actions\Engenharia\AnexarRevisaoDocumento;
use App\Models\DocumentoEngenhariaRevisao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo 18, Etapa 18.2 — download/visualização protegidos de uma revisão
 * de Documento de Engenharia. Rota fora do grupo obra.context (mesmo
 * padrão de AtividadeAnexoController, Ciclo 17, A.7.1): o ID da revisão já
 * basta pra resolver tenant (route-model-binding + BelongsToTenant) e
 * obra (via $revisao->documento->obra_id), então não depende de nenhuma
 * "obra ativa" na sessão.
 *
 * Autorização é EXPLÍCITA e contextual à obra do Documento — nunca
 * `temPermissaoEmAlgumaObraDoTenant()` (mesmo erro já corrigido na Etapa
 * 18.1.CORREÇÃO para o vínculo Documento↔Atividade, não repetido aqui):
 * usuário com `ver` só noutra obra do tenant nunca baixa revisão desta.
 *
 * `$revisao->documento` respeita SoftDeletes por padrão (Eloquent nunca
 * carrega o pai soft-deleted numa relação belongsTo comum) — Documento
 * soft-deleted já vira null aqui, sem nenhum código extra: o download
 * fica indisponível pela rota normal, mas o arquivo físico nunca é
 * tocado (histórico preservado, pronto pra reaparecer com restore()).
 */
class DocumentoEngenhariaRevisaoController extends Controller
{
    public function download(Request $request, DocumentoEngenhariaRevisao $revisao)
    {
        $documento = $revisao->documento;
        abort_unless($documento !== null, 404);

        abort_unless(
            $request->user()->temPermissaoNaObra($documento->obra_id, 'engenharia.pacotes', 'ver'),
            403
        );

        abort_unless($revisao->anexo_path, 404);

        $disco = $this->resolverDiscoDoArquivo($revisao->anexo_path);

        if ($disco === null) {
            report(new \RuntimeException("Revisão {$revisao->id}: anexo_path='{$revisao->anexo_path}' não encontrado em nenhum disco conhecido."));
            abort(404);
        }

        if ($request->boolean('inline')) {
            return Storage::disk($disco)->response($revisao->anexo_path, $revisao->anexo_nome_original);
        }

        return Storage::disk($disco)->download($revisao->anexo_path, $revisao->anexo_nome_original);
    }

    /**
     * Etapa 18.2 — janela de transição: uploads NOVOS (via
     * AnexarRevisaoDocumento) já vão pro disco privado
     * (AnexarRevisaoDocumento::DISCO = 'local'); arquivos LEGADOS ainda
     * não migrados pelo Command dedicado continuam fisicamente no disco
     * `public` até serem copiados. `anexo_path` é o MESMO valor de string
     * nos dois casos (mesma convenção de path) — só o disco muda —
     * então não existe coluna nova pra distinguir "migrado ou não": o
     * download resolve tentando o privado primeiro (fonte de verdade
     * pós-migração) e cai pro público só como fallback histórico,
     * SEMPRE lendo o conteúdo aqui no servidor (nunca devolvendo uma URL
     * pública pro navegador). Depois que o Command
     * `engenharia:migrar-revisoes-storage-privado` migrar 100% dos
     * arquivos, o fallback nunca mais é exercitado — mas fica seguro
     * mantê-lo até essa confirmação (ver relatório da Etapa 18.2).
     */
    private function resolverDiscoDoArquivo(string $caminho): ?string
    {
        if (Storage::disk(AnexarRevisaoDocumento::DISCO)->exists($caminho)) {
            return AnexarRevisaoDocumento::DISCO;
        }

        if (Storage::disk('public')->exists($caminho)) {
            return 'public';
        }

        return null;
    }
}
