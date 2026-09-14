<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fechamento Adversarial Etapa 3 (Seções 6-22) — fecha o gap real de
 * proveniência quantitativa entre um `RecebimentoPedido` (append-only,
 * granularidade de `PedidoCompraItem`, o item INTEIRO) e a(s)
 * `PedidoCompraItemParcela` (Etapa 1, granularidade de necessidade) que
 * aquele item pode atender. Fresh-read confirmou: `RecebimentoPedido`
 * nunca teve — em nenhum momento do domínio — qualquer coluna ou
 * parâmetro de Action que permitisse saber "esta quantidade recebida
 * corresponde a qual necessidade", quando o item recebe atendendo 2+
 * parcelas. Esta tabela é a ponte EXPLÍCITA e OPCIONAL — nunca
 * obrigatória no ato do recebimento (Seção 21: "receber primeiro é
 * fato; classificar destino lógico pode ser feito depois").
 *
 * **Cardinalidade N:N quantitativa** (Seção 12, provada necessária pelo
 * cenário do pedido: PedidoItem=100, ParcelaA=40+ParcelaB=60, R1=30→A,
 * R2=10→A+40→B, R3=20→B — resultado A=40/40, B=60/60): um
 * `RecebimentoPedido` pode gerar N linhas (uma por parcela destino); a
 * MESMA `PedidoCompraItemParcela` pode ser alvo de N linhas vindas de
 * recebimentos DIFERENTES. Nenhuma unicidade de par — a soma cumulativa
 * por par é o dado relevante, nunca "a última distribuição vale".
 *
 * **Tetos (Seção 13)** são de APLICAÇÃO
 * (`App\Actions\Suprimentos\DistribuirRecebimentoPedidoPorParcela`),
 * nunca de schema — mesmo padrão de toda a árvore de parcelas/alocação
 * do projeto: TETO A (soma das distribuições de UM recebimento nunca
 * excede a quantidade recebida daquele evento — "recebimento fechado",
 * mesmo idioma já usado por `AplicacaoMaterialEstoque`, Ciclo 20.4);
 * TETO B (soma das distribuições atribuídas a UMA parcela nunca excede
 * `PedidoCompraItemParcela.quantidade`); TETO C/D (a parcela alvo
 * precisa pertencer ao MESMO `PedidoCompraItem`/Pedido do recebimento —
 * nunca uma parcela de outro item ou de outro Pedido); TETO E (tenant/
 * obra/material/unidade coerentes, garantido pela cadeia FK + escopo de
 * tenant automático).
 *
 * **Ambas as FKs são `restrictOnDelete()`** — mesma lição de evidência
 * histórica já aplicada em toda a árvore do projeto desde a
 * A.9.3.CORREÇÃO: uma distribuição já registrada nunca pode
 * desaparecer silenciosamente por causa de uma exclusão em cascata de
 * `RecebimentoPedido` (append-only, nunca excluído na prática, mas a FK
 * nunca assume isso) ou de `PedidoCompraItemParcela` (só excluível
 * enquanto o Pedido é Rascunho — nunca terá distribuição real
 * associada nesse estado, mas a FK protege mesmo assim).
 *
 * **Sem UNIQUE(recebimento, parcela)** — distribuições cumulativas do
 * MESMO recebimento pra MESMA parcela (ex.: corrigir um valor
 * lançando uma linha complementar) são legítimas; a soma é o que os
 * tetos e o read-model consomem, nunca uma única linha "vigente".
 * Correção de uma distribuição já registrada é sempre um evento NOVO
 * (nunca update — `RecebimentoPedidoParcelaObserver` bloqueia
 * `updating()` incondicionalmente), e só é possível `deleting()` uma
 * linha enquanto o recebimento-dono ainda está "aberto" (soma das
 * distribuições < quantidade recebida) — mesmo mecanismo de "aberto/
 * fechado" derivado (nunca uma coluna de status) já usado por
 * `AplicacaoMaterialEstoque`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recebimento_pedido_parcelas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('recebimento_pedido_id');
            $table->foreign('recebimento_pedido_id', 'receb_pedido_parcela_receb_fk')
                ->references('id')->on('recebimentos_pedido')
                ->restrictOnDelete();

            $table->foreignUlid('pedido_compra_item_parcela_id');
            $table->foreign('pedido_compra_item_parcela_id', 'receb_pedido_parcela_parcela_fk')
                ->references('id')->on('pedido_compra_item_parcelas')
                ->restrictOnDelete();

            $table->decimal('quantidade', 14, 3);

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'recebimento_pedido_id'], 'receb_pedido_parcela_tenant_receb_idx');
            $table->index(['tenant_id', 'pedido_compra_item_parcela_id'], 'receb_pedido_parcela_tenant_parcela_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recebimento_pedido_parcelas');
    }
};
