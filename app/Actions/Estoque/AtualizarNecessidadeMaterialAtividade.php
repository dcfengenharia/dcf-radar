<?php

namespace App\Actions\Estoque;

use App\Enums\OrigemNecessidadeMaterialAtividade;
use App\Exceptions\NecessidadeMaterialAtividadeInvalidaException;
use App\Exceptions\SaldoNecessidadeInsuficienteException;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\ItemTakeOff;
use App\Models\Material;
use App\Models\ReservaEstoque;
use App\Models\User;
use App\Support\Estoque\ConciliacaoNecessidadeAtividade;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Melhoria "Posto Operacional" — único ponto de escrita de
 * `AtividadeNecessidadeMaterial`. Mesmo padrão exato de
 * `App\Actions\Estoque\AtualizarDestinacaoPlanejada` (Ciclo 20.2): guard
 * de regra de negócio + lock na própria Action, SEM Observer dedicado
 * — a FK `restrictOnDelete()` de `reservas_estoque.necessidade_atividade_id`
 * já bloqueia fisicamente a exclusão referenciada; esta Action só
 * converte essa proteção numa mensagem didática ANTES.
 *
 * **Ordem de lock**: `ItemTakeOff` é travado SEMPRE PRIMEIRO nos
 * caminhos de origem=take_off (mesmo papel de `ItemSuprimento` em
 * `AtualizarDestinacaoPlanejada`) — nenhum outro fluxo do projeto trava
 * `ItemTakeOff` simultaneamente com `RequisicaoPlanejamentoItem`/
 * `ItemSuprimento`/`AlocacaoRequisicaoPacote` (a única outra Action que
 * trava `ItemTakeOff`, `AssociarMaterialAoItemTakeOff`, nunca é chamada
 * na mesma transação que esta), então não há risco de inversão de
 * ordem com o total-order já estabelecido no projeto.
 */
