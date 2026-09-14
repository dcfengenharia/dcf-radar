<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 3 (Prazo Comercial do Pedido + Histórico de Promessa) — fato
 * append-only: "em tal data, tal usuário registrou que a previsão de
 * entrega deste Pedido é tal". Nunca sobrescreve a linha anterior — a
 * evolução da promessa (10/10 → 20/10 → 05/11) precisa continuar
 * navegável pra sempre, mesmo idioma já usado em toda a árvore RC/
 * Pedido/Adjudicação (histórico nunca apagado, só um estado novo é
 * registrado ao lado do antigo).
 *
 * **Autoridade — decisão do usuário (Seção 6, Opção A)**: `PedidoCompra.
 * data_prevista_entrega` CONTINUA sendo o snapshot/cache da previsão
 * VIGENTE (evita blast radius sobre `diasAtrasoAtual()`/
 * `diasAtrasoFinal()`/`RequisicaoCompra::dataProjetadaAtendimento()`/
 * `AlertaCadeiaSuprimento`/`CockpitSuprimentosQuery`/`SituacoesGerenciaisQuery`
 * — todos continuam lendo o MESMO campo, sem nenhuma mudança). A
 * disciplina que impede divergência: o snapshot só é escrito dentro da
 * MESMA transação que cria a linha de histórico correspondente
 * (`App\Actions\Suprimentos\AtualizarPrevisaoEntregaPedidoCompra` e o
 * bloco equivalente em `EmitirPedidoCompra`) — nunca um UPDATE solto.
 *
 * `pedido_compra_id` é `restrictOnDelete()` — evidência histórica, mesmo
 * padrão de toda a árvore RC/Pedido (o `PedidoCompra` já usa
 * `SoftDeletes`, então isto só protegeria um `forceDelete()` futuro).
 *
 * `origem` distingue só a diferença ESTRUTURAL real entre 2 caminhos de
 * código: `inicial` (a primeira previsão conhecida, registrada no
 * instante da emissão) vs. `revisao` (qualquer mudança posterior,
 * explícita, via a Action dedicada) — nunca um enum de motivo de negócio
 * (Fornecedor/Interna), que já é livremente descrito em `motivo`/
 * `observacao` (texto livre, sem necessidade de uma taxonomia fixa —
 * Seção 5: "não criar enum sem necessidade real").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_compra_previsoes_entrega', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('pedido_compra_id');
            $table->foreign('pedido_compra_id', 'ped_previsao_pedido_fk')
                ->references('id')->on('pedidos_compra')
                ->restrictOnDelete();

            $table->date('data_prevista');
            $table->string('origem');

            $table->foreignUlid('registrado_por_id')->nullable();
            $table->foreign('registrado_por_id', 'ped_previsao_registrado_por_fk')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->timestamp('registrado_em');

            $table->string('motivo')->nullable();
            $table->text('observacao')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'pedido_compra_id'], 'ped_previsao_tenant_pedido_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_compra_previsoes_entrega');
    }
};
