<?php

namespace App\Actions\Estoque;

use App\Exceptions\AssociacaoMaterialInvalidaException;
use App\Exceptions\ItemTakeOffMaterialImutavelException;
use App\Models\ItemTakeOff;
use App\Models\Material;
use App\Models\Work;
use App\Support\Estoque\PoliticaAssociacaoMaterial;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.1.CORREÇÃO — API OFICIAL e única de associação/troca
 * de Material num ItemTakeOff (fecha o Achado C1 da auditoria adversarial
 * 20.1 — até aquela correção, não existia NENHUM writer real de
 * `material_id` fora de código de teste). `App\Models\ItemTakeOff.material_id`
 * NUNCA deve ser escrito diretamente pela UI — sempre por esta Action.
 *
 * Reaproveita App\Support\Estoque\PoliticaAssociacaoMaterial como ÚNICA
 * fonte de verdade da regra "pode alterar?" — a mesma regra que
 * App\Observers\ItemTakeOffObserver usa como barreira de defesa, nunca
 * duas lógicas divergentes.
 *
 * **Cross-tenant e Material inativo são validados aqui, independente de
 * UI/permissão** (Seção 7 do pedido de correção 20.1) — mesmo com um
 * Material de outro tenant de alguma forma chegando como objeto (nunca
 * deveria, já que Material::find() já filtraria pelo scope global, mas a
 * comparação explícita é defesa em profundidade).
 *
 * **Ciclo 20, Etapa 20.9.CORREÇÃO — Achado C1 da Auditoria Integrada
 * 20.9**: `Material` é catálogo TENANT-WIDE, sem `obra_id` (decisão de
 * produto, 20.1) — por isso esta Action nunca teve como validar
 * "compatibilidade de obra" sem receber explicitamente qual obra é o
 * contexto da operação. `$obraAtual` (1º parâmetro, OBRIGATÓRIO — nunca
 * opcional, é a própria garantia de segurança) é essa referência. A
 * validação usa a MESMA cadeia autoritativa já estabelecida em 3+ pontos
 * do projeto pra derivar a obra de um `ItemTakeOff`
 * (`lista.revisao.documento.obra_id` — idêntica à de
 * `App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento::
 * garantirMesmaObra()`) — nunca uma segunda regra, nunca uma coluna
 * `obra_id` redundante em `ItemTakeOff`. Fecha os 2 vetores encontrados
 * pela auditoria: (1) leitura cross-obra via `recebimentoId` manipulado
 * em `abrirModalAssociarMaterial()` (fechada na própria UI, mas essa
 * checagem sozinha não bastaria); (2) escrita cross-obra via manipulação
 * direta da propriedade pública `itemTakeOffAssociarId` chamando
 * `confirmarAssociarMaterial()` sem nunca passar por
 * `abrirModalAssociarMaterial()` — só a validação AQUI, dentro da
 * Action, fecha esse segundo vetor de verdade.
 */
class AssociarMaterialAoItemTakeOff
{
    public function execute(Work $obraAtual, ItemTakeOff $item, Material $material): ItemTakeOff
    {
        return DB::transaction(function () use ($obraAtual, $item, $material) {
            $itemTravado = ItemTakeOff::whereKey($item->id)->lockForUpdate()->firstOrFail();

            $itemTravado->loadMissing('lista.revisao.documento');
            $obraDoItem = $itemTravado->lista?->revisao?->documento?->obra_id;

            if ($obraDoItem !== $obraAtual->id) {
                throw new AssociacaoMaterialInvalidaException(
                    'Este item do Take Off não pertence à obra atual e não pode ser associado a partir daqui.'
                );
            }

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
