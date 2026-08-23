<?php

namespace App\Actions\Engenharia;

use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Ciclo 18, Etapa 18.2 — núcleo de criação de uma revisão de Documento de
 * Engenharia, com anexo opcional gravado em storage PRIVADO. Espelha
 * App\Actions\Atividade\AnexarArquivoAtividade (Ciclo 17, A.7.1): sem
 * checagem de permissão aqui (responsabilidade do chamador).
 *
 * Atomicidade upload+banco (mesma lição do Ciclo 17): o filesystem NÃO
 * participa de nenhuma DB::transaction — envolver o storage numa
 * transaction SQL reintroduziria a janela de "commit falhou depois do
 * storage ter sucesso" já auditada. Ordem: (1) salva o arquivo primeiro
 * (se houver), (2) só então cria a revisão — se o storage falhar, nunca
 * chega a tentar o INSERT; se o INSERT falhar depois do storage ter
 * sucesso, o arquivo recém-salvo é removido explicitamente (compensação).
 * Revisão sem anexo (campo opcional, comportamento já existente) pula a
 * parte de arquivo inteira.
 *
 * Etapa 18.2.HARDENING (achado B1 da auditoria adversarial) — o retorno
 * da compensação (delete()) É verificado e uma falha de cleanup É
 * reportada explicitamente, mas NUNCA substitui a exceção original:
 * a causa raiz continua sendo sempre a falha do INSERT, mesmo quando o
 * cleanup também falha — só o cleanup fica documentado como um problema
 * secundário (arquivo órfão conhecido), nunca mascarando o erro
 * principal que o chamador precisa tratar.
 */
class AnexarRevisaoDocumento
{
    public const DISCO = 'local';

    public const TAMANHO_MAXIMO_KB = 10240;

    /**
     * @param  array{revisao: string, data_emissao: ?string, status_documento_id: ?string, descricao: string, comentarios: ?string}  $dados
     */
    public function execute(
        DocumentoEngenharia $documento,
        array $dados,
        ?UploadedFile $arquivo,
        ?string $criadoPorId
    ): DocumentoEngenhariaRevisao {
        $caminho = null;
        $nomeOriginal = null;

        if ($arquivo) {
            Validator::make(
                ['arquivo' => $arquivo],
                ['arquivo' => ['required', 'file', 'mimes:pdf', 'max:' . self::TAMANHO_MAXIMO_KB]],
                [],
                ['arquivo' => 'arquivo']
            )->validate();

            $nomeOriginal = $arquivo->getClientOriginalName();

            $caminho = $arquivo->store(
                "documentos-engenharia/{$documento->obra_id}/{$documento->id}",
                self::DISCO
            );

            if ($caminho === false) {
                throw new \RuntimeException('Falha ao salvar o arquivo da revisão no storage.');
            }
        }

        try {
            return $documento->revisoes()->create([
                'tenant_id' => $documento->tenant_id,
                'revisao' => $dados['revisao'],
                'data_emissao' => $dados['data_emissao'] ?? null,
                'status_documento_id' => $dados['status_documento_id'] ?? null,
                'descricao' => $dados['descricao'],
                'comentarios' => $dados['comentarios'] ?? null,
                'anexo_path' => $caminho,
                'anexo_nome_original' => $nomeOriginal,
                'criado_por_id' => $criadoPorId,
            ]);
        } catch (\Throwable $e) {
            // Compensação: o arquivo já foi gravado no disco, mas o
            // registro não pôde ser criado — remove o arquivo pra nunca
            // deixar um órfão físico sem linha correspondente no banco.
            // Etapa 18.2.HARDENING: o retorno/exceção do delete() de
            // compensação é verificado e reportado — nunca fica silencioso
            // — mas a exceção ORIGINAL ($e) é sempre a relançada, nunca
            // substituída pela falha secundária de cleanup.
            if ($caminho) {
                try {
                    $compensou = Storage::disk(self::DISCO)->delete($caminho);

                    if (! $compensou) {
                        report(new \RuntimeException(
                            "AnexarRevisaoDocumento: falha ao compensar upload — Storage::delete() retornou false. "
                            . "Documento={$documento->id} path={$caminho}. Causa original do INSERT: {$e->getMessage()}"
                        ));
                    }
                } catch (\Throwable $erroCompensacao) {
                    report(new \RuntimeException(
                        "AnexarRevisaoDocumento: falha ao compensar upload — Storage::delete() lançou exceção. "
                        . "Documento={$documento->id} path={$caminho}. Causa original do INSERT: {$e->getMessage()}. "
                        . "Erro da compensação: {$erroCompensacao->getMessage()}",
                        0,
                        $erroCompensacao
                    ));
                }
            }

            throw $e;
        }
    }
}
