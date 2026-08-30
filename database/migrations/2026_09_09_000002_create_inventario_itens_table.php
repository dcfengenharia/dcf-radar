<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.7 — 1 linha por (Material) ou (Material+UnidadeEstoque)
 * dentro de UMA sessão de Inventário. `tenant_id` presente, `obra_id`
 * NÃO denormalizado (mesmo padrão de `requisicao_planejamento_itens`/
 * `grd_itens` — obra sempre resolvida via `inventario_estoque_id`).
 *
 * `quantidade_sistema_snapshot` é a FOTO congelada no instante de
 * `IniciarInventarioEstoque` (Seção 3 do pedido) — nunca recalculada
 * depois, mesmo que o saldo real mude por movimentações posteriores
 * (Entrada/Saída/Transferência continuam livres durante o inventário,
 * decisão do usuário). "O sistema dizia 100 quando contamos 97" precisa
 * continuar verdadeiro pra sempre, mesmo que o saldo hoje seja 130.
 *
 * `unidade_estoque_id` (nullable, `restrictOnDelete()`): null pra
 * Material Quantitativo; preenchido pra Lote/Bobina/Serial — mesma
 * tripla denormalizada já usada em `MovimentacaoEstoque`/`ReservaEstoque`.
 * Bobina fracionada entre 2 Locais (20.5.CORREÇÃO): o snapshot aqui é
 * SEMPRE `SaldoEstoque::porUnidadeLocal()` (nunca `porUnidade()` global)
 * — inventariar o Local A de uma bobina com 300m lá e 700m no Local B
 * grava snapshot=300, nunca 1000.
 *
 * **Decisão do usuário (STOP-and-ask) — serial inesperado (Seção 8)**:
 * um item pode representar um serial físico ENCONTRADO que o sistema não
 * esperava naquele Local — `serial_texto_inesperado` (nullable) grava o
 * serial como TEXTO LIVRE informado pelo contador; `unidade_estoque_id`
 * fica SEMPRE `null` nesse caso (nenhuma `UnidadeEstoque` nova é criada
 * a partir de um Inventário — resolver de verdade, seja reconciliando
 * com outro Local ou lançando uma Entrada de verdade, é sempre manual,
 * fora do fluxo automático de Ajuste desta etapa —
 * `App\Actions\Estoque\AprovarAjusteInventario` recusa gerar Ajuste pra
 * um item nessa condição quando o Material exige Unidade).
 * `quantidade_sistema_snapshot=0` pra esse caso (nada era esperado ali).
 *
 * Imutabilidade: `App\Observers\InventarioItemObserver` bloqueia
 * `updating()`/`deleting()` incondicionalmente — o item nasce completo
 * (via `IniciarInventarioEstoque` em lote, ou via
 * `AdicionarItemInesperadoInventario` pro caso de serial inesperado) e
 * nunca é reescrito; toda evolução posterior vive em
 * `ContagemInventario` (1:N, append-only) e `InventarioAjuste` (0:1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_itens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('inventario_estoque_id')
                ->constrained('inventarios_estoque')
                ->cascadeOnDelete();
            $table->foreignUlid('material_id')->constrained('materiais')->restrictOnDelete();
            $table->foreignUlid('unidade_estoque_id')
                ->nullable()
                ->constrained('unidades_estoque')
                ->restrictOnDelete();
            $table->decimal('quantidade_sistema_snapshot', 14, 3);
            $table->string('serial_texto_inesperado')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['inventario_estoque_id', 'material_id', 'unidade_estoque_id'], 'inv_item_inv_material_unidade_unique');
            $table->index(['tenant_id', 'inventario_estoque_id'], 'inv_item_tenant_inv_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_itens');
    }
};
