<?php

namespace App\Actions\LicoesAprendidas;

use App\DTOs\LicoesAprendidas\ContextoNovaLicao;
use App\Enums\StatusCandidatoLicaoAprendida;
use App\Exceptions\CandidatoLicaoAprendidaJaTratadoException;
use App\Models\CandidatoLicaoAprendida;
use App\Models\LicaoAprendida;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 23, Etapa 23.3 (Seção 8/22/23/24) — conversão de um candidato
 * numa `LicaoAprendida` real. Fresh-read confirmado antes de codificar:
 * `CriarLicaoComOrigem::execute()` (23.2) já abre seu PRÓPRIO
 * `DB::transaction()` — chamá-la de dentro de OUTRO `DB::transaction()`
 * (aqui) participa da MESMA transação via savepoint automático do
 * Laravel/PDO, então uma falha em QUALQUER ponto (lição, vínculo de
 * origem, vínculos complementares, ou a transição do candidato) desfaz
 * TUDO — nunca uma lição órfã, nunca um candidato preso num estado
 * inconsistente.
 *
 * Concorrência (Seção 23): `lockForUpdate()` no candidato SEMPRE
 * primeiro — duas conversões simultâneas do MESMO candidato serializam
 * nesse lock; a segunda, ao adquirir o lock, relê `status` e encontra
 * `!== Pendente`, lançando a exceção ANTES de criar qualquer lição.
 * A transição final (`UPDATE ... WHERE status='pendente'`) é a defesa
 * estrutural final, mesmo idioma já usado por `LiberarReservaEstoque`/
 * `TratarInconsistenciaAvanco` — nunca confia só no lock.
 */
class ConverterCandidatoEmLicao
{
    public function execute(
        CandidatoLicaoAprendida $candidato,
        ContextoNovaLicao $contexto,
        ?Work $obraEscolhidaPeloUsuario,
        User $autor,
        array $dadosFormulario,
    ): LicaoAprendida {
        return DB::transaction(function () use ($candidato, $contexto, $obraEscolhidaPeloUsuario, $autor, $dadosFormulario) {
            $travado = CandidatoLicaoAprendida::whereKey($candidato->id)->lockForUpdate()->first();

            if (! $travado || $travado->status !== StatusCandidatoLicaoAprendida::Pendente) {
                throw new CandidatoLicaoAprendidaJaTratadoException(
                    'Este candidato já foi tratado (convertido ou descartado) por outra ação.'
                );
            }

            $licao = app(CriarLicaoComOrigem::class)->execute($contexto, $obraEscolhidaPeloUsuario, $autor, $dadosFormulario);

            $linhasAtualizadas = CandidatoLicaoAprendida::whereKey($candidato->id)
                ->where('status', StatusCandidatoLicaoAprendida::Pendente->value)
                ->update([
                    'status' => StatusCandidatoLicaoAprendida::Convertido->value,
                    'convertido_em' => now(),
                    'licao_aprendida_id' => $licao->id,
                ]);

            if ($linhasAtualizadas === 0) {
                // Nunca deveria acontecer sob o lockForUpdate() acima —
                // defesa em profundidade final; lançar aqui desfaz a
                // lição+vínculos já criados nesta mesma transação.
                throw new CandidatoLicaoAprendidaJaTratadoException(
                    'Este candidato já foi tratado (convertido ou descartado) por outra ação.'
                );
            }

            return $licao;
        });
    }
}