class AtualizarNecessidadeMaterialAtividade
{
    public function criarTakeOff(Atividade $atividade, ItemTakeOff $itemTakeOff, float $quantidade, ?User $usuario = null, ?string $observacao = null): AtividadeNecessidadeMaterial
    {
        return DB::transaction(function () use ($atividade, $itemTakeOff, $quantidade, $usuario, $observacao) {
            $itemTravado = ItemTakeOff::whereKey($itemTakeOff->id)->lockForUpdate()->firstOrFail();

            $this->garantirQuantidadePositiva($quantidade);
            $this->garantirMesmaObraTakeOff($atividade, $itemTravado);
            $this->validarSaldoTakeOff($itemTravado, $quantidade, excluirNecessidadeId: null);

            try {
                return AtividadeNecessidadeMaterial::create([
                    'obra_id' => $atividade->obra_id,
                    'atividade_id' => $atividade->id,
                    'origem' => OrigemNecessidadeMaterialAtividade::TakeOff->value,
                    'item_take_off_id' => $itemTravado->id,
                    'material_id' => null,
                    'unidade_medida_id' => $itemTravado->unidade_medida_id,
                    'quantidade_necessaria' => $quantidade,
                    'observacao' => $observacao,
                    'created_by_id' => $usuario?->id,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new NecessidadeMaterialAtividadeInvalidaException(
                        'Esta atividade já tem uma necessidade cadastrada para este item de TakeOff — edite a existente em vez de criar uma nova.'
                    );
                }

                throw $e;
            }
        });
    }

    public function criarOperacional(Atividade $atividade, Material $material, float $quantidade, string $justificativa, ?User $usuario = null): AtividadeNecessidadeMaterial
    {
        return DB::transaction(function () use ($atividade, $material, $quantidade, $justificativa, $usuario) {
            $this->garantirQuantidadePositiva($quantidade);
            $this->garantirJustificativaObrigatoria($justificativa);
            $this->garantirMaterialAtivo($material);

            try {
                return AtividadeNecessidadeMaterial::create([
                    'obra_id' => $atividade->obra_id,
                    'atividade_id' => $atividade->id,
                    'origem' => OrigemNecessidadeMaterialAtividade::Operacional->value,
                    'item_take_off_id' => null,
                    'material_id' => $material->id,
                    'unidade_medida_id' => $material->unidade_medida_id,
                    'quantidade_necessaria' => $quantidade,
                    'observacao' => $justificativa,
                    'created_by_id' => $usuario?->id,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new NecessidadeMaterialAtividadeInvalidaException(
                        'Esta atividade já tem uma necessidade operacional cadastrada para este Material — edite a existente em vez de criar uma nova.'
                    );
                }

                throw $e;
            }
        });
    }

    public function alterar(AtividadeNecessidadeMaterial $necessidade, float $novaQuantidade, ?string $observacao = null): AtividadeNecessidadeMaterial
    {
        return DB::transaction(function () use ($necessidade, $novaQuantidade, $observacao) {
            if ($necessidade->origem === OrigemNecessidadeMaterialAtividade::TakeOff) {
                ItemTakeOff::whereKey($necessidade->item_take_off_id)->lockForUpdate()->firstOrFail();
            }

            $necessidadeTravada = AtividadeNecessidadeMaterial::whereKey($necessidade->id)->lockForUpdate()->firstOrFail();

            $this->garantirQuantidadePositiva($novaQuantidade);

            if ($necessidadeTravada->origem === OrigemNecessidadeMaterialAtividade::Operacional) {
                $this->garantirJustificativaObrigatoria($observacao ?? $necessidadeTravada->observacao ?? '');
            } else {
                $itemTakeOff = ItemTakeOff::findOrFail($necessidadeTravada->item_take_off_id);
                $this->validarSaldoTakeOff($itemTakeOff, $novaQuantidade, excluirNecessidadeId: $necessidadeTravada->id);
            }

            $this->garantirNaoAbaixoDoReservado($necessidadeTravada, $novaQuantidade);

            $necessidadeTravada->update([
                'quantidade_necessaria' => $novaQuantidade,
                'observacao' => $observacao ?? $necessidadeTravada->observacao,
            ]);

            return $necessidadeTravada->fresh();
        });
    }

    public function remover(AtividadeNecessidadeMaterial $necessidade): void
    {
        DB::transaction(function () use ($necessidade) {
            if ($necessidade->origem === OrigemNecessidadeMaterialAtividade::TakeOff) {
                ItemTakeOff::whereKey($necessidade->item_take_off_id)->lockForUpdate()->firstOrFail();
            }

            $necessidadeTravada = AtividadeNecessidadeMaterial::whereKey($necessidade->id)->lockForUpdate()->firstOrFail();

            $this->garantirSemReservaVinculada($necessidadeTravada);

            $necessidadeTravada->delete();
        });
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new NecessidadeMaterialAtividadeInvalidaException('A quantidade necessária precisa ser maior que zero.');
        }
    }

    private function garantirMaterialAtivo(Material $material): void
    {
        if (! $material->ativo) {
            throw new NecessidadeMaterialAtividadeInvalidaException('Este Material está inativo e não pode receber nova necessidade.');
        }
    }

    /**
     * Justificativa obrigatória pra origem=operacional (Seção 5, sem
     * exigir aprovação formal nesta rodada — só o registro do motivo).
     */
    private function garantirJustificativaObrigatoria(string $justificativa): void
    {
        if (trim($justificativa) === '') {
            throw new NecessidadeMaterialAtividadeInvalidaException(
                'Uma necessidade sem origem em TakeOff exige uma justificativa — explique por que este material é necessário.'
            );
        }
    }

    /**
     * Cross-obra (Seção 4) — nunca confia só na FK: resolve a obra REAL
     * do ItemTakeOff pela mesma cadeia já validada em
     * `AssociarMaterialAoItemTakeOff` (lista->revisao->documento->obra_id,
     * já que ItemTakeOff nunca tem obra_id próprio — Material é
     * tenant-wide por design, Ciclo 20.1/20.9).
     */
    private function garantirMesmaObraTakeOff(Atividade $atividade, ItemTakeOff $itemTakeOff): void
    {
        $itemTakeOff->loadMissing('lista.revisao.documento');
        $obraDoItem = $itemTakeOff->lista?->revisao?->documento?->obra_id;

        if ($obraDoItem === null || $obraDoItem !== $atividade->obra_id) {
            throw new NecessidadeMaterialAtividadeInvalidaException('Este item de TakeOff não pertence à mesma obra desta Atividade.');
        }
    }

    /**
     * Over-distribuição (Seção 6): soma das necessidades de um
     * ItemTakeOff nunca pode superar `ItemTakeOff.quantidade`. Lido
     * DEPOIS do lock em ItemTakeOff.
     */
    private function validarSaldoTakeOff(ItemTakeOff $itemTakeOff, float $quantidadeDesejada, ?string $excluirNecessidadeId): void
    {
        $saldo = ConciliacaoNecessidadeAtividade::saldoADistribuir($itemTakeOff, $excluirNecessidadeId);

        if ($quantidadeDesejada > $saldo + 0.0005) {
            throw new SaldoNecessidadeInsuficienteException(
                "Quantidade solicitada ({$quantidadeDesejada}) excede o saldo ainda não distribuído ({$saldo}) deste item de TakeOff.",
                $saldo,
                $quantidadeDesejada
            );
        }
    }

    /**
     * Não permitir reduzir a necessidade abaixo do que já está
     * fisicamente reservado (ReservaEstoque Ativa rotulada com ela) —
     * liberar a reserva primeiro (mesmo espírito de
     * `AtualizarDestinacaoPlanejada::garantirNaoAbaixoDoReservado()`).
     */
    private function garantirNaoAbaixoDoReservado(AtividadeNecessidadeMaterial $necessidade, float $novaQuantidade): void
    {
        $reservado = $necessidade->quantidadeReservadaAtiva();

        if ($novaQuantidade < $reservado - 0.0005) {
            throw new NecessidadeMaterialAtividadeInvalidaException(
                "Esta necessidade já tem {$reservado} reservado (Ativa) — não é possível reduzir abaixo desse valor sem antes liberar a(s) reserva(s)."
            );
        }
    }

    private function garantirSemReservaVinculada(AtividadeNecessidadeMaterial $necessidade): void
    {
        if (ReservaEstoque::where('necessidade_atividade_id', $necessidade->id)->exists()) {
            throw new NecessidadeMaterialAtividadeInvalidaException(
                'Esta necessidade possui Reserva(s) de Estoque vinculada(s) (ativa ou já liberada) e não pode ser removida.'
            );
        }
    }
}
