<?php

namespace App\Actions\LicoesAprendidas;

use App\Enums\StatusLicaoAprendida;
use App\Exceptions\LicaoAprendidaIncompletaException;
use App\Exceptions\LicaoAprendidaTransicaoInvalidaException;
use App\Models\LicaoAprendida;
use App\Models\User;

/**
 * Ciclo 23, Etapa 23.1 — EmValidacao → Publicada. Único ponto que
 * valida completude estrutural (Seção 12 do pedido): título/situação/
 * recomendação futura/tipo/área/obra de origem são sempre obrigatórios;
 * causa/impacto/ação adotada/resultado ficam SEMPRE opcionais,
 * independente do tipo (nenhuma matriz de obrigatoriedade condicional —
 * "não tornar o formulário burocrático artificialmente", instrução
 * explícita do pedido; uma Boa Prática legitimamente pode não ter causa
 * negativa nenhuma).
 */
class PublicarLicaoAprendida
{
    public function execute(LicaoAprendida $licao, User $publicador): LicaoAprendida
    {
        if ($licao->status !== StatusLicaoAprendida::EmValidacao) {
            throw new LicaoAprendidaTransicaoInvalidaException(
                'Só é possível publicar uma lição que esteja Em Validação.'
            );
        }

        $faltantes = $this->camposFaltantes($licao);
        if ($faltantes !== []) {
            throw new LicaoAprendidaIncompletaException($faltantes);
        }

        $licao->update([
            'status' => StatusLicaoAprendida::Publicada,
            'publicado_por_id' => $publicador->id,
            'publicado_em' => now(),
        ]);

        return $licao->fresh();
    }

    /** @return array<int, string> */
    private function camposFaltantes(LicaoAprendida $licao): array
    {
        $obrigatorios = [
            'titulo' => trim((string) $licao->titulo) !== '',
            'situacao_observada' => trim((string) $licao->situacao_observada) !== '',
            'recomendacao_futura' => trim((string) $licao->recomendacao_futura) !== '',
            'tipo' => $licao->tipo !== null,
            'area_funcional' => $licao->area_funcional !== null,
            'obra_origem_id' => $licao->obra_origem_id !== null,
        ];

        return array_keys(array_filter($obrigatorios, fn ($preenchido) => ! $preenchido));
    }
}
