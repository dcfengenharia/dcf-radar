<?php

namespace App\Actions\Estoque;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\StatusInventarioEstoque;
use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\ContagemInventario;
use App\Models\InventarioEstoque;
use App\Models\InventarioItem;
use App\Models\Material;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.7 — **decisão do usuário (STOP-and-ask, Seção 8)**:
 * registra um serial FISICAMENTE encontrado que o sistema NÃO esperava
 * naquele Local. Só registro TEXTUAL do serial informado — NUNCA cria
 * `UnidadeEstoque` nova a partir daqui (evitaria inventar uma identidade
 * física sem origem comercial rastreável). Resolver de verdade (é de
 * outro Local? é uma Entrada nunca lançada?) fica pro fluxo normal de
 * Entrada/Transferência, sempre manual — por isso
 * `App\Actions\Estoque\AprovarAjusteInventario` recusa gerar Ajuste
 * automático pra um item nesta condição.
 *
 * Restrito a Material Serializado (Seção 8 do pedido fala especificamente
 * em "serial", nunca lote/bobina — um lote/bobina inesperado sempre tem
 * uma UnidadeEstoque já existente em algum Local, resolvível via
 * Transferência normal, nunca via este fluxo).
 *
 * Sem proteção de unicidade a nível de banco pro par
 * (material_id, serial_texto_inesperado) — mesma limitação estrutural já
 * documentada no projeto pra tuplas com NULL num índice único (o
 * `unidade_estoque_id` desta linha é sempre NULL) — a checagem de
 * duplicidade é feita aqui, na Action, antes de criar.
 */
class AdicionarItemInesperadoInventario
{
    public function execute(
        InventarioEstoque $inventario,
        Material $material,
        string $serialTexto,
        float $quantidadeEncontrada,
        \DateTimeInterface $contadoEm,
        User $usuario,
        ?string $observacao = null,
    ): InventarioItem {
        return DB::transaction(function () use ($inventario, $material, $serialTexto, $quantidadeEncontrada, $contadoEm, $usuario, $observacao) {
            $inventarioTravado = InventarioEstoque::whereKey($inventario->id)->lockForUpdate()->firstOrFail();

            if (! in_array($inventarioTravado->status, [StatusInventarioEstoque::EmContagem, StatusInventarioEstoque::EmAnalise], true)) {
                throw new InventarioEstoqueInvalidoException('Este Inventário não está em contagem/análise — não é possível registrar um item inesperado.');
            }

            if ($material->modo_rastreabilidade !== ModoRastreabilidadeMaterial::Serializado) {
                throw new InventarioEstoqueInvalidoException('Item inesperado só se aplica a Material Serializado — um lote/bobina inesperado já tem UnidadeEstoque existente em algum Local, resolvível por Transferência normal.');
            }

            $serialTexto = trim($serialTexto);
            if ($serialTexto === '') {
                throw new InventarioEstoqueInvalidoException('Informe o serial físico encontrado.');
            }

            $jaRegistrado = InventarioItem::where('inventario_estoque_id', $inventarioTravado->id)
                ->where('material_id', $material->id)
                ->where('serial_texto_inesperado', $serialTexto)
                ->exists();
            if ($jaRegistrado) {
                throw new InventarioEstoqueInvalidoException('Este serial inesperado já foi registrado neste Inventário.');
            }

            $dataContagem = Carbon::parse($contadoEm)->startOfDay();
            if ($dataContagem->gt(Carbon::today())) {
                throw new InventarioEstoqueInvalidoException('A data da contagem não pode estar no futuro.');
            }

            $item = InventarioItem::create([
                'inventario_estoque_id' => $inventarioTravado->id,
                'material_id' => $material->id,
                'unidade_estoque_id' => null,
                'quantidade_sistema_snapshot' => 0,
                'serial_texto_inesperado' => $serialTexto,
                'created_at' => now(),
            ]);

            ContagemInventario::create([
                'inventario_item_id' => $item->id,
                'quantidade_contada' => $quantidadeEncontrada,
                'contado_em' => $dataContagem,
                'contador_id' => $usuario->id,
                'observacao' => $observacao,
            ]);

            return $item->fresh();
        });
    }
}
