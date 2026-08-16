<?php

namespace App\Actions\Atividade;

use App\Models\Atividade;
use App\Models\AtividadeAnexo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Ciclo 17, A.7.1 — núcleo de upload de anexo PDF por atividade. Reutilizável
 * pelo componente Lookahead (A.7.2, ainda não implementada) e por qualquer
 * outro caminho futuro — não faz nenhuma checagem de permissão (isso é
 * responsabilidade do chamador, mesmo padrão de App\Actions\Atividade\
 * MarcarNaoConcluido).
 *
 * Atomicidade upload+banco: o filesystem NÃO participa da transaction SQL,
 * então DB::transaction() sozinho não protegeria contra um arquivo salvo com
 * INSERT falho. Ordem escolhida: (1) salva o arquivo primeiro, (2) só then
 * cria o registro — se o storage falhar, nunca chega a tentar o INSERT
 * (nenhum registro órfão possível); se o INSERT falhar depois do storage ter
 * sucesso, o arquivo recém-salvo é removido explicitamente (compensação) no
 * catch, pra nunca deixar um arquivo físico órfão sem registro.
 */
class AnexarArquivoAtividade
{
    public function execute(Atividade $atividade, UploadedFile $arquivo, ?User $usuario): AtividadeAnexo
    {
        Validator::make(
            ['arquivo' => $arquivo],
            ['arquivo' => ['required', 'file', 'mimes:pdf', 'max:' . AtividadeAnexo::TAMANHO_MAXIMO_KB]],
            [],
            ['arquivo' => 'arquivo']
        )->validate();

        $nomeOriginal = $arquivo->getClientOriginalName();
        $mimeType = $arquivo->getMimeType();
        $tamanhoBytes = $arquivo->getSize();

        $caminho = $arquivo->store(
            "lookahead-anexos/{$atividade->obra_id}/{$atividade->id}",
            AtividadeAnexo::DISCO
        );

        if ($caminho === false) {
            throw new \RuntimeException('Falha ao salvar o arquivo do anexo no storage.');
        }

        try {
            return AtividadeAnexo::create([
                'atividade_id' => $atividade->id,
                'nome_original' => $nomeOriginal,
                'caminho_arquivo' => $caminho,
                'mime_type' => $mimeType,
                'tamanho_bytes' => $tamanhoBytes,
                'enviado_por' => $usuario?->id,
            ]);
        } catch (\Throwable $e) {
            // Compensação: o arquivo já foi gravado no disco, mas o
            // registro não pôde ser criado — remove o arquivo pra nunca
            // deixar um órfão físico sem linha correspondente no banco.
            Storage::disk(AtividadeAnexo::DISCO)->delete($caminho);

            throw $e;
        }
    }
}
