<?php

namespace App\Actions\Estoque;

use App\Enums\DirecaoRemessaIndustrializacao;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\ConsumoIndustrializacaoInvalidaException;
use App\Models\LocalEstoque;
use App\Models\MovimentacaoEstoque;
use App\Models\OrdemIndustrializacao;
use App\Models\ProdutoIndustrializado;
use App\Models\ProdutoIndustrializadoConsumo;
use App\Models\RemessaIndustrializacao;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.5 — genealogia quantitativa N:N (decisão do
 * usuário, confirmada): registra QUANTO de UMA `RemessaIndustrializacao`
 * de direção Envio foi efetivamente consumido/transformado na
 * fabricação de UM `ProdutoIndustrializado`.
 *
 * **Reduz de verdade o saldo físico da matéria-prima em custódia do
 * terceiro** (Seção 13) — cria uma `MovimentacaoEstoque::Saida` LIVRE
 * (sem destino, mesmo idioma já usado pra "saída sem Reserva" na
 * obra) no Local Terceiro da Ordem, representando a transformação
 * física (a matéria-prima deixa de existir como tal). Nunca cria um
 * tipo novo de movimentação.
 *
 * Over-consumo bloqueado sempre: `SUM(quantidade_consumida)` de UMA
 * Remessa nunca pode ultrapassar sua própria `quantidade` — lock na
 * Remessa antes do SUM, mesmo total order de todo o domínio.
 */
class RegistrarConsumoIndustrializacao
{
    public function execute(
        ProdutoIndustrializado $produto,
        RemessaIndustrializacao $remessaEnvio,
        float $quantidadeConsumida,
        \DateTimeInterface $ocorridoEm,
        User $usuario,
        ?string $observacao = null,
    ): ProdutoIndustrializadoConsumo {
        return DB::transaction(function () use ($produto, $remessaEnvio, $quantidadeConsumida, $ocorridoEm, $usuario, $observacao) {
            $this->garantirQuantidadePositiva($quantidadeConsumida);

            $remessaTravada = RemessaIndustrializacao::whereKey($remessaEnvio->id)->lockForUpdate()->firstOrFail();

            if ($remessaTravada->direcao !== DirecaoRemessaIndustrializacao::Envio) {
                throw new ConsumoIndustrializacaoInvalidaException('Só é possível consumir matéria-prima de uma remessa de Envio — nunca de um Retorno de Sobra.');
            }

            if ($produto->ordem_industrializacao_id !== $remessaTravada->ordem_industrializacao_id) {
                throw new ConsumoIndustrializacaoInvalidaException('O Produto e a Remessa precisam pertencer à mesma Ordem de Industrialização.');
            }

            $ordem = OrdemIndustrializacao::findOrFail($remessaTravada->ordem_industrializacao_id);
            if (! $ordem->estaEmitida()) {
                throw new ConsumoIndustrializacaoInvalidaException('Só é possível registrar consumo em uma Ordem Emitida.');
            }

            $dataConsumo = Carbon::parse($ocorridoEm)->startOfDay();
            $this->garantirDataNaoFutura($dataConsumo);

            $jaConsumido = (float) ProdutoIndustrializadoConsumo::where('remessa_industrializacao_id', $remessaTravada->id)->sum('quantidade_consumida');
            $pendente = round((float) $remessaTravada->quantidade - $jaConsumido, 3);

            if ($quantidadeConsumida > $pendente + 0.0005) {
                throw new ConsumoIndustrializacaoInvalidaException(
                    "Esta Remessa tem apenas {$pendente} pendente de consumo — não é possível consumir {$quantidadeConsumida}."
                );
            }

            $localTerceiro = LocalEstoque::findOrFail($ordem->local_terceiro_id);

            $movimentacaoConsumo = MovimentacaoEstoque::create([
                'obra_id' => $ordem->obra_id,
                'tipo' => TipoMovimentacaoEstoque::Saida,
                'material_id' => $remessaTravada->material_id,
                'local_estoque_id' => $localTerceiro->id,
                'unidade_estoque_id' => $remessaTravada->unidade_estoque_id,
                'quantidade' => $quantidadeConsumida,
                'ocorrido_em' => $dataConsumo,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);

            return ProdutoIndustrializadoConsumo::create([
                'obra_id' => $ordem->obra_id,
                'produto_industrializado_id' => $produto->id,
                'remessa_industrializacao_id' => $remessaTravada->id,
                'quantidade_consumida' => $quantidadeConsumida,
                'ocorrido_em' => $dataConsumo,
                'movimentacao_consumo_id' => $movimentacaoConsumo->id,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);
        });
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new ConsumoIndustrializacaoInvalidaException('A quantidade consumida precisa ser maior que zero.');
        }
    }

    private function garantirDataNaoFutura(Carbon $data): void
    {
        if ($data->gt(Carbon::today())) {
            throw new ConsumoIndustrializacaoInvalidaException('A data do consumo não pode estar no futuro.');
        }
    }
}
