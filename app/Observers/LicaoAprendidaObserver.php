<?php

namespace App\Observers;

use App\Enums\StatusLicaoAprendida;
use App\Exceptions\LicaoAprendidaImutavelException;
use App\Models\LicaoAprendida;

/**
 * Ciclo 23, Etapa 23.1 — barreira de imutabilidade abaixo da UI (mesmo
 * padrão de `GrdObserver`/`ItemTakeOffObserver`): uma lição Publicada ou
 * Arquivada nunca tem seu CONTEÚDO reescrito, mesmo chamada direto no
 * model — só as colunas de GOVERNANÇA (status, publicado_por_id,
 * publicado_em, arquivado_por_id, arquivado_em) podem mudar, porque são
 * exatamente elas que as próprias transições de
 * `App\Actions\LicoesAprendidas\PublicarLicaoAprendida`/
 * `ArquivarLicaoAprendida` gravam.
 *
 * Exclusão (`deleting`, cobre `delete()` soft E `forceDelete()` — este
 * último sempre delega pro primeiro, mesmo mecanismo já documentado em
 * `GrdObserver`): bloqueada a partir de Publicada/Arquivada — "arquivar"
 * já É o mecanismo de remoção lógica desta etapa. Rascunho/EmValidacao
 * continuam livres pra exclusão (autor mudou de ideia, nunca deveria
 * ter sido criada).
 */
class LicaoAprendidaObserver
{
    private const CAMPOS_CONTEUDO = [
        'tenant_id',
        'obra_origem_id',
        'disciplina_id',
        'titulo',
        'situacao_observada',
        'causa',
        'impacto',
        'acao_adotada',
        'resultado',
        'recomendacao_futura',
        'tipo',
        'criticidade',
        'area_funcional',
        'data_ocorrencia',
        'data_ocorrencia_fim',
        'observacoes_internas',
    ];

    public function updating(LicaoAprendida $licao): void
    {
        $statusOriginal = $licao->getOriginal('status');
        $statusOriginal = $statusOriginal instanceof StatusLicaoAprendida ? $statusOriginal : StatusLicaoAprendida::tryFrom((string) $statusOriginal);

        if (! $statusOriginal?->estaImutavel()) {
            return;
        }

        foreach (self::CAMPOS_CONTEUDO as $campo) {
            if ($licao->isDirty($campo)) {
                throw new LicaoAprendidaImutavelException(
                    'Esta lição já foi publicada/arquivada e seu conteúdo não pode mais ser editado — arquive e registre uma nova lição para corrigir.'
                );
            }
        }
    }

    public function deleting(LicaoAprendida $licao): void
    {
        if ($licao->status->estaImutavel()) {
            throw new LicaoAprendidaImutavelException(
                'Esta lição já foi publicada/arquivada e não pode ser excluída — o histórico é preservado.'
            );
        }
    }
}
