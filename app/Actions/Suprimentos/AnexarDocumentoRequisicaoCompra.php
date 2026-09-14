<?php

namespace App\Actions\Suprimentos;

use App\Enums\TipoDocumentoRequisicaoCompra;
use App\Models\Fornecedor;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraAnexo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Etapa 2 (Dossiê Documental da RC) — núcleo de upload de um anexo do
 * processo de compra, storage PRIVADO. Espelha
 * `App\Actions\Engenharia\AnexarRevisaoDocumento` (Ciclo 18.2): sem
 * checagem de permissão aqui (responsabilidade do chamador).
 *
 * Disponível em QUALQUER status da RC (Rascunho/Emitida/Concluída) —
 * decisão explícita da Seção 14 do pedido: propostas/negociações podem
 * existir antes mesmo da RC ser formalmente emitida.
 *
 * Atomicidade upload+banco (mesma lição do Ciclo 17/18): storage não
 * participa de nenhuma `DB::transaction`. Ordem: (1) salva o arquivo,
 * (2) só então cria o registro — se o INSERT falhar depois do storage
 * ter sucesso, o arquivo é removido explicitamente (compensação,
 * reportando falha de cleanup sem nunca mascarar a exceção original).
 */
class AnexarDocumentoRequisicaoCompra
{
    /**
     * @param  array{tipo_documento: string, descricao: ?string, valor_total_referencia: ?float, prazo_referencia: ?string, validade_ate: ?string, substitui_anexo_id: ?string}  $dados
     */
    public function execute(
        RequisicaoCompra $rc,
        array $dados,
        UploadedFile $arquivo,
        ?Fornecedor $fornecedor,
        User $usuario,
    ): RequisicaoCompraAnexo {
        if (! TipoDocumentoRequisicaoCompra::tryFrom($dados['tipo_documento'] ?? '')) {
            throw new InvalidArgumentException('Tipo de documento inválido.');
        }

        if ($fornecedor && $fornecedor->obra_id !== $rc->obra_id) {
            throw new InvalidArgumentException('Este Fornecedor não pertence à mesma obra da Requisição de Compra.');
        }

        Validator::make(
            ['arquivo' => $arquivo],
            ['arquivo' => [
                'required',
                'file',
                'mimes:' . RequisicaoCompraAnexo::MIMES_ACEITOS,
                'max:' . RequisicaoCompraAnexo::TAMANHO_MAXIMO_KB,
            ]],
            [],
            ['arquivo' => 'arquivo']
        )->validate();

        $nomeOriginal = $arquivo->getClientOriginalName();
        $mimeType = $arquivo->getMimeType();
        $tamanhoBytes = $arquivo->getSize();

        // Nome de arquivo NUNCA derivado de $nomeOriginal — store() já
        // gera um nome aleatório/hash preservando só a extensão (mesmo
        // mecanismo de AnexarRevisaoDocumento/AnexarArquivoAtividade),
        // eliminando path traversal e colisão por construção.
        $caminho = $arquivo->store(
            "requisicao-compra-anexos/{$rc->obra_id}/{$rc->id}",
            RequisicaoCompraAnexo::DISCO
        );

        if ($caminho === false) {
            throw new \RuntimeException('Falha ao salvar o arquivo do anexo no storage.');
        }

        try {
            return RequisicaoCompraAnexo::create([
                'requisicao_compra_id' => $rc->id,
                'tipo_documento' => $dados['tipo_documento'],
                'fornecedor_id' => $fornecedor?->id,
                'nome_original' => $nomeOriginal,
                'caminho_arquivo' => $caminho,
                'mime_type' => $mimeType,
                'tamanho_bytes' => $tamanhoBytes,
                'descricao' => $dados['descricao'] ?? null,
                'enviado_por_id' => $usuario->id,
                'substitui_anexo_id' => $dados['substitui_anexo_id'] ?? null,
                'valor_total_referencia' => $dados['valor_total_referencia'] ?? null,
                'prazo_referencia' => $dados['prazo_referencia'] ?? null,
                'validade_ate' => $dados['validade_ate'] ?? null,
            ]);
        } catch (\Throwable $e) {
            try {
                $compensou = Storage::disk(RequisicaoCompraAnexo::DISCO)->delete($caminho);

                if (! $compensou) {
                    report(new \RuntimeException(
                        "AnexarDocumentoRequisicaoCompra: falha ao compensar upload — Storage::delete() retornou false. "
                        . "RC={$rc->id} path={$caminho}. Causa original do INSERT: {$e->getMessage()}"
                    ));
                }
            } catch (\Throwable $erroCompensacao) {
                report(new \RuntimeException(
                    "AnexarDocumentoRequisicaoCompra: falha ao compensar upload — Storage::delete() lançou exceção. "
                    . "RC={$rc->id} path={$caminho}. Causa original do INSERT: {$e->getMessage()}. "
                    . "Erro da compensação: {$erroCompensacao->getMessage()}",
                    0,
                    $erroCompensacao
                ));
            }

            throw $e;
        }
    }
}
