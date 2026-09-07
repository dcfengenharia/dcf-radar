<?php

namespace App\Actions\LicoesAprendidas;

use App\Enums\TipoEntidadeVinculoLicao;
use App\Exceptions\LicaoAprendidaImutavelException;
use App\Exceptions\VinculoLicaoInvalidoException;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaVinculo;
use App\Models\User;
use App\Support\LicoesAprendidas\VinculoLicaoResolver;

/**
 * Ciclo 23, Etapa 23.1 — cria um vínculo contextual. `$tipo` já chega
 * tipado (nunca uma string crua do request — a conversão
 * `TipoEntidadeVinculoLicao::tryFrom()` acontece no chamador, e um
 * valor fora da allowlist já falha ANTES de chegar aqui). A entidade é
 * resolvida via `VinculoLicaoResolver::resolver()`, que herda o global
 * scope de `BelongsToTenant` de cada model candidato — um ID de outro
 * tenant simplesmente não é encontrado (`null`), nunca vaza existência.
 *
 * Só permitido enquanto a lição ainda não é imutável (Rascunho/
 * EmValidacao) — os vínculos fazem parte do registro que congela na
 * publicação, mesmo espírito do conteúdo textual.
 *
 * Ciclo 23, Etapa 23.2 — `$eOrigem` (Seção 9): no máximo 1 vínculo de
 * origem por lição, garantido de verdade pelo índice único gerado
 * (`licao_vinculos_origem_unica`) — a checagem em memória aqui
 * (`vinculoOrigem()->exists()`) é só a mensagem AMIGÁVEL antes de
 * bater no banco, nunca o mecanismo real de proteção (que sobrevive a
 * corrida concorrente, o índice não).
 */
class VincularEntidadeALicao
{
    public function execute(
        LicaoAprendida $licao,
        TipoEntidadeVinculoLicao $tipo,
        string $entidadeId,
        User $autor,
        bool $eOrigem = false,
    ): LicaoAprendidaVinculo {
        if ($licao->estaImutavel()) {
            throw new LicaoAprendidaImutavelException(
                'Esta lição já foi publicada/arquivada — vínculos não podem mais ser adicionados.'
            );
        }

        if ($eOrigem && $licao->vinculoOrigem()->exists()) {
            throw new VinculoLicaoInvalidoException(
                'Esta lição já possui um vínculo de origem — não é possível ter dois.'
            );
        }

        $entidade = VinculoLicaoResolver::resolver($tipo, $entidadeId);

        if (! $entidade) {
            throw new VinculoLicaoInvalidoException(
                'A entidade selecionada não foi encontrada ou não pertence à sua empresa.'
            );
        }

        try {
            return LicaoAprendidaVinculo::create([
                'licao_aprendida_id' => $licao->id,
                'entidade_tipo' => $tipo->value,
                'entidade_id' => $entidade->id,
                'titulo_snapshot' => VinculoLicaoResolver::tituloParaSnapshot($tipo, $entidade),
                'e_origem' => $eOrigem,
                'created_by_id' => $autor->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($eOrigem && ($e->errorInfo[1] ?? null) === 1062) {
                throw new VinculoLicaoInvalidoException(
                    'Esta lição já possui um vínculo de origem — não é possível ter dois.'
                );
            }

            throw $e;
        }
    }
}
