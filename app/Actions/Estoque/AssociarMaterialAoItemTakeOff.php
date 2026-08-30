<?php

namespace App\Actions\Estoque;

use App\Exceptions\AssociacaoMaterialInvalidaException;
use App\Exceptions\ItemTakeOffMaterialImutavelException;
use App\Models\ItemTakeOff;
use App\Models\Material;
use App\Support\Estoque\PoliticaAssociacaoMaterial;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.1.CORREÇÃO — API OFICIAL e única de associação/troca
 * de Material num ItemTakeOff (fecha o Achado C1 da auditoria adversarial
 * — até esta correção, não existia NENHUM writer real de `material_id`
 * fora de código de teste). `App\Models\ItemTakeOff.material_id` NUNCA
 * deve ser escrito diretamente pela UI — sempre por esta Action.
 *
 * Reaproveita App\Support\Estoque\PoliticaAssociacaoMaterial como ÚNICA
 * fonte de verdade da regra "pode alterar?" — a mesma regra que
 * App\Observers\ItemTakeOffObserver usa como barreira de defesa, nunca
 * duas lógicas divergentes.
 *
 * **Cross-tenant e Material inativo são validados aqui, independente de
 * UI/permissão** (Seção 7 do pedido de correção) — mesmo com um Material
 * de outro tenant de alguma forma chegando como objeto (nunca deveria,
 * já que Material::find() já filtraria pelo scope global, mas a
 * comparação explícita é defesa em profundidade).
 */
class AssociarMaterialAoItemTakeOff
{
    public function execute(ItemTakeOff $item, Material $material): ItemTakeOff
    {
        return DB::transaction(function () use ($item, $material) {
            $itemTravado = ItemTakeOff::whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($material->tenant_id !== $itemTravado->tenant_id) {
                throw new AssociacaoMaterialInvalidaException(
                    'Este Material pertence a outro tenant e não pode ser associado.'
                );
            }

            if (! $material->ativo) {
                throw new AssociacaoMaterialInvalidaException(
                    'Este Material está inativo e não pode ser associado. Reative-o primeiro, se necessário.'
                );
            }

            if (! PoliticaAssociacaoMaterial::podeAlterarMaterial($itemTravado)) {
                throw new ItemTakeOffMaterialImutavelException(
                    'Este item já possui um Pedido de Compra emitido, uma entrada em estoque, ou já participa de um Pacote com Destinação Planejada para este Material — a associação de Material não pode mais ser alterada.'
                );
            }

            $itemTravado->material_id = $material->id;
            $itemTravado->save();

            return $itemTravado->fresh();
        });
    }
}
