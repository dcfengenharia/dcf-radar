<?php

namespace App\Actions\Engenharia;

use App\Exceptions\GrdAceiteJaInvalidadoException;
use App\Models\GrdAceiteEntrega;
use App\Models\User;

/**
 * Ciclo 18, Etapa 18.5.9 — invalida um aceite (nunca apaga, nunca altera
 * o conteúdo factual original — nome/empresa/setor/tipo/assinatura/
 * ocorrido_em permanecem intocados pra sempre). `update()` condicional
 * atômico (`WHERE invalidado_em IS NULL`, mesmo idioma já usado em
 * `TratarInconsistenciaAvanco`) — só a primeira tentativa afeta alguma
 * linha; uma 2ª invalidação (ou corrida concorrente de 2 invalidações
 * simultâneas) nunca sobrescreve `invalidado_por`/`motivo_invalidacao`
 * já gravados, cai em `GrdAceiteJaInvalidadoException`.
 *
 * Depois de invalidado, o `grd_destinatario_id` correspondente volta a
 * aceitar um NOVO aceite (a coluna gerada `ativo_unico_destinatario`
 * passa a `NULL` nesta linha, liberando a UNIQUE pro próximo registro —
 * ver docblock da migration).
 */
class InvalidarAceiteEntrega
{
    public function execute(GrdAceiteEntrega $aceite, string $motivo, User $usuario): GrdAceiteEntrega
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new \InvalidArgumentException('O motivo da invalidação é obrigatório.');
        }

        $linhasAfetadas = GrdAceiteEntrega::where('id', $aceite->id)
            ->whereNull('invalidado_em')
            ->update([
                'invalidado_em' => now(),
                'invalidado_por' => $usuario->id,
                'motivo_invalidacao' => $motivo,
            ]);

        if ($linhasAfetadas === 0) {
            throw new GrdAceiteJaInvalidadoException('Este aceite já está invalidado.');
        }

        return $aceite->fresh();
    }
}
