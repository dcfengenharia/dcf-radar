<?php

namespace App\Support\Estoque;

use App\Enums\StatusReservaEstoque;
use App\Enums\TipoMovimentacaoEstoque;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\ReservaEstoque;
use App\Models\UnidadeEstoque;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.2 — fonte canônica de saldo reservado/disponível
 * (Seção 19 da investigação): saldo reservado é SEMPRE derivado
 * (SUM de ReservaEstoque com status Ativa), nunca persistido. Disponível
 * = físico (App\Support\Estoque\SaldoEstoque, já existente/intocado) −
 * reservado. NENHUM dos 3 saldos (físico/reservado/disponível) é
 * gravado em coluna — sempre calculado na leitura, mesmo espírito de
 * SaldoEstoque/ConciliacaoDestinacao/ConciliacaoAlocacao.
 *
 * Ciclo 20, Etapa 20.3 — fecha os itens 18/20 da investigação: quanto de
 * uma Reserva já foi CONSUMIDO por Saídas físicas (`MovimentacaoEstoque`
 * com `reserva_estoque_id` apontando pra ela) é sempre DERIVADO aqui,
 * NUNCA persistido em `reservas_estoque.quantidade_consumida` — o status
 * da Reserva continua só `Ativa`/`Liberada` (administrativo), sem
 * "ParcialmenteConsumida"/"Consumida" antecipados (decisão do usuário,
 * mesma Seção 19/23). `consumidoPorSaidas()` soma `quantidade` bruta
 * (sempre positiva) das Saídas vinculadas — nunca precisa de
 * `fatorSaldo()` aqui, porque só existe UM tipo de MovimentacaoEstoque
 * que aponta pra `reserva_estoque_id` (Saída) — diferente de
 * `SaldoEstoque`, que agrega Entrada+Saída juntas no MESMO recurso
 * físico e por isso precisa do sinal.
 */
class SaldoReserva
{
    public static function porMaterialLocal(Material $material, LocalEstoque $local): float
    {
        $total = ReservaEstoque::where('material_id', $material->id)
            ->where('local_estoque_id', $local->id)
            ->where('status', StatusReservaEstoque::Ativa->value)
            ->sum('quantidade');

        return round((float) $total, 3);
    }

    public static function porUnidade(UnidadeEstoque $unidade): float
    {
        $total = ReservaEstoque::where('unidade_estoque_id', $unidade->id)
            ->where('status', StatusReservaEstoque::Ativa->value)
            ->sum('quantidade');

        return round((float) $total, 3);
    }

    /**
     * Ciclo 20, Etapa 20.6.CORREÇÃO — reservado ATIVO de UMA Unidade
     * (lote/serial) escopado a UM Local específico (nunca o global de
     * `porUnidade()`). Necessário porque uma Reserva é imutavelmente
     * amarrada a um `local_estoque_id` desde a criação (20.2.CORREÇÃO,
     * Achado C2) — uma reserva registrada no Local B nunca deveria
     * "contar" contra o saldo não-reservado de uma operação acontecendo
     * no Local A, mesmo que as duas apontem pra mesma `UnidadeEstoque`
     * (ex.: bobina fracionada entre dois Locais, Ciclo 20.5.CORREÇÃO).
     */
    public static function porUnidadeLocal(UnidadeEstoque $unidade, LocalEstoque $local): float
    {
        $total = ReservaEstoque::where('unidade_estoque_id', $unidade->id)
            ->where('local_estoque_id', $local->id)
            ->where('status', StatusReservaEstoque::Ativa->value)
            ->sum('quantidade');

        return round((float) $total, 3);
    }

    /**
     * Ciclo 20, Etapa 20.6.CORREÇÃO — saldo NÃO reservado de uma Unidade
     * escopado a UM Local (físico neste Local menos reservado neste
     * mesmo Local) — nunca o cálculo global de `disponivelPorUnidade()`.
     * Fonte canônica pra qualquer prévia/advertência de UI cujo contexto
     * já é um Local específico (ex.: modal de Saída) — mesma regra
     * canônica de `SaldoEstoque::porUnidadeLocal()`, nunca duplicada.
     */
    public static function disponivelPorUnidadeLocal(UnidadeEstoque $unidade, LocalEstoque $local): float
    {
        return round(SaldoEstoque::porUnidadeLocal($unidade, $local) - self::porUnidadeLocal($unidade, $local), 3);
    }

    public static function disponivelPorMaterialLocal(Material $material, LocalEstoque $local): float
    {
        return round(SaldoEstoque::porMaterialLocal($material, $local) - self::porMaterialLocal($material, $local), 3);
    }

    public static function disponivelPorUnidade(UnidadeEstoque $unidade): float
    {
        return round(SaldoEstoque::porUnidade($unidade) - self::porUnidade($unidade), 3);
    }

