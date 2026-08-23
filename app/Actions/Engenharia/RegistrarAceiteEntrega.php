<?php

namespace App\Actions\Engenharia;

use App\Enums\TipoAceiteGrd;
use App\Exceptions\GrdAceiteInvalidoException;
use App\Exceptions\GrdAceiteJaAtivoException;
use App\Models\GrdAceiteEntrega;
use App\Models\GrdDestinatario;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ciclo 18, Etapa 18.5.9 — registra o aceite/assinatura de recebimento de
 * UM `GrdDestinatario`. NÃO é assinatura digital ICP-Brasil.
 *
 * **Atomicidade upload+banco** — mesma lição já auditada em
 * `AnexarRevisaoDocumento` (18.2): o storage NUNCA participa de
 * `DB::transaction()` (envolvê-lo reintroduziria a janela "commit falhou
 * depois do storage ter sucesso"). Ordem: (1) decodifica/valida/grava o
 * PNG da assinatura primeiro (se houver), (2) só então tenta o INSERT —
 * se o storage falhar, nunca chega a tentar o INSERT; se o INSERT falhar
 * depois do storage ter sucesso (incluindo a colisão da UNIQUE
 * estrutural), o arquivo é removido explicitamente (compensação).
 *
 * **Cardinalidade — "no máximo 1 ATIVO por destinatário"**: checagem de
 * aplicação (`lockForUpdate()` + busca por aceite ativo existente) dá a
 * mensagem amigável no caminho comum; a garantia REAL sob concorrência é
 * a UNIQUE estrutural (`ativo_unico_destinatario`, ver docblock da
 * migration) — um 1062 nessa chave específica é traduzido pra
 * `GrdAceiteJaAtivoException`, nunca deixado propagar como erro de banco
 * cru.
 */
class RegistrarAceiteEntrega
{
    public const TAMANHO_MAXIMO_BYTES = 2 * 1024 * 1024; // 2MB, decodificado

    public const DISCO = 'local';

    public function execute(
        GrdDestinatario $grdDestinatario,
        TipoAceiteGrd $tipo,
        string $nomeRecebedor,
        User $usuario,
        ?string $assinaturaBase64 = null,
        ?string $observacao = null,
        ?\DateTimeInterface $ocorridoEm = null
    ): GrdAceiteEntrega {
        $grd = $grdDestinatario->grd()->firstOrFail();

        if (! $grd->estaEmitida()) {
            throw new GrdAceiteInvalidoException('Só é possível registrar aceite numa GRD Emitida.');
        }

        $nomeRecebedor = trim($nomeRecebedor);
        if ($nomeRecebedor === '') {
            throw new GrdAceiteInvalidoException('O nome de quem recebeu é obrigatório.');
        }

        if ($tipo === TipoAceiteGrd::Assinatura && ! $assinaturaBase64) {
            throw new GrdAceiteInvalidoException('Assinatura é obrigatória para o tipo "Assinatura".');
        }

        $caminho = null;
        $hash = null;

        if ($tipo === TipoAceiteGrd::Assinatura) {
            [$caminho, $hash] = $this->salvarAssinatura($grd->obra_id, $assinaturaBase64);
        }

        try {
            return DB::transaction(function () use ($grdDestinatario, $tipo, $nomeRecebedor, $usuario, $caminho, $hash, $observacao, $ocorridoEm) {
                // Lock em linha estável do destinatário: serializa 2 tentativas
                // concorrentes de registrar aceite pro MESMO GrdDestinatario —
                // defesa em profundidade além da UNIQUE estrutural (que é quem
                // protege de verdade sob corrida genuína entre 2 processos que
                // nunca chegaram a se bloquear mutuamente, ex. deadlock evitado
                // por timing diferente de lock).
                $gdAtual = GrdDestinatario::whereKey($grdDestinatario->id)->lockForUpdate()->firstOrFail();

                $jaAtivo = GrdAceiteEntrega::where('grd_destinatario_id', $gdAtual->id)
                    ->whereNull('invalidado_em')
                    ->exists();

                if ($jaAtivo) {
                    throw new GrdAceiteJaAtivoException(
                        'Já existe um aceite ativo para este destinatário — invalide-o antes de registrar um novo.'
                    );
                }

                return GrdAceiteEntrega::create([
                    'tenant_id' => $gdAtual->tenant_id,
                    'grd_destinatario_id' => $gdAtual->id,
                    'nome_recebedor_snapshot' => $nomeRecebedor,
                    'empresa_snapshot' => $gdAtual->empresa_snapshot,
                    'setor_snapshot' => $gdAtual->setor_snapshot,
                    'tipo_aceite' => $tipo,
                    'assinatura_path' => $caminho,
                    'assinatura_hash' => $hash,
                    'token' => Str::random(48),
                    'registrado_por' => $usuario->id,
                    'ocorrido_em' => $ocorridoEm ?? now(),
                    'observacao' => $observacao,
                    'created_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // Compensação: o arquivo já foi gravado, mas o registro não
            // pôde ser criado (inclusive corrida genuína pega pela UNIQUE
            // estrutural) — remove o arquivo pra nunca deixar um órfão.
            if ($caminho) {
                Storage::disk(self::DISCO)->delete($caminho);
            }

            if ($e instanceof QueryException && $this->eColisaoDeAtivoUnico($e)) {
                throw new GrdAceiteJaAtivoException(
                    'Já existe um aceite ativo para este destinatário — invalide-o antes de registrar um novo.'
                );
            }

            throw $e;
        }
    }

    /**
     * @return array{0: string, 1: string} [caminho, hash sha256]
     */
    private function salvarAssinatura(string $obraId, string $assinaturaBase64): array
    {
        $dados = $assinaturaBase64;
        if (str_starts_with($dados, 'data:')) {
            $virgula = strpos($dados, ',');
            $dados = $virgula !== false ? substr($dados, $virgula + 1) : '';
        }

        $binario = base64_decode($dados, true);
        if ($binario === false || $binario === '') {
            throw new GrdAceiteInvalidoException('Assinatura em formato inválido.');
        }

        if (strlen($binario) > self::TAMANHO_MAXIMO_BYTES) {
            throw new GrdAceiteInvalidoException('Arquivo de assinatura excede o tamanho máximo permitido.');
        }

        $info = @getimagesizefromstring($binario);
        if ($info === false || $info[2] !== IMAGETYPE_PNG) {
            throw new GrdAceiteInvalidoException('Assinatura precisa ser uma imagem PNG válida.');
        }

        // Path 100% gerado pelo sistema — nunca deriva de nome/dado fornecido
        // pelo usuário (nem existe "nome de arquivo" fornecido, já que a
        // origem é um canvas, mas o princípio vale igual: Str::random(),
        // nunca algo previsível/controlável externamente).
        $caminho = "grd-aceites/{$obraId}/" . Str::random(26) . '.png';

        $gravou = Storage::disk(self::DISCO)->put($caminho, $binario);
        if (! $gravou) {
            throw new \RuntimeException('Falha ao salvar o arquivo da assinatura no storage.');
        }

        return [$caminho, hash('sha256', $binario)];
    }

    private function eColisaoDeAtivoUnico(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            && str_contains((string) $e->getMessage(), 'grd_aceites_entrega_ativo_unico');
    }
}
