<?php

namespace App\Actions\LicoesAprendidas;

use App\Enums\StatusLicaoAprendida;
use App\Exceptions\LicaoAprendidaTransicaoInvalidaException;
use App\Models\LicaoAprendida;

/**
 * Ciclo 23, Etapa 23.1 — só edita enquanto Rascunho (EmValidacao exige
 * `DevolverParaRascunho` primeiro — decisão deliberada, item 11 do
 * pedido: correção durante revisão passa sempre por uma devolução
 * explícita, nunca uma edição silenciosa no meio da fila de validação).
 * `App\Observers\LicaoAprendidaObserver` já bloqueia Publicada/Arquivada
 * — este guard cobre o caso intermediário (EmValidacao) que o Observer
 * não trata (EmValidacao não é "imutável", só não editável por ESTA
 * Action específica).
 */
class AtualizarLicaoAprendida
{
    public function execute(LicaoAprendida $licao, array $dados): LicaoAprendida
    {
        if ($licao->status !== StatusLicaoAprendida::Rascunho) {
            throw new LicaoAprendidaTransicaoInvalidaException(
                'Só é possível editar uma lição enquanto ela está em Rascunho.'
            );
        }

        $licao->update(array_intersect_key($dados, array_flip([
            'disciplina_id', 'titulo', 'situacao_observada', 'causa', 'impacto',
            'acao_adotada', 'resultado', 'recomendacao_futura', 'tipo',
            'criticidade', 'area_funcional', 'data_ocorrencia', 'data_ocorrencia_fim',
            'observacoes_internas',
        ])));

        return $licao->fresh();
    }
}
