<?php

namespace App\Support\Estoque;

use App\Models\AplicacaoMaterialEstoque;
use App\Models\MovimentacaoEstoque;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.4 — ÚNICA fonte de verdade de "quanto de uma Saída
 * já foi conciliado" e "esta Saída já está 100% conciliada". Nunca
 * persistido em nenhuma coluna (mesma filosofia 100% derivada de
 * `App\Support\Estoque\SaldoEstoque`/`SaldoReserva`/`ConciliacaoDestinacao`)
 * — reaproveitado pelas 3 Actions de Aplicação E por
 * `App\Observers\AplicacaoMaterialEstoqueObserver` (a MESMA regra,
 * nunca duplicada).
 *
 * "Fechada" (decisão do usuário, Seção 12): `SUM(aplicações) >=
 * quantidade` da Saída, com a mesma tolerância de ponto flutuante
 * (0.0005) já usada em todo o domínio de Estoque. Depois de fechada,
 * nenhuma Aplicação daquela Saída pode ser criada/editada/excluída —
 * nem mesmo pra reabrir uma conciliação incorreta (decisão explícita).
 */
class PoliticaConciliacaoAplicacao
{
    private const TOLERANCIA = 0.0005;

    public static function totalAplicado(MovimentacaoEstoque $saida, ?string $excluirAplicacaoId = null): float
    {
        $query = AplicacaoMaterialEstoque::where('movimentacao_estoque_id', $saida->id);

        if ($excluirAplicacaoId) {
            $query->where('id', '!=', $excluirAplicacaoId);
        }

        return round((float) $query->sum('quantidade'), 3);
    }

    public static function pendente(MovimentacaoEstoque $saida, ?string $excluirAplicacaoId = null): float
    {
        return round((float) $saida->quantidade - self::totalAplicado($saida, $excluirAplicacaoId), 3);
    }

    public static function saidaEstaFechada(MovimentacaoEstoque $saida, ?string $excluirAplicacaoId = null): bool
    {
        return self::totalAplicado($saida, $excluirAplicacaoId) >= (float) $saida->quantidade - self::TOLERANCIA;
    }

    /**
     * Versão em LOTE de `totalAplicado()`/`pendente()`, pra listagens
     * (UI de "Saídas com saldo pendente de conciliação") — nunca 1 SUM
     * por linha.
     *
     * @param  array<int, string>  $saidaIds
     * @return Collection<string, float> chave = movimentacao_estoque_id
     */
    public static function totalAplicadoEmLote(array $saidaIds): Collection
    {
        if (empty($saidaIds)) {
            return collect();
        }

        return AplicacaoMaterialEstoque::query()
            ->whereIn('movimentacao_estoque_id', $saidaIds)
            ->groupBy('movimentacao_estoque_id')
            ->selectRaw('movimentacao_estoque_id, SUM(quantidade) as total')
            ->pluck('total', 'movimentacao_estoque_id')
            ->map(fn ($v) => round((float) $v, 3));
    }
}
