<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.7 — Ajuste formal de estoque originado por
 * divergência de Inventário. **Decisão do usuário (STOP-and-ask,
 * decisão crítica sobre o enum)**: `TipoMovimentacaoEstoque` NUNCA ganha
 * `AjusteEntrada`/`AjusteSaida` — Ajuste reaproveita `Entrada`/`Saida`
 * já existentes (mesmo padrão comprovado em `RegistrarTransferenciaEstoque`
 * — confirmado lendo o código: a Saida/Entrada de uma Transferência não
 * populam `reserva_estoque_id`/`item_suprimento_id`/`frente_trabalho_id`/
 * `retirado_por`, e a rastreabilidade vive inteiramente numa entidade
 * CORRELATA separada, `TransferenciaEstoque`). Esta tabela é essa mesma
 * peça pro Ajuste: `movimentacao_estoque_id` (restrictOnDelete — evidência
 * histórica) aponta pra UMA `MovimentacaoEstoque` comum (tipo Entrada se
 * a divergência foi positiva, Saida se negativa) já criada por
 * `App\Actions\Estoque\AprovarAjusteInventario` na MESMA transação.
 * Direção do Ajuste é sempre lida via `movimentacaoEstoque.tipo` (relação)
 * — nunca duplicada aqui como coluna própria.
 *
 * `inventario_item_id` (restrictOnDelete, `unique` — no máximo 1 Ajuste
 * por item, nunca 2 correções empilhadas pro mesmo item/inventário).
 *
 * `justificativa` (text, NOT NULL) — exigida antes de qualquer Ajuste
 * (Seção 12 do pedido); sem catálogo de motivo dedicado (investigado:
 * `CausaNaoCumprimento` é de outro domínio, Atividade — nenhum catálogo
 * de "motivo de divergência de estoque" reutilizável existe; texto livre
 * evita um enum rígido prematuro pras causas abertas de obra, conforme o
 * próprio pedido pede).
 *
 * `aprovado_por` (nullOnDelete) + `created_at` (=momento da aprovação,
 * sem coluna redundante `aprovado_em`) — **decisão do usuário
 * (STOP-and-ask, separação de responsabilidade)**: aprovar exige DUPLA
 * autorização (`estoque.inventario|editar` E `estoque.movimentacao|editar`
 * simultaneamente, mesmo padrão já usado em `PlanoAcao::
 * transformarEmRestricoes()`, Ciclo 11) — não existe um estágio
 * "proposto" persistido separado: análise+justificativa+aprovação
 * acontecem numa ÚNICA ação/transação, exatamente como o precedente do
 * Ciclo 11 (permissão num domínio não substitui a do outro).
 *
 * Append-only: `App\Observers\InventarioAjusteObserver` bloqueia
 * `updating()`/`deleting()` incondicionalmente — corrigir um Ajuste
 * aprovado por engano exigiria um NOVO Ajuste/Inventário, nunca reescrita
 * (fora de escopo desta etapa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_ajustes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('inventario_item_id')
                ->unique()
                ->constrained('inventario_itens')
                ->restrictOnDelete();
            $table->foreignUlid('movimentacao_estoque_id')
                ->constrained('movimentacoes_estoque')
                ->restrictOnDelete();
            $table->decimal('quantidade', 14, 3);
            $table->text('justificativa');
            $table->foreignUlid('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'inventario_item_id'], 'inv_ajuste_tenant_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_ajustes');
    }
};
