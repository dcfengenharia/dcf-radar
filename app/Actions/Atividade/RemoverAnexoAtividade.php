<?php

namespace App\Actions\Atividade;

use App\Models\AtividadeAnexo;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo 17, A.7.1 — remoção segura de um anexo: registro e arquivo físico
 * sempre saem juntos, nunca um sem o outro silenciosamente. Sem checagem de
 * permissão aqui (responsabilidade do chamador — mesmo padrão de
 * AnexarArquivoAtividade/MarcarNaoConcluido).
 *
 * Ordem escolhida (registro primeiro, arquivo depois): se a remoção do
 * arquivo físico falhar por algum motivo (ex.: permissão de disco), o pior
 * cenário é um arquivo órfão no storage (inofensivo, limpável depois) — nunca
 * uma linha em `atividade_anexos` apontando pra um arquivo já removido, que
 * quebraria o download com um 404 confuso e continuaria aparecendo em
 * listagens como se o anexo ainda existisse.
 */
class RemoverAnexoAtividade
{
    public function execute(AtividadeAnexo $anexo): void
    {
        $caminho = $anexo->caminho_arquivo;

        $anexo->delete();

        if (! Storage::disk(AtividadeAnexo::DISCO)->delete($caminho)) {
            report(new \RuntimeException("Falha ao remover arquivo físico do anexo removido (caminho: {$caminho})."));
        }
    }
}
