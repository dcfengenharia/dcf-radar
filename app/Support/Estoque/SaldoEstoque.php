<?php

namespace App\Support\Estoque;

use App\Enums\TipoMovimentacaoEstoque;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\RecebimentoPedido;
use App\Models\UnidadeEstoque;
use App\Models\MovimentacaoEstoque;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.1 — saldo é sempre DERIVADO de MovimentacaoEstoque,
 * nunca persistido redundante (mesma filosofia de App\Support\Suprimentos\
 * ConciliacaoRecebimento/ConciliacaoAlocacao/ConciliacaoTakeOff, Ciclo
 * 19). Toda agregação em lote usa 1 query GROUP BY, nunca 1 SUM por linha
 * em loop.
 *
 * Ciclo 20, Etapa 20.1.CORREÇÃO — fecha o Achado B2 da auditoria
 * adversarial: TODOS os métodos abaixo agregam via
 * `App\Enums\TipoMovimentacaoEstoque::fatorSaldo()`, nunca um
 * `SUM(quantidade)` cru — mesmo hoje só existindo o tipo Entrada
 * (fator +1). Isso é feito 100% em SQL (`CASE tipo ...`), nunca
 * carregando movimentações em PHP pra somar (performance — Seção 29 do
 * pedido de correção). Quando uma fase futura adicionar Saída/Ajuste/
 * Estorno, só `TipoMovimentacaoEstoque::fatorSaldo()` precisa mudar —
 * nenhum método aqui é tocado. `quantidade` em si permanece sempre
 * positiva (regra de `RegistrarEntradaEstoque`, nunca alterada aqui) —
 * é o `CASE` que aplica o sinal contábil, nunca o valor armazenado.
 */
class SaldoEstoque
{
    /**
     * Expressão SQL central de sinal — única fonte de verdade de "como
     * cada tipo de movimentação afeta o saldo". Constrói o CASE a partir
     * do próprio enum (nunca hardcoded aqui), então um tipo novo no
     * futuro só exige tocar `TipoMovimentacaoEstoque::fatorSaldo()`.
     */
    private static function expressaoSaldoSql(): string
    {
        $casos = array_map(
            fn (TipoMovimentacaoEstoque $tipo) => "WHEN '{$tipo->value}' THEN quantidade * {$tipo->fatorSaldo()}",
            TipoMovimentacaoEstoque::cases()
        );

        return 'COALESCE(SUM(CASE tipo ' . implode(' ', $casos) . ' ELSE 0 END), 0)';
    }

    public static function porUnidade(UnidadeEstoque $unidade): float
    {
        $total = MovimentacaoEstoque::where('unidade_estoque_id', $unidade->id)
            ->selectRaw(self::expressaoSaldoSql() . ' as total')
            ->value('total');

        return round((float) $total, 3);
    }

    /**
     * Ciclo 20, Etapa 20.5.CORREÇÃO — fecha o Achado C1 da auditoria
     * adversarial da 20.5: `UnidadeEstoque.local_estoque_id` deixou de
     * ser a fonte de verdade de "onde esta unidade está agora" — vira só
     * "local de criação/origem", imutável e informativo (uma remessa
     * parcial de bobina/lote pode deixar a MESMA unidade com saldo em
     * MAIS DE UM Local ao mesmo tempo, ex.: 700m na obra + 300m num
     * Terceiro). Esta é a fonte canônica pra responder "quanto desta
     * unidade está fisicamente NESTE local", sempre via ledger — nunca
     * via comparação de `local_estoque_id`.
     */
    public static function porUnidadeLocal(UnidadeEstoque $unidade, LocalEstoque $local): float
    {
        $total = MovimentacaoEstoque::where('unidade_estoque_id', $unidade->id)
            ->where('local_estoque_id', $local->id)
            ->selectRaw(self::expressaoSaldoSql() . ' as total')
            ->value('total');

        return round((float) $total, 3);
    }

    /**
     * Ciclo 20, Etapa 20.5.CORREÇÃO — unidades (lote/serial) de um
     * Material com QUALQUER saldo físico > 0 NUM Local específico —
     * substitui `UnidadeEstoque::where('local_estoque_id', ...)` (que
     * assumia localização única) nas telas de seleção de unidade
     * (Reserva/Saída/Remessa). 2 queries totais, nunca N+1: 1 GROUP BY
     * pra resolver quais unidades têm presença física neste Local + 1
     * pra buscar os models.
     *
     * @return Collection<int, UnidadeEstoque>
     */
    public static function unidadesComPresencaNoLocal(Material $material, LocalEstoque $local): Collection
    {
        $saldosPorUnidade = MovimentacaoEstoque::query()
            ->where('material_id', $material->id)
            ->where('local_estoque_id', $local->id)
            ->whereNotNull('unidade_estoque_id')
            ->groupBy('unidade_estoque_id')
            ->selectRaw('unidade_estoque_id, ' . self::expressaoSaldoSql() . ' as total')
            ->pluck('total', 'unidade_estoque_id')
            ->filter(fn ($v) => (float) $v > 0.0005);

        if ($saldosPorUnidade->isEmpty()) {
            return collect();
        }

        return UnidadeEstoque::whereIn('id', $saldosPorUnidade->keys())->get();
    }

