<?php

namespace App\Actions\Estoque;

use App\Exceptions\DestinacaoPlanejadaImutavelException;
use App\Exceptions\DestinacaoPlanejadaInvalidaException;
use App\Exceptions\SaldoDestinacaoInsuficienteException;
use App\Models\DestinacaoPlanejadaMaterial;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\Material;
use App\Models\ReservaEstoque;
use App\Models\User;
use App\Support\Estoque\ConciliacaoDestinacao;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.2 — único ponto de escrita de DestinacaoPlanejadaMaterial.
 * Mesmo padrão exato de App\Actions\Suprimentos\AlocarRequisicaoAoPacote
 * (Ciclo 19.3/19.4): guard de regra de negócio + lock na própria Action,
 * SEM Observer dedicado — DestinacaoPlanejadaMaterial não tem
 * SoftDeletes (mesmo estilo de RequisicaoPlanejamentoItem/
 * AlocacaoRequisicaoPacote), então `reservas_estoque.
 * destinacao_planejada_material_id` (restrictOnDelete) já bloqueia
 * fisicamente a exclusão referenciada — esta Action só converte essa
 * proteção numa mensagem didática ANTES de deixar a FK ser a defesa
 * final (mesmo espírito de garantirSemConsumoPorRc()).
 *
 * **Ordem de lock — extensão do total order já estabelecido no projeto**
 * (RequisicaoPlanejamentoItem -> ItemSuprimento -> AlocacaoRequisicaoPacote,
 * 19.3.CORREÇÃO/19.4.CORREÇÃO): `ItemSuprimento` (Pacote) é travado
 * SEMPRE PRIMEIRO aqui, na MESMA posição 2 da ordem já usada por
 * AlocarRequisicaoAoPacote — é o recurso comum que serializa "reduzir
 * uma AlocacaoRequisicaoPacote" (que também trava ItemSuprimento nessa
 * posição) contra "criar/aumentar uma Destinação" (que lê o total
 * formal derivado dessas mesmas alocações). Sem esse lock compartilhado,
 * as duas operações concorrentes poderiam cada uma decidir com base num
 * total formal que a outra já invalidou. A própria linha de Destinação
 * (quando já existe) é travada DEPOIS, posição 3 — mesmo papel de
 * AlocacaoRequisicaoPacote na cadeia original.
 */
class AtualizarDestinacaoPlanejada
{
    public function criar(ItemSuprimento $pacote, Material $material, FrenteTrabalho $frente, float $quantidade, ?User $usuario = null): DestinacaoPlanejadaMaterial
    {
        return DB::transaction(function () use ($pacote, $material, $frente, $quantidade, $usuario) {
            $pacoteTravado = ItemSuprimento::whereKey($pacote->id)->lockForUpdate()->firstOrFail();

            $this->garantirQuantidadePositiva($quantidade);
            $this->garantirMesmaObra($pacoteTravado, $frente);
            $this->garantirMaterialAtivo($material);
            $this->garantirFrenteDisponivel($frente);
            $this->validarSaldo($pacoteTravado->id, $material->id, $quantidade, excluirDestinacaoId: null);

            try {
                return DestinacaoPlanejadaMaterial::create([
                    'obra_id' => $pacoteTravado->obra_id,
                    'item_suprimento_id' => $pacoteTravado->id,
                    'material_id' => $material->id,
                    'frente_trabalho_id' => $frente->id,
                    'quantidade_planejada' => $quantidade,
                    'created_by_id' => $usuario?->id,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new DestinacaoPlanejadaInvalidaException(
                        'Já existe uma Destinação Planejada para este Material/Pacote nesta Frente — edite a existente em vez de criar uma nova.'
                    );
                }

                throw $e;
            }
        });
    }

    public function alterar(DestinacaoPlanejadaMaterial $destinacao, float $novaQuantidade): DestinacaoPlanejadaMaterial
    {
        return DB::transaction(function () use ($destinacao, $novaQuantidade) {
            ItemSuprimento::whereKey($destinacao->item_suprimento_id)->lockForUpdate()->firstOrFail();
            $destinacaoTravada = DestinacaoPlanejadaMaterial::whereKey($destinacao->id)->lockForUpdate()->firstOrFail();

            $this->garantirQuantidadePositiva($novaQuantidade);
            $this->validarSaldo($destinacaoTravada->item_suprimento_id, $destinacaoTravada->material_id, $novaQuantidade, excluirDestinacaoId: $destinacaoTravada->id);
            $this->garantirNaoAbaixoDoReservado($destinacaoTravada, $novaQuantidade);

            $destinacaoTravada->update(['quantidade_planejada' => $novaQuantidade]);

            return $destinacaoTravada->fresh();
        });
    }

    public function remover(DestinacaoPlanejadaMaterial $destinacao): void
    {
        DB::transaction(function () use ($destinacao) {
            ItemSuprimento::whereKey($destinacao->item_suprimento_id)->lockForUpdate()->firstOrFail();
            $destinacaoTravada = DestinacaoPlanejadaMaterial::whereKey($destinacao->id)->lockForUpdate()->firstOrFail();

            $this->garantirSemReservaVinculada($destinacaoTravada);

            $destinacaoTravada->delete();
        });
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new DestinacaoPlanejadaInvalidaException('A quantidade planejada precisa ser maior que zero.');
        }
    }

