<?php

namespace App\Support\Estoque;

use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\UnidadeEstoque;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.4 — cobertura de Reservas contra o saldo FÍSICO,
 * sempre respeitando a mesma granularidade física da Reserva (Seção
 * 27/28 do pedido — Material+Local pra Reserva Quantitativa, Unidade
 * específica pra Reserva Lote/Serial; nunca comparar reservas
 * incompatíveis fisicamente misturando as duas).
 *
 * **Déficit agregado, nunca atribuído a uma Reserva/Frente específica**
 * (decisão do usuário, Seção 25/26 — Opção F): `deficit = max(0,
 * reservado_ativo - físico)`. Quando o físico não cobre tudo que está
 * reservado, o sistema sabe COM CERTEZA o tamanho do problema, mas
 * NUNCA escolhe sozinho qual Reserva/Frente foi prejudicada — isso só é
 * calculável com certeza via `App\Support\Estoque\DesviosAplicacao`,
 * quando existe rastreabilidade DIRETA (Saída vinculada à própria
 * Reserva).
 *
 * **Recomposição/reposição necessária = o próprio déficit** (Seção 29)
 * — não é uma entidade nova, é o MESMO número com outro rótulo de
 * exibição. Quando uma nova Entrada física aumenta o saldo, o déficit
 * cai automaticamente na próxima leitura (100% derivado — Seção 30,
 * nunca uma Movimentação fake, nunca altera Reserva/Destinação).
 */
class CoberturaReservas
{
    public static function porMaterialLocal(Material $material, LocalEstoque $local): array
    {
        $fisico = SaldoEstoque::porMaterialLocal($material, $local);
        $reservado = SaldoReserva::porMaterialLocal($material, $local);
        $deficit = round(max(0, $reservado - $fisico), 3);

        return [
            'fisico' => $fisico,
            'reservado_ativo' => $reservado,
            'deficit' => $deficit,
            'reposicao_necessaria' => $deficit,
        ];
    }

    public static function porUnidade(UnidadeEstoque $unidade): array
    {
        $fisico = SaldoEstoque::porUnidade($unidade);
        $reservado = SaldoReserva::porUnidade($unidade);
        $deficit = round(max(0, $reservado - $fisico), 3);

        return [
            'fisico' => $fisico,
            'reservado_ativo' => $reservado,
            'deficit' => $deficit,
            'reposicao_necessaria' => $deficit,
        ];
    }

    /**
     * Versão em lote pra listagem/dashboard — recebe os pares
     * Material+Local já resolvidos pelo chamador (nunca descobre pares
     * sozinha). Agrupa por Local (poucos Locais por obra, tipicamente)
     * e resolve físico/reservado em 1 query cada POR LOCAL via
     * `SaldoEstoque`/`SaldoReserva::porMateriaisNoLocal()` já existentes
     * — nunca 1 query por par individual.
     *
     * @param  Collection<int, array{material: Material, local: LocalEstoque}>  $pares
     * @return Collection<int, array{material_id: string, local_estoque_id: string, fisico: float, reservado_ativo: float, deficit: float}>
     */
    public static function porPares(Collection $pares): Collection
    {
        if ($pares->isEmpty()) {
            return collect();
        }

        return $pares
            ->groupBy(fn (array $par) => $par['local']->id)
            ->flatMap(function (Collection $grupo) {
                /** @var LocalEstoque $local */
                $local = $grupo->first()['local'];
                $materialIds = $grupo->pluck('material.id')->unique()->values()->all();

                $fisicoPorMaterial = SaldoEstoque::porMateriaisNoLocal($materialIds, $local);
                $reservadoPorMaterial = SaldoReserva::porMateriaisNoLocal($materialIds, $local);

                return $grupo->map(function (array $par) use ($local, $fisicoPorMaterial, $reservadoPorMaterial) {
                    $materialId = $par['material']->id;
                    $fisico = (float) ($fisicoPorMaterial[$materialId] ?? 0.0);
                    $reservado = (float) ($reservadoPorMaterial[$materialId] ?? 0.0);
                    $deficit = round(max(0, $reservado - $fisico), 3);

                    return [
                        'material_id' => $materialId,
                        'local_estoque_id' => $local->id,
                        'fisico' => $fisico,
                        'reservado_ativo' => $reservado,
                        'deficit' => $deficit,
                    ];
                });
            })
            ->values();
    }
}