    public static function porMaterialLocal(Material $material, LocalEstoque $local): float
    {
        $total = MovimentacaoEstoque::where('material_id', $material->id)
            ->where('local_estoque_id', $local->id)
            ->selectRaw(self::expressaoSaldoSql() . ' as total')
            ->value('total');

        return round((float) $total, 3);
    }

    public static function porMaterial(Material $material): float
    {
        $total = MovimentacaoEstoque::where('material_id', $material->id)
            ->selectRaw(self::expressaoSaldoSql() . ' as total')
            ->value('total');

        return round((float) $total, 3);
    }

    /**
     * Saldo consolidado de VÁRIOS materiais numa única query (listagem —
     * nunca 1 SUM por linha da tabela de Materiais).
     *
     * @param  array<int, string>  $materialIds
     * @return Collection<string, float> chave = material_id
     */
    public static function porMateriais(array $materialIds): Collection
    {
        if (empty($materialIds)) {
            return collect();
        }

        return MovimentacaoEstoque::query()
            ->whereIn('material_id', $materialIds)
            ->groupBy('material_id')
            ->selectRaw('material_id, ' . self::expressaoSaldoSql() . ' as total')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));
    }

    /**
     * Ciclo 20, Etapa 20.4 — saldo físico de VÁRIOS materiais numa única
     * query, escopado por Local (usado por
     * App\Support\Estoque\CoberturaReservas::porPares(), que agrupa por
     * Local antes de chamar — mesmo padrão de
     * SaldoReserva::porMateriaisNoLocal(), já existente).
     *
     * @param  array<int, string>  $materialIds
     * @return Collection<string, float> chave = material_id
     */
    public static function porMateriaisNoLocal(array $materialIds, LocalEstoque $local): Collection
    {
        if (empty($materialIds)) {
            return collect();
        }

        return MovimentacaoEstoque::query()
            ->whereIn('material_id', $materialIds)
            ->where('local_estoque_id', $local->id)
            ->groupBy('material_id')
            ->selectRaw('material_id, ' . self::expressaoSaldoSql() . ' as total')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));
    }

    /**
     * Saldo já incorporado ao estoque a partir de UM RecebimentoPedido —
     * usado pra derivar o saldo AINDA disponível pra entrada (over-entrada,
     * Seção 15/17 da investigação original). Usa a mesma expressão de
     * sinal central por consistência (hoje só Entrada existe nesta
     * dimensão, mas nunca um SUM cru separado).
     */
    public static function incorporadoDeRecebimento(RecebimentoPedido $recebimento): float
    {
        $total = MovimentacaoEstoque::where('recebimento_pedido_id', $recebimento->id)
            ->selectRaw(self::expressaoSaldoSql() . ' as total')
            ->value('total');

        return round((float) $total, 3);
    }

    public static function pendenteDeIncorporacao(RecebimentoPedido $recebimento): float
    {
        return round((float) $recebimento->quantidade_recebida - self::incorporadoDeRecebimento($recebimento), 3);
    }

    /**
     * Ciclo 20, Etapa 20.3.CORREÇÃO — versão em LOTE de
     * incorporadoDeRecebimento(), pra fechar o Achado B de N+1 real em
     * ⚡estoque.blade.php::recebimentosPendentes(). `incorporadoDeRecebimento()`
     * acima nunca é alterado (mesma semântica, mesmo cálculo) — este
     * método só evita 1 query por linha numa listagem.
     *
     * @param  array<int, string>  $recebimentoIds
     * @return Collection<string, float> chave = recebimento_pedido_id
     */
    public static function incorporadoDeRecebimentos(array $recebimentoIds): Collection
    {
        if (empty($recebimentoIds)) {
            return collect();
        }

        return MovimentacaoEstoque::query()
            ->whereIn('recebimento_pedido_id', $recebimentoIds)
            ->groupBy('recebimento_pedido_id')
            ->selectRaw('recebimento_pedido_id, ' . self::expressaoSaldoSql() . ' as total')
            ->pluck('total', 'recebimento_pedido_id')
            ->map(fn ($v) => round((float) $v, 3));
    }

    /**
     * Ciclo 20, Etapa 20.7 — TODAS as posições (Material, ou
     * Material+UnidadeEstoque) com saldo físico > 0 NUM Local especifico
     * — usado por App\Actions\Estoque\IniciarInventarioEstoque pra
     * popular o snapshot em LOTE (1 query TOTAL, nunca 1 por Material/
     * Unidade em loop — Seção 25 do pedido, inventário de Local pode ter
     * centenas/milhares de itens). GROUP BY (material_id, unidade_estoque_id)
     * cobre Quantitativo (unidade_estoque_id sempre NULL no grupo) e Lote/
     * Serial (unidade_estoque_id preenchido) numa única passada.
     *
     * @return Collection<int, array{material_id: string, unidade_estoque_id: ?string, saldo: float}>
     */
    public static function posicoesNoLocal(LocalEstoque $local): Collection
    {
        return MovimentacaoEstoque::query()
            ->where('local_estoque_id', $local->id)
            ->groupBy('material_id', 'unidade_estoque_id')
            ->selectRaw('material_id, unidade_estoque_id, ' . self::expressaoSaldoSql() . ' as total')
            ->get()
            ->map(fn ($row) => [
                'material_id' => $row->material_id,
                'unidade_estoque_id' => $row->unidade_estoque_id,
                'saldo' => round((float) $row->total, 3),
            ])
            ->filter(fn (array $posicao) => $posicao['saldo'] > 0.0005)
            ->values();
    }
}