    private function garantirMesmaObra(ItemSuprimento $pacote, FrenteTrabalho $frente): void
    {
        if ($pacote->obra_id !== $frente->obra_id) {
            throw new DestinacaoPlanejadaInvalidaException('Esta Frente de Trabalho não pertence à mesma obra deste Pacote de Compra.');
        }
    }

    private function garantirMaterialAtivo(Material $material): void
    {
        if (! $material->ativo) {
            throw new DestinacaoPlanejadaInvalidaException('Este Material está inativo e não pode receber nova Destinação Planejada.');
        }
    }

    private function garantirFrenteDisponivel(FrenteTrabalho $frente): void
    {
        if ($frente->trashed()) {
            throw new DestinacaoPlanejadaInvalidaException('Esta Frente de Trabalho está arquivada e não pode receber nova Destinação Planejada.');
        }
    }

    /**
     * Over-destinação (Seção 7): soma das Destinações do par
     * Pacote+Material nunca pode superar a demanda formal (soma das
     * alocações). Lido DEPOIS do lock em ItemSuprimento (posição 2) —
     * livre de corrida contra AlocarRequisicaoAoPacote::alterarQuantidade/
     * remover(), que trava a MESMA linha antes de reduzir uma alocação.
     */
    private function validarSaldo(string $itemSuprimentoId, string $materialId, float $quantidadeDesejada, ?string $excluirDestinacaoId): void
    {
        $saldo = ConciliacaoDestinacao::saldoADestinar($itemSuprimentoId, $materialId, $excluirDestinacaoId);

        if ($quantidadeDesejada > $saldo + 0.0005) {
            throw new SaldoDestinacaoInsuficienteException(
                "Quantidade solicitada ({$quantidadeDesejada}) excede o saldo ainda não destinado ({$saldo}) deste Material neste Pacote.",
                $saldo,
                $quantidadeDesejada
            );
        }
    }

    /**
     * Seção 12: não permitir reduzir a Destinação abaixo do que já está
     * fisicamente reservado (ReservaEstoque Ativa) — liberar a reserva
     * primeiro.
     */
    private function garantirNaoAbaixoDoReservado(DestinacaoPlanejadaMaterial $destinacao, float $novaQuantidade): void
    {
        $reservado = $destinacao->quantidadeReservadaAtiva();

        if ($novaQuantidade < $reservado - 0.0005) {
            throw new DestinacaoPlanejadaImutavelException(
                "Esta Destinação já tem {$reservado} reservado (Ativa) — não é possível reduzir abaixo desse valor sem antes liberar a(s) reserva(s)."
            );
        }
    }

    /**
     * Seção 43: uma Destinação com QUALQUER ReservaEstoque vinculada
     * (Ativa ou Liberada — liberada continua sendo evidência histórica)
     * nunca pode ser excluída. A FK restrictOnDelete já garante isso
     * fisicamente; esta checagem só converte num erro didático antes.
     */
    private function garantirSemReservaVinculada(DestinacaoPlanejadaMaterial $destinacao): void
    {
        if (ReservaEstoque::where('destinacao_planejada_material_id', $destinacao->id)->exists()) {
            throw new DestinacaoPlanejadaImutavelException(
                'Esta Destinação Planejada possui Reserva(s) de Estoque vinculada(s) (ativa ou já liberada) e não pode ser excluída.'
            );
        }
    }
}
