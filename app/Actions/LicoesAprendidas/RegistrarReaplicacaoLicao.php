<?php

namespace App\Actions\LicoesAprendidas;

use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Exceptions\ReaplicacaoLicaoInvalidaException;
use App\Exceptions\ReaplicacaoLicaoJaRegistradaException;
use App\Exceptions\ReaplicacaoLicaoNaoAutorizadaException;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaReaplicacao;
use App\Models\LicaoAprendidaReaplicacaoContexto;
use App\Models\User;
use App\Models\Work;
use App\Support\LicoesAprendidas\VinculoLicaoResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 23, Etapa 23.5.B — registra que `$licao` foi conscientemente
 * REAPLICADA em `$obraDestino`. Invariantes de domínio (Seções 3/7/8/17),
 * sempre revalidadas aqui — nunca confiadas ao chamador:
 *
 * - Ciclo 23.5.B.CORREÇÃO (Seção 1) — `$usuario` (ator EXPLÍCITO, nunca
 *   `Auth::user()` implícito — compatível com Job/CLI/fila) precisa
 *   possuir `gestao.licoes-aprendidas|criar` na obra de destino
 *   (`LicaoAprendidaReaplicacaoPolicy::registrar()`). Checado aqui
 *   DENTRO da Action, nunca só no chamador (trait/Livewire) — write-path
 *   seguro por construção: uma chamada direta desta Action (tinker, Job
 *   futuro, outro componente que esqueça de checar Policy) nunca
 *   contorna autorização. O chamador continua checando a MESMA Policy
 *   antes, só para UX (mensagem amigável sem round-trip) — nunca a única
 *   defesa.
 * - `$licao` precisa estar `Publicada` no momento do registro (revalida
 *   `fresh()`, nunca o objeto potencialmente stale recebido).
 * - `$obraDestino` nunca pode ser a própria obra de origem da lição —
 *   reaplicação é circulação de conhecimento pra OUTRA obra.
 * - `$obraDestino` precisa pertencer ao MESMO tenant da lição — defesa
 *   em profundidade, mesmo o chamador já devendo ter resolvido isso
 *   corretamente (nenhuma manipulação de ID cross-tenant é aceita).
 *
 * Concorrência/double-click (Seção 3/19): a defesa REAL é a constraint
 * `UNIQUE(tenant_id, licao_aprendida_id, obra_id)` — nunca uma checagem
 * em PHP como único mecanismo. Uma 2ª tentativa simultânea pra o mesmo
 * par Lição×Obra sempre colide no `INSERT` (SQLSTATE 23000/MySQL 1062),
 * capturada aqui e convertida em `ReaplicacaoLicaoJaRegistradaException`
 * — nunca um 500 cru, mesmo idioma já usado em
 * `VincularEntidadeALicao`/`PlanoAcao::transformarEmRestricoes()`.
 *
 * Contexto operacional (Seção 5) é sempre OPCIONAL e nunca essencial ao
 * fato corporativo em si — uma entidade de contexto que não resolve mais
 * (removida entre o clique e a confirmação) é simplesmente IGNORADA
 * (nunca aborta a transação inteira), diferente do vínculo de ORIGEM de
 * uma lição (23.2), que é obrigatório e cuja falha desfaz tudo. "Contexto
 * NÃO altera a cardinalidade corporativa da reaplicação" (Seção 5) — o
 * registro principal sempre é criado independente de quantos contextos
 * (0, 1 ou mais) efetivamente resolvem.
 *
 * @param  array<int, array{tipo: TipoEntidadeVinculoLicao, id: string}>  $contextos
 */
class RegistrarReaplicacaoLicao
{
    public function execute(
        LicaoAprendida $licao,
        Work $obraDestino,
        User $usuario,
        ?string $observacaoInicial = null,
        array $contextos = [],
    ): LicaoAprendidaReaplicacao {
        if (! $usuario->can('registrar', [LicaoAprendidaReaplicacao::class, $obraDestino])) {
            throw new ReaplicacaoLicaoNaoAutorizadaException(
                'Você não tem permissão para registrar reaplicação nesta obra.'
            );
        }

        $licaoFresca = $licao->fresh();

        if (! $licaoFresca || $licaoFresca->status !== StatusLicaoAprendida::Publicada) {
            throw new ReaplicacaoLicaoInvalidaException(
                'Só é possível registrar reaplicação de uma lição Publicada.'
            );
        }

        if ($obraDestino->tenant_id !== $licaoFresca->tenant_id) {
            throw new ReaplicacaoLicaoInvalidaException(
                'A obra de destino precisa pertencer à mesma empresa da lição.'
            );
        }

        if ($obraDestino->id === $licaoFresca->obra_origem_id) {
            throw new ReaplicacaoLicaoInvalidaException(
                'Reaplicação representa a circulação do conhecimento para outra obra — não é possível registrar reaplicação na própria obra de origem da lição.'
            );
        }

        return DB::transaction(function () use ($licaoFresca, $obraDestino, $usuario, $observacaoInicial, $contextos) {
            try {
                $reaplicacao = LicaoAprendidaReaplicacao::create([
                    'licao_aprendida_id' => $licaoFresca->id,
                    'obra_id' => $obraDestino->id,
                    'observacao_inicial' => $observacaoInicial,
                    'created_by_id' => $usuario->id,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new ReaplicacaoLicaoJaRegistradaException(
                        'Esta lição já possui uma reaplicação registrada nesta obra.'
                    );
                }

                throw $e;
            }

            // Dedup por (tipo, id) — nunca deixa um chamador descuidado
            // (2 contextos idênticos na mesma chamada) colidir contra
            // `licao_reaplicacao_ctx_reap_entidade_unique` e derrubar a
            // transação inteira: contexto duplicado é só ignorado, o fato
            // principal (a reaplicação) nunca paga o preço por isso.
            $contextosUnicos = collect($contextos)
                ->filter(fn (array $c) => ($c['tipo'] ?? null) instanceof TipoEntidadeVinculoLicao && ! empty($c['id']))
                ->unique(fn (array $c) => $c['tipo']->value.':'.$c['id']);

            foreach ($contextosUnicos as $contexto) {
                $entidade = VinculoLicaoResolver::resolver($contexto['tipo'], $contexto['id']);
                if (! $entidade) {
                    // Contexto opcional — entidade removida/inexistente nunca
                    // impede o registro do fato corporativo principal.
                    continue;
                }

                LicaoAprendidaReaplicacaoContexto::create([
                    'reaplicacao_id' => $reaplicacao->id,
                    'entidade_tipo' => $contexto['tipo']->value,
                    'entidade_id' => $entidade->id,
                    'titulo_snapshot' => VinculoLicaoResolver::tituloParaSnapshot($contexto['tipo'], $entidade),
                    'created_by_id' => $usuario->id,
                ]);
            }

            return $reaplicacao->fresh(['contextos']);
        });
    }
}
