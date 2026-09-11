<?php

namespace App\Actions\Estoque;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\StatusInventarioEstoque;
use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\InventarioEstoque;
use App\Models\InventarioItem;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\User;
use App\Support\Estoque\SaldoEstoque;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.7.CORREÇÃO — fecha a lacuna funcional real
 * confirmada em teste manual: um Material QUANTITATIVO cujo saldo
 * sistêmico no Local era zero no instante do snapshot (`IniciarInventarioEstoque`)
 * nunca tinha um `InventarioItem` correspondente, e `AdicionarItemInesperadoInventario`
 * (a única "porta de entrada" pra item fora do snapshot) é restrita a
 * Material Serializado por design (Seção 8 da 20.7) — sem nenhuma
 * maneira legítima de contar 40 UND de um Material quantitativo
 * fisicamente encontrado, sem inventar uma Entrada/Recebimento fictício.
 *
 * **Diferença deliberada em relação a `AdicionarItemInesperadoInventario`
 * (nunca hardcoda `quantidade_sistema_snapshot = 0`)**: um serial nunca
 * antes visto estruturalmente não pode ter saldo agregado conhecido (não
 * existe `UnidadeEstoque` pra ele), então `0` é sempre correto por
 * construção. Um Material Quantitativo, ao contrário, PODE ter saldo
 * genuíno se uma Entrada/Transferência legítima aconteceu depois do
 * `Iniciar Inventário` e antes deste registro (o inventário nunca trava
 * movimentação em paralelo, decisão já vigente desde a 20.7) — por isso
 * o snapshot aqui é sempre o saldo FRESCO no instante da criação deste
 * item (`SaldoEstoque::porMaterialLocal()`, sob lock do Local, mesmo
 * padrão já usado em `AprovarAjusteInventario`), nunca um valor
 * hardcoded. No cenário relatado (saldo sempre zero), isso produz
 * exatamente `snapshot=0` — mas nunca mascara uma divergência real caso
 * o saldo já não seja mais zero no momento em que o contador resolve o
 * código. Isso é literalmente o mesmo princípio já usado pra todo o
 * resto do domínio ("o snapshot é o que o sistema sabia no instante em
 * que ESTE item nasceu", nunca recalculado depois — aqui só generalizado
 * pro instante de criação ser posterior ao `Iniciar`, em vez de coincidir
 * com ele).
 *
 * **Nunca gera efeito colateral físico** — só cria o `InventarioItem`
 * (mesmo princípio já vigente: `MovimentacaoEstoque`/`SaldoEstoque` só
 * mudam na aprovação do Ajuste, `App\Actions\Estoque\AprovarAjusteInventario`,
 * intocada e já plenamente compatível com este caso — um item com
 * `unidade_estoque_id=null` e `serial_texto_inesperado=null` cai
 * naturalmente no ramo "Material Quantitativo" dela). A contagem em si
 * (`quantidade_contada`) continua sendo um passo SEPARADO, via o mesmo
 * `App\Actions\Estoque\RegistrarContagemInventario` já existente — esta
 * Action nunca bundla contagem, ao contrário de
 * `AdicionarItemInesperadoInventario` (cujo modal dedicado já pede
 * serial+quantidade juntos).
 *
 * **Duplicidade — proteção só na aplicação, mesma limitação estrutural
 * já documentada no projeto**: `inventario_itens` tem
 * `unique(inventario_estoque_id, material_id, unidade_estoque_id)`, mas
 * `unidade_estoque_id` é sempre `NULL` pra Quantitativo — o MySQL trata
 * cada `NULL` como distinto nesse índice, então a checagem de "já existe
 * uma posição pra este Material neste Inventário" é feita aqui, sob o
 * lock do `InventarioEstoque` (serializa tentativas concorrentes pro
 * MESMO inventário).
 *
 * **Material por Lote nunca é aceito por aqui** (Seção "Rastreabilidade"
 * do pedido) — precisa da identidade da Unidade (lote/bobina) específica,
 * resolvida via o fluxo normal de Entrada/Transferência; esta Action
 * rejeita explicitamente com mensagem distinta da rejeição de
 * Serializado, nunca a mesma mensagem genérica pros dois modos.
 */
class AdicionarMaterialQuantitativoInesperadoInventario
{
    public function execute(InventarioEstoque $inventario, Material $material, User $usuario): InventarioItem
    {
        return DB::transaction(function () use ($inventario, $material) {
            $inventarioTravado = InventarioEstoque::whereKey($inventario->id)->lockForUpdate()->firstOrFail();

            if (! in_array($inventarioTravado->status, [StatusInventarioEstoque::EmContagem, StatusInventarioEstoque::EmAnalise], true)) {
                throw new InventarioEstoqueInvalidoException('Este Inventário não está em contagem/análise — não é possível adicionar um Material inesperado.');
            }

            if ($material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Serializado) {
                throw new InventarioEstoqueInvalidoException(
                    'Este Material é Serializado — use "Registrar serial inesperado" para informar o serial físico encontrado.'
                );
            }

            if ($material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Lote) {
                throw new InventarioEstoqueInvalidoException(
                    'Este Material é rastreado por Lote/Unidade — identifique o lote/bobina específico (escaneie ou informe o código da Unidade) em vez do código do Material Mestre.'
                );
            }

            if (! $material->ativo) {
                throw new InventarioEstoqueInvalidoException('Este Material está inativo e não pode ser adicionado a um Inventário.');
            }

            $jaExiste = InventarioItem::where('inventario_estoque_id', $inventarioTravado->id)
                ->where('material_id', $material->id)
                ->whereNull('unidade_estoque_id')
                ->whereNull('serial_texto_inesperado')
                ->exists();
            if ($jaExiste) {
                throw new InventarioEstoqueInvalidoException('Este Material já faz parte deste Inventário.');
            }

            $local = $inventarioTravado->localEstoque()->firstOrFail();

            // Recurso físico travado ANTES de ler o saldo fresco — mesmo
            // total order já usado em AprovarAjusteInventario.
            LocalEstoque::whereKey($local->id)->lockForUpdate()->firstOrFail();
            $snapshotFresco = SaldoEstoque::porMaterialLocal($material, $local);

            return InventarioItem::create([
                'inventario_estoque_id' => $inventarioTravado->id,
                'material_id' => $material->id,
                'unidade_estoque_id' => null,
                'quantidade_sistema_snapshot' => $snapshotFresco,
                'serial_texto_inesperado' => null,
                'created_at' => now(),
            ]);
        });
    }
}
