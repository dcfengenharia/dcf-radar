<?php

namespace App\Actions\LicoesAprendidas;

use App\Enums\ResultadoAvaliacaoReaplicacao;
use App\Exceptions\AvaliacaoReaplicacaoNaoAutorizadaException;
use App\Models\LicaoAprendidaReaplicacao;
use App\Models\LicaoAprendidaReaplicacaoAvaliacao;
use App\Models\User;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 10/19) — registra uma NOVA avaliação de
 * `$reaplicacao`. Sempre append-only: nunca atualiza/substitui uma
 * avaliação anterior (`App\Observers\LicaoAprendidaReaplicacaoAvaliacaoObserver`
 * bloqueia isso estruturalmente). Sem `lockForUpdate()`/transação
 * própria — é um `INSERT` puro, sem nenhuma invariante de unicidade a
 * proteger: duas avaliações legítimas em sequência (ou até concorrentes)
 * são sempre permitidas e nunca deduplicadas silenciosamente (diferente
 * de `RegistrarReaplicacaoLicao`, que protege a unidade Lição×Obra).
 *
 * Ciclo 23.5.B.CORREÇÃO (Seção 1) — `$usuario` (ator EXPLÍCITO, nunca
 * `Auth::user()` implícito) precisa possuir `gestao.licoes-aprendidas|editar`
 * na obra de destino DESTA reaplicação
 * (`LicaoAprendidaReaplicacaoPolicy::avaliar()`), checado DENTRO da
 * Action — write-path seguro por construção, mesmo raciocínio de
 * `RegistrarReaplicacaoLicao`. O chamador continua checando a MESMA
 * Policy antes, só para UX.
 *
 * Elegibilidade: nenhuma checagem de status da lição aqui de propósito
 * (Seção 9) — avaliar uma reaplicação cuja lição foi arquivada DEPOIS
 * continua sendo um registro legítimo do resultado real observado; só a
 * CRIAÇÃO de uma reaplicação NOVA exige lição Publicada
 * (`RegistrarReaplicacaoLicao`), nunca a avaliação de uma já existente.
 */
class AvaliarReaplicacaoLicao
{
    public function execute(
        LicaoAprendidaReaplicacao $reaplicacao,
        ResultadoAvaliacaoReaplicacao $resultado,
        User $usuario,
        ?string $observacao = null,
    ): LicaoAprendidaReaplicacaoAvaliacao {
        if (! $usuario->can('avaliar', $reaplicacao)) {
            throw new AvaliacaoReaplicacaoNaoAutorizadaException(
                'Você não tem permissão para avaliar esta reaplicação.'
            );
        }

        return LicaoAprendidaReaplicacaoAvaliacao::create([
            'reaplicacao_id' => $reaplicacao->id,
            'resultado' => $resultado->value,
            'avaliado_por_id' => $usuario->id,
            'avaliado_em' => now(),
            'observacao' => $observacao,
        ]);
    }
}
