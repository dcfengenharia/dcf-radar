<?php

namespace App\Actions\LicoesAprendidas;

use App\DTOs\LicoesAprendidas\ContextoNovaLicao;
use App\Models\LicaoAprendida;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 23, Etapa 23.2 (Seção 22/48) — cria a lição JUNTO com o vínculo
 * de origem e os vínculos complementares deterministas, tudo dentro de
 * UMA transação: uma falha em qualquer vínculo nunca deixa uma lição
 * "órfã" (criada, mas sem o registro de onde ela veio).
 *
 * `$obraEscolhidaPeloUsuario` só é usado quando `$contexto->
 * obraDeterministica` é `false` (hoje, só Material) — quando é `true`,
 * a obra usada é SEMPRE `$contexto->obraSugerida` (derivada da própria
 * entidade de origem, revalidada aqui de novo, nunca confiada a um
 * campo escondido do formulário — Seção 16).
 */
class CriarLicaoComOrigem
{
    public function execute(
        ContextoNovaLicao $contexto,
        ?Work $obraEscolhidaPeloUsuario,
        User $autor,
        array $dadosFormulario,
    ): LicaoAprendida {
        $obra = $contexto->obraDeterministica ? $contexto->obraSugerida : $obraEscolhidaPeloUsuario;

        if (! $obra) {
            throw new \InvalidArgumentException('Nenhuma obra válida foi determinada para esta lição.');
        }

        return DB::transaction(function () use ($contexto, $obra, $autor, $dadosFormulario) {
            $licao = app(CriarLicaoAprendida::class)->execute($obra, $autor, $dadosFormulario);

            app(VincularEntidadeALicao::class)->execute(
                $licao,
                $contexto->tipoOrigem,
                $contexto->entidadeOrigemId,
                $autor,
                eOrigem: true,
            );

            foreach ($contexto->vinculosComplementares as $complementar) {
                app(VincularEntidadeALicao::class)->execute(
                    $licao,
                    $complementar['tipo'],
                    $complementar['id'],
                    $autor,
                    eOrigem: false,
                );
            }

            return $licao->fresh(['vinculos']);
        });
    }
}
