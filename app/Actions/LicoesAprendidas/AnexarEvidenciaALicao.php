<?php

namespace App\Actions\LicoesAprendidas;

use App\Exceptions\LicaoAprendidaImutavelException;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaEvidencia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Ciclo 23, Etapa 23.2 — anexa uma evidência a uma lição. Espelha
 * `App\Actions\Atividade\AnexarArquivoAtividade` (mesma ordem
 * arquivo-primeiro-depois-registro, mesma compensação em caso de falha
 * do INSERT). Allowlist de mimetype = a UNIÃO dos 2 precedentes reais já
 * aceitos em infraestrutura equivalente do projeto (`AtividadeAnexo`:
 * `pdf`; `DocumentoEngenhariaRevisao`: `jpg,jpeg,png` — confirmado via
 * grep, nenhum outro precedente de upload de arquivo existe no projeto)
 * — nunca "documentos de escritório" (docx/xlsx), que não têm nenhum
 * precedente equivalente aceito.
 *
 * Só permitido enquanto a lição NÃO é imutável (Rascunho/EmValidacao) —
 * mesma fronteira já usada por `VincularEntidadeALicao`/
 * `RemoverVinculoDaLicao` desde a 23.1: uma vez Publicada/Arquivada, o
 * conjunto de evidências também congela (Seção 27 do pedido — "recomendado:
 * sem adicionar/remover/substituir após Publicada").
 */
class AnexarEvidenciaALicao
{
    public function execute(LicaoAprendida $licao, UploadedFile $arquivo, ?User $usuario): LicaoAprendidaEvidencia
    {
        if ($licao->estaImutavel()) {
            throw new LicaoAprendidaImutavelException(
                'Não é possível anexar evidências a uma lição publicada ou arquivada.'
            );
        }

        Validator::make(
            ['arquivo' => $arquivo],
            ['arquivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:' . LicaoAprendidaEvidencia::TAMANHO_MAXIMO_KB]],
            [],
            ['arquivo' => 'arquivo']
        )->validate();

        $nomeOriginal = $arquivo->getClientOriginalName();
        $mimeType = $arquivo->getMimeType();
        $tamanhoBytes = $arquivo->getSize();

        $caminho = $arquivo->store(
            "licoes-aprendidas-evidencias/{$licao->tenant_id}/{$licao->id}",
            LicaoAprendidaEvidencia::DISCO
        );

        if ($caminho === false) {
            throw new \RuntimeException('Falha ao salvar o arquivo da evidência no storage.');
        }

        try {
            return LicaoAprendidaEvidencia::create([
                'licao_aprendida_id' => $licao->id,
                'nome_original' => $nomeOriginal,
                'caminho_arquivo' => $caminho,
                'mime_type' => $mimeType,
                'tamanho_bytes' => $tamanhoBytes,
                'enviado_por' => $usuario?->id,
            ]);
        } catch (\Throwable $e) {
            Storage::disk(LicaoAprendidaEvidencia::DISCO)->delete($caminho);

            throw $e;
        }
    }
}