    /**
     * Reservado consolidado de VÁRIOS materiais numa única query
     * (listagem — nunca 1 SUM por linha), escopado por Local.
     *
     * @param  array<int, string>  $materialIds
     * @return Collection<string, float> chave = material_id
     */
    public static function porMateriaisNoLocal(array $materialIds, LocalEstoque $local): Collection
    {
        if (empty($materialIds)) {
            return collect();
        }

        return ReservaEstoque::query()
            ->whereIn('material_id', $materialIds)
            ->where('local_estoque_id', $local->id)
            ->where('status', StatusReservaEstoque::Ativa->value)
            ->groupBy('material_id')
            ->selectRaw('material_id, SUM(quantidade) as total')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));
    }

    /**
     * Ciclo 21, Etapa 21.1 — reservado ATIVO consolidado de VÁRIOS
     * materiais numa única query, escopado por OBRA (nunca por Local) —
     * mesmo motivo de `SaldoEstoque::porMateriaisNaObra()`: uma camada
     * gerencial que responde "por obra" nunca pode agregar reserva de
     * outra obra do mesmo tenant. `ReservaEstoque.obra_id` já é
     * denormalizado desde 20.2 — este método só filtra por ele.
     *
     * @param  array<int, string>  $materialIds
     * @return Collection<string, float> chave = material_id
     */
    public static function porMateriaisNaObra(array $materialIds, string $obraId): Collection
    {
        if (empty($materialIds)) {
            return collect();
        }

        return ReservaEstoque::query()
            ->whereIn('material_id', $materialIds)
            ->where('obra_id', $obraId)
            ->where('status', StatusReservaEstoque::Ativa->value)
            ->groupBy('material_id')
            ->selectRaw('material_id, SUM(quantidade) as total')
            ->pluck('total', 'material_id')
            ->map(fn ($v) => round((float) $v, 3));
    }

    /**
     * Ciclo 21, Etapa 21.1 — reservado ATIVO agregado por PAR (Pacote,
     * Material) — necessário pra `CoberturaMaterialAtividadeQuery`
     * saber quanto do estoque já está comprometido ESPECIFICAMENTE com
     * um Pacote (não com "algum lugar da obra"), respeitando reservas
     * genéricas (sem `destinacao_planejada_material_id`, permitidas
     * desde 20.2.CORREÇÃO) — por isso agrega direto por
     * `item_suprimento_id`, nunca via `porDestinacoes()` (que exigiria
     * toda reserva ter uma Destinação, o que nunca foi verdade).
     *
     * @param  Collection<int, array{item_suprimento_id: string, material_id: string}>  $pares
     * @return Collection<string, float> chave = "{pacoteId}|{materialId}"
     */
    public static function porPacotesEMateriais(Collection $pares): Collection
    {
        if ($pares->isEmpty()) {
            return collect();
        }

        $pacoteIds = $pares->pluck('item_suprimento_id')->unique()->values()->all();
        $materialIds = $pares->pluck('material_id')->unique()->values()->all();

        return ReservaEstoque::query()
            ->whereIn('item_suprimento_id', $pacoteIds)
            ->whereIn('material_id', $materialIds)
            ->where('status', StatusReservaEstoque::Ativa->value)
            ->groupBy('item_suprimento_id', 'material_id')
            ->selectRaw('item_suprimento_id, material_id, SUM(quantidade) as total')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->item_suprimento_id}|{$row->material_id}" => round((float) $row->total, 3)]);
    }

    /**
     * Reservado ATIVO consolidado de VÁRIAS DestinacaoPlanejadaMaterial
     * numa única query — usado pela UI (listagem de Destinações, nunca 1
     * SUM por linha) e pelo guard de redução em
     * App\Actions\Estoque\AtualizarDestinacaoPlanejada (que, por operar
     * sobre 1 linha por vez, continua usando
     * DestinacaoPlanejadaMaterial::quantidadeReservadaAtiva() — este
     * método aqui é só a versão em lote pra telas).
     *
     * @param  array<int, string>  $destinacaoIds
     * @return Collection<string, float> chave = destinacao_planejada_material_id
     */
    public static function porDestinacoes(array $destinacaoIds): Collection
    {
        if (empty($destinacaoIds)) {
            return collect();
        }

        return ReservaEstoque::query()
            ->whereIn('destinacao_planejada_material_id', $destinacaoIds)
            ->where('status', StatusReservaEstoque::Ativa->value)
            ->groupBy('destinacao_planejada_material_id')
            ->selectRaw('destinacao_planejada_material_id, SUM(quantidade) as total')
            ->pluck('total', 'destinacao_planejada_material_id')
            ->map(fn ($v) => round((float) $v, 3));
    }

    /**
     * Ciclo 20.3 — quanto de UMA Reserva já foi consumido por Saídas
     * físicas vinculadas (`movimentacoes_estoque.reserva_estoque_id`).
     * Nunca persistido — sempre um SUM sob demanda.
     */
    public static function consumidoPorSaidas(ReservaEstoque $reserva): float
    {
        $total = MovimentacaoEstoque::where('reserva_estoque_id', $reserva->id)
            ->where('tipo', TipoMovimentacaoEstoque::Saida->value)
            ->sum('quantidade');

        return round((float) $total, 3);
    }

    /**
     * Ciclo 20.3 — saldo AINDA NÃO consumido de uma Reserva (item 20 da
     * investigação: "Reserva totalmente consumida" = 0 aqui — nova Saída
     * vinculada a ela é bloqueada por
     * App\Actions\Estoque\RegistrarSaidaEstoque, nunca por delete/rewrite
     * da Reserva em si).
     */
    public static function saldoPendenteConsumo(ReservaEstoque $reserva): float
    {
        return round((float) $reserva->quantidade - self::consumidoPorSaidas($reserva), 3);
    }

    /**
     * Consumido consolidado de VÁRIAS Reservas numa única query (listagem
     * — nunca 1 SUM por linha, Seção 50 da investigação de
     * performance).
     *
     * @param  array<int, string>  $reservaIds
     * @return Collection<string, float> chave = reserva_estoque_id
     */
    public static function consumidoPorReservas(array $reservaIds): Collection
    {
        if (empty($reservaIds)) {
            return collect();
        }

        return MovimentacaoEstoque::query()
            ->whereIn('reserva_estoque_id', $reservaIds)
            ->where('tipo', TipoMovimentacaoEstoque::Saida->value)
            ->groupBy('reserva_estoque_id')
            ->selectRaw('reserva_estoque_id, SUM(quantidade) as total')
            ->pluck('total', 'reserva_estoque_id')
            ->map(fn ($v) => round((float) $v, 3));
    }
}
