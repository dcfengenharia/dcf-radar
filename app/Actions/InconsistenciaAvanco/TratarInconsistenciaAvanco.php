<?php

namespace App\Actions\InconsistenciaAvanco;

use App\Enums\StatusInconsistenciaAvanco;
use App\Exceptions\InconsistenciaJaTratadaException;
use App\Models\InconsistenciaAvanco;
use App\Models\User;
use InvalidArgumentException;

/**
 * Ciclo 17, A.9.6 — único ponto de escrita do tratamento humano de uma
 * InconsistenciaAvanco. A importação do cronograma é o fato factual
 * verdadeiro (regra de produto da A.9.1 em diante) — esta Action NUNCA
 * toca Restricao/AtividadeItemProntidao/ProgramacaoSemanal/Atividade/
 * Fotografia F/O/P, só o domínio da própria inconsistência/tratamento.
 *
 * Concorrência: sem lock explícito nem transação (uma única escrita) — a
 * proteção é o UPDATE condicional `WHERE status = 'aberta'`, atômico ao
 * nível de linha no InnoDB. Duas chamadas concorrentes pra mesma
 * ocorrência: só uma afeta 1 linha (vence); a outra afeta 0 linhas e
 * recebe InconsistenciaJaTratadaException — nunca sobrescreve
 * silenciosamente autor/justificativa do primeiro tratamento.
 */
class TratarInconsistenciaAvanco
{
    /**
     * @throws InvalidArgumentException justificativa vazia/só espaços
     * @throws InconsistenciaJaTratadaException já foi tratada por outra requisição
     */
    public function execute(InconsistenciaAvanco $inconsistencia, User $usuario, string $justificativa): InconsistenciaAvanco
    {
        $justificativa = trim($justificativa);

        if ($justificativa === '') {
            throw new InvalidArgumentException('A justificativa é obrigatória.');
        }

        $linhasAfetadas = InconsistenciaAvanco::where('id', $inconsistencia->id)
            ->where('status', StatusInconsistenciaAvanco::Aberta->value)
            ->update([
                'status' => StatusInconsistenciaAvanco::Tratada->value,
                'tratado_por' => $usuario->id,
                'tratado_em' => now(),
                'justificativa' => $justificativa,
            ]);

        if ($linhasAfetadas === 0) {
            throw new InconsistenciaJaTratadaException($inconsistencia);
        }

        return $inconsistencia->fresh();
    }
}
